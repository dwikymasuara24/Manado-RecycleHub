<?php
require_once __DIR__ . '/../include/config.php';
$db = getDB();
echo "--- PICKUP REQUESTS ---\n";
foreach($db->query("SELECT id, request_code, nomor_wa, status FROM pickup_requests ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "ID={$row['id']} Code={$row['request_code']} WA={$row['nomor_wa']} Status={$row['status']}\n";
}
echo "--- CLEANUP REQUESTS ---\n";
foreach($db->query("SELECT id, request_code, nomor_wa, status FROM cleanup_requests ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "ID={$row['id']} Code={$row['request_code']} WA={$row['nomor_wa']} Status={$row['status']}\n";
}
