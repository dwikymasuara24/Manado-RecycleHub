<?php
require_once 'include/config.php';
$db = getDB();
echo "=== KOLOM pickup_requests ===\n";
$cols = $db->query("SHOW COLUMNS FROM pickup_requests")->fetchAll(PDO::FETCH_COLUMN);
echo implode(', ', $cols) . "\n";
