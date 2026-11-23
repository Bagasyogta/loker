/*
 * =========================================================================================
 * PROYEK: SMART LOCKER IOT DENGAN QR CODE DAN 2FA
 * MODUL : ESP32-CAM (AI-THINKER MODEL)
 * FUNGSI: KHUSUS PEMINDAI QR CODE DAN MENGIRIM PAYLOAD KE ESP32 UTAMA VIA SERIAL UART (TX/RX)
 * =========================================================================================
 * 
 * PANDUAN INSTALASI LIBRARY PADA ARDUINO IDE:
 * 1. Buka Arduino IDE -> Sketch -> Include Library -> Manage Libraries...
 * 2. Cari library: "ESP32QRCodeReader" (oleh Yuri / alvarowolfx)
 * 3. Klik Install.
 * 
 * SKEMA PENGKABELAN (WIRING SERIAL ESP32-CAM KE ESP32 UTAMA):
 * - Pin VCC (5V) ESP32-CAM  <---> Power Supply 5V DC (Disarankan catu daya eksternal minimal 2A)
 * - Pin GND ESP32-CAM       <---> GND Bersama (Common Ground dengan ESP32 Utama)
 * - Pin U0TXD (GPIO 1) CAM  <---> Pin RX2 (GPIO 16) ESP32 Utama
 * - Pin U0RXD (GPIO 3) CAM  <---> Pin TX2 (GPIO 17) ESP32 Utama (Opsional untuk handshake)
 * 
 * CATATAN SAAT UPLOAD PROGRAM:
 * - Hubungkan Pin IO0 ke GND saat proses upload sketch melalui FTDI / USB-to-UART adapter.
 * - Lepas koneksi IO0 dari GND dan tekan tombol RESET setelah upload selesai.
 * =========================================================================================
 */

#include <Arduino.h>
#include <ESP32QRCodeReader.h>

// Definisi pin Flash LED bawaan ESP32-CAM (GPIO 4) untuk indikator visual
#define FLASH_LED_PIN 4

// Inisialisasi objek pembaca QR Code dengan resolusi FRAMESIZE_VGA atau FRAMESIZE_QVGA
// Rekomendasi Bab 3: Jarak optimal 5 - 25 cm
ESP32QRCodeReader reader(CAMERA_MODEL_AI_THINKER);

// Variabel kontrol waktu agar tidak terjadi pengiriman berulang (cooldown)
unsigned long lastScanTime = 0;
const unsigned long SCAN_COOLDOWN_MS = 3000; // Jeda 3 detik setelah scan sukses
String lastScannedPayload = "";

void setup() {
  // Inisialisasi Serial UART0 dengan baud rate 115200 (ke ESP32 utama via U0TXD)
  Serial.begin(115200);
  delay(1000);

  // Inisialisasi Flash LED
  pinMode(FLASH_LED_PIN, OUTPUT);
  digitalWrite(FLASH_LED_PIN, LOW); // Matikan flash awal

  // Setup kamera dan pemindai QR Code
  reader.setup();

  // Mulai proses pembacaan kamera di background task (FreeRTOS)
  reader.begin();

  // Kedipkan Flash LED 2x sebagai penanda modul siap bekerja
  for (int i = 0; i < 2; i++) {
    digitalWrite(FLASH_LED_PIN, HIGH);
    delay(100);
    digitalWrite(FLASH_LED_PIN, LOW);
    delay(100);
  }
}

void onQrCodeDetected(struct QRCodeData qrCodeData) {
  if (qrCodeData.valid) {
    String payload = String((const char *)qrCodeData.payload);
    payload.trim();

    unsigned long now = millis();

    // Filter duplikasi pembacaan dalam interval cooldown
    if (payload.length() > 0 && (payload != lastScannedPayload || (now - lastScanTime) > SCAN_COOLDOWN_MS)) {
      lastScannedPayload = payload;
      lastScanTime = now;

      // Nyalakan Flash LED singkat (150ms) sebagai indikator keberhasilan deteksi optik
      digitalWrite(FLASH_LED_PIN, HIGH);

      // ==================================================================
      // KIRIM PAYLOAD TOKEN QR KE ESP32 UTAMA MELALUI SERIAL UART (TX)
      // Format pengiriman: <PAYLOAD_STRING>\n
      // ==================================================================
      Serial.println(payload);

      delay(150);
      digitalWrite(FLASH_LED_PIN, LOW);
    }
  }
}

void loop() {
  struct QRCodeData qrCodeData;

  // Cek apakah ada frame yang berhasil di-decode menjadi data QR
  if (reader.receiveQrCode(&qrCodeData, 100)) {
    onQrCodeDetected(qrCodeData);
  }

  // Memberikan waktu istirahat pada watchdog CPU
  vTaskDelay(10 / portTICK_PERIOD_MS);
}
