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

// Jika belum ada peminjamanId, buat sesi peminjaman baru berdasarkan loker_id
if (!$peminjamanId) {
    $lokerId = isset($_GET['loker_id']) ? intval($_GET['loker_id']) : 0;
    
    // Cek unit loker
    $stmtLoker = $db->prepare("SELECT * FROM loker WHERE id = ?");
    $stmtLoker->execute([$lokerId]);
    $loker = $stmtLoker->fetch();

    if (!$loker || $loker['status'] !== 'tersedia') {
        $_SESSION['flash_error'] = 'Unit loker tidak tersedia atau sedang digunakan!';
        header("Location: dashboard.php");
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'buat_sewa') {
        $durasi = intval($_POST['durasi'] ?? 1);
        if ($durasi < 1) $durasi = 1;
        $harga = $durasi * DEFAULT_HOURLY_RATE;

        // Buat record peminjaman baru
        $stmtInsertRent = $db->prepare("
            INSERT INTO peminjaman (id_user, id_loker, rent_durasi, rent_price, status) 
            VALUES (?, ?, ?, ?, 'menunggu_scan')
        ");
        $stmtInsertRent->execute([$user['id'], $lokerId, $durasi, $harga]);
        $peminjamanId = $db->lastInsertId();

        // Generate Token QR Code Unik (Faktor 1 Kepemilikan)
        $token = 'SL-RENT-' . strtoupper(bin2hex(random_bytes(6))) . '-' . $peminjamanId;
        $expired = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        $stmtInsertQr = $db->prepare("
            INSERT INTO qr_code (id_peminjaman, qr_token, expired, status, tipe, dibuat) 
            VALUES (?, ?, ?, 'aktif', 'sewa', NOW())
        ");
        $stmtInsertQr->execute([$peminjamanId, $token, $expired]);

        header("Location: sewa.php?id=" . $peminjamanId);
        exit;
    }
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
        $_SESSION['flash_error'] = 'Data peminjaman tidak ditemukan!';
        header("Location: dashboard.php");
        exit;
    }

    // Ambil token QR aktif
    $stmtQr = $db->prepare("
        SELECT * FROM qr_code 
        WHERE id_peminjaman = ? AND tipe = 'sewa' 
        ORDER BY id DESC LIMIT 1
    ");
    $stmtQr->execute([$peminjamanId]);
    $qrRecord = $stmtQr->fetch();

    // Proses Verifikasi 2FA (Input Password User Sesuai Bab 3)
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
                // Sesuai Activity Diagram Gambar 3.5: Password Salah -> Tampilkan Pesan Salah
                $error = 'Kata Sandi Salah! Verifikasi faktor kedua (2FA) gagal.';

                // Catat log gagal ke tabel AKSES
                $stmtLog = $db->prepare("
                    INSERT INTO akses (id_peminjaman, id_qr, qr_valid, password_valid, hasil, waktu) 
                    VALUES (?, ?, 1, 0, 'gagal_password', NOW())
                ");
                $stmtLog->execute([$peminjamanId, $qrRecord['id'] ?? null]);
            } else {
                // Password Cocok -> Aktivasi Sewa & Buka Solenoid Lock
                $db->beginTransaction();
                try {
                    // Update peminjaman
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

                    // Catat log sukses ke tabel AKSES (sesuai Bab 3.4.2 Gambar 3.9)
                    $stmtLogSuccess = $db->prepare("
                        INSERT INTO akses (id_peminjaman, id_qr, qr_valid, password_valid, hasil, waktu) 
                        VALUES (?, ?, 1, 1, 'berhasil_sewa', NOW())
                    ");
                    $stmtLogSuccess->execute([$peminjamanId, $qrRecord['id'] ?? null]);

                    $db->commit();

                    $_SESSION['flash_success'] = "Autentikasi 2FA Berhasil! Solenoid Door Lock Loker #{$peminjaman['no_loker']} terbuka. Silakan masukkan barang dan tutup pintu.";
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
    <title>Sewa Loker - Smart Locker IoT</title>
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

    <?php if (!$peminjamanId && isset($loker)): ?>
        <!-- FORM PEMILIHAN DURASI SEWA AWAL -->
        <div class="card" style="margin-top: 20px;">
            <div class="card-header">
                <h2>Konfirmasi Pemesanan Unit Loker #<?= $loker['no_loker']; ?></h2>
            </div>
            <div class="card-body">
                <form method="POST" action="sewa.php">
                    <input type="hidden" name="action" value="buat_sewa">
                    <input type="hidden" name="loker_id" value="<?= $loker['id']; ?>">

                    <div class="step-box">
                        <div class="step-number">1</div>
                        <div class="step-text">
                            <h4>Pilih Durasi Penyewaan</h4>
                            <p>Tarif dasar penyewaan adalah <?= formatRupiah(DEFAULT_HOURLY_RATE); ?> per jam.</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="durasi">Durasi Waktu Sewa (Jam)</label>
                        <select name="durasi" id="durasi" class="form-control" onchange="updateTotal(this.value)">
                            <option value="1">1 Jam - <?= formatRupiah(DEFAULT_HOURLY_RATE * 1); ?></option>
                            <option value="2">2 Jam - <?= formatRupiah(DEFAULT_HOURLY_RATE * 2); ?></option>
                            <option value="3">3 Jam - <?= formatRupiah(DEFAULT_HOURLY_RATE * 3); ?></option>
                            <option value="6">6 Jam - <?= formatRupiah(DEFAULT_HOURLY_RATE * 6); ?></option>
                            <option value="12">12 Jam - <?= formatRupiah(DEFAULT_HOURLY_RATE * 12); ?></option>
                            <option value="24">24 Jam - <?= formatRupiah(DEFAULT_HOURLY_RATE * 24); ?></option>
                        </select>
                    </div>

                    <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 14px; margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                            <span style="color: var(--text-muted);">Saldo Tersedia Anda:</span>
                            <strong><?= formatRupiah($user['saldo']); ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 1.05rem;">
                            <span>Estimasi Total Tagihan:</span>
                            <strong id="labelTotal" style="color: var(--primary);"><?= formatRupiah(DEFAULT_HOURLY_RATE); ?></strong>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block" style="padding: 12px; font-size: 1rem;">
                        Lanjutkan dan Buat QR Code Akses &rarr;
                    </button>
                </form>
            </div>
        </div>

        <script>
        function updateTotal(hours) {
            const rate = <?= DEFAULT_HOURLY_RATE; ?>;
            const total = hours * rate;
            document.getElementById('labelTotal').innerText = 'Rp ' + total.toLocaleString('id-ID');
        }
        </script>

    <?php elseif ($peminjaman): ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" style="margin-top: 16px;">
                <span>⚠️</span> <div><?= htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <!-- PROSES DUA FAKTOR (2FA): FAKTOR 1 QR CODE & FAKTOR 2 PASSWORD USER -->
        <div class="card" style="margin-top: 20px;">
            <div class="card-header">
                <h2>Prosedur Keamanan 2FA &bull; Loker #<?= $peminjaman['no_loker']; ?></h2>
                <span class="status-badge status-<?= $peminjaman['status']; ?>">
                    <?= str_replace('_', ' ', strtoupper($peminjaman['status'])); ?>
                </span>
            </div>
            <div class="card-body">

                <!-- STEP 1: SCAN QR CODE -->
                <div class="step-box" style="<?= $peminjaman['status'] === 'menunggu_scan' ? 'border-color: var(--primary); background: #eff6ff;' : 'opacity: 0.7;'; ?>">
                    <div class="step-number" style="<?= $peminjaman['status'] !== 'menunggu_scan' ? 'background: #10b981;' : ''; ?>">
                        <?= $peminjaman['status'] !== 'menunggu_scan' ? '✔' : '1'; ?>
                    </div>
                    <div class="step-text">
                        <h4>Faktor 1: Pemindaian QR Code (Kepemilikan Akses)</h4>
                        <p>Arahkan kode QR di bawah ini ke modul kamera ESP32-CAM yang terpasang pada unit loker.</p>
                    </div>
                </div>

                <?php if ($peminjaman['status'] === 'menunggu_scan'): ?>
                    <div class="qr-container">
                        <div class="qr-box" id="qrcode"></div>
                        <div style="margin-top: 12px;">
                            <span style="font-size: 0.85rem; color: var(--text-muted);">Token QR Anda:</span><br>
                            <code style="font-size: 1.05rem; font-weight: bold; color: var(--primary);"><?= htmlspecialchars($qrRecord['qr_token'] ?? ''); ?></code>
                        </div>
                        <div class="qr-timer">
                            Masa berlaku token s/d: <strong><?= date('H:i:s', strtotime($qrRecord['expired'] ?? '+15 min')); ?></strong>
                        </div>

                        <div style="margin-top: 16px; display: flex; gap: 8px; align-items: center; justify-content: center; font-size: 0.85rem; color: #0284c7;">
                            <span class="spinner" style="display:inline-block; animation: spin 1s linear infinite;">⏳</span>
                            <span>Menunggu kamera ESP32-CAM membaca kode QR...</span>
                        </div>

                        <!-- Tombol Bantuan Simulasi Scan Kamera (Sangat bermanfaat untuk Pengujian & Demo tanpa alat) -->
                        <div style="margin-top: 20px; padding: 12px; background: #f8fafc; border-radius: 8px; width: 100%; border: 1px dashed #cbd5e1;">
                            <span style="font-size: 0.78rem; color: var(--text-muted); display: block; margin-bottom: 6px;">Untuk Keperluan Pengujian Penguji/Dosen (Tanpa Hardware Fisik):</span>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="simulateScan('<?= $qrRecord['qr_token'] ?? ''; ?>')">
                                📷 Simulasikan Scan oleh ESP32-CAM
                            </button>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- STEP 2: VERIFIKASI PASSWORD USER (FAKTOR 2 KNOWLEDGE) -->
                <div class="step-box" style="<?= $peminjaman['status'] === 'menunggu_password' ? 'border-color: var(--primary); background: #eff6ff;' : ($peminjaman['status'] === 'menunggu_scan' ? 'opacity: 0.5;' : ''); ?>">
                    <div class="step-number" style="<?= $peminjaman['status'] === 'aktif' ? 'background: #10b981;' : ''; ?>">
                        <?= $peminjaman['status'] === 'aktif' ? '✔' : '2'; ?>
                    </div>
                    <div class="step-text">
                        <h4>Faktor 2: Otentikasi Kata Sandi Pengguna (2FA Knowledge)</h4>
                        <p>Masukkan kata sandi akun Anda untuk otorisasi final pembukaan solenoid door lock.</p>
                    </div>
                </div>

                <?php if ($peminjaman['status'] === 'menunggu_password'): ?>
                    <div style="background: #ffffff; border: 2px solid #93c5fd; border-radius: 12px; padding: 20px; margin-bottom: 20px;">
                        <div class="alert alert-success" style="margin-bottom: 14px;">
                            <span>✅</span> <div><strong>QR Code Berhasil Dipindai!</strong> Silakan lengkapi autentikasi tahap kedua di bawah ini.</div>
                        </div>

                        <form method="POST" action="sewa.php?id=<?= $peminjamanId; ?>">
                            <input type="hidden" name="action" value="verifikasi_2fa">

                            <div class="form-group">
                                <label for="password">Masukkan Kata Sandi Akun Anda (User Password)</label>
                                <input type="password" id="password" name="password" class="form-control" placeholder="Masukkan password Anda" required autofocus>
                                <small style="color: var(--text-muted); font-size: 0.8rem; margin-top: 4px; display: block;">
                                    Sistem memverifikasi kecocokan sandi dengan basis data MySQL sebelum membuka kunci solenoid.
                                </small>
                            </div>

                            <button type="submit" class="btn btn-success btn-block" style="padding: 12px; font-size: 1rem;">
                                🔓 Verifikasi 2FA & Buka Pintu Loker
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <script>
        // Render QR Code pada Canvas menggunakan qrcode.min.js
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

            // Fungsi Simulasi Scan Kamera ESP32-CAM via AJAX
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
