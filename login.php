<?php
require_once __DIR__ . '/config/database.php';

if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit;
}

$error = '';
$success = '';

if (isset($_SESSION['success_msg'])) {
    $success = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Username dan Password wajib diisi.';
    } else {
        $db = getDbConnection();
        $stmt = $db->prepare("SELECT id, username, password FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Login sukses
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            header("Location: dashboard.php");
            exit;
        } else {
            // Sesuai Activity Diagram Gambar 3.3 (Menampilkan Pesan Gagal Login)
            $error = 'Kredensial tidak valid! Username atau Password salah.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Smart Locker IoT 2FA</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <span class="brand-badge">IoT Smart Locker</span>
            <h1 style="margin-top: 10px;">Masuk Akun</h1>
            <p>Sistem Penyewaan Loker Cerdas dengan QR Code & 2FA</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <span>⚠️</span> <div><?= htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <span>✅</span> <div><?= htmlspecialchars($success); ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="form-group">
                <label for="username">Username atau Email</label>
                <input type="text" id="username" name="username" class="form-control" placeholder="Masukkan username atau email" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Kata Sandi (Password)</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Masuk ke Sistem</button>
        </form>

        <div style="margin-top: 20px; text-align: center; font-size: 0.85rem; color: #64748b;">
            Belum memiliki akun? <a href="register.php" style="color: var(--primary); font-weight: 600; text-decoration: none;">Daftar Sekarang</a>
        </div>
    </div>
</div>
</body>
</html>
