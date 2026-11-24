<?php
require_once __DIR__ . '/config/database.php';
requireLogin();

$user = getLoggedInUser();
$db = getDbConnection();

// Ambil daftar unit loker
$stmtLoker = $db->query("SELECT * FROM loker ORDER BY no_loker ASC");
$lockers = $stmtLoker->fetchAll();

// Cek apakah user sedang memiliki sesi peminjaman aktif atau menunggu
$stmtActiveRent = $db->prepare("
    SELECT p.*, l.no_loker 
    FROM peminjaman p 
    JOIN loker l ON p.id_loker = l.id 
    WHERE p.id_user = ? AND p.status IN ('menunggu_scan', 'menunggu_password', 'aktif', 'menunggu_akhiri_scan', 'menunggu_akhiri_password')
    ORDER BY p.id DESC LIMIT 1
");
$stmtActiveRent->execute([$user['id']]);
$activeRental = $stmtActiveRent->fetch();

// Hitung tarif parkir berjalan jika sesi sedang aktif
$liveTariff = null;
if ($activeRental && $activeRental['status'] === 'aktif' && !empty($activeRental['rent_start'])) {
    $liveTariff = calculateParkingTariff($activeRental['rent_start']);
}

// Ambil riwayat peminjaman user
$stmtHistory = $db->prepare("
    SELECT p.*, l.no_loker 
    FROM peminjaman p 
    JOIN loker l ON p.id_loker = l.id 
    WHERE p.id_user = ? 
    ORDER BY p.id DESC LIMIT 10
");
$stmtHistory->execute([$user['id']]);
$history = $stmtHistory->fetchAll();

// Ambil log akses 2FA terbaru untuk user ini
$stmtAkses = $db->prepare("
    SELECT a.*, l.no_loker, q.qr_token 
    FROM akses a 
    JOIN peminjaman p ON a.id_peminjaman = p.id 
    JOIN loker l ON p.id_loker = l.id 
    LEFT JOIN qr_code q ON a.id_qr = q.id 
    WHERE p.id_user = ? 
    ORDER BY a.id DESC LIMIT 8
");
$stmtAkses->execute([$user['id']]);
$accessLogs = $stmtAkses->fetchAll();

$msgSuccess = $_SESSION['flash_success'] ?? '';
$msgError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Smart Locker IoT</title>
    <link rel="stylesheet" href="assets/css/style.css">
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
        <a href="topup.php" class="btn btn-sm btn-success">+ Top Up</a>
        <a href="hardware_sim.php" class="btn btn-sm btn-secondary" title="Simulasi Hardware ESP32 & ESP32-CAM">📟 Simulator</a>
        <a href="logout.php" class="btn btn-sm btn-danger">Keluar</a>
    </div>
</header>

<main class="container">

    <?php if ($msgSuccess): ?>
        <div class="alert alert-success">
            <span>✅</span> <div><?= htmlspecialchars($msgSuccess); ?></div>
        </div>
    <?php endif; ?>

    <?php if ($msgError): ?>
        <div class="alert alert-danger">
            <span>⚠️</span> <div><?= htmlspecialchars($msgError); ?></div>
        </div>
    <?php endif; ?>

    <!-- Status Sesi Check-In Berjalan -->
    <?php if ($activeRental): ?>
        <div class="card" style="border-left: 6px solid var(--primary); margin-bottom: 24px;">
            <div class="card-header" style="background: var(--primary-light);">
                <div>
                    <span class="status-badge status-aktif">Sesi Check-In Aktif</span>
                    <h2 style="margin-top: 6px;">Unit Loker #<?= $activeRental['no_loker']; ?></h2>
                </div>
                <div>
                    <?php if ($activeRental['status'] === 'aktif'): ?>
                        <a href="akhiri_sewa.php?id=<?= $activeRental['id']; ?>" class="btn btn-danger">
                            Check-Out / Ambil Barang
                        </a>
                    <?php else: ?>
                        <a href="sewa.php?id=<?= $activeRental['id']; ?>" class="btn btn-primary">
                            Lanjutkan Proses Check-In (2FA)
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div style="display: flex; flex-wrap: wrap; gap: 24px; font-size: 0.9rem;">
                    <div>
                        <strong style="color: var(--text-muted); display: block;">Waktu Check-In:</strong>
                        <span><?= $activeRental['rent_start'] ? date('d M Y, H:i:s', strtotime($activeRental['rent_start'])) : 'Menunggu Verifikasi Check-In'; ?></span>
                    </div>
                    <div>
                        <strong style="color: var(--text-muted); display: block;">Durasi Berjalan:</strong>
                        <span><?= $liveTariff ? $liveTariff['duration_text'] . ' <small>(Jam ke-' . $liveTariff['hours'] . ')</small>' : 'Menunggu Check-In'; ?></span>
                    </div>
                    <div>
                        <strong style="color: var(--text-muted); display: block;">Tarif Berjalan:</strong>
                        <span style="font-weight: 700; color: var(--primary);">
                            <?= $liveTariff ? formatRupiah($liveTariff['fee']) : formatRupiah(DEFAULT_HOURLY_RATE); ?>
                        </span>
                    </div>
                    <div>
                        <strong style="color: var(--text-muted); display: block;">Status Operasional:</strong>
                        <span class="status-badge status-<?= $activeRental['status']; ?>"><?= str_replace('_', ' ', strtoupper($activeRental['status'])); ?></span>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Bagian Ketersediaan Loker Fisik -->
    <section style="margin-bottom: 30px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <div>
                <h2>Ketersediaan Unit Loker</h2>
                <p style="color: var(--text-muted); font-size: 0.85rem;">Status real-time pintu loker yang terhubung dengan mikrokontroler ESP32</p>
            </div>
            <a href="dashboard.php" class="btn btn-sm btn-secondary">🔄 Refresh Status</a>
        </div>

        <div class="grid-3">
            <?php foreach ($lockers as $l): ?>
                <?php 
                    // ESP32 dianggap online jika ada aktivitas polling/heartbeat dalam 10 detik terakhir
                    $isEspOnline = (!empty($l['last_seen']) && (time() - strtotime($l['last_seen'])) <= 10);
                    $isOccupied = ($l['status'] === 'terpakai');
                    $isMaintenance = ($l['status'] === 'maintenance');

                    if (!$isEspOnline && !$isOccupied) {
                        $displayStatus = 'Tidak Tersedia';
                        $badgeClass = 'status-tidak-tersedia';
                        $cardClass = 'offline';
                    } else {
                        $displayStatus = ucfirst($l['status']);
                        $badgeClass = 'status-' . $l['status'];
                        $cardClass = $isOccupied ? 'occupied' : ($isMaintenance ? 'maintenance' : 'available');
                    }
                ?>
                <div class="locker-card <?= $cardClass; ?>">
                    <span class="status-badge <?= $badgeClass; ?>"><?= $displayStatus; ?></span>
                    <div class="locker-number">L-0<?= $l['no_loker']; ?></div>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 14px;">
                        Tarif: <?= formatRupiah(DEFAULT_HOURLY_RATE); ?> / Jam (Sistem Parkir)
                        <?php if (!$isEspOnline && !$isOccupied): ?>
                            <br><small style="color: #64748b; font-size: 0.75rem;">(ESP32 Belum Konek)</small>
                        <?php elseif ($isEspOnline): ?>
                            <br><small style="color: #10b981; font-size: 0.75rem; font-weight: 600;">● ESP32 Terkoneksi</small>
                        <?php endif; ?>
                    </p>

                    <?php if (!$isEspOnline && !$isOccupied): ?>
                        <button class="btn btn-sm btn-secondary btn-block" disabled title="Loker tidak tersedia karena ESP32 belum terkoneksi">Tidak Tersedia</button>
                    <?php elseif ($l['status'] === 'tersedia'): ?>
                        <?php if ($activeRental): ?>
                            <button class="btn btn-sm btn-secondary btn-block" disabled title="Selesaikan sesi aktif Anda terlebih dahulu">Sedang Digunakan</button>
                        <?php else: ?>
                            <a href="sewa.php?loker_id=<?= $l['id']; ?>" class="btn btn-sm btn-primary btn-block">Check-In Loker Ini</a>
                        <?php endif; ?>
                    <?php elseif ($l['status'] === 'terpakai'): ?>
                        <span style="font-size: 0.8rem; font-weight: 600; color: #dc2626;">🔒 Terkunci (Sedang Digunakan)</span>
                    <?php else: ?>
                        <span style="font-size: 0.8rem; font-weight: 600; color: #d97706;">🛠️ Dalam Perawatan</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Grid Riwayat Peminjaman & Log Akses 2FA -->
    <div class="grid-2">
        <!-- Riwayat Peminjaman -->
        <div class="card">
            <div class="card-header">
                <h3>Riwayat Peminjaman Terakhir</h3>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Loker</th>
                                <th>Waktu Mulai</th>
                                <th>Durasi</th>
                                <th>Total</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($history)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 24px;">Belum ada riwayat transaksi peminjaman.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($history as $h): ?>
                                    <tr>
                                        <td><strong>L-0<?= $h['no_loker']; ?></strong></td>
                                        <td><?= $h['rent_start'] ? date('d/m/y H:i', strtotime($h['rent_start'])) : '-'; ?></td>
                                        <td><?= $h['rent_durasi']; ?> Jam</td>
                                        <td><?= formatRupiah($h['rent_price']); ?></td>
                                        <td><span class="status-badge status-<?= $h['status']; ?>"><?= ucfirst($h['status']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Log Akses Autentikasi 2FA (Sesuai Bab 3.4.2 & Bab 3.5) -->
        <div class="card">
            <div class="card-header">
                <h3>Log Validasi Keamanan 2FA (Akses)</h3>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>QR Token</th>
                                <th>QR Valid</th>
                                <th>Pass Valid</th>
                                <th>Hasil</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($accessLogs)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 24px;">Belum ada log autentikasi 2FA.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($accessLogs as $al): ?>
                                    <tr>
                                        <td style="font-size: 0.8rem;"><?= date('H:i:s d/m', strtotime($al['waktu'])); ?></td>
                                        <td><code><?= substr($al['qr_token'] ?? '-', 0, 8); ?>...</code></td>
                                        <td>
                                            <?= $al['qr_valid'] ? '<span style="color: green; font-weight: bold;">✔ Valid</span>' : '<span style="color: red;">✖ Gagal</span>'; ?>
                                        </td>
                                        <td>
                                            <?= $al['password_valid'] ? '<span style="color: green; font-weight: bold;">✔ Cocok</span>' : '<span style="color: red;">✖ Salah</span>'; ?>
                                        </td>
                                        <td>
                                            <span style="font-size: 0.78rem; font-weight: 600; color: <?= strpos($al['hasil'], 'berhasil') !== false ? 'green' : 'red'; ?>;">
                                                <?= htmlspecialchars($al['hasil']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</main>

<footer class="footer">
    <p>Implementasi Sistem Smart Locker Berbasis IoT dengan QR Code dan 2FA &bull; Universitas Pendidikan Indonesia 2026</p>
</footer>

</body>
</html>
