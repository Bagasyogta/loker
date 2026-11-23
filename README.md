# Implementasi Sistem Smart Locker Berbasis IoT dengan QR Code dan 2 Factor Authentication (2FA)

Proyek ini dibangun secara presisi mengacu pada perancangan **Bab 3 Proposal Skripsi** Program Studi S1 Teknik Komputer, Universitas Pendidikan Indonesia:
- **Metode**: *Design and Development (D&D)*
- **Faktor Keamanan**: *Two-Factor Authentication* (Faktor 1: **QR Code Token Dinamis** + Faktor 2: **Password Akun Pengguna**)
- **Platform Web**: PHP Native & MySQL (PDO)
- **Arsitektur Mikrokontroler**:
  1. **ESP32-CAM**: Khusus modul pemindai optik (*QR Code scanner*), mengirim payload via Serial UART (TX/RX).
  2. **ESP32 Utama**: Mikrokontroler pusat pengendali WiFi, LCD 16x2 I2C, Relay Solenoid Door Lock, dan Sensor LDR.

---

## 📁 Struktur Direktori Proyek

```
Metopen/
├── config/
│   └── database.php             # Konfigurasi PDO MySQL, session, dan utilitas sistem
├── assets/
│   ├── css/
│   │   └── style.css            # Desain UI modern, responsif & ramah perangkat seluler
│   └── js/
│       └── qrcode.min.js        # Library QR Code offline mandiri berbasis HTML5 Canvas
├── api/
│   ├── scan_qr.php              # REST API: verifikasi token QR yang dikirim ESP32
│   ├── check_status.php         # REST API: polling trigger buka pintu solenoid & status sewa
│   ├── ldr_log.php              # REST API: pencatatan data intensitas cahaya (Lux) LDR
│   └── simulate_scan.php        # REST API: pemicu simulasi pemindaian untuk demonstrasi
├── esp32_cam/
│   └── esp32_cam.ino            # Firmware ESP32-CAM (Pemindai QR Code via Serial TX)
├── esp32_main/
│   └── esp32_main.ino           # Firmware ESP32 Utama (WiFi, LCD, Solenoid, LDR, REST Client)
├── database.sql                 # Skema basis data relasional MySQL (Gambar 3.9) & seeding awal
├── index.php                    # Gerbang rute utama sistem
├── login.php                    # Form masuk kredensial (Gambar 3.3)
├── register.php                 # Form pendaftaran akun baru (Gambar 3.4)
├── logout.php                   # Pembersihan sesi dan cookie (Gambar 3.8)
├── dashboard.php                # Dashboard ketersediaan loker, sewa aktif, dan riwayat transaksi
├── sewa.php                     # Alur pemesanan, generate QR, & verifikasi password 2FA (Gambar 3.5)
├── akhiri_sewa.php              # Pengakhiran sewa, 2FA, & validasi kecukupan saldo (Gambar 3.7)
├── topup.php                    # Pengisian saldo simulasi dummy (Gambar 3.6)
├── hardware_sim.php             # Simulator Hardware Web Interaktif (LCD, Solenoid, LDR Slider)
└── README.md                    # Dokumentasi teknis lengkap
```

---

## 🗄️ Relasi Basis Data (Gambar 3.9 Proposal)

Basis data `smart_locker` mengimplementasikan 5 tabel inti berelasi penuh:
1. `users`: Menyimpan kredensial akun terenkripsi (`password_hash`), kontak, dan `saldo` simulasi.
2. `loker`: Menyimpan nomor unit loker fisik (`no_loker`) dan status operasional (`tersedia`, `terpakai`, `maintenance`).
3. `peminjaman`: Entitas transaksi utama (*junction table*) penghubung `users` dan `loker`, mencatat waktu sewa, tarif, dan flag `door_unlock_trigger`.
4. `qr_code`: Menyimpan token acak unik dengan relasi ke `peminjaman`, lengkap dengan stempel kadaluarsa (`expired`).
5. `akses`: Menyimpan rekam jejak validasi otorisasi 2FA (`qr_valid`, `password_valid`, `hasil`, dan `waktu`).
6. `log_sensor_ldr`: Menyimpan data pengujian pencahayaan ADC dan klasifikasi lux (Tabel 2.1 & Bab 3.5).

---

## 🔐 Mekanisme Two-Factor Authentication (2FA)

Sesuai rumusan dan batasan Bab 3:
1. **Faktor 1 (What You Have / Kepemilikan)**:
   - Pengguna menekan tombol sewa / akhiri sewa pada antarmuka web melalui smartphone.
   - Sistem membangkitkan kode QR dinamis berisi token unik kriptografis bertenggat waktu (15 menit).
   - Pengguna mengarahkan layar ke modul kamera **ESP32-CAM**.
   - ESP32-CAM membaca payload dan mengirim string token ke **ESP32 Utama** melalui pin TX UART.
   - ESP32 Utama memverifikasi token ke API Server. Jika valid, LCD menampilkan `"Input Password Web"`.
2. **Faktor 2 (What You Know / Pengetahuan)**:
   - Antarmuka web pengguna otomatis membuka form otentikasi lapis kedua: **Password Akun Pengguna**.
   - Pengguna memasukkan password akunnya.
   - Backend memverifikasi kecocokan password dengan hash pada basis data MySQL.
   - Hanya ketika **Faktor 1 (QR Valid)** DAN **Faktor 2 (Password Cocok)** terpenuhi, sistem mengaktifkan `door_unlock_trigger = 1`.
   - ESP32 Utama mendeteksi trigger, mengaktifkan modul relay sehingga **Solenoid Door Lock terbuka**, dan LCD menampilkan `"Akses Diterima!"`.

