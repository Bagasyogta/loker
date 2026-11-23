<?php
/**
 * Logout Pengguna Sesuai Activity Diagram Gambar 3.8
 * Menghapus session, membersihkan cookie, dan redirect ke halaman login
 */
require_once __DIR__ . '/config/database.php';

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

header("Location: login.php");
exit;
