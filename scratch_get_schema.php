<?php
require_once __DIR__ . '/include/config.php';
$db = getDB();
$columns = $db->query("SHOW COLUMNS FROM pickup_requests")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($columns, JSON_PRETTY_PRINT);
unlink(__FILE__); // Self-delete
