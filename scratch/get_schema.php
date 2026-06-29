<?php
require_once __DIR__ . '/../include/config.php';
$db = getDB();
echo "--- PICKUP REQUESTS COLUMNS ---\n";
foreach($db->query("SHOW COLUMNS FROM pickup_requests")->fetchAll() as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}
echo "\n--- CLEANUP REQUESTS COLUMNS ---\n";
foreach($db->query("SHOW COLUMNS FROM cleanup_requests")->fetchAll() as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}
echo "\n--- OFFICERS COLUMNS ---\n";
foreach($db->query("SHOW COLUMNS FROM officers")->fetchAll() as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}
echo "\n--- WEIGHING RECORDS COLUMNS ---\n";
foreach($db->query("SHOW COLUMNS FROM weighing_records")->fetchAll() as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}
