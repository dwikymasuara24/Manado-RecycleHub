<?php
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/auth.php';

$pdo = getDB();

echo "DB=" . $pdo->query("SELECT DATABASE()")->fetchColumn() . PHP_EOL;
echo "roles:" . PHP_EOL;
foreach ($pdo->query("SELECT id,name FROM roles ORDER BY id") as $r) {
    echo $r['id'] . '|' . $r['name'] . PHP_EOL;
}
echo "users:" . PHP_EOL;
foreach ($pdo->query("SELECT id,role_id,nama,email,is_active FROM users ORDER BY id") as $u) {
    echo $u['id'] . '|' . $u['role_id'] . '|' . $u['nama'] . '|' . $u['email'] . '|' . $u['is_active'] . '|len=' . strlen($u['email']) . '|hex=' . bin2hex($u['email']) . PHP_EOL;
}
echo "lookup exact:" . PHP_EOL;
$stmt = $pdo->prepare("SELECT u.id,u.email,u.password_hash,u.is_active,r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=?");
$stmt->execute(['warga.demo@manadorecyclehub.id']);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
var_export($row);
echo PHP_EOL;
if ($row) {
    echo 'password_verify=' . (password_verify('Warga@123', $row['password_hash']) ? 'true' : 'false') . PHP_EOL;
}
echo "lookup like:" . PHP_EOL;
$stmt2 = $pdo->query("SELECT id,email FROM users WHERE email LIKE '%warga.demo%'");
var_export($stmt2->fetchAll(PDO::FETCH_ASSOC));
echo PHP_EOL;

$ok = attemptLogin($pdo, 'warga.demo@manadorecyclehub.id', 'Warga@123');
echo 'attemptLogin=' . ($ok ? 'true' : 'false') . PHP_EOL;
echo 'session=' . json_encode($_SESSION, JSON_UNESCAPED_SLASHES) . PHP_EOL;
