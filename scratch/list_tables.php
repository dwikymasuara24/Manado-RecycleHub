<?php
require_once 'include/config.php';
$db = getDB();
$res = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach($res as $r) echo $r . "\n";
