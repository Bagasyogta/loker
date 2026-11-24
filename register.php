<?php
require_once __DIR__ . '/config/database.php';

if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $telepon  = trim($_POST['telepon'] ?? '');
    $password = $_POST['password'] ?? '';
    $konfirmasi = $_POST['konfirmasi_password'] ?? '';

    // Validasi format sesuai Activity Diagram Gambar 3.4
    if (empty($username) || empty($email) || empty($telepon) || empty($password)) {
        $error = 'Semua field formulir wajib diisi!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid!';
    } elseif (!preg_match('/^[0-9+]{8,15}$/', $telepon)) {
        $error = 'Nomor telepon harus berupa angka (8-15 digit)!';
    } elseif (strlen($password) < 6) {
        $error = 'Kata sandi minimal 6 karakter!';
    } elseif ($password !== $konfirmasi) {
        $error = 'Konfirmasi kata sandi tidak cocok!';
    } else {
        $db = getDbConnection();

        // Cek apakah username / email sudah terdaftar
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $error = 'Username atau Email sudah terdaftar. Silakan gunakan yang lain.';
        } else {
            // Simpan data pendaftaran
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmtInsert = $db->prepare("INSERT INTO users (username, email, telepon, password, saldo, dibuat) VALUES (?, ?, ?, ?, 50000.00, NOW())");
            
            if ($stmtInsert->execute([$username, $email, $telepon, $hash])) {
                $_SESSION['success_msg'] = 'Pendaftaran berhasil! Akun Anda telah aktif, silakan masuk.';
                header("Location: login.php");
                exit;
            } else {
                $error = 'Gagal menyimpan data pendaftaran. Terjadi kesalahan sistem.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrasi - Smart Locker IoT</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <span class="brand-badge">Pendaftaran Akun</span>
            <h1 style="margin-top: 10px;">Daftar Akun Baru</h1>
            <p>Lengkapi data diri untuk menggunakan layanan loker cerdas</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <span>⚠️</span> <div><?= htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control" placeholder="Masukkan username" value="<?= htmlspecialchars($_POST['username'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label for="email">Alamat Email</label>
                <input type="email" id="email" name="email" class="form-control" placeholder="nama@domain.com" value="<?= htmlspecialchars($_POST['email'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label for="telepon">Nomor Telepon / WhatsApp</label>
                <input type="tel" id="telepon" name="telepon" class="form-control" placeholder="Nomor Telepon / WhatsApp" value="<?= htmlspecialchars($_POST['telepon'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label for="password">Kata Sandi (Password)</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="Minimal 6 karakter" required>
            </div>

            <div class="form-group">
                <label for="konfirmasi_password">Ulangi Kata Sandi</label>
                <input type="password" id="konfirmasi_password" name="konfirmasi_password" class="form-control" placeholder="Ketik ulang kata sandi" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Daftar Sekarang</button>
        </form>

        <div style="margin-top: 20px; text-align: center; font-size: 0.85rem; color: #64748b;">
            Sudah memiliki akun? <a href="login.php" style="color: var(--primary); font-weight: 600; text-decoration: none;">Masuk Disini</a>
        </div>
    </div>
</div>
</body>
</html>
