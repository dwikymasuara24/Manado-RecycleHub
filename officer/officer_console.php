<?php
// officer/officer_console.php — Entry point: redirect ke halaman Tugas Hari Ini
require_once __DIR__ . '/../include/auth.php';
requireRole('officer');
header('Location: dashboard.php');
exit;
