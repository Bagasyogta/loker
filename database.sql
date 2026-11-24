-- ====================================================================
-- Database: smart_locker
-- Proposal: Implementasi Sistem Smart Locker Berbasis IoT dengan QR Code dan 2FA
-- Program Studi S1 Teknik Komputer - UPI Cibiru
-- ====================================================================

CREATE DATABASE IF NOT EXISTS `smart_locker` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `smart_locker`;

-- Drop tables in order of dependencies
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `log_sensor_ldr`;
DROP TABLE IF EXISTS `akses`;
DROP TABLE IF EXISTS `qr_code`;
DROP TABLE IF EXISTS `peminjaman`;
DROP TABLE IF EXISTS `loker`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------------------------------
-- 1. Tabel USERS
-- --------------------------------------------------------------------
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `telepon` VARCHAR(20) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `saldo` DECIMAL(12,2) NOT NULL DEFAULT 50000.00,
  `dibuat` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------
-- 2. Tabel LOKER
-- --------------------------------------------------------------------
CREATE TABLE `loker` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `no_loker` INT NOT NULL UNIQUE,
  `status` ENUM('tersedia', 'terpakai', 'maintenance') NOT NULL DEFAULT 'tersedia'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------
-- 3. Tabel PEMINJAMAN
-- --------------------------------------------------------------------
CREATE TABLE `peminjaman` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_user` INT NOT NULL,
  `id_loker` INT NOT NULL,
  `rent_start` DATETIME DEFAULT NULL,
  `rent_end` DATETIME DEFAULT NULL,
  `rent_durasi` INT NOT NULL DEFAULT 1, -- dalam jam
  `rent_price` DECIMAL(10,2) NOT NULL DEFAULT 5000.00,
  `status` ENUM('menunggu_scan', 'menunggu_password', 'aktif', 'menunggu_akhiri_scan', 'menunggu_akhiri_password', 'selesai', 'dibatalkan') NOT NULL DEFAULT 'menunggu_scan',
  `door_unlock_trigger` TINYINT(1) NOT NULL DEFAULT 0,
  `door_unlocked_at` DATETIME DEFAULT NULL,
  FOREIGN KEY (`id_user`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`id_loker`) REFERENCES `loker`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------
-- 4. Tabel QR CODE
-- --------------------------------------------------------------------
CREATE TABLE `qr_code` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_peminjaman` INT NOT NULL,
  `qr_token` VARCHAR(100) NOT NULL UNIQUE,
  `expired` DATETIME NOT NULL,
  `status` ENUM('aktif', 'digunakan', 'kadaluarsa') NOT NULL DEFAULT 'aktif',
  `tipe` ENUM('sewa', 'akhiri') NOT NULL DEFAULT 'sewa',
  `dibuat` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`id_peminjaman`) REFERENCES `peminjaman`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------
-- 5. Tabel AKSES (Log verifikasi 2FA & status solenoid)
-- --------------------------------------------------------------------
CREATE TABLE `akses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_peminjaman` INT NOT NULL,
  `id_qr` INT DEFAULT NULL,
  `qr_valid` TINYINT(1) NOT NULL DEFAULT 0,
  `password_valid` TINYINT(1) NOT NULL DEFAULT 0,
  `hasil` VARCHAR(50) NOT NULL, -- e.g. 'berhasil_sewa', 'berhasil_kembali', 'gagal_qr', 'gagal_password', 'gagal_saldo'
  `waktu` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`id_peminjaman`) REFERENCES `peminjaman`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`id_qr`) REFERENCES `qr_code`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------
-- 6. Tabel LOG SENSOR LDR (Pengujian intensitas cahaya / lux Bab 3.5)
-- --------------------------------------------------------------------
CREATE TABLE `log_sensor_ldr` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nilai_adc` INT NOT NULL,
  `estimasi_lux` FLOAT NOT NULL,
  `kategori` VARCHAR(30) NOT NULL, -- Gelap, Redup, Standar, Terang, Sangat Terang
  `waktu` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------
-- DATA AWAL UNIT LOKER (HANYA 2 UNIT LOKER)
-- --------------------------------------------------------------------
INSERT INTO `loker` (`id`, `no_loker`, `status`) VALUES
(1, 1, 'tersedia'),
(2, 2, 'tersedia');
