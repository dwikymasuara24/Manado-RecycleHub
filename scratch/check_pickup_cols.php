<?php
require_once 'include/config.php';
$db = getDB();
$res = $db->query("DESCRIBE pickup_requests")->fetchAll(PDO::FETCH_ASSOC);
foreach($res as $r) echo $r['Field'] . "\n";
