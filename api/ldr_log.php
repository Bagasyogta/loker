<?php
/**
 * REST API: api/ldr_log.php
 * Endpoint untuk mencatat hasil pembacaan sensor LDR dari ESP32
 * Digunakan untuk pengujian intensitas cahaya (Lux) sesuai Bab 3.5
 */
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

$inputRaw = file_get_contents('php://input');
$data = json_decode($inputRaw, true) ?: $_POST;

$adc = isset($data['ldr_val']) ? intval($data['ldr_val']) : null;

if ($adc === null) {
    jsonResponse(['status' => 'error', 'message' => 'Parameter ldr_val wajib diisi!'], 400);
}

$cahaya = classifyLdrLux($adc);

$db = getDbConnection();
$db->exec("UPDATE loker SET last_seen = NOW()");
$stmt = $db->prepare("INSERT INTO log_sensor_ldr (nilai_adc, estimasi_lux, kategori, waktu) VALUES (?, ?, ?, NOW())");
$stmt->execute([$adc, $cahaya['lux'], $cahaya['kategori']]);

jsonResponse([
    'status' => 'success',
    'adc' => $adc,
    'lux' => $cahaya['lux'],
    'kategori' => $cahaya['kategori'],
    'timestamp' => date('Y-m-d H:i:s')
]);
