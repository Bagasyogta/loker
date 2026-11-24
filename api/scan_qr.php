<?php
/**
 * REST API: api/scan_qr.php
 * Dipanggil oleh Mikrokontroler ESP32 ketika menerima payload QR Code dari ESP32-CAM
 * Parameter JSON: { "qr_token": "...", "ldr_val": 350 }
 */
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Hanya menerima request POST!'], 405);
}

// Baca raw body input JSON atau form POST
$inputRaw = file_get_contents('php://input');
$data = json_decode($inputRaw, true);

if (!$data) {
    $data = $_POST;
}

$qrToken = trim($data['qr_token'] ?? '');
$ldrVal  = isset($data['ldr_val']) ? intval($data['ldr_val']) : null;

if (empty($qrToken)) {
    jsonResponse(['status' => 'error', 'message' => 'Token QR tidak boleh kosong!'], 400);
}

$db = getDbConnection();

// Perbarui heartbeat bahwa ESP32 sedang aktif
$db->exec("UPDATE loker SET last_seen = NOW()");

// Jika ada nilai LDR yang dikirim bersamaan, catat ke log_sensor_ldr (Bab 3.5 Pengujian Pencahayaan)
if ($ldrVal !== null) {
    $cahaya = classifyLdrLux($ldrVal);
    $stmtLdr = $db->prepare("INSERT INTO log_sensor_ldr (nilai_adc, estimasi_lux, kategori, waktu) VALUES (?, ?, ?, NOW())");
    $stmtLdr->execute([$ldrVal, $cahaya['lux'], $cahaya['kategori']]);
}

// Cari token di database
$stmt = $db->prepare("
    SELECT q.*, p.id_user, p.id_loker, p.status as status_peminjaman, l.no_loker 
    FROM qr_code q 
    JOIN peminjaman p ON q.id_peminjaman = p.id 
    JOIN loker l ON p.id_loker = l.id 
    WHERE q.qr_token = ?
    LIMIT 1
");
$stmt->execute([$qrToken]);
$qr = $stmt->fetch();

if (!$qr) {
    // Token tidak ditemukan sama sekali
    jsonResponse([
        'status' => 'error',
        'message' => 'QR Code Tidak Dikenali!',
        'action' => 'reject',
        'lcd_line1' => 'QR Tidak Valid!',
        'lcd_line2' => 'Akses Ditolak'
    ], 404);
}

// Cek apakah token sudah digunakan atau kadaluarsa
$currentTime = date('Y-m-d H:i:s');
if ($qr['status'] !== 'aktif' || $qr['expired'] < $currentTime) {
    // Catat log gagal ke tabel AKSES
    $stmtLog = $db->prepare("
        INSERT INTO akses (id_peminjaman, id_qr, qr_valid, password_valid, hasil, waktu) 
        VALUES (?, ?, 0, 0, 'gagal_qr_expired', NOW())
    ");
    $stmtLog->execute([$qr['id_peminjaman'], $qr['id']]);

    jsonResponse([
        'status' => 'error',
        'message' => 'QR Code Sudah Kadaluarsa atau Telah Digunakan!',
        'action' => 'reject',
        'lcd_line1' => 'QR Kadaluarsa!',
        'lcd_line2' => 'Silakan Refresh'
    ], 400);
}

// QR Code Dinyatakan VALID (Faktor 1 Lolos)
// Update status peminjaman ke tahap berikutnya: 'menunggu_password' atau 'menunggu_akhiri_password'
if ($qr['tipe'] === 'sewa') {
    $nextStatus = 'menunggu_password';
} else {
    $nextStatus = 'menunggu_akhiri_password';
}

$stmtUpdateRent = $db->prepare("UPDATE peminjaman SET status = ? WHERE id = ?");
$stmtUpdateRent->execute([$nextStatus, $qr['id_peminjaman']]);

// Catat log sukses tahap 1 (QR Valid) di tabel AKSES
$stmtLog1 = $db->prepare("
    INSERT INTO akses (id_peminjaman, id_qr, qr_valid, password_valid, hasil, waktu) 
    VALUES (?, ?, 1, 0, 'qr_valid_menunggu_password', NOW())
");
$stmtLog1->execute([$qr['id_peminjaman'], $qr['id']]);

jsonResponse([
    'status' => 'success',
    'message' => 'QR Code Terverifikasi! Lanjutkan masukkan kata sandi pada website.',
    'action' => 'wait_password',
    'peminjaman_id' => $qr['id_peminjaman'],
    'locker_no' => $qr['no_loker'],
    'tipe' => $qr['tipe'],
    'lcd_line1' => 'QR Valid: L-' . $qr['no_loker'],
    'lcd_line2' => 'Input Password Web'
]);
