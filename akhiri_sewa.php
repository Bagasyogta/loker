<?php
require_once __DIR__ . '/config/database.php';
requireLogin();

$user = getLoggedInUser();
$db = getDbConnection();
$error = '';

$peminjamanId = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Cari data sesi check-in aktif milik user
$stmtRent = $db->prepare("
    SELECT p.*, l.no_loker 
    FROM peminjaman p 
    JOIN loker l ON p.id_loker = l.id 
    WHERE p.id = ? AND p.id_user = ? AND p.status IN ('aktif', 'menunggu_akhiri_scan', 'menunggu_akhiri_password')
");
$stmtRent->execute([$peminjamanId, $user['id']]);
$peminjaman = $stmtRent->fetch();

if (!$peminjaman) {
    $_SESSION['flash_error'] = 'Tidak ditemukan sesi peminjaman aktif untuk unit loker ini.';
    header("Location: dashboard.php");
    exit;
}

// Inisialisasi token QR Check-Out jika status masih 'aktif'
if ($peminjaman['status'] === 'aktif') {
    $token = 'SL-OUT-' . strtoupper(bin2hex(random_bytes(6))) . '-' . $peminjamanId;
    $expired = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    $stmtInsertQr = $db->prepare("
        INSERT INTO qr_code (id_peminjaman, qr_token, expired, status, tipe, dibuat) 
        VALUES (?, ?, ?, 'aktif', 'akhiri', NOW())
    ");
    $stmtInsertQr->execute([$peminjamanId, $token, $expired]);

    $stmtUpdateStatus = $db->prepare("UPDATE peminjaman SET status = 'menunggu_akhiri_scan' WHERE id = ?");
    $stmtUpdateStatus->execute([$peminjamanId]);
    $peminjaman['status'] = 'menunggu_akhiri_scan';
}

// Ambil QR code aktif tipe 'akhiri' (check-out)
$stmtQr = $db->prepare("
    SELECT * FROM qr_code 
    WHERE id_peminjaman = ? AND tipe = 'akhiri' 
    ORDER BY id DESC LIMIT 1
");
$stmtQr->execute([$peminjamanId]);
$qrRecord = $stmtQr->fetch();

// Hitung tarif parkir berjalan (dihitung sejak waktu check-in, bertambah Rp 5.000 setiap jam)
$tariff = calculateParkingTariff($peminjaman['rent_start']);
$totalBiaya = $tariff['fee'];
$totalJam = $tariff['hours'];
$durasiText = $tariff['duration_text'];

// Proses Verifikasi Password (2FA) & Pembayaran
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'konfirmasi_checkout') {
    $password = $_POST['password'] ?? '';

    if (empty($password)) {
        $error = 'Kata sandi wajib diisi untuk konfirmasi check-out!';
    } else {
        $stmtUserDb = $db->prepare("SELECT password, saldo FROM users WHERE id = ?");
        $stmtUserDb->execute([$user['id']]);
        $currentUser = $stmtUserDb->fetch();

        // 1. Cek Password (Faktor 2 - 2FA)
        if (!password_verify($password, $currentUser['password'])) {
            $error = 'Kata sandi salah! Verifikasi tahap kedua (2FA) gagal.';
            $stmtLog = $db->prepare("
                INSERT INTO akses (id_peminjaman, id_qr, qr_valid, password_valid, hasil, waktu) 
                VALUES (?, ?, 1, 0, 'gagal_password_checkout', NOW())
            ");
            $stmtLog->execute([$peminjamanId, $qrRecord['id'] ?? null]);
        } else {
            // 2. Cek Kecukupan Saldo
            if ($currentUser['saldo'] < $totalBiaya) {
                $error = 'Saldo Anda tidak mencukupi untuk pembayaran! Total Tagihan: ' . formatRupiah($totalBiaya) . ' (Durasi: ' . $durasiText . '), Saldo Anda: ' . formatRupiah($currentUser['saldo']) . '. Silakan lakukan Top Up terlebih dahulu.';
                
                $stmtLog = $db->prepare("
                    INSERT INTO akses (id_peminjaman, id_qr, qr_valid, password_valid, hasil, waktu) 
                    VALUES (?, ?, 1, 1, 'gagal_saldo_checkout', NOW())
                ");
                $stmtLog->execute([$peminjamanId, $qrRecord['id'] ?? null]);
            } else {
                // Saldo Cukup & Password Benar -> Potong Saldo & Buka Loker Pengambilan
                $db->beginTransaction();
                try {
                    // Potong Saldo
                    $stmtPotong = $db->prepare("UPDATE users SET saldo = saldo - ? WHERE id = ?");
                    $stmtPotong->execute([$totalBiaya, $user['id']]);

                    // Update Sesi Peminjaman -> Selesai
                    $stmtUpdatePeminjaman = $db->prepare("
                        UPDATE peminjaman 
                        SET status = 'selesai', rent_end = NOW(), rent_durasi = ?, rent_price = ?, door_unlock_trigger = 1, door_unlocked_at = NOW() 
                        WHERE id = ?
                    ");
                    $stmtUpdatePeminjaman->execute([$totalJam, $totalBiaya, $peminjamanId]);

                    // Reset Status Loker -> Tersedia
                    $stmtResetLoker = $db->prepare("UPDATE loker SET status = 'tersedia' WHERE id = ?");
                    $stmtResetLoker->execute([$peminjaman['id_loker']]);

                    // Update QR -> Digunakan
                    if ($qrRecord) {
                        $stmtUpdateQr = $db->prepare("UPDATE qr_code SET status = 'digunakan' WHERE id = ?");
                        $stmtUpdateQr->execute([$qrRecord['id']]);
                    }

                    // Catat Log Sukses ke Tabel AKSES
                    $stmtLogSuccess = $db->prepare("
                        INSERT INTO akses (id_peminjaman, id_qr, qr_valid, password_valid, hasil, waktu) 
                        VALUES (?, ?, 1, 1, 'berhasil_checkout', NOW())
                    ");
                    $stmtLogSuccess->execute([$peminjamanId, $qrRecord['id'] ?? null]);

                    $db->commit();

                    $_SESSION['flash_success'] = "Pembayaran " . formatRupiah($totalBiaya) . " Berhasil! Solenoid Door Lock Loker #{$peminjaman['no_loker']} terbuka untuk pengambilan barang. Terima kasih!";
                    header("Location: dashboard.php");
                    exit;
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = 'Terjadi kesalahan sistem saat memproses transaksi: ' . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check-Out Loker - Smart Locker IoT</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/qrcode.min.js"></script>
</head>
<body>

<header class="navbar">
    <a href="dashboard.php" class="navbar-brand">
        <span class="brand-badge">IoT</span>
        <span>Smart Locker System</span>
    </a>
    <div class="navbar-user">
        <div class="user-badge">
            <span>👤 <?= htmlspecialchars($user['username']); ?></span>
            <span class="saldo-chip">Saldo: <?= formatRupiah($user['saldo']); ?></span>
        </div>
        <a href="dashboard.php" class="btn btn-sm btn-secondary">&larr; Dashboard</a>
    </div>
</header>

<main class="container" style="max-width: 680px;">

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger" style="margin-top: 16px;">
            <span>⚠️</span> <div><?= htmlspecialchars($error); ?></div>
        </div>
    <?php endif; ?>

    <div class="card" style="margin-top: 20px;">
        <div class="card-header">
            <h2>Prosedur Check-Out &bull; Unit Loker #<?= $peminjaman['no_loker']; ?></h2>
            <span class="status-badge status-<?= $peminjaman['status']; ?>">
                <?= str_replace('_', ' ', strtoupper($peminjaman['status'])); ?>
            </span>
        </div>
        <div class="card-body">

            <!-- Rincian Biaya Sistem Parkir Berjalan -->
            <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 16px; margin-bottom: 20px;">
                <h4 style="margin-bottom: 10px; color: var(--primary);">Perhitungan Tarif Pemakaian (Sistem Parkir)</h4>
                
                <div style="display: flex; justify-content: space-between; font-size: 0.88rem; margin-bottom: 4px;">
                    <span style="color: var(--text-muted);">Waktu Check-In:</span>
                    <span><?= date('d M Y, H:i:s', strtotime($peminjaman['rent_start'])); ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 0.88rem; margin-bottom: 4px;">
                    <span style="color: var(--text-muted);">Waktu Check-Out (Saat Ini):</span>
                    <span><?= date('d M Y, H:i:s'); ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 0.88rem; margin-bottom: 4px;">
                    <span style="color: var(--text-muted);">Durasi Pemakaian:</span>
                    <strong><?= $durasiText; ?> (Dihitung <?= $totalJam; ?> Jam)</strong>
                </div>
                
                <div style="display: flex; justify-content: space-between; font-size: 0.95rem; border-top: 1px dashed var(--border); padding-top: 8px; margin-top: 8px;">
                    <span>Tarif per Jam:</span>
                    <span><?= formatRupiah(DEFAULT_HOURLY_RATE); ?> / Jam</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 1.05rem; margin-top: 4px;">
                    <span><strong>Total Tagihan:</strong></span>
                    <strong style="color: var(--danger); font-size: 1.15rem;"><?= formatRupiah($totalBiaya); ?></strong>
                </div>

                <div style="display: flex; justify-content: space-between; font-size: 0.9rem; border-top: 1px solid var(--border); padding-top: 8px; margin-top: 8px;">
                    <span>Saldo Akun Anda:</span>
                    <strong style="color: <?= $user['saldo'] >= $totalBiaya ? 'green' : 'red'; ?>;">
                        <?= formatRupiah($user['saldo']); ?>
                    </strong>
                </div>
                <?php if ($user['saldo'] < $totalBiaya): ?>
                    <div style="margin-top: 10px;">
                        <a href="topup.php" class="btn btn-sm btn-success btn-block">+ Top Up Saldo Sekarang</a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- STEP 1: SCAN QR CODE CHECK-OUT -->
            <div class="step-box" style="<?= $peminjaman['status'] === 'menunggu_akhiri_scan' ? 'border-color: var(--primary); background: #eff6ff;' : 'opacity: 0.7;'; ?>">
                <div class="step-number" style="<?= $peminjaman['status'] === 'menunggu_akhiri_password' ? 'background: #10b981;' : ''; ?>">
                    <?= $peminjaman['status'] === 'menunggu_akhiri_password' ? '✔' : '1'; ?>
                </div>
                <div class="step-text">
                    <h4>Tahap 1: Pindai QR Code Check-Out (Faktor 1 Kepemilikan)</h4>
                    <p>Arahkan kode QR Check-Out di bawah ke modul kamera ESP32-CAM pada loker.</p>
                </div>
            </div>

            <?php if ($peminjaman['status'] === 'menunggu_akhiri_scan'): ?>
                <div class="qr-container">
                    <div class="qr-box" id="qrcode"></div>
                    <div style="margin-top: 12px;">
                        <span style="font-size: 0.85rem; color: var(--text-muted);">Token Check-Out Anda:</span><br>
                        <code style="font-size: 1.05rem; font-weight: bold; color: var(--primary);"><?= htmlspecialchars($qrRecord['qr_token'] ?? ''); ?></code>
                    </div>
                    <div class="qr-timer">
                        Masa berlaku s/d: <strong><?= date('H:i:s', strtotime($qrRecord['expired'] ?? '+15 min')); ?></strong>
                    </div>

                    <div style="margin-top: 16px; display: flex; gap: 8px; align-items: center; justify-content: center; font-size: 0.85rem; color: #0284c7;">
                        <span style="display:inline-block; animation: spin 1s linear infinite;">⏳</span>
                        <span>Menunggu kamera ESP32-CAM membaca kode check-out...</span>
                    </div>

                    <div style="margin-top: 20px; padding: 12px; background: #f8fafc; border-radius: 8px; width: 100%; border: 1px dashed #cbd5e1;">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="simulateScan('<?= $qrRecord['qr_token'] ?? ''; ?>')">
                            📷 Simulasikan Scan oleh ESP32-CAM
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- STEP 2: VERIFIKASI PASSWORD & PEMBAYARAN -->
            <div class="step-box" style="<?= $peminjaman['status'] === 'menunggu_akhiri_password' ? 'border-color: var(--primary); background: #eff6ff;' : ($peminjaman['status'] === 'menunggu_akhiri_scan' ? 'opacity: 0.5;' : ''); ?>">
                <div class="step-number">2</div>
                <div class="step-text">
                    <h4>Tahap 2: Konfirmasi Password Akun (Faktor 2 Knowledge) & Pembayaran</h4>
                    <p>Masukkan kata sandi akun Anda sebagai konfirmasi kedua untuk membuka pintu pengambilan barang.</p>
                </div>
            </div>

            <?php if ($peminjaman['status'] === 'menunggu_akhiri_password'): ?>
                <div style="background: #ffffff; border: 2px solid #93c5fd; border-radius: 12px; padding: 20px;">
                    <div class="alert alert-success" style="margin-bottom: 14px;">
                        <span>✅</span> <div><strong>QR Code Check-Out Berhasil Terbaca!</strong> Masukkan kata sandi akun Anda untuk konfirmasi kedua dan membuka pintu loker.</div>
                    </div>

                    <form method="POST" action="akhiri_sewa.php?id=<?= $peminjamanId; ?>">
                        <input type="hidden" name="action" value="konfirmasi_checkout">

                        <div class="form-group">
                            <label for="password">Kata Sandi Akun Pengguna</label>
                            <input type="password" id="password" name="password" class="form-control" placeholder="Masukkan password Anda" required autofocus>
                        </div>

                        <button type="submit" class="btn btn-danger btn-block" style="padding: 12px; font-size: 1rem;">
                            💰 Bayar (<?= formatRupiah($totalBiaya); ?>) & Buka Pintu Pengambilan
                        </button>
                    </form>
                </div>
            <?php endif; ?>

        </div>
    </div>

</main>

<script>
<?php if ($peminjaman['status'] === 'menunggu_akhiri_scan' && $qrRecord): ?>
    new QRCode(document.getElementById("qrcode"), {
        text: "<?= $qrRecord['qr_token']; ?>",
        width: 200,
        height: 200,
        colorDark : "#0f172a",
        colorLight : "#ffffff",
        correctLevel : QRCode.CorrectLevel.H
    });

    const pollingInterval = setInterval(() => {
        fetch('api/check_status.php?mode=user_polling&peminjaman_id=<?= $peminjamanId; ?>')
            .then(res => res.json())
            .then(data => {
                if (data.status === 'menunggu_akhiri_password' || data.status === 'selesai') {
                    clearInterval(pollingInterval);
                    window.location.reload();
                }
            })
            .catch(err => console.error(err));
    }, 2000);

    function simulateScan(token) {
        if (!token) return;
        fetch('api/simulate_scan.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ qr_token: token, ldr_val: 350 })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                window.location.reload();
            } else {
                alert(data.message || 'Gagal simulasi scan');
            }
        })
        .catch(err => {
            alert('Terjadi kesalahan koneksi.');
        });
    }
<?php endif; ?>
</script>

<style>
@keyframes spin { 100% { transform: rotate(360deg); } }
</style>

</body>
</html>
