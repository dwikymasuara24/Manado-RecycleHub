<?php
require_once __DIR__ . '/../include/config.php';
$db = getDB();
$p1 = password_hash('admin123', PASSWORD_DEFAULT);
$p2 = password_hash('officer123', PASSWORD_DEFAULT);
$db->prepare("UPDATE users SET password=? WHERE email=?")->execute([$p1, 'esa@gmail.com']);
$db->prepare("UPDATE users SET password=? WHERE email=?")->execute([$p2, 'egisepang@gmail.com']);
echo "Passwords updated successfully.\n";
