<?php
/**
 * Konfigurasi Database dan Utilitas Sistem Smart Locker
 * Sesuai Perancangan Bab 3 Proposal Smart Locker IoT
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Konfigurasi Database MySQL
define('DB_HOST', '192.168.2.2');
define('DB_USER', 'web');
define('DB_PASS', '');
define('DB_NAME', 'smart_locker');
define('DB_PORT', 3306);

// Tarif Sewa Dasar (Rp per jam)
define('DEFAULT_HOURLY_RATE', 5000);

/**
 * Mendapatkan koneksi PDO ke MySQL / MariaDB
 * Otomatis membuat database dan tabel jika belum ada di server
 * @return PDO
 */
function getDbConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ];

        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Jika database belum ada, coba buat database smart_locker secara otomatis
            try {
                $dsnServer = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
                $tempPdo = new PDO($dsnServer, DB_USER, DB_PASS, $options);
                $tempPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e2) {
                die("Koneksi Database Gagal: " . $e->getMessage() . "<br>Pastikan MariaDB aktif dan user '" . DB_USER . "' diizinkan mengakses dari container.");
            }
        }

        // Cek dan buat otomatis tabel jika belum ada
        initDatabaseIfNeeded($pdo);
    }
    return $pdo;
}

/**
 * Otomatis membuat tabel dan data awal dari database.sql jika tabel belum ada
 */
function initDatabaseIfNeeded($pdo) {
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'users'");
        if ($check->rowCount() === 0) {
            $sqlFile = __DIR__ . '/../database.sql';
            if (file_exists($sqlFile)) {
                $sqlContent = file_get_contents($sqlFile);
                try {
                    $pdo->exec($sqlContent);
                } catch (Exception $ex) {
                    // Jika multi-statement gagal, jalankan baris per baris
                    $queries = explode(';', $sqlContent);
                    foreach ($queries as $q) {
                        $q = trim($q);
                        if (!empty($q)) {
                            try { $pdo->exec($q); } catch (Exception $ignored) {}
                        }
                    }
                }
            }
        }

        // Pastikan kolom last_seen ada di tabel loker (Heartbeat status ESP32)
        try {
            $colCheck = $pdo->query("SHOW COLUMNS FROM loker LIKE 'last_seen'");
            if ($colCheck->rowCount() === 0) {
                $pdo->exec("ALTER TABLE loker ADD COLUMN last_seen DATETIME NULL DEFAULT NULL");
            }
        } catch (Exception $e) {}
    } catch (Exception $e) {
        // Abaikan jika ada permission error non-fatal
    }
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
 * Menghitung tarif loker berbasis waktu per jam (sistem tarif seperti parkir)
 * Dihitung setelah check-in, jam pertama Rp 5.000, setiap kelebihan jam bertambah Rp 5.000
 */
function calculateParkingTariff($rentStart, $rentEnd = null) {
    if (empty($rentStart)) {
        return [
            'seconds' => 0,
            'hours' => 1,
            'fee' => DEFAULT_HOURLY_RATE,
            'duration_text' => '0 Menit'
        ];
    }

    $startTime = strtotime($rentStart);
    $endTime = $rentEnd ? strtotime($rentEnd) : time();
    $diffSeconds = max(0, $endTime - $startTime);

    // Sistem parkir: Minimal 1 jam, setiap masuk jam berikutnya dihitung +1 jam
    $hours = max(1, (int)ceil($diffSeconds / 3600.0));
    $fee = $hours * DEFAULT_HOURLY_RATE;

    $h = floor($diffSeconds / 3600);
    $m = floor(($diffSeconds % 3600) / 60);
    $s = $diffSeconds % 60;

    if ($h > 0) {
        $durationText = "{$h} Jam {$m} Menit";
    } elseif ($m > 0) {
        $durationText = "{$m} Menit {$s} Detik";
    } else {
        $durationText = "{$s} Detik";
    }

    return [
        'seconds' => $diffSeconds,
        'hours' => $hours,
        'fee' => $fee,
        'duration_text' => $durationText
    ];
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
