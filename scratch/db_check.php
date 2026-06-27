<?php
require_once __DIR__ . '/../include/config.php';
$db = getDB();

echo "=== PICKUP REQUESTS ===\n";
$stmt = $db->query("SELECT id, request_code, nama_pemohon, nomor_wa, status, created_at FROM pickup_requests ORDER BY id DESC LIMIT 10");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "ID: {$r['id']} | Code: {$r['request_code']} | Name: {$r['nama_pemohon']} | WA: {$r['nomor_wa']} | Status: {$r['status']} | Date: {$r['created_at']}\n";
}

echo "\n=== CLEANUP REQUESTS ===\n";
$stmt = $db->query("SELECT id, request_code, nama_pemohon, nomor_wa, status, created_at FROM cleanup_requests ORDER BY id DESC LIMIT 10");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "ID: {$r['id']} | Code: {$r['request_code']} | Name: {$r['nama_pemohon']} | WA: {$r['nomor_wa']} | Status: {$r['status']} | Date: {$r['created_at']}\n";
}
