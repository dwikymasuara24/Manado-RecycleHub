<?php
require_once __DIR__ . '/../include/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Hapus semua data session
$_SESSION = [];
session_destroy();

// Redirect ke halaman login
header('Location: ' . baseUrl('login.php'));
exit;
