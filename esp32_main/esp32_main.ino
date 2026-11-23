/*
 * =========================================================================================
 * PROYEK: SMART LOCKER IOT DENGAN QR CODE DAN 2FA
 * MODUL : ESP32 UTAMA (DEV KIT V1)
 * FUNGSI: MIKROKONTROLER PUSAT PENGENDALI OPERASIONAL LOKER:
 *         - Menerima token QR dari ESP32-CAM via Serial UART2 (RX2 / TX2)
 *         - Komunikasi WiFi & HTTP REST API ke Server Web PHP
 *         - Menampilkan informasi status pada Layar LCD 16x2 I2C
 *         - Mengendalikan Aktuator Relay Solenoid Door Lock
 *         - Membaca dan melaporkan intensitas cahaya Sensor LDR (Lux)
 * =========================================================================================
 * 
 * PANDUAN INSTALASI LIBRARY PADA ARDUINO IDE:
 * 1. LiquidCrystal_I2C (oleh Frank de Brabander / Marco Schwartz)
 * 2. ArduinoJson (oleh Benoit Blanchon - versi 6 atau 7)
 * 
 * SKEMA PENGKABELAN (WIRING DIAGRAM):
 * 1. Komunikasi Serial UART2 ke ESP32-CAM:
 *    - Pin GPIO 16 (RX2) ESP32 Utama  <---> Pin U0TXD (GPIO 1) ESP32-CAM
 *    - Pin GPIO 17 (TX2) ESP32 Utama  <---> Pin U0RXD (GPIO 3) ESP32-CAM
 *    - GND ESP32 Utama                <---> GND ESP32-CAM (Wajib terhubung / Common Ground!)
 * 
 * 2. LCD I2C 16x2:
 *    - VCC <---> 5V
 *    - GND <---> GND
 *    - SDA <---> GPIO 21
 *    - SCL <---> GPIO 22
 * 
 * 3. Modul Relay (Solenoid Door Lock):
 *    - VCC <---> 5V
 *    - GND <---> GND
 *    - IN  <---> GPIO 23
 * 
 * 4. Sensor LDR:
 *    - VCC <---> 3.3V atau 5V
 *    - GND <---> GND
 *    - AO (Analog Out) <---> GPIO 34 (ADC1_CH6)
 * =========================================================================================
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <ArduinoJson.h>

// =====================================================================
// KONFIGURASI JARINGAN WIFI DAN ALAMAT SERVER WEB
// =====================================================================
const char* WIFI_SSID     = "NAMA_WIFI_ANDA";       // Ganti dengan SSID WiFi Anda
const char* WIFI_PASSWORD = "PASSWORD_WIFI_ANDA";   // Ganti dengan Password WiFi Anda

// Alamat URL Server Web PHP Smart Locker (Host Server Port 4060)
// Di Docker, web berjalan langsung di root port 4060:
const char* SERVER_BASE_URL = "http://192.168.2.2:4060";

// =====================================================================
// DEFINISI PIN PERANGKAT KERAS
// =====================================================================
#define PIN_RELAY_SOLENOID 23  // Pin pengontrol modul relay solenoid door lock
#define PIN_SENSOR_LDR     34  // Pin analog input sensor LDR

// Konfigurasi Level Logika Relay (Sebagian besar modul relay aktif LOW)
#define RELAY_ACTIVE   LOW   // Sinyal untuk membuka solenoid door lock
#define RELAY_INACTIVE HIGH  // Sinyal untuk mengunci kembali solenoid door lock

// Konfigurasi Serial2 untuk menerima stream dari ESP32-CAM
#define CAM_RX_PIN 16
#define CAM_TX_PIN 17

// Inisialisasi Layar LCD 16x2 dengan alamat I2C umum (0x27 atau 0x3F)
LiquidCrystal_I2C lcd(0x27, 16, 2);

// Inisialisasi UART Serial Hardware kedua untuk komunikasi dengan ESP32-CAM
HardwareSerial SerialCam(2);

// Timer polling status dan pelaporan LDR
unsigned long lastPollingTime = 0;
const unsigned long POLLING_INTERVAL_MS = 2000; // Cek trigger buka pintu setiap 2 detik

unsigned long lastLdrLogTime = 0;
const unsigned long LDR_LOG_INTERVAL_MS = 30000; // Kirim data lux setiap 30 detik

// =====================================================================
// FUNGSI HELPER TAMPILAN LCD
// =====================================================================
void updateLcd(String baris1, String baris2) {
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print(baris1.substring(0, 16));
  lcd.setCursor(0, 1);
  lcd.print(baris2.substring(0, 16));
}

void setLcdStandby() {
  updateLcd("Smart Locker IoT", "Siap Digunakan");
}

// =====================================================================
// FUNGSI BUKA SOLENOID DOOR LOCK
// =====================================================================
void triggerUnlockDoor(int lockerNo, int durationSec) {
  Serial.printf("\n[HARDWARE] Membuka Solenoid Lock Unit #%d selama %d detik...\n", lockerNo, durationSec);
  
  updateLcd("Akses Diterima!", "Loker " + String(lockerNo) + " Buka");

  // Aktifkan Relay -> Solenoid Menarik Masuk (Pintu Terbuka)
  digitalWrite(PIN_RELAY_SOLENOID, RELAY_ACTIVE);

  // Tahan selama durasi yang ditentukan (default 5 detik)
  delay(durationSec * 1000);

  // Nonaktifkan Relay -> Solenoid Memanjang Kembali (Pintu Terkunci Otomatis)
  digitalWrite(PIN_RELAY_SOLENOID, RELAY_INACTIVE);

  Serial.println("[HARDWARE] Waktu sewa/ambil selesai. Solenoid terkunci kembali.");
  updateLcd("Pintu Terkunci", "Otomatis");
  delay(2000);

  setLcdStandby();
}

// =====================================================================
// FUNGSI MENGIRIM TOKEN QR KE API SERVER (/api/scan_qr.php)
// =====================================================================
void sendQrTokenToServer(String token, int ldrVal) {
  if (WiFi.status() != WL_CONNECTED) {
    updateLcd("WiFi Terputus!", "Gagal Kirim Data");
    delay(2000);
    setLcdStandby();
    return;
  }

  HTTPClient http;
  String url = String(SERVER_BASE_URL) + "/api/scan_qr.php";

  http.begin(url);
  http.addHeader("Content-Type", "application/json");

  // Siapkan Payload JSON
  StaticJsonDocument<256> doc;
  doc["qr_token"] = token;
  doc["ldr_val"]  = ldrVal;

  String requestBody;
  serializeJson(doc, requestBody);

  Serial.printf("\n[HTTP POST] Mengirim ke %s\nPayload: %s\n", url.c_str(), requestBody.c_str());
  int httpCode = http.POST(requestBody);

  if (httpCode > 0) {
    String response = http.getString();
    Serial.printf("[HTTP RESPONSE %d] %s\n", httpCode, response.c_str());

    StaticJsonDocument<512> resDoc;
    DeserializationError error = deserializeJson(resDoc, response);

    if (!error) {
      String status = resDoc["status"] | "";
      String line1  = resDoc["lcd_line1"] | "QR Terverifikasi";
      String line2  = resDoc["lcd_line2"] | "Cek Layar HP";

      updateLcd(line1, line2);

      if (status == "success") {
        // Berhasil terverifikasi, menunggu pengguna input password di web
        Serial.println("[LOGIKA] QR Valid. Menunggu otorisasi password 2FA di website...");
      } else {
        // QR Gagal / Kadaluarsa
        delay(2500);
        setLcdStandby();
      }
    } else {
      updateLcd("Respon Error", "JSON Parse Gagal");
      delay(2000);
      setLcdStandby();
    }
  } else {
    Serial.printf("[HTTP ERROR] Request gagal, error: %s\n", http.errorToString(httpCode).c_str());
    updateLcd("Server Error", "Koneksi Gagal");
    delay(2000);
    setLcdStandby();
  }

  http.end();
}

// =====================================================================
// FUNGSI POLLING STATUS TRIGGER UNLOCK KE API (/api/check_status.php)
// =====================================================================
void checkUnlockTrigger() {
  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  String url = String(SERVER_BASE_URL) + "/api/check_status.php?mode=esp32";

  http.begin(url);
  int httpCode = http.GET();

  if (httpCode == 200) {
    String response = http.getString();
    StaticJsonDocument<384> doc;
    DeserializationError error = deserializeJson(doc, response);

    if (!error) {
      String command = doc["command"] | "";
      if (command == "unlock") {
        int lockerNo = doc["locker_no"] | 1;
        int durationSec = doc["duration_sec"] | 5;
        triggerUnlockDoor(lockerNo, durationSec);
      }
    }
  }

  http.end();
}

// =====================================================================
// FUNGSI PENGIRIMAN DATA INTENSITAS CAHAYA LDR KE SERVER
// =====================================================================
void sendLdrTelemetry() {
  if (WiFi.status() != WL_CONNECTED) return;

  int ldrVal = analogRead(PIN_SENSOR_LDR);

  HTTPClient http;
  String url = String(SERVER_BASE_URL) + "/api/ldr_log.php";

  http.begin(url);
  http.addHeader("Content-Type", "application/json");

  StaticJsonDocument<128> doc;
  doc["ldr_val"] = ldrVal;

  String body;
  serializeJson(doc, body);

  http.POST(body);
  http.end();

  Serial.printf("[TELEMETRI] Data LDR terkirim: ADC %d\n", ldrVal);
}

// =====================================================================
// SETUP AWAL ESP32
// =====================================================================
void setup() {
  Serial.begin(115200);
  delay(1000);
  Serial.println("\n=============================================");
  Serial.println("  SISTEM SMART LOCKER IOT (ESP32 UTAMA)     ");
  Serial.println("=============================================");

  // Inisialisasi Pin Output & Input
  pinMode(PIN_RELAY_SOLENOID, OUTPUT);
  digitalWrite(PIN_RELAY_SOLENOID, RELAY_INACTIVE); // Pastikan solenoid terkunci awal

  pinMode(PIN_SENSOR_LDR, INPUT);

  // Inisialisasi Serial UART2 untuk ESP32-CAM
  SerialCam.begin(115200, SERIAL_8N1, CAM_RX_PIN, CAM_TX_PIN);

  // Inisialisasi Layar LCD
  Wire.begin(21, 22);
  lcd.init();
  lcd.backlight();
  updateLcd("Smart Locker IoT", "Menghubungkan...");

  // Hubungkan ke WiFi
  Serial.printf("[WIFI] Menghubungkan ke SSID: %s\n", WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  int wifiAttempts = 0;
  while (WiFi.status() != WL_CONNECTED && wifiAttempts < 20) {
    delay(500);
    Serial.print(".");
    wifiAttempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\n[WIFI] Berhasil Terhubung!");
    Serial.print("[WIFI] Alamat IP ESP32: ");
    Serial.println(WiFi.localIP());

    updateLcd("WiFi Terhubung!", WiFi.localIP().toString());
    delay(2000);
  } else {
    Serial.println("\n[WIFI GAGAL] Tidak dapat terhubung ke WiFi. Mode Offline Standby.");
    updateLcd("WiFi Offline!", "Cek Konfigurasi");
    delay(2000);
  }

  setLcdStandby();
}

// =====================================================================
// LOOP UTAMA PROGRAM
// =====================================================================
void loop() {
  // 1. CEK APAKAH ADA PAYLOAD QR MASUK DARI ESP32-CAM VIA UART2 (RX2)
  if (SerialCam.available()) {
    String qrToken = SerialCam.readStringUntil('\n');
    qrToken.trim();

    if (qrToken.length() > 0) {
      Serial.printf("\n[SERIAL RX] Menerima QR Token dari ESP32-CAM: %s\n", qrToken.c_str());

      // Tampilkan feedback instan pada LCD
      updateLcd("QR Terdeteksi!", "Memvalidasi...");

      // Baca sensor LDR saat pemindaian dilakukan (variabel pengujian Bab 3.5)
      int ldrVal = analogRead(PIN_SENSOR_LDR);

      // Kirim ke server web untuk verifikasi
      sendQrTokenToServer(qrToken, ldrVal);
    }
  }

  // 2. PERIODIK POLLING TRIGGER BUKA SOLENOID (SETELAH 2FA DI WEB SUKSES)
  unsigned long now = millis();
  if (now - lastPollingTime >= POLLING_INTERVAL_MS) {
    lastPollingTime = now;
    checkUnlockTrigger();
  }

  // 3. PERIODIK PELAPORAN DATA SENSOR LDR UNTUK LOG PENGUJIAN BAB 3.5
  if (now - lastLdrLogTime >= LDR_LOG_INTERVAL_MS) {
    lastLdrLogTime = now;
    sendLdrTelemetry();
  }
}