---

## 🚀 Panduan Instalasi Web Server & Basis Data

### 1. Persiapan Database (MySQL)
1. Buka phpMyAdmin (misal melalui XAMPP atau Laragon).
2. Buat database baru bernama `smart_locker` (atau biarkan script membuatnya otomatis).
3. Import file [database.sql](file:///c:/Users/Saikeno/Documents/Metopen/database.sql).
4. Akun pengujian bawaan (*default demo*):
   - **Username**: `bagas`
   - **Password**: `password123`
   - **Saldo Awal**: `Rp 100.000`

### 2. Konfigurasi Web PHP
Buka berkas [config/database.php](file:///c:/Users/Saikeno/Documents/Metopen/config/database.php) dan sesuaikan kredensial jika berbeda:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'smart_locker');
```

### 3. Menjalankan Server Web
Jika menggunakan XAMPP:
- Pindahkan atau tautkan folder `Metopen` ke direktori `htdocs/`.
- Akses melalui peramban: `http://localhost/Metopen/`

Atau jika menggunakan PHP CLI bawaan:
```bash
cd c:\Users\Saikeno\Documents\Metopen
php -S 0.0.0.0:8000
```
Lalu akses `http://localhost:8000`.

---

## 🔌 Skema Pengkabelan Hardware (Wiring Diagram)

### A. ESP32-CAM ke ESP32 Utama (Komunikasi Serial UART):
| Pin ESP32-CAM | Pin ESP32 DevKit Utama | Catatan |
|---|---|---|
| **U0TXD (GPIO 1)** | **RX2 (GPIO 16)** | Transmisi data token hasil scan QR |
| **U0RXD (GPIO 3)** | **TX2 (GPIO 17)** | Opsional / Handshake |
| **GND** | **GND** | **Wajib dihubungkan bersama (Common Ground)** |
| **5V / VCC** | **External 5V DC (Min 2A)** | ESP32-CAM memerlukan arus stabil saat kamera aktif |

> **Catatan Upload ESP32-CAM**: Hubungkan pin **IO0 ke GND** saat menekan tombol Upload pada Arduino IDE. Setelah selesai, lepas jumper dan tekan tombol RST.

### B. ESP32 Utama ke Periferal:
| Periferal | Pin ESP32 DevKit Utama | Deskripsi |
|---|---|---|
| **LCD 16x2 I2C** | SDA: **GPIO 21**, SCL: **GPIO 22**, VCC: 5V, GND: GND | Menampilkan status operasional loker |
| **Modul Relay** | IN: **GPIO 23**, VCC: 5V, GND: GND | Mengontrol arus Solenoid Lock 12V |
| **Sensor LDR** | AO: **GPIO 34 (ADC1_CH6)**, VCC: 3.3V/5V, GND: GND | Mengukur intensitas cahaya (Lux) |
| **Solenoid Door Lock** | Dihubungkan ke Kontak Relay (COM & NO) + Catu Daya 12V DC | Pengunci mekanik pintu loker |

---

## 💻 Panduan Pemrograman Arduino IDE

### 1. Instalasi Library:
Buka Arduino IDE -> **Tools** -> **Manage Libraries...**, kemudian instal:
1. `ESP32QRCodeReader` (oleh Yuri / alvarowolfx)
2. `LiquidCrystal_I2C` (oleh Frank de Brabander / Marco Schwartz)
3. `ArduinoJson` (oleh Benoit Blanchon - versi 6 atau 7)

### 2. Konfigurasi Sketch ESP32 Utama ([esp32_main/esp32_main.ino](file:///c:/Users/Saikeno/Documents/Metopen/esp32_main/esp32_main.ino)):
Ubah konfigurasi berikut sesuai jaringan lokal Anda:
```cpp
const char* WIFI_SSID     = "Nama_WiFi_Anda";
const char* WIFI_PASSWORD = "Password_WiFi_Anda";
const char* SERVER_BASE_URL = "http://192.168.1.100/Metopen"; // IP Laptop/Server Web
```

---

## 📟 Simulator Hardware Web ([hardware_sim.php](file:///c:/Users/Saikeno/Documents/Metopen/hardware_sim.php))

Untuk memudahkan pengujian dan presentasi di hadapan penguji tanpa harus selalu menyalakan perangkat keras fisik:
1. Masuk ke halaman: `http://localhost/Metopen/hardware_sim.php`
2. Tampilan menyajikan:
   - **Layar LCD 16x2 I2C**: Menampilkan visual dot-matrix karakter real-time yang sama dengan LCD fisik.
   - **Indikator Solenoid Lock**: Menampilkan animasi visual saat relay aktif (Hijau/Terbuka) dan terkunci kembali (Merah).
   - **Slider Sensor LDR**: Menyesuaikan nilai ADC (0-4095) dan menghitung Lux otomatis (Gelap, Redup, Standar, Terang, Sangat Terang).
   - **Simulasi Scan Serial**: Memilih token QR aktif dan mengirimkannya seolah-olah ditransmisikan oleh kamera ESP32-CAM.
