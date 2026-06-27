<?php
require_once __DIR__ . '/include/config.php';
$db = getDB();
$cols = $db->query("SHOW COLUMNS FROM survey_responses")->fetchAll(PDO::FETCH_ASSOC);
print_r($cols);
