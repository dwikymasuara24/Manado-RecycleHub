<?php
require_once 'include/config.php';
$db = getDB();
try {
    $db->exec("ALTER TABLE pickup_requests ADD COLUMN is_kendala TINYINT(1) DEFAULT 0 AFTER catatan_officer");
    echo "Column added successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
