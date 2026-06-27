<?php
require_once __DIR__ . '/../include/config.php';

try {
    $db = getDB();
    
    $updates = [
        'jeksen@mail.com' => 'Officer@123',
        'jeksen2@mail.com' => 'Admin@123'
    ];
    
    echo "--- Update Jeksen Passwords ---\n";
    
    foreach ($updates as $email => $pass) {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $updateStmt = $db->prepare("UPDATE users SET password_hash = ?, is_active = 1 WHERE id = ?");
            $updateStmt->execute([$hash, $user['id']]);
            echo "Updated password for {$email} to: {$pass}\n";
        } else {
            echo "User {$email} not found.\n";
        }
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
