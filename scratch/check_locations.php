<?php
require_once __DIR__ . '/../include/config.php';
$db = getDB();

echo "=== OFFICERS ===\n";
$officers = $db->query("SELECT id, nama, last_lat, last_lng, status FROM officers")->fetchAll();
foreach ($officers as $o) {
    echo "ID: {$o['id']}, Name: {$o['nama']}, Lat: {$o['last_lat']}, Lng: {$o['last_lng']}, Status: {$o['status']}\n";
}

echo "=== PICKUP REQUESTS ===\n";
$pickups = $db->query("SELECT id, request_code, nama_pemohon, latitude, longitude, status FROM pickup_requests")->fetchAll();
foreach ($pickups as $p) {
    echo "ID: {$p['id']}, Code: {$p['request_code']}, Pemohon: {$p['nama_pemohon']}, Lat: {$p['latitude']}, Lng: {$p['longitude']}, Status: {$p['status']}\n";
}

echo "=== CLEANUP REQUESTS ===\n";
$cleanups = $db->query("SELECT id, request_code, nama_pemohon, latitude, longitude, status FROM cleanup_requests")->fetchAll();
foreach ($cleanups as $c) {
    echo "ID: {$c['id']}, Code: {$c['request_code']}, Pemohon: {$c['nama_pemohon']}, Lat: {$c['latitude']}, Lng: {$c['longitude']}, Status: {$c['status']}\n";
}
