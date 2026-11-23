<?php
/**
 * REST API: api/check_status.php
 * Digunakan oleh:
 * 1. Web browser: mode=user_polling&peminjaman_id=X (Cek status sewa real-time)
 * 2. Mikrokontroler ESP32: mode=esp32 (Polling instruksi buka solenoid lock)
 */
require_once __DIR__ . '/../config/database.php';

$mode = $_GET['mode'] ?? 'esp32';
$db = getDbConnection();

if ($mode === 'user_polling') {
    $peminjamanId = intval($_GET['peminjaman_id'] ?? 0);
    $stmt = $db->prepare("SELECT id, status, door_unlock_trigger FROM peminjaman WHERE id = ?");
    $stmt->execute([$peminjamanId]);
    $rent = $stmt->fetch();

    if (!$rent) {
        jsonResponse(['status' => 'not_found'], 404);
    }

    jsonResponse([
        'status' => $rent['status'],
        'door_unlock_trigger' => (int)$rent['door_unlock_trigger']
    ]);
}

// Mode Mikrokontroler ESP32 Utama
if ($mode === 'esp32') {
    // Cari apakah ada peminjaman dengan trigger buka solenoid aktif
    $stmt = $db->query("
        SELECT p.id, p.id_loker, p.status, l.no_loker 
        FROM peminjaman p 
        JOIN loker l ON p.id_loker = l.id 
        WHERE p.door_unlock_trigger = 1 
        ORDER BY p.id DESC LIMIT 1
    ");
    $trigger = $stmt->fetch();

    if ($trigger) {
        // Reset trigger agar solenoid tidak terbuka berulang
        $stmtReset = $db->prepare("UPDATE peminjaman SET door_unlock_trigger = 0 WHERE id = ?");
        $stmtReset->execute([$trigger['id']]);

        jsonResponse([
            'status' => 'success',
            'command' => 'unlock',
            'locker_no' => (int)$trigger['no_loker'],
            'duration_sec' => 5, // Durasi solenoid terbuka
            'lcd_line1' => 'Akses Diterima!',
            'lcd_line2' => 'Loker ' . $trigger['no_loker'] . ' Terbuka'
        ]);
    } else {
        // Tidak ada perintah pembukaan pintu, status standby
        jsonResponse([
            'status' => 'idle',
            'command' => 'none',
            'lcd_line1' => 'Smart Locker IoT',
            'lcd_line2' => 'Siap Digunakan'
        ]);
    }
}

jsonResponse(['status' => 'invalid_mode'], 400);
