<?php

require_once __DIR__ . '/include/config.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

$emails = ['admin@manadurecyclehub.id', 'admin@admin.com']; 
$password_baru = 'admin123'; 

$hash = password_hash($password_baru, PASSWORD_BCRYPT);

try {
    $db = getDB();
    
    $check = $db->prepare("SELECT id, nama, email FROM users WHERE email IN (?, ?)");
    $check->execute($emails);
    $users = $check->fetchAll();
    
    if ($users) {
        foreach ($users as $user) {
            $stmt = $db->prepare("UPDATE users SET password_hash = ?, is_active = 1 WHERE id = ?");
            $stmt->execute([$hash, $user['id']]);
            echo "✅ Sukses Mereset Password Akun {$user['nama']} ({$user['email']}) -> $password_baru\n";
        }
    } else {
        echo "❌ Tidak ada user admin ditemukan.\n";
    }
} catch (Exception $e) {
    echo "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 40px auto; padding: 20px; border: 1.5px solid #cbd5e1; background: #f8fafc; border-radius: 8px;'>";
    echo "<h3 style='color: #64748b; margin-top: 0;'>❌ Terjadi Kesalahan Database</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
