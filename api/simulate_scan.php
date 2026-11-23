<?php
/**
 * REST API: api/simulate_scan.php
 * Endpoint pembantu untuk memicu simulasi pembacaan QR Code langsung dari browser testing
 */
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

$inputRaw = file_get_contents('php://input');
$data = json_decode($inputRaw, true) ?: $_POST;

$qrToken = trim($data['qr_token'] ?? '');
$ldrVal  = isset($data['ldr_val']) ? intval($data['ldr_val']) : 350;

if (empty($qrToken)) {
    jsonResponse(['status' => 'error', 'message' => 'Token kosong'], 400);
}

$db = getDbConnection();

// Log sensor LDR
$cahaya = classifyLdrLux($ldrVal);
$stmtLdr = $db->prepare("INSERT INTO log_sensor_ldr (nilai_adc, estimasi_lux, kategori, waktu) VALUES (?, ?, ?, NOW())");
$stmtLdr->execute([$ldrVal, $cahaya['lux'], $cahaya['kategori']]);

// Cari QR
$stmt = $db->prepare("SELECT * FROM qr_code WHERE qr_token = ?");
$stmt->execute([$qrToken]);
$qr = $stmt->fetch();

if (!$qr) {
    jsonResponse(['status' => 'error', 'message' => 'Token QR tidak ditemukan'], 404);
}

$nextStatus = ($qr['tipe'] === 'sewa') ? 'menunggu_password' : 'menunggu_akhiri_password';
$stmtUpdate = $db->prepare("UPDATE peminjaman SET status = ? WHERE id = ?");
$stmtUpdate->execute([$nextStatus, $qr['id_peminjaman']]);

jsonResponse([
    'status' => 'success',
    'message' => 'Simulasi scan berhasil. Status berubah ke ' . $nextStatus
]);
