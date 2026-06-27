<?php
require_once dirname(__DIR__) . '/include/config.php';
$db = getDB();
$tables = ['blog_posts', 'diy_projects', 'diy_steps', 'idea_box'];
foreach($tables as $table) {
    echo "=== Table: $table ===\n";
    try {
        $cols = $db->query("DESCRIBE `$table`")->fetchAll();
        foreach($cols as $col) {
            echo "Col: {$col['Field']} - {$col['Type']} - Null: {$col['Null']} - Key: {$col['Key']}\n";
        }
    } catch(Exception $e) {
        echo "Error or Table not exists: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
