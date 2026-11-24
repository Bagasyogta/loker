<?php
require_once __DIR__ . '/config/database.php';
requireLogin();

$user = getLoggedInUser();
$db = getDbConnection();
$error = '';
$success = '';

$peminjamanId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$peminjaman = null;
$qrRecord = null;

// Jika belum ada peminjamanId, buat sesi Check-In baru berdasarkan loker_id
if (!$peminjamanId) {
    $lokerId = isset($_GET['loker_id']) ? intval($_GET['loker_id']) : 0;
    
    // Cek unit loker
    $stmtLoker = $db->prepare("SELECT * FROM loker WHERE id = ?");
    $stmtLoker->execute([$lokerId]);
    $loker = $stmtLoker->fetch();

    $isEspOnline = (!empty($loker['last_seen']) && (time() - strtotime($loker['last_seen'])) <= 10);

    if (!$loker || $loker['status'] !== 'tersedia' || !$isEspOnline) {
        $_SESSION['flash_error'] = 'Unit loker saat ini tidak tersedia atau mikrokontroler (ESP32) belum terhubung!';
        header("Location: dashboard.php");
        exit;
    }

    // Inisialisasi sesi Check-In baru
    $stmtInsertRent = $db->prepare("
        INSERT INTO peminjaman (id_user, id_loker, rent_durasi, rent_price, status) 
        VALUES (?, ?, 1, ?, 'menunggu_scan')
    ");
    $stmtInsertRent->execute([$user['id'], $lokerId, DEFAULT_HOURLY_RATE]);
    $peminjamanId = $db->lastInsertId();

    // Generate Token QR Code Check-In (Faktor 1 Kepemilikan)
    $token = 'SL-IN-' . strtoupper(bin2hex(random_bytes(6))) . '-' . $peminjamanId;
    $expired = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    $stmtInsertQr = $db->prepare("
        INSERT INTO qr_code (id_peminjaman, qr_token, expired, status, tipe, dibuat) 
        VALUES (?, ?, ?, 'aktif', 'sewa', NOW())
    ");
    $stmtInsertQr->execute([$peminjamanId, $token, $expired]);

    header("Location: sewa.php?id=" . $peminjamanId);
    exit;
} else {
    // Ambil data peminjaman yang ada
    $stmtRent = $db->prepare("
        SELECT p.*, l.no_loker 
        FROM peminjaman p 
        JOIN loker l ON p.id_loker = l.id 
        WHERE p.id = ? AND p.id_user = ?
    ");
    $stmtRent->execute([$peminjamanId, $user['id']]);
    $peminjaman = $stmtRent->fetch();

    if (!$peminjaman) {
        $_SESSION['flash_error'] = 'Data sesi check-in tidak ditemukan!';
        header("Location: dashboard.php");
        exit;
    }

    // Ambil token QR aktif tipe 'sewa' (check-in)
    $stmtQr = $db->prepare("
        SELECT * FROM qr_code 
        WHERE id_peminjaman = ? AND tipe = 'sewa' 
        ORDER BY id DESC LIMIT 1
    ");
    $stmtQr->execute([$peminjamanId]);
    $qrRecord = $stmtQr->fetch();

    // Proses Verifikasi 2FA (Input Password User sebagai Konfirmasi Kedua)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verifikasi_2fa') {
        $password = $_POST['password'] ?? '';

        if (empty($password)) {
            $error = 'Kata sandi akun wajib diisi untuk verifikasi 2FA!';
        } else {
            // Ambil hash password terbaru dari database
            $stmtUserPass = $db->prepare("SELECT password FROM users WHERE id = ?");
            $stmtUserPass->execute([$user['id']]);
            $userDb = $stmtUserPass->fetch();

            $isPasswordValid = password_verify($password, $userDb['password']);

            if (!$isPasswordValid) {
                // Password Salah
                $error = 'Kata Sandi Salah! Verifikasi faktor kedua (2FA) gagal.';

                // Catat log gagal ke tabel AKSES
                $stmtLog = $db->prepare("
                    INSERT INTO akses (id_peminjaman, id_qr, qr_valid, password_valid, hasil, waktu) 
                    VALUES (?, ?, 1, 0, 'gagal_password_checkin', NOW())
                ");
                $stmtLog->execute([$peminjamanId, $qrRecord['id'] ?? null]);
            } else {
                // Password Cocok -> Aktivasi Check-In & Buka Solenoid Lock
                $db->beginTransaction();
                try {
                    // Update peminjaman: status aktif, waktu mulai sekarang
                    $stmtUpdateRent = $db->prepare("
                        UPDATE peminjaman 
                        SET status = 'aktif', rent_start = NOW(), door_unlock_trigger = 1, door_unlocked_at = NOW() 
                        WHERE id = ?
                    ");
                    $stmtUpdateRent->execute([$peminjamanId]);

                    // Update unit loker menjadi terpakai
                    $stmtUpdateLoker = $db->prepare("UPDATE loker SET status = 'terpakai' WHERE id = ?");
                    $stmtUpdateLoker->execute([$peminjaman['id_loker']]);

                    // Update status QR code menjadi digunakan
                    if ($qrRecord) {
                        $stmtUpdateQr = $db->prepare("UPDATE qr_code SET status = 'digunakan' WHERE id = ?");
                        $stmtUpdateQr->execute([$qrRecord['id']]);
                    }

                    // Catat log sukses ke tabel AKSES
                    $stmtLogSuccess = $db->prepare("
                        INSERT INTO akses (id_peminjaman, id_qr, qr_valid, password_valid, hasil, waktu) 
                        VALUES (?, ?, 1, 1, 'berhasil_checkin', NOW())
                    ");
                    $stmtLogSuccess->execute([$peminjamanId, $qrRecord['id'] ?? null]);

                    $db->commit();

                    $_SESSION['flash_success'] = "Check-In Berhasil! Solenoid Door Lock Loker #{$peminjaman['no_loker']} terbuka. Silakan masukkan barang dan tutup pintu kembali. Tarif parkir mulai berjalan.";
                    header("Location: dashboard.php");
                    exit;
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = 'Terjadi kesalahan sistem saat mengaktifkan loker: ' . $e->getMessage();
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
    <title>Check-In Loker - Smart Locker IoT</title>
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

    <?php if ($peminjaman): ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" style="margin-top: 16px;">
                <span>⚠️</span> <div><?= htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <div class="card" style="margin-top: 20px;">
            <div class="card-header">
                <h2>Prosedur Check-In &bull; Unit Loker #<?= $peminjaman['no_loker']; ?></h2>
                <span class="status-badge status-<?= $peminjaman['status']; ?>">
                    <?= str_replace('_', ' ', strtoupper($peminjaman['status'])); ?>
                </span>
            </div>
            <div class="card-body">

                <!-- Informasi Tarif Parkir -->
                <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 14px; margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <strong style="color: var(--primary); font-size: 0.95rem;">Sistem Tarif Parkir:</strong>
                            <p style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">
                                Dihitung setelah check-in berhasil: <strong><?= formatRupiah(DEFAULT_HOURLY_RATE); ?></strong> untuk 1 jam pertama, dan bertambah <strong><?= formatRupiah(DEFAULT_HOURLY_RATE); ?></strong> setiap jam berikutnya.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- STEP 1: SCAN QR CODE CHECK-IN -->
                <div class="step-box" style="<?= $peminjaman['status'] === 'menunggu_scan' ? 'border-color: var(--primary); background: #eff6ff;' : 'opacity: 0.7;'; ?>">
                    <div class="step-number" style="<?= $peminjaman['status'] !== 'menunggu_scan' ? 'background: #10b981;' : ''; ?>">
                        <?= $peminjaman['status'] !== 'menunggu_scan' ? '✔' : '1'; ?>
                    </div>
                    <div class="step-text">
                        <h4>Tahap 1: Pindai QR Code Check-In (Faktor 1 Kepemilikan)</h4>
                        <p>Arahkan kode QR di bawah ini ke modul kamera ESP32-CAM yang terpasang pada unit loker.</p>
                    </div>
                </div>

                <?php if ($peminjaman['status'] === 'menunggu_scan'): ?>
                    <div class="qr-container">
                        <div class="qr-box" id="qrcode"></div>
                        <div style="margin-top: 12px;">
                            <span style="font-size: 0.85rem; color: var(--text-muted);">Token Check-In Anda:</span><br>
                            <code style="font-size: 1.05rem; font-weight: bold; color: var(--primary);"><?= htmlspecialchars($qrRecord['qr_token'] ?? ''); ?></code>
                        </div>
                        <div class="qr-timer">
                            Masa berlaku token s/d: <strong><?= date('H:i:s', strtotime($qrRecord['expired'] ?? '+15 min')); ?></strong>
                        </div>

                        <div style="margin-top: 16px; display: flex; gap: 8px; align-items: center; justify-content: center; font-size: 0.85rem; color: #0284c7;">
                            <span class="spinner" style="display:inline-block; animation: spin 1s linear infinite;">⏳</span>
                            <span>Menunggu kamera ESP32-CAM membaca kode QR...</span>
                        </div>

                        <!-- Tombol Bantuan Simulasi Scan Kamera -->
                        <div style="margin-top: 20px; padding: 12px; background: #f8fafc; border-radius: 8px; width: 100%; border: 1px dashed #cbd5e1;">
                            <span style="font-size: 0.78rem; color: var(--text-muted); display: block; margin-bottom: 6px;">Simulasi Pemindaian Kamera:</span>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="simulateScan('<?= $qrRecord['qr_token'] ?? ''; ?>')">
                                📷 Simulasikan Scan oleh ESP32-CAM
                            </button>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- STEP 2: VERIFIKASI PASSWORD USER SEBAGAI KONFIRMASI KEDUA (2FA) -->
                <div class="step-box" style="<?= $peminjaman['status'] === 'menunggu_password' ? 'border-color: var(--primary); background: #eff6ff;' : ($peminjaman['status'] === 'menunggu_scan' ? 'opacity: 0.5;' : ''); ?>">
                    <div class="step-number" style="<?= $peminjaman['status'] === 'aktif' ? 'background: #10b981;' : ''; ?>">
                        <?= $peminjaman['status'] === 'aktif' ? '✔' : '2'; ?>
                    </div>
                    <div class="step-text">
                        <h4>Tahap 2: Konfirmasi Kata Sandi Akun (Faktor 2 Knowledge)</h4>
                        <p>Masukkan kata sandi akun Anda sebagai konfirmasi kedua sebelum pintu loker dibuka.</p>
                    </div>
                </div>

                <?php if ($peminjaman['status'] === 'menunggu_password'): ?>
                    <div style="background: #ffffff; border: 2px solid #93c5fd; border-radius: 12px; padding: 20px; margin-bottom: 20px;">
                        <div class="alert alert-success" style="margin-bottom: 14px;">
                            <span>✅</span> <div><strong>QR Code Berhasil Dipindai Kamera!</strong> Silakan masukkan password akun Anda untuk konfirmasi kedua.</div>
                        </div>

                        <form method="POST" action="sewa.php?id=<?= $peminjamanId; ?>">
                            <input type="hidden" name="action" value="verifikasi_2fa">

                            <div class="form-group">
                                <label for="password">Kata Sandi Akun Pengguna</label>
                                <input type="password" id="password" name="password" class="form-control" placeholder="Masukkan password Anda" required autofocus>
                                <small style="color: var(--text-muted); font-size: 0.8rem; margin-top: 4px; display: block;">
                                    Sistem akan memverifikasi password dan membuka solenoid door lock loker.
                                </small>
                            </div>

                            <button type="submit" class="btn btn-success btn-block" style="padding: 12px; font-size: 1rem;">
                                🔓 Konfirmasi Password & Buka Loker
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <script>
        <?php if ($peminjaman['status'] === 'menunggu_scan' && $qrRecord): ?>
            new QRCode(document.getElementById("qrcode"), {
                text: "<?= $qrRecord['qr_token']; ?>",
                width: 200,
                height: 200,
                colorDark : "#0f172a",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.H
            });

            // Polling status apakah QR sudah discan oleh ESP32-CAM
            const pollingInterval = setInterval(() => {
                fetch('api/check_status.php?mode=user_polling&peminjaman_id=<?= $peminjamanId; ?>')
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'menunggu_password' || data.status === 'aktif') {
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

    <?php endif; ?>

</main>

<style>
@keyframes spin { 100% { transform: rotate(360deg); } }
</style>

</body>
</html>
