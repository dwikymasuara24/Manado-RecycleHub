<?php
require_once __DIR__ . '/include/config.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

$db = getDB();
$out = "";
foreach($db->query("SELECT id, nama, email, role_id FROM users")->fetchAll() as $row) {
    $out .= "ID={$row['id']}, nama={$row['nama']}, email={$row['email']}, role={$row['role_id']}\n";
}
file_put_contents('users_list.txt', $out);
echo "Done\n";
