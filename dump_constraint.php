<?php
require_once __DIR__ . '/include/config.php';
$pdo = getDB();
$out = $pdo->query("SHOW CREATE TABLE survey_responses")->fetchColumn(1);
file_put_contents(__DIR__ . '/constraint.txt', $out);
echo "Written to constraint.txt";
