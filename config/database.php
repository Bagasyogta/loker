<?php
/**
 * Konfigurasi Database dan Utilitas Sistem Smart Locker
 * Sesuai Perancangan Bab 3 Proposal Smart Locker IoT
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Konfigurasi Database MySQL (Mendukung Environment Variable Docker / Fallback Manual)
define('DB_HOST', getenv('DB_HOST') ?: '192.168.2.2');
define('DB_USER', getenv('DB_USER') ?: 'web');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_NAME', getenv('DB_NAME') ?: 'smart_locker');
define('DB_PORT', getenv('DB_PORT') ? (int)getenv('DB_PORT') : 3306);

// Tarif Sewa Dasar (Rp per jam)
define('DEFAULT_HOURLY_RATE', 5000);

/**
 * Mendapatkan koneksi PDO ke MySQL
 * @return PDO
 */
function getDbConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("Koneksi Database Gagal: " . $e->getMessage() . "<br>Pastikan MySQL aktif dan database 'smart_locker' sudah diimport.");
        }
    }
    return $pdo;
}

/**
 * Cek apakah user telah login
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Dapatkan data user yang sedang login
 * @return array|null
 */
function getLoggedInUser() {
    if (!isLoggedIn()) return null;
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT id, username, email, telepon, saldo, dibuat FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

/**
 * Memastikan halaman hanya bisa diakses oleh user yang login
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
}

/**
 * Format mata uang Rupiah
 */
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

/**
 * Response JSON standar untuk API
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/**
 * Menghitung estimasi intensitas cahaya (Lux) dari nilai ADC sensor LDR
 * Berdasarkan Tabel 2.1 Kategori Intensitas Cahaya (Bab 2 & Bab 3)
 */
function classifyLdrLux($adcValue) {
    // ESP32 ADC: 0 - 4095 (12-bit)
    // Sensor LDR pull-up module: nilai analog makin kecil = makin terang (atau sebaliknya tergantung wiring)
    // Estimasi regresi linier / logaritmik sederhana untuk simulasi pengujian
    $lux = max(1.0, round((4095 - $adcValue) / 4.0, 2));

    if ($lux < 15) {
        $kategori = 'Gelap';
    } elseif ($lux <= 100) {
        $kategori = 'Redup';
    } elseif ($lux <= 300) {
        $kategori = 'Standar';
    } elseif ($lux <= 750) {
        $kategori = 'Terang';
    } else {
        $kategori = 'Sangat Terang';
    }

    return [
        'lux' => $lux,
        'kategori' => $kategori
    ];
}
