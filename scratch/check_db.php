<?php
require_once __DIR__ . '/../include/config.php';
$db = getDB();

echo "--- TABLES ---\n";
foreach($db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    echo "$t\n";
}

echo "\n--- SAMPLE PICKUP REQUESTS ---\n";
$stmt = $db->query("SELECT id, request_code, nama_pemohon, partner_name, service_type, berat_total_kg, price_per_kg, status FROM pickup_requests LIMIT 20");
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
