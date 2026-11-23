<?php
/**
 * Simulator Hardware Smart Locker (ESP32 & ESP32-CAM)
 * Berguna untuk mendemonstrasikan integrasi perangkat keras, LCD 16x2, Solenoid Lock, dan Sensor LDR
 * secara visual tanpa memerlukan perangkat fisik setiap saat.
 */
require_once __DIR__ . '/config/database.php';
requireLogin();

$user = getLoggedInUser();
$db = getDbConnection();

// Ambil token-token QR aktif
$stmtTokens = $db->query("
    SELECT q.*, p.id_user, u.username, l.no_loker 
    FROM qr_code q
    JOIN peminjaman p ON q.id_peminjaman = p.id
    JOIN users u ON p.id_user = u.id
    JOIN loker l ON p.id_loker = l.id
    WHERE q.status = 'aktif'
    ORDER BY q.id DESC LIMIT 10
");
$activeTokens = $stmtTokens->fetchAll();

// Ambil data log LDR terakhir
$stmtLdr = $db->query("SELECT * FROM log_sensor_ldr ORDER BY id DESC LIMIT 5");
$ldrLogs = $stmtLdr->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hardware Simulator - Smart Locker IoT</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .lcd-display {
            background-color: #0284c7;
            border: 8px solid #334155;
            border-radius: 8px;
            padding: 14px 18px;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.5);
            font-family: 'Courier New', Courier, monospace;
            color: #ffffff;
            font-size: 1.3rem;
            letter-spacing: 2px;
            font-weight: bold;
            text-shadow: 0 0 4px rgba(255,255,255,0.8);
            margin-bottom: 20px;
        }
        .lcd-line {
            min-height: 28px;
            white-space: pre;
        }
        .solenoid-indicator {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 1.1rem;
            transition: all 0.3s;
        }
        .solenoid-locked {
            background: #fee2e2;
            color: #991b1b;
            border: 2px solid #ef4444;
        }
        .solenoid-unlocked {
            background: #dcfce7;
            color: #166534;
            border: 2px solid #10b981;
            box-shadow: 0 0 15px rgba(16, 185, 129, 0.4);
        }
        .console-log {
            background: #0f172a;
            color: #38bdf8;
            font-family: monospace;
            font-size: 0.8rem;
            padding: 14px;
            border-radius: 8px;
            height: 180px;
            overflow-y: auto;
            border: 1px solid #1e293b;
        }
    </style>
</head>
<body>

<header class="navbar">
    <a href="dashboard.php" class="navbar-brand">
        <span class="brand-badge">Simulator</span>
        <span>Hardware Smart Locker (ESP32 + CAM)</span>
    </a>
    <div class="navbar-user">
        <a href="dashboard.php" class="btn btn-sm btn-secondary">&larr; Kembali ke Dashboard</a>
    </div>
</header>

<main class="container">
    <div class="grid-2">

        <!-- Panel Perangkat Keras Interaktif -->
        <div class="card">
            <div class="card-header">
                <h3>Tampilan Fisik Unit Loker (LCD & Solenoid)</h3>
                <span class="status-badge status-aktif">ESP32 Online</span>
            </div>
            <div class="card-body">
                
                <!-- Display LCD 16x2 I2C -->
                <label style="font-size: 0.8rem; font-weight: bold; color: var(--text-muted); display: block; margin-bottom: 6px;">
                    LCD I2C 16x2 Display:
                </label>
                <div class="lcd-display">
                    <div class="lcd-line" id="lcdLine1">Smart Locker IoT</div>
                    <div class="lcd-line" id="lcdLine2">Siap Digunakan</div>
                </div>

                <!-- Solenoid Door Lock -->
                <div style="margin-bottom: 24px; text-align: center;">
                    <label style="font-size: 0.8rem; font-weight: bold; color: var(--text-muted); display: block; margin-bottom: 8px;">
                        Status Aktuator Solenoid Door Lock (Relay):
                    </label>
                    <div id="solenoidBox" class="solenoid-indicator solenoid-locked">
                        <span id="solenoidIcon">🔒</span>
                        <span id="solenoidText">PINTU TERKUNCI (RELAY OFF)</span>
                    </div>
                </div>

                <!-- Sensor LDR Simulator -->
                <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 14px; margin-bottom: 20px;">
                    <label style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 6px;">
                        Pengujian Sensor LDR (ADC & Estimasi Lux):
                    </label>
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
                        <input type="range" id="ldrSlider" min="0" max="4095" value="1200" style="flex: 1;" oninput="updateLdrPreview(this.value)">
                        <span id="ldrAdcValue" style="font-weight: bold; width: 60px;">1200</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.85rem;">
                        <span>Estimasi: <strong id="ldrLuxValue">723 Lux</strong></span>
                        <span>Kategori: <strong id="ldrCategory" style="color: var(--primary);">Terang</strong></span>
                    </div>
                    <button type="button" class="btn btn-sm btn-secondary" style="margin-top: 10px;" onclick="sendLdrData()">
                        📤 Kirim Log Sensor LDR ke Database
                    </button>
                </div>

            </div>
        </div>

        <!-- Panel ESP32-CAM Serial & Polling Monitor -->
        <div class="card">
            <div class="card-header">
                <h3>ESP32-CAM Serial Scanner & Network</h3>
            </div>
            <div class="card-body">

                <!-- Trigger Scan QR Token -->
                <div class="form-group">
                    <label for="tokenInput">Simulasi Pembacaan Kamera ESP32-CAM (Kirim via UART TX/RX):</label>
                    <div style="display: flex; gap: 8px;">
                        <input type="text" id="tokenInput" class="form-control" placeholder="Pilih atau masukkan token QR...">
                        <button type="button" class="btn btn-primary" onclick="triggerScan()">Kirim RX</button>
                    </div>
                </div>

                <!-- Daftar Token QR yang Sedang Aktif di Database -->
                <div style="margin-bottom: 16px;">
                    <label style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); display: block; margin-bottom: 6px;">
                        Token QR Aktif yang Siap Discan:
                    </label>
                    <?php if (empty($activeTokens)): ?>
                        <p style="font-size: 0.8rem; color: var(--text-muted);">Tidak ada token QR aktif. Silakan lakukan sewa atau akhiri sewa di dashboard.</p>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 6px; max-height: 120px; overflow-y: auto;">
                            <?php foreach ($activeTokens as $at): ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; background: #f1f5f9; padding: 6px 10px; border-radius: 6px; font-size: 0.8rem;">
                                    <span>L-0<?= $at['no_loker']; ?> (<?= $at['tipe']; ?>) - <code><?= $at['qr_token']; ?></code></span>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="pickToken('<?= $at['qr_token']; ?>')">Pilih</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Console Monitor -->
                <label style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); display: block; margin-bottom: 6px;">
                    Monitor Serial UART (RX/TX) & HTTP Log:
                </label>
                <div class="console-log" id="consoleLog">
                    <div>[BOOT] ESP32 Ready. Serial2 (RX2/TX2) listening to ESP32-CAM...</div>
                    <div>[NETWORK] Connected to WiFi. HTTP REST client initialized.</div>
                </div>

            </div>
        </div>

    </div>
