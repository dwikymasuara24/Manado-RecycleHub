<?php
// officer/api.php — AJAX handler untuk officer console
require_once __DIR__ . '/../include/auth.php';
requireRole('officer');
header('Content-Type: application/json');

$db = getDB();
$officerId = (int)($_SESSION['officer_id'] ?? 0);

if (!$officerId) {
    echo json_encode(['ok' => false, 'error' => 'Sesi petugas tidak valid. Silakan login kembali.']);
    exit;
}

$action = $_POST['ajax'] ?? '';

// ── Update status pickup ──────────────────────────────────────
if ($action === 'update_status') {
    $pid         = (int)($_POST['pickup_id'] ?? 0);
    $status      = $_POST['status'] ?? '';
    $catatan     = trim($_POST['catatan_officer'] ?? '');
    $berat       = trim($_POST['berat_aktual'] ?? '');
    $price       = trim($_POST['price_per_kg'] ?? '');
    $pickup_type = trim($_POST['pickup_type'] ?? '');
    $valid       = ['dijadwalkan','dalam_perjalanan','sedang_diproses','selesai','dibatalkan'];
    if ($pid && in_array($status, $valid)) {
        $isKendala = (int)($_POST['is_kendala'] ?? 0);
        $extra = ', is_kendala=?'; $params = [$status, $isKendala];
        if ($status === 'selesai') {
            $extra .= ', completed_at=IF(completed_at IS NULL,NOW(),completed_at)';
            if ($berat !== '') { $extra .= ', berat_total_kg=?'; $params[] = (float)$berat; }
            if ($price !== '') { $extra .= ', price_per_kg=?'; $params[] = (float)$price; }
        }
        if ($status === 'sedang_diproses') $extra .= ', confirmed_at=IF(confirmed_at IS NULL,NOW(),confirmed_at)';
        if (isset($_POST['catatan_officer'])) { $extra .= ', catatan_officer=?'; $params[] = $catatan; }
        
        // Add pickup_type if present
        $extra .= ', pickup_type=?'; $params[] = $pickup_type !== '' ? $pickup_type : null;

        $params[] = $pid;
        try {
            $db->prepare("UPDATE pickup_requests SET status=?, updated_at=NOW()$extra WHERE id=? AND officer_id=$officerId")->execute($params);
            
            // If item_weights are passed, update them
            if (isset($_POST['item_weights'])) {
                $itemWeights = json_decode($_POST['item_weights'], true);
                if (is_array($itemWeights)) {
                    $stmtUpdateItem = $db->prepare("UPDATE pickup_request_items SET aktual_kg = ? WHERE id = ? AND pickup_id = ?");
                    foreach ($itemWeights as $iw) {
                        $iwId = (int)($iw['id'] ?? 0);
                        $iwVal = $iw['weight'] !== '' ? (float)$iw['weight'] : null;
                        if ($iwId) {
                            $stmtUpdateItem->execute([$iwVal, $iwId, $pid]);
                        }
                    }
                }
            }

            triggerWhatsAppOnStatusChange($db, $pid, $status, 'daur_ulang');
            if ($status === 'selesai') {
                recordWeighing($db, $pid);
            }

            // Kirim notifikasi ke Admin
            try {
                $req_code = $db->query("SELECT request_code FROM pickup_requests WHERE id = $pid")->fetchColumn();
                $officer_name = $db->query("SELECT u.nama FROM users u JOIN officers o ON o.user_id = u.id WHERE o.id = $officerId")->fetchColumn();
                $status_labels = [
                    'dalam_perjalanan' => 'sedang menuju lokasi',
                    'sedang_diproses' => 'sedang memproses',
                    'selesai' => 'menyelesaikan tugas',
                    'dibatalkan' => 'membatalkan tugas'
                ];
                $lbl = $status_labels[$status] ?? "mengubah status ke $status";
                createNotification($db, 'admin', 'Update Petugas', "Petugas $officer_name $lbl untuk request $req_code.", 'pickup', $pid, 'pickup_requests');
            } catch(Exception $ex) {}

            logActivity($db, $officerId, ($isKendala ? "officer_report_kendala #$pid" : "officer_update #$pid → $status"), 'pickup_requests', $pid);
            echo json_encode(['ok'=>true,'status'=>$status]); exit;
        } catch (Exception $e) {
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
        }
    }
    echo json_encode(['ok'=>false,'error'=>'Invalid params']); exit;
}

