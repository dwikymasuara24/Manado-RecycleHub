<?php
require_once __DIR__ . '/../include/config.php';
$db = getDB();
echo "--- pickup_requests ---\n";
$q = $db->query("DESCRIBE pickup_requests");
while($r = $q->fetch(PDO::FETCH_ASSOC)) {
    print_r($r);
}
echo "\n--- schedules ---\n";
try {
    $q = $db->query("DESCRIBE schedules");
    while($r = $q->fetch(PDO::FETCH_ASSOC)) {
        print_r($r);
    }
} catch (Exception $e) { echo "schedules not found\n"; }

echo "\n--- routes ---\n";
try {
    $q = $db->query("DESCRIBE routes");
    while($r = $q->fetch(PDO::FETCH_ASSOC)) {
        print_r($r);
    }
} catch (Exception $e) { echo "routes not found\n"; }
