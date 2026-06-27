<?php
// officer/logout.php — Logout Petugas Lapangan
require_once __DIR__ . '/../include/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$_SESSION = [];
session_destroy();

header('Location: ' . baseUrl('login.php'));
exit;
