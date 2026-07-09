<?php
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/auth.php';
requireRole('admin');
$page_id    = 'officer_management';
$page_title = 'Petugas';
$db         = getDB();
$csrfToken  = csrfToken();
 
 // ── Auto-migrasi ──────────────────────────────────────────────
 try { $db->exec("ALTER TABLE officers ADD COLUMN officer_type VARCHAR(20) DEFAULT 'Collector'"); } catch (Exception $e) {}

// ── POST handler ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCsrfToken();
    $action = $_POST['action'];

    if ($action === 'save') {
        $id     = (int)($_POST['id'] ?? 0);
        $nama   = trim($_POST['nama'] ?? '');
        $nip    = trim($_POST['nip'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $wa     = trim($_POST['nomor_wa'] ?? '');
        $kend   = trim($_POST['kendaraan'] ?? '');
        $status = $_POST['status'] ?? 'aktif';
        $type   = $_POST['officer_type'] ?? 'Collector';
        $tgl    = $_POST['tanggal_bergabung'] ?: null;

        if (!$nama) {
            flash('danger','Field wajib belum diisi!');
            header('Location: officer_management.php'); exit;
        }

        if ($id) {
            $db->prepare("UPDATE officers SET nama=?,nip=?,kendaraan=?,status=?,tanggal_bergabung=?,officer_type=? WHERE id=?")
               ->execute([$nama,$nip,$kend,$status,$tgl,$type,$id]);
            $uid = $db->query("SELECT user_id FROM officers WHERE id=$id")->fetchColumn();
            if ($uid) {
                if ($email) {
                    $db->prepare("UPDATE users SET nama=?,email=?,nomor_wa=? WHERE id=?")->execute([$nama,$email,$wa,$uid]);
                } else {
                    $db->prepare("UPDATE users SET nama=?,nomor_wa=? WHERE id=?")->execute([$nama,$wa,$uid]);
                }
                
                $new_pw = trim($_POST['password'] ?? '');
                if ($new_pw !== '') {
                    $hash = password_hash($new_pw, PASSWORD_BCRYPT);
                    $db->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $uid]);
                }
            }
            logActivity($db,1,"edit_officer #$id",'officers',$id,[],['nama'=>$nama]);
            flash('success','Data petugas diperbarui!');
        } else {
            $custom_pw = trim($_POST['password'] ?? '');
            $pw       = password_hash($custom_pw !== '' ? $custom_pw : 'Petugas@123', PASSWORD_BCRYPT);
            $emailGen = $email ?: strtolower(str_replace(' ','.',$nama)).'@mrh.id';
            $db->prepare("INSERT INTO users (role_id,nama,email,password_hash,nomor_wa,kota) VALUES (2,?,?,?,?,'Manado')")
               ->execute([$nama,$emailGen,$pw,$wa]);
            $uid      = (int)$db->lastInsertId();
            
            // Generate Code: S01, S02, S03
            $allowedTypes = ['Collector', 'Bin', 'Sack'];
            $safeType = in_array($type, $allowedTypes, true) ? $type : 'Collector';
            $cnt = 1;
            do {
                $code = 'S' . str_pad($cnt, 2, '0', STR_PAD_LEFT);
                $stmtExists = $db->prepare("SELECT COUNT(*) FROM officers WHERE officer_code = ?");
                $stmtExists->execute([$code]);
                $exists = (int)$stmtExists->fetchColumn();
                $cnt++;
            } while ($exists > 0);
            
            $db->prepare("INSERT INTO officers (user_id,officer_code,nama,nip,kendaraan,status,tanggal_bergabung,officer_type) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$uid,$code,$nama,$nip,$kend,$status,$tgl,$safeType]);
            logActivity($db,1,"tambah_officer $code",'officers',(int)$db->lastInsertId(),[],['nama'=>$nama]);
            flash('success',"Petugas $nama ($code) ditambahkan!");
        }
        header('Location: officer_management.php'); exit;
    }

    if ($action === 'delete') {
        $id  = (int)($_POST['id'] ?? 0);
        $uid = $db->query("SELECT user_id FROM officers WHERE id=$id")->fetchColumn();
        $db->prepare("DELETE FROM officers WHERE id=?")->execute([$id]);
        if ($uid) $db->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
        logActivity($db,1,"hapus_officer #$id",'officers',$id);
        flash('success','Petugas dihapus.'); header('Location: officer_management.php'); exit;
    }

    if ($action === 'save_threshold') {
        $days = (int)($_POST['inactivity_threshold_days'] ?? 7);
        $uid  = $_SESSION['user_id'] ?? null;
        $db->prepare("INSERT INTO site_settings (setting_key, setting_value, updated_by) VALUES ('inactivity_threshold_days', ?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?")
           ->execute([$days, $uid, $days, $uid]);
        flash('success', "Batas ketidakaktifan diperbarui menjadi $days hari!");
        header('Location: officer_management.php'); exit;
    }

    if ($action === 'deactivate') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare("UPDATE officers SET status='nonaktif' WHERE id=?")->execute([$id]);
            logActivity($db, $_SESSION['user_id'] ?? 1, "deaktif_officer #$id", 'officers', $id);
            flash('success', 'Petugas berhasil dinonaktifkan.');
        }
        header('Location: officer_management.php'); exit;
    }

    // ── ASSIGN TUGAS (AJAX) ───────────────────────────────
    if ($action === 'assign_task') {
        header('Content-Type: application/json');
        $oid     = (int)($_POST['officer_id']  ?? 0);
        $rids    = json_decode($_POST['request_ids'] ?? '[]', true);
        $tgl     = trim($_POST['tanggal_jemput'] ?? '') ?: null;
        $catatan = trim($_POST['catatan_tugas']  ?? '');
        if (!$oid || empty($rids)) {
            echo json_encode(['ok'=>false,'msg'=>'Pilih petugas dan minimal 1 request!']); exit;
        }
        $extraSet = ', jam_jemput=NULL, is_kendala=0'; $baseParams = [$oid, 'dijadwalkan'];
        if ($tgl)     { $extraSet .= ', tanggal_jemput=?'; $baseParams[] = $tgl; }
        if ($catatan) { $extraSet .= ', catatan_officer=?'; $baseParams[] = $catatan; }
        $count = 0;
        foreach ($rids as $rid) {
            $rid = (int)$rid; if (!$rid) continue;
            $p = array_merge($baseParams, [$rid]);
            $db->prepare("UPDATE pickup_requests SET officer_id=?, status=?, updated_at=NOW()$extraSet WHERE id=? AND status NOT IN ('selesai','dibatalkan')")->execute($p);
            $count++;
        }
        $officerNama = $db->query("SELECT nama FROM officers WHERE id=$oid")->fetchColumn();
        logActivity($db,1,"assign_tugas $count req → $officerNama",'officers',$oid);
        echo json_encode(['ok'=>true,'count'=>$count,'officer_nama'=>$officerNama]);
        exit;
    }

    // ── UNASSIGN REQUEST (AJAX) ───────────────────────────
    if ($action === 'unassign_request') {
        header('Content-Type: application/json');
        $rid = (int)($_POST['request_id'] ?? 0);
        if ($rid) {
            $db->prepare("UPDATE pickup_requests SET officer_id=NULL, status='dikonfirmasi', is_kendala=0, updated_at=NOW() WHERE id=? AND status NOT IN ('selesai','dibatalkan')")->execute([$rid]);
            logActivity($db,1,"unassign_request #$rid",'pickup_requests',$rid);
        }
        echo json_encode(['ok'=>true]); exit;
    }
}

