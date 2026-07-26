<?php

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireLogin() {
    if (empty($_SESSION['user_id'])) {
        flash('danger', 'Silakan login terlebih dahulu.');
        header('Location: ' . baseUrl('login.php'));
        exit;
    }
}

function requireRole(string $requiredRole) {
    requireLogin();

    $userRole = $_SESSION['role_name'] ?? '';

    
    $aliases = ['petugas' => 'officer', 'administrator' => 'admin'];
    $userRole     = $aliases[$userRole]     ?? $userRole;
    $requiredRole = $aliases[$requiredRole] ?? $requiredRole;

    
    $_SESSION['role_name'] = $userRole;

    
    if ($userRole === $requiredRole) return;

    
    switch ($userRole) {
        case 'admin':
            header('Location: ' . baseUrl('admin/dashboard')); break;
        case 'officer':
            header('Location: ' . baseUrl('officer/dashboard')); break;
        default:
            header('Location: ' . baseUrl('index')); break;
    }
    exit;
}

function csrfToken(): string {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrfInput(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function requireCsrfToken(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $sessionToken = $_SESSION['_csrf_token'] ?? '';
    $requestToken  = $_POST['csrf_token'] ?? '';

    if (!$sessionToken || !$requestToken || !hash_equals($sessionToken, $requestToken)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

function attemptLogin(PDO $db, string $email, string $password): bool {
    $stmt = $db->prepare("SELECT u.*, r.name as role_name 
                          FROM users u 
                          JOIN roles r ON r.id = u.role_id 
                          WHERE u.email = ? AND u.is_active = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);

        
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['role_id'] = (int)$user['role_id'];
        $_SESSION['role_name'] = $user['role_name'];
        $_SESSION['user_nama'] = $user['nama'];
        $_SESSION['user_email'] = $user['email'];

        
        if ($user['role_name'] === 'officer') {
            $off = $db->prepare("SELECT id FROM officers WHERE user_id = ?");
            $off->execute([$user['id']]);
            $officerId = $off->fetchColumn();
            if ($officerId) {
                $_SESSION['officer_id'] = (int)$officerId;
            }
        }

        
        $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
        
        logActivity($db, $user['id'], 'login', 'users', $user['id']);
        return true;
    }

    return false;
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function currentUserId(): ?int {
    return $_SESSION['user_id'] ?? null;
}

function currentUserRole(): ?string {
    return $_SESSION['role_name'] ?? null;
}
