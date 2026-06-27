<?php
require_once __DIR__ . '/../include/config.php';
$db = getDB();

$pickups = $db->query("SELECT id, status, kecamatan, officer_id, tanggal_jemput FROM pickup_requests")->fetchAll();
echo "Pickup Requests:\n";
foreach ($pickups as $p) {
    echo "ID: {$p['id']}, Status: {$p['status']}, Kecamatan: {$p['kecamatan']}, Officer: {$p['officer_id']}, Tgl: {$p['tanggal_jemput']}\n";
}

$officers = $db->query("SELECT id, nama, status FROM officers")->fetchAll();
echo "\nOfficers:\n";
foreach ($officers as $o) {
    echo "ID: {$o['id']}, Nama: {$o['nama']}, Status: {$o['status']}\n";
}

$schedules = $db->query("SELECT id, tanggal, kecamatan, officer_id, status FROM schedules")->fetchAll();
echo "\nSchedules:\n";
foreach ($schedules as $s) {
    echo "ID: {$s['id']}, Tanggal: {$s['tanggal']}, Kecamatan: {$s['kecamatan']}, Officer: {$s['officer_id']}, Status: {$s['status']}\n";
}