// ── Update lokasi GPS ─────────────────────────────────────────
if ($action === 'update_location') {
    $lat = (float)($_POST['lat'] ?? 0);
    $lng = (float)($_POST['lng'] ?? 0);
    if ($lat && $lng) {
        try { $db->prepare("UPDATE officers SET last_lat=?,last_lng=?,last_seen_at=NOW() WHERE id=?")->execute([$lat,$lng,$officerId]); } catch(Exception $e){}
    }
    echo json_encode(['ok'=>true]); exit;
}

// ── Get request details ────────────────────────────────────────
if ($action === 'get_details') {
    $id = (int)($_POST['id'] ?? 0);
    $type = $_POST['type'] ?? '';
    
    if ($id && $type === 'daur_ulang') {
        $req = $db->prepare("SELECT * FROM pickup_requests WHERE id = ?");
        $req->execute([$id]);
        $data = $req->fetch(PDO::FETCH_ASSOC);
        
        if ($data) {
            $item_stmt = $db->prepare("SELECT pri.id, pri.estimasi_kg, pri.aktual_kg, pri.catatan, wc.name AS category_name FROM pickup_request_items pri JOIN waste_categories wc ON pri.category_id = wc.id WHERE pri.pickup_id = ?");
            $item_stmt->execute([$id]);
            $data['items'] = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true, 'data'=>$data]); exit;
        }
    } elseif ($id && $type === 'cleanup') {
        $req = $db->prepare("SELECT * FROM cleanup_requests WHERE id = ?");
        $req->execute([$id]);
        $data = $req->fetch(PDO::FETCH_ASSOC);
        
        if ($data) {
            $item_stmt = $db->prepare("SELECT ci.berat_kg, ci.catatan, wc.name AS category_name FROM cleanup_items ci JOIN waste_categories wc ON ci.category_id = wc.id WHERE ci.cleanup_id = ?");
            $item_stmt->execute([$id]);
            $data['items'] = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true, 'data'=>$data]); exit;
        }
    }
    echo json_encode(['ok'=>false, 'error'=>'Request not found']); exit;
}

// ── Revert/Hapus Riwayat Selesai ─────────────────────────────
if ($action === 'delete_riwayat') {
    $id = (int)($_POST['id'] ?? 0);
    $type = $_POST['type'] ?? '';
    
    if ($id && $type) {
        $db->beginTransaction();
        try {
            if ($type === 'daur_ulang') {
                $stmtCheck = $db->prepare("SELECT id FROM pickup_requests WHERE id = ? AND officer_id = ?");
                $stmtCheck->execute([$id, $officerId]);
                if (!$stmtCheck->fetchColumn()) {
                    echo json_encode(['ok'=>false, 'error'=>'Tugas tidak ditemukan atau bukan milik Anda.']);
                    exit;
                }
                
                $db->prepare("DELETE FROM weighing_records WHERE pickup_request_id = ?")->execute([$id]);
                $db->prepare("UPDATE pickup_requests SET status='dijadwalkan', berat_total_kg=NULL, completed_at=NULL WHERE id=?")->execute([$id]);
                $db->prepare("UPDATE pickup_request_items SET aktual_kg=0 WHERE pickup_id=?")->execute([$id]);
            } elseif ($type === 'cleanup') {
                $stmtCheck = $db->prepare("SELECT id FROM cleanup_requests WHERE id = ? AND officer_id = ?");
                $stmtCheck->execute([$id, $officerId]);
                if (!$stmtCheck->fetchColumn()) {
                    echo json_encode(['ok'=>false, 'error'=>'Tugas tidak ditemukan atau bukan milik Anda.']);
                    exit;
                }
                
                $db->prepare("DELETE FROM weighing_records WHERE cleanup_request_id = ?")->execute([$id]);
                $db->prepare("UPDATE cleanup_requests SET status='dijadwalkan', biaya_aktual=NULL, completed_at=NULL WHERE id=?")->execute([$id]);
                $db->prepare("UPDATE cleanup_items SET berat_kg=0 WHERE cleanup_id=?")->execute([$id]);
            }
            $db->commit();
            logActivity($db, $officerId, "officer_delete_riwayat #$id ($type)", ($type === 'daur_ulang' ? 'pickup_requests' : 'cleanup_requests'), $id);
            echo json_encode(['ok'=>true]); exit;
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]); exit;
        }
    }
    echo json_encode(['ok'=>false, 'error'=>'Invalid params']); exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