</main>

<script>
function log(msg) {
    const c = document.getElementById('consoleLog');
    const div = document.createElement('div');
    const time = new Date().toLocaleTimeString();
    div.innerText = `[${time}] ${msg}`;
    c.appendChild(div);
    c.scrollTop = c.scrollHeight;
}

function updateLdrPreview(val) {
    document.getElementById('ldrAdcValue').innerText = val;
    const lux = Math.max(1, Math.round((4095 - val) / 4.0));
    document.getElementById('ldrLuxValue').innerText = lux + ' Lux';

    let cat = 'Gelap';
    if (lux >= 15 && lux <= 100) cat = 'Redup';
    else if (lux <= 300) cat = 'Standar';
    else if (lux <= 750) cat = 'Terang';
    else if (lux > 750) cat = 'Sangat Terang';

    document.getElementById('ldrCategory').innerText = cat;
}

function sendLdrData() {
    const adc = document.getElementById('ldrSlider').value;
    log(`[HTTP POST] Mengirim data LDR ADC: ${adc} ke /api/ldr_log.php`);
    fetch('api/ldr_log.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ldr_val: parseInt(adc) })
    })
    .then(r => r.json())
    .then(d => {
        log(`[HTTP RESPONSE] LDR tercatat: ${d.lux} Lux (${d.kategori})`);
    });
}

function pickToken(token) {
    document.getElementById('tokenInput').value = token;
}

function triggerScan() {
    const token = document.getElementById('tokenInput').value.trim();
    if (!token) {
        alert('Masukkan atau pilih token QR terlebih dahulu!');
        return;
    }
    const adc = document.getElementById('ldrSlider').value;

    log(`[ESP32-CAM -> UART TX] Mengirim token via serial: "${token}"`);
    log(`[ESP32 UART RX] Diterima dari ESP32-CAM: "${token}"`);
    document.getElementById('lcdLine1').innerText = "QR Terdeteksi!";
    document.getElementById('lcdLine2').innerText = "Memvalidasi...";

    fetch('api/scan_qr.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ qr_token: token, ldr_val: parseInt(adc) })
    })
    .then(r => r.json())
    .then(data => {
        log(`[API RESPONSE] Status: ${data.status} | Pesan: ${data.message}`);
        if (data.lcd_line1) {
            document.getElementById('lcdLine1').innerText = data.lcd_line1;
            document.getElementById('lcdLine2').innerText = data.lcd_line2;
        }
    })
    .catch(err => {
        log(`[API ERROR] ${err.message}`);
    });
}

// Polling Mikrokontroler ESP32 untuk mendeteksi instruksi pembukaan solenoid
setInterval(() => {
    fetch('api/check_status.php?mode=esp32')
        .then(r => r.json())
        .then(data => {
            if (data.command === 'unlock') {
                log(`[ESP32 ACTION] PERINTAH BUKA SOLENOID DITERIMA untuk Loker #${data.locker_no}`);
                document.getElementById('lcdLine1').innerText = data.lcd_line1;
                document.getElementById('lcdLine2').innerText = data.lcd_line2;

                const solBox = document.getElementById('solenoidBox');
                solBox.className = 'solenoid-indicator solenoid-unlocked';
                document.getElementById('solenoidIcon').innerText = '🔓';
                document.getElementById('solenoidText').innerText = `LOKER #${data.locker_no} TERBUKA (RELAY ON)`;

                // Kunci kembali setelah 5 detik
                setTimeout(() => {
                    solBox.className = 'solenoid-indicator solenoid-locked';
                    document.getElementById('solenoidIcon').innerText = '🔒';
                    document.getElementById('solenoidText').innerText = 'PINTU TERKUNCI (RELAY OFF)';
                    document.getElementById('lcdLine1').innerText = 'Smart Locker IoT';
                    document.getElementById('lcdLine2').innerText = 'Siap Digunakan';
                    log(`[ESP32 ACTION] Waktu buka selesai. Solenoid terkunci kembali otomatis.`);
                }, (data.duration_sec || 5) * 1000);
            }
        })
        .catch(() => {});
}, 3000);
</script>

</body>
</html>
