<?php
require_once __DIR__ . '/config/database.php';

if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit;
} else {
    header("Location: login.php");
    exit;
}
