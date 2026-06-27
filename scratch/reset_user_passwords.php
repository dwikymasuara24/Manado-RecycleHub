<?php
require_once __DIR__ . '/../include/config.php';

try {
    $db = getDB();
    
    // List of users we want to ensure have specific passwords
    $updates = [
        'admin@manadurecyclehub.id' => [
            'name' => 'Super Admin MRH',
            'role' => 'admin',
            'role_id' => 1,
            'password' => 'Admin@123'
        ],
        'esa@gmail.com' => [
            'name' => 'Esa Massing',
            'role' => 'admin',
            'role_id' => 1,
            'password' => 'Admin@123'
        ],
        'egisepang@gmail.com' => [
            'name' => 'Regina Sepang',
            'role' => 'officer',
            'role_id' => 2,
            'password' => 'Officer@123'
        ],
        'warga.demo@manadorecyclehub.id' => [
            'name' => 'Demo Warga',
            'role' => 'warga',
            'role_id' => 3,
            'password' => 'Warga@123'
        ]
    ];
    
    echo "--- Update Password/User Script ---\n";
    
    foreach ($updates as $email => $data) {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        $hash = password_hash($data['password'], PASSWORD_DEFAULT);
        
        if ($user) {
            // Update existing user
            $updateStmt = $db->prepare("UPDATE users SET password_hash = ?, is_active = 1 WHERE id = ?");
            $updateStmt->execute([$hash, $user['id']]);
            echo "Updated password for existing user: {$email} (Password: {$data['password']})\n";
        } else {
            // Create user
            $insertStmt = $db->prepare("INSERT INTO users (role_id, nama, email, password_hash, is_active, email_verified, created_at) VALUES (?, ?, ?, ?, 1, 1, NOW())");
            $insertStmt->execute([$data['role_id'], $data['name'], $email, $hash]);
            $newUid = $db->lastInsertId();
            echo "Created new user: {$email} (Password: {$data['password']})\n";
            
            // If officer, we also need to create a record in officers table
            if ($data['role'] === 'officer') {
                $checkOff = $db->prepare("SELECT id FROM officers WHERE user_id = ?");
                $checkOff->execute([$newUid]);
                if (!$checkOff->fetch()) {
                    $code = 'OFF' . str_pad($newUid, 4, '0', STR_PAD_LEFT);
                    $db->prepare("INSERT INTO officers (user_id, officer_code, nama, status, kendaraan, created_at) VALUES (?, ?, ?, 'aktif', 'Motor — DB 1234 XY', NOW())")
                       ->execute([$newUid, $code, $data['name']]);
                    echo "Created officer record for: {$data['name']}\n";
                }
            }
        }
    }
    
    echo "\n--- Current Active Users in DB ---\n";
    $stmt = $db->query("SELECT u.id, u.nama, u.email, r.name AS role_name, u.is_active FROM users u JOIN roles r ON u.role_id = r.id");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "ID: {$row['id']} | Name: {$row['nama']} | Email: {$row['email']} | Role: {$row['role_name']} | Active: {$row['is_active']}\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
