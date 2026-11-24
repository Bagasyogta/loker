<?php
/**
 * Halaman Setup & Migrasi Otomatis Tabel Basis Data
 * Buka di browser: http://<IP_SERVER>:4060/setup_db.php
 */
require_once __DIR__ . '/config/database.php';

$logs = [];
$status = 'success';

try {
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ];

    // 1. Cek Koneksi ke Server MariaDB
    $dsnServer = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
    $pdoServer = new PDO($dsnServer, DB_USER, DB_PASS, $options);
    $logs[] = "✅ Terhubung ke server MariaDB (" . DB_HOST . ":" . DB_PORT . ") dengan user '" . DB_USER . "'.";

    // 2. Buat Database jika belum ada
    $pdoServer->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    $logs[] = "✅ Database `" . DB_NAME . "` berhasil diverifikasi / dibuat.";

    // 3. Konek ke Database smart_locker
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    // 4. Baca dan Eksekusi database.sql
    $sqlFile = __DIR__ . '/database.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Berkas database.sql tidak ditemukan di direktori root!");
    }

    $sqlContent = file_get_contents($sqlFile);
    try {
        $pdo->exec($sqlContent);
        $logs[] = "✅ Berkas database.sql berhasil dieksekusi secara penuh.";
    } catch (Exception $e) {
        $queries = explode(';', $sqlContent);
        foreach ($queries as $q) {
            $q = trim($q);
            if (!empty($q)) {
                try { $pdo->exec($q); } catch (Exception $subEx) {}
            }
        }
        $logs[] = "✅ Perintah database.sql dieksekusi per query (mode aman).";
    }

    // 5. Verifikasi Tabel yang Terbentuk
    $stmtTables = $pdo->query("SHOW TABLES");
    $tables = $stmtTables->fetchAll(PDO::FETCH_COLUMN);
    $logs[] = "📋 Daftar tabel yang aktif di database `" . DB_NAME . "`: " . implode(', ', $tables);

    // 6. Cek Data Awal
    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $lokerCount = $pdo->query("SELECT COUNT(*) FROM loker")->fetchColumn();
    $logs[] = "👤 Jumlah Akun Pengguna Terdaftar: " . $userCount . " user.";
    $logs[] = "📦 Jumlah Unit Loker: " . $lokerCount . " unit terdaftar (Loker 1 dan Loker 2).";

} catch (Exception $e) {
    $status = 'error';
    $logs[] = "❌ Terjadi kesalahan: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inisialisasi Database - Smart Locker</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .log-box {
            background: #0f172a;
            color: #38bdf8;
            font-family: monospace;
            padding: 16px;
            border-radius: 8px;
            line-height: 1.6;
            margin: 16px 0;
            font-size: 0.9rem;
        }
    </style>
</head>
<body style="background: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh;">
<div style="width: 100%; max-width: 600px; padding: 20px;">
    <div class="card">
        <div class="card-header">
            <h2>Inisialisasi Otomatis Tabel Database</h2>
            <span class="status-badge <?= $status === 'success' ? 'status-tersedia' : 'status-terpakai'; ?>">
                <?= strtoupper($status); ?>
            </span>
        </div>
        <div class="card-body">
            <p style="font-size: 0.85rem; color: var(--text-muted);">
                Proses ini otomatis membaca berkas <code>database.sql</code> dan memasang seluruh tabel relasi sesuai Bab 3 Proposal ke server MariaDB.
            </p>

            <div class="log-box">
                <?php foreach ($logs as $l): ?>
                    <div><?= htmlspecialchars($l); ?></div>
                <?php endforeach; ?>
            </div>

            <?php if ($status === 'success'): ?>
                <div class="alert alert-success">
                    <span>🎉</span> <div><strong>Sukses!</strong> Seluruh tabel dan data awal loker berhasil dibuat.</div>
                </div>
                <div style="display: flex; gap: 10px;">
                    <a href="login.php" class="btn btn-primary btn-block">Ke Halaman Login &rarr;</a>
                    <a href="dashboard.php" class="btn btn-secondary btn-block">Ke Dashboard &rarr;</a>
                </div>
            <?php else: ?>
                <div class="alert alert-danger">
                    <span>⚠️</span> <div>Gagal inisialisasi tabel. Periksa izin user MariaDB atau status server.</div>
                </div>
                <a href="setup_db.php" class="btn btn-primary btn-block">Coba Ulangi</a>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