// ── FETCH DATA ───────────────────────────────────────────────
$fStatus = $_GET['status'] ?? '';
$search  = trim($_GET['q'] ?? '');
$where   = '1=1'; $params = [];

if ($search) {
    $where   .= " AND (o.nama LIKE ? OR o.officer_code LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($fStatus) {
    $where .= " AND o.status=?"; $params[] = $fStatus;
}

$stmt = $db->prepare("SELECT o.*, u.email, u.nomor_wa,
    (SELECT COUNT(*) FROM pickup_requests pr WHERE pr.officer_id=o.id) AS total_tugas,
    (SELECT COUNT(*) FROM pickup_requests pr WHERE pr.officer_id=o.id AND pr.status='selesai') AS selesai,
    (SELECT COUNT(*) FROM pickup_requests pr WHERE pr.officer_id=o.id AND pr.status IN ('dikonfirmasi','dijadwalkan','sedang_diproses')) AS aktif,
    (
        SELECT MAX(COALESCE(completed_at, updated_at)) 
        FROM (
            SELECT completed_at, updated_at, officer_id FROM pickup_requests WHERE status='selesai'
            UNION ALL
            SELECT completed_at, updated_at, officer_id FROM cleanup_requests WHERE status='selesai'
        ) t 
        WHERE t.officer_id = o.id
    ) AS last_active_date
    FROM officers o
    JOIN users u ON u.id=o.user_id
    WHERE $where
    ORDER BY o.created_at DESC");
$stmt->execute($params);
$officers = $stmt->fetchAll();

$thresholdDays = (int)($db->query("SELECT setting_value FROM site_settings WHERE setting_key='inactivity_threshold_days'")->fetchColumn() ?: 7);

// ── Stats ringkas ──
$stats = $db->query("SELECT
    COUNT(*) AS total,
    SUM(status='aktif') AS aktif,
    SUM(status='cuti') AS cuti,
    SUM(status='nonaktif') AS nonaktif
    FROM officers")->fetch();

// ── Edit data ──
$editData = null;
if (!empty($_GET['edit'])) {
    $eid      = (int)$_GET['edit'];
    $editData = $db->query("SELECT o.*, u.email, u.nomor_wa FROM officers o JOIN users u ON u.id=o.user_id WHERE o.id=$eid")->fetch();
}

// ── Preview data ──
$previewData  = null;
$pOfficerReqs = null;
if (!empty($_GET['preview'])) {
    $pid         = (int)$_GET['preview'];
    $previewData = $db->query("SELECT o.*, u.email, u.nomor_wa FROM officers o JOIN users u ON u.id=o.user_id WHERE o.id=$pid")->fetch();
    if ($previewData) {
        $pOfficerReqs = $db->query("
            SELECT SUM(t.total) as total, SUM(t.selesai) as selesai, SUM(t.aktif) as aktif
            FROM (
                SELECT COUNT(*) as total,
                    SUM(CASE WHEN status='selesai' THEN 1 ELSE 0 END) as selesai,
                    SUM(CASE WHEN status IN ('dikonfirmasi','dijadwalkan','sedang_diproses','sedang_cleanup') THEN 1 ELSE 0 END) as aktif
                FROM pickup_requests WHERE officer_id=$pid
                UNION ALL
                SELECT COUNT(*) as total,
                    SUM(CASE WHEN status='selesai' THEN 1 ELSE 0 END) as selesai,
                    SUM(CASE WHEN status IN ('dikonfirmasi','dijadwalkan','sedang_diproses','sedang_cleanup') THEN 1 ELSE 0 END) as aktif
                FROM cleanup_requests WHERE officer_id=$pid
            ) t
        ")->fetch();
        $pRecentReqs = $db->query("
            SELECT * FROM (
                SELECT id, request_code, created_at, status, kecamatan, tanggal_jemput as tgl FROM pickup_requests WHERE officer_id=$pid
                UNION ALL
                SELECT id, request_code, created_at, status, kecamatan, tanggal_tugas as tgl FROM cleanup_requests WHERE officer_id=$pid
            ) t ORDER BY created_at DESC LIMIT 5
        ")->fetchAll();
    }
}

$kecamatans = ['Wenang','Malalayang','Tikala','Paal Dua','Bunaken','Singkil','Mapanget','Wanea','Sario','Tuminting'];

// ── Semua request aktif untuk modal assign (termasuk yang sudah di-assign) ──
$assignableReqs = $db->query("
    SELECT pr.id, pr.request_code, pr.nama_pemohon, pr.kecamatan, pr.status,
           pr.tanggal_jemput, pr.jam_jemput, pr.officer_id,
           o.nama AS current_officer
    FROM pickup_requests pr
    LEFT JOIN officers o ON o.id = pr.officer_id
    WHERE pr.status NOT IN ('selesai','dibatalkan')
    ORDER BY pr.officer_id IS NULL DESC, pr.created_at DESC
    LIMIT 150
")->fetchAll();

// ── Helper: inisial nama ──
function getInitials(string $nama): string {
    $words = preg_split('/\s+/', trim(preg_replace('/[^a-zA-Z ]/', '', $nama)));
    $ini   = strtoupper(substr($words[0] ?? '', 0, 1));
    if (count($words) > 1) $ini .= strtoupper(substr(end($words), 0, 1));
    return $ini ?: '??';
}

// ── Helper: avatar warna berdasar nama ──
function avatarColor(string $nama): string {
    $colors = ['#2e7d32','#1565c0','#6a1b9a','#d84315','#00695c','#4527a0','#283593','#37474f'];
    return $colors[crc32($nama) % count($colors)];
}

// ── Helper: completion rate ──
function completionRate(int $selesai, int $total): int {
    return $total > 0 ? (int)round($selesai / $total * 100) : 0;
}

require_once __DIR__ . '/layout/header.php';
?>

<style>
/* ═══════════════════════════════════════════
   PAGE HEADER
═══════════════════════════════════════════ */
.page-header { margin-bottom: 24px; }
.page-header h1 { font-size: 22px; font-weight: 800; color: #1e293b; margin: 0 0 4px; }
.page-header p  { font-size: 13px; color: #94a3b8; margin: 0; }

/* ═══════════════════════════════════════════
   STAT BAR
═══════════════════════════════════════════ */
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 22px; }
.stat-card { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 16px 18px; display: flex; flex-direction: column; gap: 3px; box-shadow: 0 1px 4px rgba(0,0,0,.05); transition: transform .15s, box-shadow .2s; }
.stat-card:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,.09); }
.sc-label { font-size: 10px; font-weight: 700; color: #94a3b8; letter-spacing: .6px; text-transform: uppercase; }
.sc-val   { font-size: 26px; font-weight: 800; color: var(--green-700, #2e7d32); line-height: 1.1; }
.sc-sub   { font-size: 10px; color: #cbd5e1; font-weight: 600; }
.stat-card.warn   .sc-val { color: #d97706; }
.stat-card.info   .sc-val { color: #0284c7; }
.stat-card.muted  .sc-val { color: #94a3b8; }

/* ═══════════════════════════════════════════
   TOOLBAR
═══════════════════════════════════════════ */
.toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
.toolbar-left { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.search-input, .filter-select { border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; font-size: 13px; background: #fff; outline: none; transition: border .2s; }
.search-input:focus, .filter-select:focus { border-color: var(--green-500, #22c55e); }
.search-input { min-width: 200px; }

/* ═══════════════════════════════════════════
   OFFICER CARD GRID — layout utama
═══════════════════════════════════════════ */
.officer-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
    gap: 18px;
}

.officer-card {
    background: #fff;
    border: 1.5px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
    transition: transform .18s, box-shadow .2s, border-color .2s;
    display: flex;
    flex-direction: column;
}
.officer-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 28px rgba(0,0,0,.11);
    border-color: #bbf7d0;
}

/* ── Card header strip ── */
.oc-header {
    padding: 20px 20px 14px;
    display: flex;
    align-items: flex-start;
    gap: 14px;
    border-bottom: 1px solid #f1f5f9;
    position: relative;
}
.oc-avatar {
    width: 52px; height: 52px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; font-weight: 800; color: #fff;
    flex-shrink: 0;
    letter-spacing: 0;
    box-shadow: 0 3px 10px rgba(0,0,0,.18);
}
.oc-info { flex: 1; min-width: 0; }
.oc-nama { font-size: 15px; font-weight: 800; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.oc-code { font-size: 11px; color: var(--green-700, #2e7d32); font-weight: 700; font-family: monospace; margin-top: 1px; }
.oc-nip  { font-size: 10px; color: #94a3b8; font-weight: 600; margin-top: 2px; }

/* Status badge posisi kanan atas */
.oc-status-badge {
    position: absolute; top: 14px; right: 14px;
    font-size: 10px; font-weight: 800; padding: 3px 9px;
    border-radius: 20px; letter-spacing: .3px;
}
.badge-aktif    { background: #dcfce7; color: #166534; }
.badge-cuti     { background: #fef3c7; color: #92400e; }
.badge-nonaktif { background: #fee2e2; color: #991b1b; }

/* ── Card body rows ── */
.oc-body { padding: 14px 20px; flex: 1; display: flex; flex-direction: column; gap: 9px; }

.oc-row {
    display: flex; align-items: center; gap: 9px;
    font-size: 12px; color: #475569;
}
.oc-row .oc-icon { font-size: 14px; flex-shrink: 0; width: 20px; text-align: center; }
.oc-row .oc-text { flex: 1; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.oc-row .oc-text-muted { color: #94a3b8; font-weight: 500; }

/* ── Performance bar ── */
.perf-wrap { margin: 4px 0 2px; }
.perf-label {
    display: flex; justify-content: space-between;
    font-size: 10px; font-weight: 700; color: #94a3b8;
    margin-bottom: 4px;
}
.perf-label span:last-child { color: #16a34a; }
.perf-bar {
    height: 6px; background: #f1f5f9; border-radius: 99px; overflow: hidden;
}
.perf-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #40916c, #74c69d);
    border-radius: 99px;
    transition: width .5s ease;
}

/* ── Task stat row ── */
.oc-task-row {
    display: flex; gap: 8px; margin-top: 2px;
}
.oc-task-chip {
    flex: 1; background: #f8fafc; border: 1px solid #e2e8f0;
    border-radius: 8px; padding: 7px 6px; text-align: center;
}
.oc-task-chip .tc-val { font-size: 17px; font-weight: 800; color: #1e293b; line-height: 1; }
.oc-task-chip .tc-lbl { font-size: 9px; font-weight: 700; color: #94a3b8; letter-spacing: .3px; text-transform: uppercase; margin-top: 2px; }
.oc-task-chip.green { background: #f0fdf4; border-color: #bbf7d0; }
.oc-task-chip.green .tc-val { color: #16a34a; }
.oc-task-chip.blue  { background: #eff6ff; border-color: #bfdbfe; }
.oc-task-chip.blue  .tc-val { color: #1d4ed8; }

/* ── Card footer actions ── */
.oc-footer {
    padding: 12px 16px;
    border-top: 1px solid #f1f5f9;
    display: flex; gap: 6px; align-items: center;
    background: #fafafa;
}
.oc-footer-date {
    font-size: 10px; color: #cbd5e1; font-weight: 600;
    flex: 1;
}
.btn-icon {
    padding: 6px 9px; border-radius: 8px; border: 1px solid #e2e8f0;
    background: #fff; cursor: pointer; transition: all .15s;
    font-size: 13px; line-height: 1; display: inline-flex;
    align-items: center; justify-content: center;
}
.btn-icon:hover              { border-color: #bbf7d0; background: #f0fdf4; }
.btn-icon.confirm-btn:hover  { border-color: #bbf7d0; background: #f0fdf4; color: #16a34a; }
.btn-icon.danger:hover       { border-color: #fca5a5; background: #fff5f5; }

/* ── Empty state ── */
.empty-state {
    grid-column: 1 / -1;
    text-align: center; padding: 60px 0; color: #94a3b8;
}
.empty-state .empty-icon { font-size: 48px; margin-bottom: 12px; }
.empty-state p { font-weight: 600; font-size: 14px; }

/* ═══════════════════════════════════════════
   MODAL
═══════════════════════════════════════════ */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,.5); z-index: 1000; align-items: center; justify-content: center; padding: 16px; backdrop-filter: blur(3px); }
.modal-overlay.open, .modal-overlay[style*="display:flex"] { display: flex; }
.modal { background: #fff; border-radius: 18px; width: 100%; box-shadow: 0 12px 48px rgba(0,0,0,.2); max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; animation: modalIn .22s ease; }
.modal form { display: flex; flex-direction: column; flex: 1; overflow: hidden; min-height: 0; }
@keyframes modalIn { from { opacity: 0; transform: scale(.96) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.modal-header { padding: 18px 24px 14px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; background: #fff; border-radius: 18px 18px 0 0; }
.modal-header h3 { font-size: 15px; font-weight: 800; color: #1e293b; margin: 0; }
.modal-close { background: none; border: none; font-size: 20px; cursor: pointer; color: #94a3b8; padding: 4px 7px; border-radius: 6px; transition: all .15s; line-height: 1; }
.modal-close:hover { color: #ef4444; background: #fee2e2; }
.modal-body { padding: 20px 24px; flex: 1; overflow-y: auto; }
.modal-footer { padding: 14px 24px; border-top: 1px solid #f1f5f9; display: flex; gap: 8px; justify-content: flex-end; background: #fafafa; border-radius: 0 0 18px 18px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-group { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px; }
.form-label { font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .4px; }
.form-input { border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 9px 12px; font-size: 13px; outline: none; transition: border .2s, box-shadow .2s; font-family: inherit; width: 100%; box-sizing: border-box; background: #f8fafc; }
.form-input:focus { border-color: var(--green-500, #22c55e); box-shadow: 0 0 0 3px rgba(34,197,94,.12); background: #fff; }

/* ── Preview modal layout dari detail.php ── */
.detail-layout { display: grid; grid-template-columns: 1.15fr 1.5fr; gap: 20px; align-items: start; }
.dcard { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 14px; }
.dcard:last-child { margin-bottom: 0; }
.dcard h3 { font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; }

.stat-row-p { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 12px; }
.sc2 { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 8px; text-align: center; }
.sc2-num { font-size: 22px; font-weight: 800; color: var(--green-700, #2e7d32); line-height: 1.1; }
.sc2-lbl { font-size: 9px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .4px; margin-top: 3px; }

.saw-crit { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid #f1f5f9; }
.saw-crit:last-child { border-bottom: none; }
.crit-bar { height: 5px; background: #e2e8f0; border-radius: 3px; overflow: hidden; flex: 1; margin: 0 10px; }
.crit-bar-f { height: 100%; background: linear-gradient(90deg, #40916c, #74c69d); border-radius: 3px; }

.preview-row { display: flex; align-items: flex-start; gap: 10px; padding: 7px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
.preview-row:last-child { border-bottom: none; }
.pl { min-width: 110px; font-weight: 700; color: #64748b; font-size: 11px; padding-top: 1px; text-transform: uppercase; letter-spacing: .3px; }
.pv { color: #1e293b; flex: 1; word-break: break-word; font-weight: 600; font-size: 13px; }

/* ── Recent requests mini table ── */
.mini-table { width: 100%; border-collapse: collapse; font-size: 11px; }
.mini-table th { background: #f8fafc; padding: 7px 10px; text-align: left; color: #64748b; font-size: 10px; font-weight: 700; letter-spacing: .4px; text-transform: uppercase; border-bottom: 2px solid #e2e8f0; }
.mini-table td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.mini-table tr:last-child td { border-bottom: none; }

/* ── Completion rate bar ── */
.rate-wrap { background: #f8fafc; border-radius: 10px; padding: 12px 14px; text-align: center; }
.rate-val { font-size: 26px; font-weight: 800; color: var(--green-700, #2e7d32); line-height: 1; }
.rate-lbl { font-size: 11px; color: #94a3b8; font-weight: 600; margin-top: 2px; }
.rate-bar { height: 8px; background: #e2e8f0; border-radius: 99px; overflow: hidden; margin-top: 10px; }
.rate-bar-fill { height: 100%; background: linear-gradient(90deg, #40916c, #74c69d); border-radius: 99px; transition: width .6s ease; }

@media (max-width: 640px) {
    .officer-grid { grid-template-columns: 1fr; }
    .detail-layout { grid-template-columns: 1fr; }
    .form-row { grid-template-columns: 1fr; }
    .modal { max-height: 95vh; margin: 10px; width: calc(100% - 20px); }
    .modal-body { padding: 16px; }
    .modal-footer { padding: 12px 16px; }
    .modal-header { padding: 14px 16px; }
}
</style>

<div class="page-header">
  <h1>👷 Manajemen Petugas (Officer)</h1>
</div>

<!-- ── STAT CARDS ── -->
<div class="stat-grid">
  <div class="stat-card">
    <span class="sc-label">Total Petugas</span>
    <span class="sc-val"><?= (int)$stats['total'] ?></span>
    <span class="sc-sub">terdaftar</span>
  </div>
  <div class="stat-card ok">
    <span class="sc-label">Aktif</span>
    <span class="sc-val"><?= (int)$stats['aktif'] ?></span>
    <span class="sc-sub">bertugas</span>
  </div>
  <div class="stat-card warn">
    <span class="sc-label">Cuti</span>
    <span class="sc-val"><?= (int)$stats['cuti'] ?></span>
    <span class="sc-sub">sementara</span>
  </div>
  <div class="stat-card muted">
    <span class="sc-label">Nonaktif</span>
    <span class="sc-val"><?= (int)$stats['nonaktif'] ?></span>
    <span class="sc-sub">tidak bertugas</span>
  </div>
</div>

<div class="card">
  <!-- Toolbar -->
  <div class="toolbar">
    <div class="toolbar-left">
      <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input class="search-input" name="q" type="text"
               placeholder="🔍 Cari nama / kode..."
               value="<?= htmlspecialchars($search) ?>">
        <select class="filter-select" name="status" onchange="this.form.submit()">
          <option value="">Semua Status</option>
          <option value="aktif"    <?= $fStatus==='aktif'    ?'selected':'' ?>>✅ Aktif</option>
          <option value="cuti"     <?= $fStatus==='cuti'     ?'selected':'' ?>>🟡 Cuti</option>
          <option value="nonaktif" <?= $fStatus==='nonaktif' ?'selected':'' ?>>🔴 Nonaktif</option>
        </select>
        <button type="submit" class="btn btn-outline">Cari</button>
        <?php if ($search || $fStatus): ?>
          <a href="officer_management.php" class="btn btn-outline">✕ Reset</a>
        <?php endif; ?>
      </form>
    </div>
    <div class="toolbar-right" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
      <form method="POST" style="display:flex;gap:6px;align-items:center;background:#f8fafc;padding:4px 8px;border-radius:8px;border:1px solid #e2e8f0">
        <input type="hidden" name="action" value="save_threshold">
        <?= csrfInput() ?>
        <span style="font-size:11px;font-weight:700;color:#64748b;white-space:nowrap">Maks. Inaktif:</span>
        <input class="form-input" name="inactivity_threshold_days" type="number" min="1" max="90" style="width:50px;padding:4px 6px;font-size:12px;background:#fff;border-color:#cbd5e1" value="<?= $thresholdDays ?>">
        <span style="font-size:11px;color:#64748b;white-space:nowrap">Hari</span>
        <button type="submit" class="btn btn-outline" style="padding:4px 8px;font-size:11px;height:auto">Set</button>
      </form>
      <button class="btn btn-primary" onclick="openModal('modalOfficer')">+ Tambah Petugas</button>
    </div>
  </div>

  <!-- ── OFFICER CARD GRID ── -->
  <div class="officer-grid">
    <?php if ($officers): ?>
    <?php foreach ($officers as $o):
      $ini      = getInitials($o['nama'] ?? '');
      $aColor   = avatarColor($o['nama'] ?? '');
      $total    = (int)$o['total_tugas'];
      $selesai  = (int)$o['selesai'];
      $aktif    = (int)$o['aktif'];
      $rate     = completionRate($selesai, $total);
      $stLabel  = match($o['status']) { 'aktif' => '✅ Aktif', 'cuti' => '🟡 Cuti', 'nonaktif' => '🔴 Nonaktif', default => $o['status'] };
      $stClass  = 'badge-'.($o['status'] ?? 'nonaktif');

      // Hitung ketidakaktifan
      $lastActive = $o['last_active_date'];
      $inactiveDays = null;
      $isInactive = false;
      if ($lastActive) {
          $lastActiveTime = strtotime($lastActive);
          $diff = time() - $lastActiveTime;
          $inactiveDays = (int)floor($diff / (60 * 60 * 24));
      } else {
          $joined = $o['tanggal_bergabung'] ?: $o['created_at'];
          if ($joined) {
              $joinedTime = strtotime($joined);
              $diff = time() - $joinedTime;
              $inactiveDays = (int)floor($diff / (60 * 60 * 24));
          }
      }
      if ($inactiveDays !== null && $inactiveDays >= $thresholdDays && $o['status'] === 'aktif') {
          $isInactive = true;
      }
    ?>
    <div class="officer-card" <?= $isInactive ? 'style="border-color:#fca5a5;background:#fffdfd"' : '' ?>>
      <!-- Header -->
      <div class="oc-header">
        <div class="oc-avatar" style="background:<?= $aColor ?>">
          <?= htmlspecialchars($ini) ?>
        </div>
        <div class="oc-info">
          <div class="oc-nama"><?= htmlspecialchars($o['nama'] ?? '-') ?></div>
          <div style="display:flex;align-items:center;gap:5px">
            <div class="oc-code"><?= htmlspecialchars($o['officer_code'] ?? '-') ?></div>
            <span style="font-size:9px;background:#f1f5f9;color:#64748b;padding:1px 5px;border-radius:4px;font-weight:800"><?= $o['officer_type'] ?? 'Collector' ?></span>
          </div>
          <div class="oc-nip">NIP: <?= htmlspecialchars($o['nip'] ?: '—') ?></div>
        </div>
        <span class="oc-status-badge <?= $stClass ?>"><?= $stLabel ?></span>
        <?php if ($isInactive): ?>
          <span class="oc-status-badge badge-nonaktif" style="top: 40px; background:#fff5f5; color:#dc2626; border:1px solid #fca5a5;">⚠️ Inaktif <?= $inactiveDays ?> Hari</span>
        <?php endif; ?>
      </div>

      <!-- Body info rows -->
      <div class="oc-body">

        <div class="oc-row">
          <span class="oc-icon">🚛</span>
          <span class="oc-text <?= empty($o['kendaraan']) ? 'oc-text-muted' : '' ?>">
            <?= htmlspecialchars($o['kendaraan'] ?: '—') ?>
          </span>
        </div>
        <div class="oc-row">
          <span class="oc-icon">📱</span>
          <span class="oc-text <?= empty($o['nomor_wa']) ? 'oc-text-muted' : '' ?>">
            <?= htmlspecialchars($o['nomor_wa'] ?: '—') ?>
          </span>
        </div>
        <div class="oc-row">
          <span class="oc-icon">✉️</span>
          <span class="oc-text oc-text-muted" style="font-size:11px">
            <?= htmlspecialchars($o['email'] ?: '—') ?>
          </span>
        </div>

        <!-- Task chips -->
        <div class="oc-task-row">
          <div class="oc-task-chip">
            <div class="tc-val"><?= $total ?></div>
            <div class="tc-lbl">Total</div>
          </div>
          <div class="oc-task-chip green">
            <div class="tc-val"><?= $selesai ?></div>
            <div class="tc-lbl">Selesai</div>
          </div>
          <div class="oc-task-chip blue">
            <div class="tc-val"><?= $aktif ?></div>
            <div class="tc-lbl">Aktif</div>
          </div>
        </div>

        <!-- Completion rate bar -->
        <div class="perf-wrap">
          <div class="perf-label">
            <span>Completion Rate</span>
            <span><?= $rate ?>%</span>
          </div>
          <div class="perf-bar">
            <div class="perf-bar-fill" style="width:<?= $rate ?>%"></div>
          </div>
        </div>
      </div>

      <!-- Footer actions -->
      <div class="oc-footer">
        <span class="oc-footer-date">
          🗓️ <?= $o['tanggal_bergabung'] ? fmtDate($o['tanggal_bergabung']) : '—' ?>
        </span>
        <a class="btn-icon" href="officer_management.php?preview=<?= $o['id'] ?>" title="Lihat Detail">👁️</a>
        <a class="btn-icon" href="officer_management.php?edit=<?= $o['id'] ?>" title="Edit">✏️</a>
        <?php if ($o['status'] === 'aktif'): ?>
        <?php if ($isInactive): ?>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin menonaktifkan petugas <?= htmlspecialchars($o['nama']) ?> karena tidak aktif?')">
          <input type="hidden" name="action" value="deactivate">
          <input type="hidden" name="id" value="<?= $o['id'] ?>">
          <?= csrfInput() ?>
          <button type="submit" class="btn-icon danger" title="Deaktifkan Petugas" style="border-color:#fca5a5;background:#fff5f5;color:#ef4444">🔴</button>
        </form>
        <?php endif; ?>
        <?php 
        $waClean = preg_replace('/[^0-9]/', '', $o['nomor_wa'] ?? '');
        if ($waClean !== ''):
            if (str_starts_with($waClean, '0')) {
                $waClean = '62' . substr($waClean, 1);
            } elseif (str_starts_with($waClean, '8')) {
                $waClean = '62' . $waClean;
            }
            $waText = urlencode("Halo " . ($o['nama'] ?? '') . ", kami mendeteksi Anda tidak aktif melakukan penjemputan selama beberapa hari di " . SITE_NAME . ". Apakah ada kendala?");
            ?>
            <a href="https://wa.me/<?= $waClean ?>?text=<?= $waText ?>" target="_blank" class="btn-icon" title="Hubungi Petugas (WA)" style="border-color:#bbf7d0;background:#f0fdf4;color:#16a34a">💬</a>
        <?php endif; ?>
        <button class="btn-icon" title="Assign Tugas"
                style="background:#fffbeb;border-color:#fde68a;color:#b45309"
                onclick="openAssignFromOfficer(<?= $o['id'] ?>, '<?= htmlspecialchars($o['nama']) ?>', '<?= htmlspecialchars($o['officer_code']) ?>')">
          📋
        </button>
        <?php endif; ?>
        <button class="btn-icon danger" title="Hapus"
                onclick="delOfficer(<?= $o['id'] ?>, '<?= htmlspecialchars($o['nama']) ?>')">🗑️</button>
      </div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon">👷</div>
      <p>Tidak ada petugas ditemukan<?= ($search||$fStatus) ? ' yang cocok dengan filter' : '' ?>.</p>
      <?php if ($search||$fStatus): ?>
        <a href="officer_management.php" style="color:var(--green-600,#16a34a);font-size:12px;margin-top:6px;display:inline-block">Tampilkan semua</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div style="margin-top:16px;font-size:12px;color:#94a3b8">
    Total: <strong style="color:#334155"><?= count($officers) ?></strong> petugas ditemukan
  </div>
</div>

<!-- ══ ADMIN: MANAJEMEN ZONA & EFISIENSI RUTE REMOVED ══ -->

<!-- ═══════════════════════════════════════════
     MODAL: TAMBAH / EDIT PETUGAS
═══════════════════════════════════════════ -->
<div class="modal-overlay" id="modalOfficer" <?= $editData ? 'style="display:flex"' : '' ?>>
  <div class="modal" style="max-width:600px">
    <div class="modal-header">
      <h3><?= $editData ? '✏️ Edit Petugas: '.htmlspecialchars($editData['nama']) : '➕ Tambah Petugas Baru' ?></h3>
      <a href="officer_management.php" class="modal-close">✕</a>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id"     value="<?= $editData['id'] ?? '' ?>">
      <?= csrfInput() ?>
      <div class="modal-body">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Nama Lengkap *</label>
            <input class="form-input" name="nama" required
                   value="<?= htmlspecialchars($editData['nama'] ?? '') ?>"
                   placeholder="Nama petugas">
          </div>
          <div class="form-group">
            <label class="form-label">NIP</label>
            <input class="form-input" name="nip"
                   value="<?= htmlspecialchars($editData['nip'] ?? '') ?>"
                   placeholder="Nomor Induk Petugas">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Email</label>
            <input class="form-input" name="email" type="email"
                   value="<?= htmlspecialchars($editData['email'] ?? '') ?>"
                   placeholder="email@mrh.id">
          </div>
          <div class="form-group">
            <label class="form-label">Nomor WA</label>
            <input class="form-input" name="nomor_wa"
                   value="<?= htmlspecialchars($editData['nomor_wa'] ?? '') ?>"
                   placeholder="8xxx...">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Kendaraan</label>
            <input class="form-input" name="kendaraan"
                   value="<?= htmlspecialchars($editData['kendaraan'] ?? '') ?>"
                   placeholder="Contoh: Motor (DB 1234 XY)">
          </div>
          <div class="form-group">
            <label class="form-label">Tanggal Bergabung</label>
            <input class="form-input" name="tanggal_bergabung" type="date"
                   value="<?= $editData['tanggal_bergabung'] ?? '' ?>">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Entity Type (Jenis) *</label>
            <select class="form-input" name="officer_type" required>
              <option value="Collector" <?= ($editData['officer_type']??'Collector')==='Collector'?'selected':'' ?>>👤 Collector (Officer / Petugas)</option>
              <option value="Bin"       <?= ($editData['officer_type']??'')==='Bin'      ?'selected':'' ?>>🗑️ Bin (Keranjang Sampah)</option>
              <option value="Sack"      <?= ($editData['officer_type']??'')==='Sack'     ?'selected':'' ?>>💰 Sack (Karung / Titik)</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <select class="form-input" name="status">
              <option value="aktif"    <?= ($editData['status']??'aktif')==='aktif'    ?'selected':'' ?>>✅ Aktif</option>
              <option value="cuti"     <?= ($editData['status']??'')==='cuti'          ?'selected':'' ?>>🟡 Cuti</option>
              <option value="nonaktif" <?= ($editData['status']??'')==='nonaktif'      ?'selected':'' ?>>🔴 Nonaktif</option>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group" style="grid-column: span 2;">
            <label class="form-label">Password <?= $editData ? 'Baru (Kosongkan jika tidak ingin diubah)' : 'Akun (Opsional)' ?></label>
            <input class="form-input" name="password" type="password" placeholder="<?= $editData ? 'Masukkan password baru untuk reset...' : 'Masukkan password (opsional, default: Petugas@123)' ?>">
          </div>
        </div>

        <?php if (!$editData): ?>
        <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:10px;padding:10px 14px;font-size:12px;color:#166534;margin-top:4px">
          <strong>ℹ️</strong> Password default petugas baru: <code style="background:#dcfce7;padding:1px 6px;border-radius:4px;font-weight:700">Petugas@123</code> jika kolom password dikosongkan.
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <a href="officer_management.php" class="btn btn-outline">Batal</a>
        <button type="submit" class="btn btn-primary">💾 Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════
     MODAL: PREVIEW DETAIL — layout dari detail.php
═══════════════════════════════════════════ -->
<?php if ($previewData):
  $pIni    = getInitials($previewData['nama'] ?? '');
  $pColor  = avatarColor($previewData['nama'] ?? '');
  $pTotal  = (int)($pOfficerReqs['total']   ?? 0);
  $pSel    = (int)($pOfficerReqs['selesai'] ?? 0);
  $pAkt    = (int)($pOfficerReqs['aktif']   ?? 0);
  $pRate   = completionRate($pSel, $pTotal);
  $pStClass = 'badge-'.($previewData['status'] ?? 'nonaktif');
  $pStLabel = match($previewData['status']) { 'aktif' => '✅ Aktif', 'cuti' => '🟡 Cuti', 'nonaktif' => '🔴 Nonaktif', default => $previewData['status'] };
?>
<div class="modal-overlay open" id="modalPreview">
  <div class="modal" style="max-width:1040px; width:100%">
    <div class="modal-header">
      <h3>👷 Detail Petugas</h3>
      <a href="officer_management.php" class="modal-close">✕</a>
    </div>
    <div class="modal-body">
      <div class="detail-layout">

        <!-- KOLOM KIRI: profil + SAW-style kriteria -->
        <div>
          <div class="dcard" style="text-align:center;background:#fff">
            <!-- Avatar besar -->
            <div style="width:72px;height:72px;border-radius:50%;background:<?= $pColor ?>;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:800;color:#fff;margin:0 auto 12px;box-shadow:0 4px 16px rgba(0,0,0,.18)">
              <?= htmlspecialchars($pIni) ?>
            </div>
            <div style="font-size:17px;font-weight:800;color:#1e293b;margin-bottom:2px">
              <?= htmlspecialchars($previewData['nama']) ?>
            </div>
            <div style="font-size:12px;font-weight:700;color:var(--green-700,#2e7d32);font-family:monospace;margin-bottom:6px">
              <?= htmlspecialchars($previewData['officer_code'] ?? '-') ?>
            </div>
            <span class="oc-status-badge <?= $pStClass ?>" style="position:static;display:inline-block">
              <?= $pStLabel ?>
            </span>

            <!-- Info rows -->
            <div style="margin-top:16px;text-align:left">
              <?php
              $infoRows = [
                ['🏢', 'Type',        $previewData['officer_type'] ?? 'Collector'],
                ['🚛', 'Kendaraan', $previewData['kendaraan']   ?: '—'],
                ['✉️', 'Email',        $previewData['email']       ?? '—'],
                ['📱', 'WA',          $previewData['nomor_wa']    ?: '—'],
                ['🪪', 'NIP',         $previewData['nip']         ?: '—'],
                ['🗓️', 'Bergabung',   $previewData['tanggal_bergabung'] ? fmtDate($previewData['tanggal_bergabung']) : '—'],
              ];
              foreach ($infoRows as [$ic, $lbl, $val]): ?>
              <div style="display:grid;grid-template-columns:24px 160px 1fr;gap:8px;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:12px;align-items:start">
                <span style="text-align:center;font-size:14px;padding-top:1px"><?= $ic ?></span>
                <span style="color:#94a3b8;font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:0.5px;padding-top:2px"><?= $lbl ?></span>
                <span style="font-weight:600;color:#334155;word-break:break-word;font-size:13px;line-height:1.3"><?= htmlspecialchars($val) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- KOLOM KANAN: statistik + riwayat -->
        <div>
          <!-- Stat chips -->
          <div class="dcard" style="background:#fff">
            <h3>📊 Statistik Performa</h3>
            <div class="stat-row-p">
              <div class="sc2">
                <div class="sc2-num"><?= $pTotal ?></div>
                <div class="sc2-lbl">Total</div>
              </div>
              <div class="sc2" style="border-color:#bbf7d0;background:#f0fdf4">
                <div class="sc2-num" style="color:#16a34a"><?= $pSel ?></div>
                <div class="sc2-lbl">Selesai</div>
              </div>
              <div class="sc2" style="border-color:#bfdbfe;background:#eff6ff">
                <div class="sc2-num" style="color:#1d4ed8"><?= $pAkt ?></div>
                <div class="sc2-lbl">Aktif</div>
              </div>
            </div>
            <!-- Completion rate -->
            <div class="rate-wrap">
              <div class="rate-val"><?= $pRate ?>%</div>
              <div class="rate-lbl">Completion Rate</div>
              <div class="rate-bar">
                <div class="rate-bar-fill" style="width:<?= $pRate ?>%"></div>
              </div>
            </div>
          </div>

          <!-- Riwayat request terbaru -->
          <div class="dcard" style="background:#fff">
            <h3>📋 Riwayat Request Terbaru</h3>
            <?php if (!empty($pRecentReqs)): ?>
            <div style="display:flex;flex-direction:column;gap:8px;margin-top:12px">
              <?php foreach ($pRecentReqs as $pr):
                $prSt = $pr['status'];
                $prDot = match($prSt) {
                  'menunggu'        => '🟡', 'dikonfirmasi' => '🔵',
                  'dijadwalkan'     => '🟣', 'dalam_perjalanan' => '🛵', 'sedang_diproses' => '🟠',
                  'selesai'         => '🟢', 'dibatalkan'  => '🔴', default => '⚪'
                };
              ?>
              <div style="display:flex;justify-content:space-between;align-items:center;padding:12px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;transition:border-color .2s">
                <div>
                  <div style="font-family:monospace;font-weight:800;color:var(--green-700,#2e7d32);font-size:13px">
                    <?= htmlspecialchars($pr['request_code'] ?? '#'.$pr['id']) ?>
                  </div>
                  <div style="font-size:11px;color:#64748b;font-weight:600;margin-top:4px">
                    📍 <?= htmlspecialchars($pr['kecamatan'] ?? '—') ?>
                  </div>
                </div>
                <div style="text-align:right">
                  <div style="font-size:10px;font-weight:800;padding:4px 8px;border-radius:6px;background:#fff;border:1px solid #e2e8f0;display:inline-block;margin-bottom:6px;color:#334155;box-shadow:0 1px 2px rgba(0,0,0,.03)">
                    <?= $prDot ?> <?= ucfirst(str_replace('_',' ',$prSt)) ?>
                  </div>
                  <div style="font-size:10px;color:#94a3b8;font-weight:600">
                    🗓️ <?= !empty($pr['tgl']) && $pr['tgl'] !== '0000-00-00' ? date('d M Y', strtotime($pr['tgl'])) : '—' ?>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="text-align:center;padding:20px 0;color:#94a3b8;font-size:12px;font-weight:600">
              Belum ada request yang ditugaskan ke petugas ini.
            </div>
            <?php endif; ?>
          </div>
        </div>

      </div><!-- /detail-layout -->
    </div>
    <div class="modal-footer">
      <a href="officer_management.php" class="btn btn-outline">Tutup</a>
      <?php if ($previewData['status'] === 'aktif'): ?>
      <button class="btn btn-outline"
              style="background:#fffbeb;color:#b45309;border-color:#fde68a;font-weight:700"
              onclick="closeModal('modalPreview');openAssignFromOfficer(<?= $previewData['id'] ?>,'<?= htmlspecialchars($previewData['nama']) ?>','<?= htmlspecialchars($previewData['officer_code']??'') ?>')">
        📋 Assign Tugas
      </button>
      <?php endif; ?>
      <a href="req_management.php?status=dijadwalkan" class="btn btn-outline" style="font-size:12px">🗂️ Lihat di Req. Mgmt</a>
      <a href="officer_management.php?edit=<?= $previewData['id'] ?>" class="btn btn-primary">✏️ Edit Petugas</a>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════
     CSS TAMBAHAN: ASSIGN TUGAS
═══════════════════════════════════════════ -->
<style>
.assign-req-item {
    display:flex;align-items:center;gap:10px;
    padding:9px 12px;border-radius:8px;border:1.5px solid #e2e8f0;
    margin-bottom:6px;cursor:pointer;transition:all .15s;
    background:#fff;
}
.assign-req-item:hover { border-color:#bbf7d0;background:#f0fdf4; }
.assign-req-item.selected { border-color:#2e7d32;background:#f0fdf4; }
.assign-req-item input[type=checkbox] { width:15px;height:15px;accent-color:#2e7d32;flex-shrink:0; }
.assign-req-code { font-size:11px;font-weight:800;color:#2e7d32;font-family:monospace;white-space:nowrap; }
.assign-req-nama { font-size:12px;font-weight:600;color:#1e293b;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
.assign-req-kec  { font-size:10px;color:#94a3b8;white-space:nowrap; }
.assign-req-status { font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;white-space:nowrap; }
.assign-filter-bar { display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center; }
.assign-filter-bar input,.assign-filter-bar select {
    border:1.5px solid #e2e8f0;border-radius:7px;padding:6px 10px;font-size:12px;
    outline:none;background:#fff;font-family:inherit;
}
.assign-filter-bar input:focus,.assign-filter-bar select:focus { border-color:#2e7d32; }
.assign-select-all-bar {
    display:flex;align-items:center;gap:8px;
    padding:6px 10px;background:#f8fafc;border-radius:7px;
    font-size:12px;font-weight:700;color:#475569;margin-bottom:8px;
}
.assign-count-badge {
    background:#2e7d32;color:#fff;border-radius:20px;
    padding:2px 9px;font-size:11px;font-weight:800;
}
</style>

<!-- ═══════════════════════════════════════════
     MODAL: ASSIGN TUGAS KE OFFICER
═══════════════════════════════════════════ -->
<div class="modal-overlay" id="modalAssignTask">
  <div class="modal" style="max-width:640px">
    <div class="modal-header">
      <h3>📋 Assign Tugas — <span id="assignOfficerLabel" style="color:#2e7d32"></span></h3>
      <button class="modal-close" onclick="closeModal('modalAssignTask')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="assignOfficerIdField">

      <!-- Tab filter: Belum Assign / Semua -->
      <div style="display:flex;gap:6px;margin-bottom:12px;border-bottom:2px solid #f0f0f0;padding-bottom:8px">
        <button id="tabBelumAssign" onclick="filterAssignTab('unassigned')"
          style="padding:5px 14px;border-radius:6px;border:none;font-size:12px;font-weight:700;cursor:pointer;background:#1c6434;color:#fff">
          ⚡ Belum Di-assign
        </button>
        <button id="tabSemuaReq" onclick="filterAssignTab('all')"
          style="padding:5px 14px;border-radius:6px;border:1.5px solid #e2e8f0;font-size:12px;font-weight:700;cursor:pointer;background:#fff;color:#64748b">
          📋 Semua Request Aktif
        </button>
        <span style="margin-left:auto;font-size:11px;color:#94a3b8;align-self:center">
          <span id="visibleCount">0</span> request ditampilkan
        </span>
      </div>

      <!-- Filter bar -->
      <div class="assign-filter-bar">
        <input type="text" id="assignSearchReq" placeholder="🔍 Cari nama / kode..." oninput="filterAssignReqs()" style="flex:1;min-width:150px">
        <select id="assignFilterKec" onchange="filterAssignReqs()">
          <option value="">Semua Kecamatan</option>
          <?php foreach ($kecamatans as $k): ?>
          <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($k) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Select all bar -->
      <div class="assign-select-all-bar">
        <input type="checkbox" id="assignCheckAll" onchange="toggleAssignAll(this.checked)">
        <label for="assignCheckAll" style="cursor:pointer">Pilih Semua</label>
        <span class="assign-count-badge" id="assignSelCount">0</span>
        <span style="font-weight:500;color:#94a3b8">request dipilih</span>
      </div>

      <!-- Daftar request -->
      <div id="assignReqList" style="max-height:300px;overflow-y:auto;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px">
        <?php if (!empty($assignableReqs)): ?>
          <?php foreach ($assignableReqs as $ur):
            $urSt = $ur['status'];
            $urStColor = match($urSt){
              'menunggu'     =>'background:#fef3c7;color:#92400e',
              'dikonfirmasi' =>'background:#dbeafe;color:#1e40af',
              'dijadwalkan'  =>'background:#ede9fe;color:#5b21b6',
              default        =>'background:#f3f4f6;color:#374151'
            };
            $isAssigned = !empty($ur['officer_id']);
          ?>
          <div class="assign-req-item"
               data-id="<?= $ur['id'] ?>"
               data-code="<?= htmlspecialchars($ur['request_code']) ?>"
               data-nama="<?= htmlspecialchars(strtolower($ur['nama_pemohon'])) ?>"
               data-kec="<?= htmlspecialchars($ur['kecamatan'] ?? '') ?>"
               data-assigned="<?= $isAssigned ? '1' : '0' ?>"
               onclick="toggleAssignItem(this)">
            <input type="checkbox" class="assign-req-cb" value="<?= $ur['id'] ?>" onclick="event.stopPropagation();toggleAssignItem(this.closest('.assign-req-item'))">
            <div style="flex:1;min-width:0">
              <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                <span class="assign-req-code"><?= htmlspecialchars($ur['request_code']) ?></span>
                <span class="assign-req-status" style="<?= $urStColor ?>"><?= ucfirst(str_replace('_',' ',$urSt)) ?></span>
                <?php if($isAssigned): ?>
                <span style="font-size:10px;background:#fff3e0;color:#e65100;padding:2px 7px;border-radius:8px;font-weight:700">
                  👷 <?= htmlspecialchars($ur['current_officer']) ?>
                </span>
                <?php endif; ?>
              </div>
              <div style="display:flex;gap:8px;margin-top:3px;align-items:center;flex-wrap:wrap">
                <span class="assign-req-nama"><?= htmlspecialchars($ur['nama_pemohon']) ?></span>
                <span class="assign-req-kec">📍 <?= htmlspecialchars($ur['kecamatan'] ?? '-') ?></span>
                <?php if($ur['tanggal_jemput']): ?>
                <span style="font-size:10px;color:#94a3b8">🗓️ <?= date('d M', strtotime($ur['tanggal_jemput'])) ?></span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div style="text-align:center;padding:30px 0;color:#94a3b8;font-size:13px;font-weight:600">
            <div style="font-size:32px;margin-bottom:8px">🎉</div>
            Semua request sudah selesai atau dibatalkan!
          </div>
        <?php endif; ?>
      </div>

      <!-- Catatan -->
      <div class="form-group" style="margin-top:14px;margin-bottom:0">
        <label class="form-label">Catatan Tugas</label>
        <textarea class="form-input" id="assignTaskCatatan" rows="2"
                  placeholder="Instruksi khusus untuk petugas..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modalAssignTask')">Batal</button>
      <button class="btn btn-primary" id="btnDoAssignTask" onclick="doAssignTask()">
        📋 Simpan Penugasan (<span id="assignTaskCount">0</span>)
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════
     MODAL: HAPUS
═══════════════════════════════════════════ -->
<div class="modal-overlay" id="modalDelete">
  <div class="modal" style="max-width:400px">
    <div class="modal-header">
      <h3>🗑️ Konfirmasi Hapus</h3>
      <button class="modal-close" onclick="closeModal('modalDelete')">✕</button>
    </div>
    <div class="modal-body">
      <p style="font-size:14px;color:#334155;font-weight:600;margin:0" id="deleteMsg"></p>
      <p style="font-size:12px;color:#ef4444;margin-top:8px;font-weight:600">
        ⚠️ Data user terkait juga akan dihapus. Tindakan ini tidak bisa dibatalkan.
      </p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modalDelete')">Batal</button>
      <form method="POST" id="deleteForm" style="display:inline">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="deleteId">
        <?= csrfInput() ?>
        <button type="submit" class="btn btn-danger">Ya, Hapus</button>
      </form>
    </div>
  </div>
</div>

<script>
function delOfficer(id, nama) {
  document.getElementById('deleteMsg').textContent =
    'Hapus petugas "' + nama + '"? Data user terkait juga akan dihapus.';
  document.getElementById('deleteId').value = id;
  openModal('modalDelete');
}

// ESC & overlay click close
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => {
    if (e.target === el && el.id !== 'modalPreview') closeModal(el.id);
  });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay[style*="display:flex"]')
            .forEach(m => { if (m.id !== 'modalPreview') closeModal(m.id); });
  }
});

/* ══════════════════════════════════════════════
   ASSIGN TASK — JS
══════════════════════════════════════════════ */

let _assignTab = 'unassigned'; // default: tampilkan yang belum di-assign

function filterAssignTab(tab) {
  _assignTab = tab;
  // Update tab button styles
  const btnU = document.getElementById('tabBelumAssign');
  const btnA = document.getElementById('tabSemuaReq');
  if (tab === 'unassigned') {
    btnU.style.cssText = 'padding:5px 14px;border-radius:6px;border:none;font-size:12px;font-weight:700;cursor:pointer;background:#1c6434;color:#fff';
    btnA.style.cssText = 'padding:5px 14px;border-radius:6px;border:1.5px solid #e2e8f0;font-size:12px;font-weight:700;cursor:pointer;background:#fff;color:#64748b';
  } else {
    btnA.style.cssText = 'padding:5px 14px;border-radius:6px;border:none;font-size:12px;font-weight:700;cursor:pointer;background:#1c6434;color:#fff';
    btnU.style.cssText = 'padding:5px 14px;border-radius:6px;border:1.5px solid #e2e8f0;font-size:12px;font-weight:700;cursor:pointer;background:#fff;color:#64748b';
  }
  filterAssignReqs();
}

function openAssignFromOfficer(officerId, officerNama, officerCode) {
  document.getElementById('assignOfficerIdField').value = officerId;
  document.getElementById('assignOfficerLabel').textContent = officerNama + ' (' + officerCode + ')';
  // Reset pilihan
  document.querySelectorAll('.assign-req-cb').forEach(cb => {
    cb.checked = false;
    cb.closest('.assign-req-item').classList.remove('selected');
  });
  document.getElementById('assignCheckAll').checked = false;
  document.getElementById('assignTaskCatatan').value = '';
  document.getElementById('assignSearchReq').value  = '';
  document.getElementById('assignFilterKec').value  = '';
  _assignTab = 'unassigned';
  filterAssignTab('unassigned');
  updateAssignCount();
  openModal('modalAssignTask');
}

function toggleAssignItem(item) {
  var cb = item.querySelector('.assign-req-cb');
  cb.checked = !cb.checked;
  item.classList.toggle('selected', cb.checked);
  updateAssignCount();
  document.getElementById('assignCheckAll').checked = false;
}

function toggleAssignAll(checked) {
  document.querySelectorAll('.assign-req-item:not([style*="display:none"]) .assign-req-cb').forEach(cb => {
    cb.checked = checked;
    cb.closest('.assign-req-item').classList.toggle('selected', checked);
  });
  updateAssignCount();
}

function updateAssignCount() {
  var n = document.querySelectorAll('.assign-req-cb:checked').length;
  document.getElementById('assignSelCount').textContent  = n;
  document.getElementById('assignTaskCount').textContent = n;
}

function filterAssignReqs() {
  var q   = document.getElementById('assignSearchReq').value.toLowerCase();
  var kec = document.getElementById('assignFilterKec').value.toLowerCase();
  var visible = 0;
  document.querySelectorAll('.assign-req-item').forEach(item => {
    var matchTab = _assignTab === 'all' || item.dataset.assigned === '0';
    var matchQ   = !q   || item.dataset.code.toLowerCase().includes(q) || item.dataset.nama.includes(q);
    var matchKec = !kec || item.dataset.kec.toLowerCase() === kec;
    var show = matchTab && matchQ && matchKec;
    item.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  document.getElementById('visibleCount').textContent = visible;
}

async function doAssignTask() {
  var oid  = document.getElementById('assignOfficerIdField').value;
  var cbs  = [...document.querySelectorAll('.assign-req-cb:checked')];
  var ids  = cbs.map(cb => parseInt(cb.value));
  var cat  = document.getElementById('assignTaskCatatan').value;

  if (!ids.length) { showToast('danger', 'Pilih minimal 1 request!'); return; }

  var btn = document.getElementById('btnDoAssignTask');
  btn.textContent = 'Memproses...'; btn.disabled = true;

  try {
    var fd = new FormData();
    fd.append('action',         'assign_task');
    fd.append('officer_id',     oid);
    fd.append('request_ids',    JSON.stringify(ids));
    fd.append('catatan_tugas',  cat);
    fd.append('csrf_token', <?= json_encode($csrfToken) ?>);

    var res  = await fetch('officer_management.php', {method:'POST', body:fd});
    var data = await res.json();
    if (data.ok) {
      showToast('success', data.count + ' request berhasil di-assign ke ' + data.officer_nama + '!');
      closeModal('modalAssignTask');
      setTimeout(() => location.reload(), 900);
    } else {
      showToast('danger', data.msg || 'Gagal assign!');
    }
  } catch(e) { showToast('danger', 'Error: ' + e.message); }
  btn.innerHTML = '📋 Simpan Penugasan (<span id="assignTaskCount">0</span>)';
  btn.disabled = false;
}
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
