<?php
require_once __DIR__ . '/../include/config.php';
$pdo = getDB();
try {
    $st = $pdo->query("DESCRIBE officers");
    $res = $st->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents(__DIR__ . '/columns_result.txt', print_r($res, true));
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/columns_result.txt', "ERROR: " . $e->getMessage() . "\n");
}
echo "Done\n";
