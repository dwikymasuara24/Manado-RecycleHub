<?php
// ============================================================
//  logout.php — Sistem Logout Hub Utama
// ============================================================
require_once __DIR__ . '/include/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Hapus semua data sesi
$_SESSION = [];

// Hapus cookie sesi jika ada
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// Redirect ke halaman utama default
header('Location: ' . baseUrl('index.php'));
exit;
