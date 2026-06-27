<?php
require_once __DIR__ . '/include/config.php';
$db = getDB();

echo "Database Date/Time:\n";
$dt = $db->query("SELECT NOW() as db_now, CURDATE() as db_today")->fetch();
print_r($dt);

echo "\nPHP Date/Time:\n";
echo "PHP Now: " . date('Y-m-d H:i:s') . "\n";
echo "PHP Today: " . date('Y-m-d') . "\n";

echo "\nActive Officers:\n";
$officers = $db->query("SELECT o.id as officer_id, u.nama, u.email FROM officers o JOIN users u ON o.user_id = u.id")->fetchAll();
print_r($officers);

echo "\nPickup Requests scheduled for today or not completed:\n";
$reqs = $db->query("SELECT id, request_code, nama_pemohon, status, officer_id, tanggal_jemput, jam_jemput FROM pickup_requests WHERE status NOT IN ('selesai', 'dibatalkan')")->fetchAll();
print_r($reqs);
unlink(__FILE__);
