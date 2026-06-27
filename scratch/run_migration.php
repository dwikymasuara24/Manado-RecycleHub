<?php
require_once __DIR__ . '/../include/config.php';

try {
    $db = getDB();
    $sql = file_get_contents(__DIR__ . '/fix_db.sql');
    
    // Split by semicolon but be careful with triggers if any (not here though)
    // For simple migrations, exec() might work if the driver supports multiple statements
    // But it's safer to split or just run it.
    
    $db->exec($sql);
    echo "Migration successful!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
