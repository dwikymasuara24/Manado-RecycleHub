<?php
require_once __DIR__ . '/../include/config.php';
$db = getDB();

echo "=== PICKUP REQUESTS COORDS ===\n";
$stmt = $db->query("SELECT id, request_code, latitude, longitude FROM pickup_requests");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "ID: {$r['id']} | Code: {$r['request_code']} | Lat: {$r['latitude']} | Lng: {$r['longitude']}\n";
}

echo "\n=== CLEANUP REQUESTS COORDS ===\n";
$stmt = $db->query("SELECT id, request_code, latitude, longitude FROM cleanup_requests");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "ID: {$r['id']} | Code: {$r['request_code']} | Lat: {$r['latitude']} | Lng: {$r['longitude']}\n";
}
