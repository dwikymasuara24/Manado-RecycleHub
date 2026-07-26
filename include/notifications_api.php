<?php

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$user_id = $_SESSION['user_id'] ?? 0;

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = getDB();
$last_id = (int)($_GET['last_id'] ?? 0);
$action = $_GET['action'] ?? '';

if ($action === 'mark_read') {
    $notif_id = (int)($_POST['id'] ?? 0);
    if ($notif_id > 0) {
        try {
            $db->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?")
               ->execute([$notif_id, $user_id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    exit;
}

try {
    
    if ($last_id === 0) {
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmtCount->execute([$user_id]);
        $unread_count = (int)$stmtCount->fetchColumn();

        $stmtList = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 5");
        $stmtList->execute([$user_id]);
        $list = $stmtList->fetchAll();

        echo json_encode([
            'success' => true,
            'unread_count' => $unread_count,
            'notifications' => $list,
            'max_id' => !empty($list) ? (int)$list[0]['id'] : 0
        ]);
    } else {
        
        $stmtNew = $db->prepare("SELECT * FROM notifications WHERE user_id = ? AND id > ? ORDER BY id ASC");
        $stmtNew->execute([$user_id, $last_id]);
        $new_notifs = $stmtNew->fetchAll();

        
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmtCount->execute([$user_id]);
        $unread_count = (int)$stmtCount->fetchColumn();

        $max_id = $last_id;
        foreach ($new_notifs as $n) {
            if ((int)$n['id'] > $max_id) {
                $max_id = (int)$n['id'];
            }
        }

        echo json_encode([
            'success' => true,
            'unread_count' => $unread_count,
            'notifications' => $new_notifs,
            'max_id' => $max_id
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
