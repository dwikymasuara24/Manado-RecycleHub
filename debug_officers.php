<?php
require_once __DIR__ . '/include/config.php';
$db = getDB();

$officers = $db->query("SELECT o.id as officer_id, o.officer_code, u.nama, u.email FROM officers o JOIN users u ON o.user_id = u.id")->fetchAll();
file_put_contents(__DIR__ . '/debug_officers.txt', print_r($officers, true));
echo "OK";
