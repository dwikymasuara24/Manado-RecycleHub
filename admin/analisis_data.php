<?php
// ============================================================
//  analisis_data.php — Admin Panel: Analisis Data
//  Manado Recycle Hub
//  Sinkron penuh dengan kuesioner.php (user console)
//  Membaca survey_responses + pickup_requests
// ============================================================
require_once __DIR__ . '/../include/config.php';
$page_id    = 'analisis_data';
$page_title = 'Analisis Data';
$db         = getDB();

// ── Auto-migrasi: pastikan tabel survey_responses ada & memiliki kolom terbaru ────────
$db->exec("CREATE TABLE IF NOT EXISTS survey_responses (
    id                         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    response_code              VARCHAR(20)  NULL UNIQUE,
    q1_sampah_mendesak         VARCHAR(10)  NULL,
    q2_paham_3r                VARCHAR(10)  NULL,
    q3_daur_ulang_rumah        VARCHAR(10)  NULL,
    q4_pilah_organik_anorganik VARCHAR(10)  NULL,
    q5_jenis_sampah_didaur_ulang TEXT         NULL,
    q6_kesulitan               TEXT         NULL,
    q7_bersedia_pilah          VARCHAR(10)  NULL,
    nama                       VARCHAR(150) NULL,
    email                      VARCHAR(200) NULL,
    nomor_wa                   VARCHAR(50)  NULL,
    alamat                     TEXT         NULL,
    created_at                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_q1 (q1_sampah_mendesak),
    INDEX idx_q2 (q2_paham_3r)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Pastikan kolom-kolom baru ditambahkan jika tabel sudah ada sebelumnya
try {
    $existingCols = array_map('strtolower', $db->query("SHOW COLUMNS FROM survey_responses")->fetchAll(PDO::FETCH_COLUMN));
    
    if (!in_array('response_code', $existingCols)) {
        $db->exec("ALTER TABLE survey_responses ADD COLUMN response_code VARCHAR(20) NULL AFTER id");
        try {
            $db->exec("ALTER TABLE survey_responses ADD UNIQUE KEY uq_response_code (response_code)");
        } catch (Exception $e) {}
    }
    if (!in_array('q1_sampah_mendesak', $existingCols)) {
        $db->exec("ALTER TABLE survey_responses ADD COLUMN q1_sampah_mendesak VARCHAR(10) NULL");
    }
    if (!in_array('q2_paham_3r', $existingCols)) {
        $db->exec("ALTER TABLE survey_responses ADD COLUMN q2_paham_3r VARCHAR(10) NULL");
    }
    if (!in_array('q3_daur_ulang_rumah', $existingCols)) {
        $db->exec("ALTER TABLE survey_responses ADD COLUMN q3_daur_ulang_rumah VARCHAR(10) NULL");
    }
    if (!in_array('q4_pilah_organik_anorganik', $existingCols)) {
        $db->exec("ALTER TABLE survey_responses ADD COLUMN q4_pilah_organik_anorganik VARCHAR(10) NULL");
    }
    if (!in_array('q5_jenis_sampah_didaur_ulang', $existingCols)) {
        $db->exec("ALTER TABLE survey_responses ADD COLUMN q5_jenis_sampah_didaur_ulang TEXT NULL");
    }
    if (!in_array('q6_kesulitan', $existingCols)) {
        $db->exec("ALTER TABLE survey_responses ADD COLUMN q6_kesulitan TEXT NULL");
    }
    if (!in_array('q7_bersedia_pilah', $existingCols)) {
        $db->exec("ALTER TABLE survey_responses ADD COLUMN q7_bersedia_pilah VARCHAR(10) NULL");
    }
    if (!in_array('updated_at', $existingCols)) {
        $db->exec("ALTER TABLE survey_responses ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    }
    
    // Hapus constraint check JSON yang membatasi format data jika ada
    try {
        $db->exec("ALTER TABLE survey_responses DROP CHECK survey_responses_chk_1");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE survey_responses MODIFY q5_jenis_sampah_didaur_ulang TEXT NULL");
    } catch (Exception $e) {}
    
    // Backfill response_code untuk data yang kosong/NULL agar tidak menyebabkan error unique key atau error query
    $stmtNull = $db->query("SELECT id FROM survey_responses WHERE response_code IS NULL OR response_code = ''");
    $nullRows = $stmtNull->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($nullRows)) {
        $stmtUpdate = $db->prepare("UPDATE survey_responses SET response_code = ? WHERE id = ?");
        foreach ($nullRows as $rowId) {
            $newCode = 'SRV-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            $stmtUpdate->execute([$newCode, $rowId]);
        }
    }
} catch (Exception $e) {
    error_log('[MRH Survey Migration Error] ' . $e->getMessage());
}

// ── POST: hapus response ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_survey') {
        $sid  = (int)($_POST['id'] ?? 0);
        $code = $db->query("SELECT response_code FROM survey_responses WHERE id=$sid")->fetchColumn();
        $db->prepare("DELETE FROM survey_responses WHERE id=?")->execute([$sid]);
        if (function_exists('logActivity')) logActivity($db, 1, "hapus_survey $code", 'survey_responses', $sid);
        if (function_exists('flash')) flash('success', "Response $code dihapus.");
        header('Location: analisis_data.php');
        exit;
    }
}

// ── Filter kuesioner ──────────────────────────────────────────
$sqSearch = trim($_GET['sq'] ?? '');
$sqQ1     = $_GET['sq_q1']   ?? '';
$sqQ2     = $_GET['sq_q2']   ?? '';
$sqQ7     = $_GET['sq_q7']   ?? '';

// ── Statistik Utama ──────────────────────────────────────────
$totalSampahPickup = (float)$db->query("SELECT COALESCE(SUM(berat_total_kg),0) FROM pickup_requests WHERE status='selesai' AND MONTH(COALESCE(completed_at, updated_at))=MONTH(CURDATE()) AND YEAR(COALESCE(completed_at, updated_at))=YEAR(CURDATE())")->fetchColumn();
$totalSampahCleanup = (float)$db->query("SELECT COALESCE(SUM(ci.berat_kg),0) FROM cleanup_items ci JOIN cleanup_requests cr ON cr.id=ci.cleanup_id WHERE cr.status='selesai' AND MONTH(COALESCE(cr.completed_at, cr.updated_at))=MONTH(CURDATE()) AND YEAR(COALESCE(cr.completed_at, cr.updated_at))=YEAR(CURDATE())")->fetchColumn();
$totalSampahKg = $totalSampahPickup + $totalSampahCleanup;
$totalKuesioner = (int)$db->query("SELECT COUNT(*) FROM survey_responses")->fetchColumn();

$totalReqPickup = (int)$db->query("SELECT COUNT(*) FROM pickup_requests WHERE status='selesai' AND MONTH(COALESCE(completed_at, updated_at))=MONTH(CURDATE()) AND YEAR(COALESCE(completed_at, updated_at))=YEAR(CURDATE())")->fetchColumn();
$totalReqCleanup = (int)$db->query("SELECT COUNT(*) FROM cleanup_requests WHERE status='selesai' AND MONTH(COALESCE(completed_at, updated_at))=MONTH(CURDATE()) AND YEAR(COALESCE(completed_at, updated_at))=YEAR(CURDATE())")->fetchColumn();
$totalReq = $totalReqPickup + $totalReqCleanup;

$avgBerat = $totalReq > 0 ? round($totalSampahKg / $totalReq, 1) : 0;

$cleanupPend = (float)$db->query("
    SELECT SUM(
        CASE WHEN status='selesai' THEN COALESCE(biaya_aktual,0) 
             ELSE COALESCE(biaya_estimasi,0) 
        END
    ) 
    FROM cleanup_requests 
    WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())
")->fetchColumn();

// ── Request per Kecamatan ─────────────────────────────────────
$kecData = $db->query("SELECT kecamatan, SUM(cnt) as cnt FROM (
    SELECT kecamatan, COUNT(*) as cnt FROM pickup_requests WHERE kecamatan IS NOT NULL GROUP BY kecamatan
    UNION ALL
    SELECT kecamatan, COUNT(*) as cnt FROM cleanup_requests WHERE kecamatan IS NOT NULL GROUP BY kecamatan
) t GROUP BY kecamatan ORDER BY cnt DESC LIMIT 8")->fetchAll();

// ── Jenis Sampah Terbanyak ────────────────────────────────────
$wasteData = $db->query("
    SELECT wc.name, wc.ikon_emoji, SUM(t.total_kg) as total_kg
    FROM (
        SELECT pri.category_id, COALESCE(SUM(COALESCE(pri.aktual_kg, pri.estimasi_kg)),0) as total_kg FROM pickup_request_items pri JOIN pickup_requests pr ON pr.id=pri.pickup_id WHERE pr.status='selesai' GROUP BY pri.category_id
        UNION ALL
        SELECT ci.category_id, COALESCE(SUM(ci.berat_kg),0) as total_kg FROM cleanup_items ci JOIN cleanup_requests cr ON cr.id=ci.cleanup_id WHERE cr.status='selesai' GROUP BY ci.category_id
    ) t
    JOIN waste_categories wc ON wc.id=t.category_id
    GROUP BY wc.id, wc.name, wc.ikon_emoji
    ORDER BY total_kg DESC LIMIT 8
")->fetchAll();

// ── Tren Request per Minggu ───────────────────────────────────
$trendData = $db->query("
    SELECT yw, SUM(cnt) as cnt FROM (
        SELECT YEARWEEK(created_at, 1) AS yw, COUNT(*) AS cnt FROM pickup_requests WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK) GROUP BY yw
        UNION ALL
        SELECT YEARWEEK(created_at, 1) AS yw, COUNT(*) AS cnt FROM cleanup_requests WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK) GROUP BY yw
    ) t GROUP BY yw ORDER BY yw ASC
")->fetchAll();

// ── Distribusi Status Request ─────────────────────────────────
$statusData = $db->query("SELECT status, SUM(cnt) as cnt FROM (
    SELECT status, COUNT(*) as cnt FROM pickup_requests GROUP BY status
    UNION ALL
    SELECT status, COUNT(*) as cnt FROM cleanup_requests GROUP BY status
) t GROUP BY status ORDER BY cnt DESC")->fetchAll();

// ── Performa Data Collecting (Petugas, Bin, Sack) ───────────
$officerPerf = $db->query("
    SELECT 
        o.id, o.nama, o.officer_code, 
        COALESCE(SUM(t.tugas_selesai), 0) as total_tugas, 
        COALESCE(SUM(t.total_kg), 0) as total_kg
    FROM officers o
    LEFT JOIN (
        SELECT officer_id, 1 as tugas_selesai, COALESCE(berat_total_kg,0) as total_kg
        FROM pickup_requests WHERE status='selesai'
        UNION ALL
        SELECT cr.officer_id, 1 as tugas_selesai, COALESCE(ci.berat,0) as total_kg
        FROM cleanup_requests cr
        LEFT JOIN (
            SELECT cleanup_id, SUM(berat_kg) as berat FROM cleanup_items GROUP BY cleanup_id
        ) ci ON ci.cleanup_id = cr.id
        WHERE cr.status='selesai'
    ) t ON t.officer_id = o.id
    GROUP BY o.id, o.nama, o.officer_code
    HAVING total_tugas > 0 OR o.nama LIKE '%Bin%' OR o.nama LIKE '%Sack%'
    ORDER BY total_kg DESC
")->fetchAll();

// ── Survey Stats (untuk chart progress bar) ───────────────────
$surveyTotal = max((int)$db->query("SELECT COUNT(*) FROM survey_responses")->fetchColumn(), 1);
$sqDef = [
    ['label'=>'Sampah adalah masalah mendesak',  'col'=>'q1_sampah_mendesak'],
    ['label'=>'Paham konsep 3R',                  'col'=>'q2_paham_3r'],
    ['label'=>'Mendaur ulang di rumah',           'col'=>'q3_daur_ulang_rumah'],
    ['label'=>'Memilah organik/anorganik',        'col'=>'q4_pilah_organik_anorganik'],
    ['label'=>'Bersedia memilah jika ada jemput', 'col'=>'q7_bersedia_pilah'],
];
$surveyStats = [];
foreach ($sqDef as $q) {
    $ya    = (int)$db->query("SELECT COUNT(*) FROM survey_responses WHERE {$q['col']}='Ya'")->fetchColumn();
    $tidak = (int)$db->query("SELECT COUNT(*) FROM survey_responses WHERE {$q['col']}='Tidak'")->fetchColumn();
    $surveyStats[] = [
        'label' => $q['label'],
        'ya'    => $ya,
        'tidak' => $tidak,
        'pct'   => round($ya / $surveyTotal * 100),
    ];
}

// ── Jenis sampah terbanyak dari kuesioner (Q5 CSV) ───────────
$jenisSampahCount = [];
$allQ5 = $db->query("SELECT q5_jenis_sampah_didaur_ulang FROM survey_responses WHERE q5_jenis_sampah_didaur_ulang IS NOT NULL AND q5_jenis_sampah_didaur_ulang != ''")->fetchAll();
foreach ($allQ5 as $row) {
    $items = array_map('trim', explode(',', $row['q5_jenis_sampah_didaur_ulang']));
    foreach ($items as $item) {
        if ($item !== '') {
            $jenisSampahCount[$item] = ($jenisSampahCount[$item] ?? 0) + 1;
        }
    }
}
arsort($jenisSampahCount);

// ── Tren Kuesioner per Minggu (12 minggu terakhir) ───────────
$surveyTrend = $db->query("
    SELECT YEARWEEK(created_at, 1) AS yw, COUNT(*) AS cnt
    FROM survey_responses
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
    GROUP BY yw ORDER BY yw ASC
")->fetchAll();

// ── Daftar response kuesioner (dengan filter) ─────────────────
$sWhere  = '1=1';
$sParams = [];
if ($sqSearch) {
    $sWhere   .= " AND (nama LIKE ? OR email LIKE ? OR nomor_wa LIKE ? OR response_code LIKE ?)";
    $sParams[] = "%$sqSearch%"; $sParams[] = "%$sqSearch%";
    $sParams[] = "%$sqSearch%"; $sParams[] = "%$sqSearch%";
}
if ($sqQ1) { $sWhere .= " AND q1_sampah_mendesak=?"; $sParams[] = $sqQ1; }
if ($sqQ2) { $sWhere .= " AND q2_paham_3r=?";        $sParams[] = $sqQ2; }
if ($sqQ7) { $sWhere .= " AND q7_bersedia_pilah=?";  $sParams[] = $sqQ7; }

$surveyStmt = $db->prepare("SELECT * FROM survey_responses WHERE $sWhere ORDER BY created_at DESC");
$surveyStmt->execute($sParams);
$surveyList = $surveyStmt->fetchAll();

// ── Preview kuesioner ─────────────────────────────────────────
$surveyPreview = null;
if (!empty($_GET['preview_survey'])) {
    $pid = (int)$_GET['preview_survey'];
    $surveyPreview = $db->query("SELECT * FROM survey_responses WHERE id=$pid")->fetch();
}

// ── Top 5 Request Terbaru ─────────────────────────────────────
$topRecent = $db->query("
    SELECT request_code, nama_pemohon, place_name, partner_name, kecamatan, status, created_at FROM (
        SELECT request_code, nama_pemohon, place_name, partner_name, kecamatan, status, created_at FROM pickup_requests
        UNION ALL
        SELECT request_code, nama_pemohon, NULL as place_name, NULL as partner_name, kecamatan, status, created_at FROM cleanup_requests
    ) t ORDER BY created_at DESC LIMIT 5
")->fetchAll();

require_once __DIR__ . '/layout/header.php';
?>

<style>
/* ═══════════════════════════════════════
   PAGE HEADER
═══════════════════════════════════════ */
.page-header{margin-bottom:20px}
.page-header h1{font-size:22px;font-weight:800;color:#1e293b;margin:0 0 4px}
.page-header p{font-size:13px;color:#94a3b8;margin:0}

/* ═══════════════════════════════════════
   STAT CARDS
═══════════════════════════════════════ */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px}
.mb-24{margin-bottom:24px}
.stat-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px 18px;box-shadow:0 1px 4px rgba(0,0,0,.05);transition:box-shadow .2s,transform .15s}
.stat-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.1);transform:translateY(-1px)}
.stat-label{font-size:10px;font-weight:700;color:#94a3b8;letter-spacing:.6px;text-transform:uppercase;margin-bottom:4px}
.stat-value{font-size:24px;font-weight:800;line-height:1.1;margin-bottom:2px}
.stat-sub{font-size:10px;color:#cbd5e1;font-weight:600}
.stat-card.green .stat-value{color:#2e7d32}
.stat-card.blue  .stat-value{color:#0284c7}
.stat-card.amber .stat-value{color:#d97706}
.stat-card.red   .stat-value{color:#dc2626}

/* ═══════════════════════════════════════
   GRID 2 COLUMNS
═══════════════════════════════════════ */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px}
@media(max-width:900px){.grid-2{grid-template-columns:1fr}}

/* ═══════════════════════════════════════
   CARD (admin panel)
═══════════════════════════════════════ */
.card{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:20px 22px;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.card-title{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:800;color:#1e293b;margin-bottom:16px;flex-wrap:wrap}
.ct-icon{font-size:18px}

/* ═══════════════════════════════════════
   BAR CHART (vertikal)
═══════════════════════════════════════ */
.bar-chart{display:flex;align-items:flex-end;gap:10px;height:150px;overflow-x:auto;padding-bottom:4px}
.bar-item{display:flex;flex-direction:column;align-items:center;gap:4px;min-width:48px}
.bar-item span{font-size:9px;color:#64748b;text-align:center;line-height:1.3}
.bar-val{font-size:10px;font-weight:800;color:#2e7d32}
.bar{background:linear-gradient(180deg,#4caf50,#2e7d32);border-radius:4px 4px 0 0;width:32px;min-height:4px;transition:height .4s}

/* ═══════════════════════════════════════
   PROGRESS BAR
═══════════════════════════════════════ */
.progress-bar{background:#f0f0f0;border-radius:4px;height:8px;overflow:hidden;margin-top:4px}
.progress-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,#4caf50,#2e7d32);transition:width .4s}

/* ═══════════════════════════════════════
   BADGES
═══════════════════════════════════════ */
.badge{display:inline-flex;align-items:center;gap:3px;border-radius:20px;padding:2px 8px;font-size:11px;font-weight:700}
.badge-green{background:#dcfce7;color:#166534}
.badge-amber{background:#fef3c7;color:#92400e}
.badge-red  {background:#fee2e2;color:#991b1b}
.badge-blue {background:#dbeafe;color:#1e40af}
.badge-ya   {background:#dcfce7;color:#166534}
.badge-tidak{background:#fee2e2;color:#991b1b}
.badge-none {background:#f1f5f9;color:#64748b}

/* ═══════════════════════════════════════
   TABLE
═══════════════════════════════════════ */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px}
thead th{background:#f8fafc;padding:9px 11px;text-align:left;font-size:10px;font-weight:700;color:#64748b;letter-spacing:.5px;text-transform:uppercase;border-bottom:2px solid #e2e8f0;white-space:nowrap}
tbody tr{border-bottom:1px solid #f1f5f9;transition:background .15s}
tbody tr:hover{background:#f8fffe}
tbody td{padding:9px 11px;vertical-align:middle;color:#334155}

/* ═══════════════════════════════════════
   TOOLBAR / FILTER
═══════════════════════════════════════ */
.filter-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.search-input,.filter-select{border:1.5px solid #e2e8f0;border-radius:8px;padding:7px 11px;font-size:13px;background:#fff;outline:none;transition:border .2s}
.search-input:focus,.filter-select:focus{border-color:#22c55e}
.search-input{min-width:200px}

/* ═══════════════════════════════════════
   BUTTONS
═══════════════════════════════════════ */
.btn{display:inline-flex;align-items:center;gap:5px;border-radius:8px;padding:7px 14px;font-size:13px;font-weight:700;cursor:pointer;transition:all .15s;border:1.5px solid transparent;text-decoration:none}
.btn-primary{background:#2e7d32;color:#fff;border-color:#2e7d32}
.btn-primary:hover{background:#1b5e20}
.btn-outline{background:#fff;color:#334155;border-color:#e2e8f0}
.btn-outline:hover{border-color:#4ade80;background:#f0fdf4}
.btn-danger{background:#fff;color:#dc2626;border-color:#fca5a5}
.btn-danger:hover{background:#fee2e2}
.btn-icon{padding:4px 7px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;cursor:pointer;transition:all .15s;font-size:13px;line-height:1;display:inline-flex;align-items:center;justify-content:center}
.btn-icon:hover{border-color:#4ade80;background:#f0fdf4}

/* ═══════════════════════════════════════
   SECTION TITLE
═══════════════════════════════════════ */
.section-title{font-size:16px;font-weight:800;color:#1e293b;margin:28px 0 14px;display:flex;align-items:center;gap:8px}
.section-title .sc-line{flex:1;height:1.5px;background:#e2e8f0}

/* ═══════════════════════════════════════
   MODAL
═══════════════════════════════════════ */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:1000;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(2px)}
.modal-overlay.open,.modal-overlay[style*="display:flex"]{display:flex}
.modal{background:#fff;border-radius:16px;width:100%;box-shadow:0 8px 48px rgba(0,0,0,.2);max-height:92vh;overflow-y:auto;display:flex;flex-direction:column;animation:modalIn .2s ease}
@keyframes modalIn{from{opacity:0;transform:scale(.97) translateY(8px)}to{opacity:1;transform:scale(1) translateY(0)}}
.modal-header{padding:18px 24px 14px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:#fff;z-index:1;border-radius:16px 16px 0 0}
.modal-header h3{font-size:15px;font-weight:800;color:#1e293b;margin:0}
.modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:#94a3b8;line-height:1;padding:4px 6px;border-radius:6px;transition:all .15s}
.modal-close:hover{color:#ef4444;background:#fee2e2}
.modal-body{padding:20px 24px;flex:1}
.modal-footer{padding:14px 24px;border-top:1px solid #f1f5f9;display:flex;gap:8px;justify-content:flex-end;background:#fafafa;border-radius:0 0 16px 16px}

/* ═══════════════════════════════════════
   PREVIEW CARD (kuesioner)
═══════════════════════════════════════ */
.preview-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px}
.preview-row{display:flex;align-items:flex-start;gap:12px;padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:13px}
.preview-row:last-child{border-bottom:none}
.pl{min-width:160px;font-weight:700;color:#64748b;font-size:11px;padding-top:2px;text-transform:uppercase;letter-spacing:.3px}
.pv{color:#1e293b;flex:1;word-break:break-word;font-weight:600}

/* ═══════════════════════════════════════
   SYNC INDICATOR
═══════════════════════════════════════ */
.sync-bar{display:flex;align-items:center;gap:6px;font-size:11px;color:#64748b;margin-bottom:14px;flex-wrap:wrap}
.sync-dot{width:7px;height:7px;border-radius:50%;background:#22c55e;flex-shrink:0;animation:pulseDot 2s infinite}
@keyframes pulseDot{0%,100%{opacity:1;box-shadow:0 0 0 0 rgba(34,197,94,.4)}50%{opacity:.7;box-shadow:0 0 0 4px rgba(34,197,94,0)}}

/* ═══════════════════════════════════════
   ANSWER PILL
═══════════════════════════════════════ */
.ans-pill{display:inline-block;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700}
.ans-ya   {background:#dcfce7;color:#166534}
.ans-tidak{background:#fee2e2;color:#991b1b}
.ans-none {background:#f1f5f9;color:#94a3b8;font-style:italic}

/* ═══════════════════════════════════════
   JENIS SAMPAH TAG CLOUD
═══════════════════════════════════════ */
.tag-cloud{display:flex;flex-wrap:wrap;gap:7px;margin-top:4px}
.tag{background:#e8f5e9;color:#2e7d32;border-radius:20px;padding:3px 12px;font-size:12px;font-weight:700;border:1px solid #c8e6c9}
.tag .tag-cnt{background:#2e7d32;color:#fff;border-radius:10px;padding:0 5px;font-size:10px;margin-left:4px}

/* ═══════════════════════════════════════
   KESULITAN QUOTE
═══════════════════════════════════════ */
.quote-box{background:#fffbeb;border-left:3px solid #f59e0b;border-radius:0 8px 8px 0;padding:10px 14px;font-size:12px;color:#78350f;line-height:1.6;margin-top:8px;font-style:italic}

/* ═══════════════════════════════════════
   EMPTY STATE
═══════════════════════════════════════ */
.empty-state{text-align:center;padding:48px 0;color:#94a3b8}
.empty-state .es-icon{font-size:40px;margin-bottom:10px}
.empty-state .es-text{font-weight:600;font-size:13px}
</style>

<div class="page-header">
  <h1>📊 Analisis Data</h1>
  <p>Statistik dan tren pengangkutan sampah + hasil kuesioner masyarakat — data real-time dari database</p>
</div>

<!-- Navigasi cepat -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap">
  <a href="dashboard.php"        class="btn btn-outline btn-sm">🖥️ Dashboard</a>
  <a href="laporan_harian.php"   class="btn btn-outline btn-sm">📅 Lap. Harian</a>
  <a href="laporan_mingguan.php" class="btn btn-outline btn-sm">📆 Lap. Mingguan</a>
  <a href="laporan_bulanan.php"  class="btn btn-outline btn-sm">📊 Lap. Bulanan</a>
  <a href="rute_jadwal.php"      class="btn btn-outline btn-sm">🗺️ Rute & Jadwal</a>
</div>

<!-- ── STAT CARDS ── -->
<div class="stats-grid mb-24">
  <div class="stat-card green">
    <div class="stat-label">Total Sampah (kg)</div>
    <div class="stat-value"><?= number_format($totalSampahKg, 1) ?></div>
    <div class="stat-sub">bulan ini (estimasi)</div>
  </div>
  <div class="stat-card blue">
    <div class="stat-label">Kuesioner Masuk</div>
    <div class="stat-value"><?= number_format($totalKuesioner) ?></div>
    <div class="stat-sub">total responden</div>
  </div>
  <div class="stat-card amber">
    <div class="stat-label">Rata-rata/Request</div>
    <div class="stat-value"><?= $avgBerat ?> kg</div>
    <div class="stat-sub">per penjemputan bulan ini</div>
  </div>
  <div class="stat-card red">
    <div class="stat-label">Clean Up Service</div>
    <div class="stat-value">Rp <?= number_format($cleanupPend/1000) ?>K</div>
    <div class="stat-sub">est. & aktual pendapatan bulan ini</div>
  </div>
</div>

<!-- ── CHARTS ROW 1 ── -->
<div class="grid-2 mb-24">
  <!-- Request per Kecamatan -->
  <div class="card">
    <div class="card-title"><div class="ct-icon">📊</div> Request per Kecamatan</div>
    <?php if ($kecData): ?>
    <div class="bar-chart">
      <?php
      $maxKec = max(array_column($kecData,'cnt') ?: [1]);
      foreach ($kecData as $k):
        $h = round(($k['cnt']/$maxKec)*120);
      ?>
      <div class="bar-item">
        <span class="bar-val"><?= $k['cnt'] ?></span>
        <div class="bar" style="height:<?= max($h,4) ?>px"></div>
        <span><?= htmlspecialchars($k['kecamatan']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state"><div class="es-icon">📭</div><div class="es-text">Belum ada data</div></div>
    <?php endif; ?>
  </div>

  <!-- Jenis Sampah Terbanyak -->
  <div class="card">
    <div class="card-title"><div class="ct-icon">🗑️</div> Jenis Sampah Terbanyak (kg estimasi)</div>
    <?php if ($wasteData && max(array_column($wasteData,'total_kg')) > 0): ?>
    <?php
    $maxW = max(array_column($wasteData,'total_kg') ?: [1]);
    foreach ($wasteData as $w):
      $pct = $maxW > 0 ? round(($w['total_kg']/$maxW)*100) : 0;
    ?>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
      <span style="min-width:140px;font-size:12px"><?= htmlspecialchars($w['ikon_emoji'].' '.$w['name']) ?></span>
      <div style="flex:1;background:#f0f0f0;border-radius:4px;height:8px;overflow:hidden">
        <div style="height:100%;background:#2e7d32;border-radius:4px;width:<?= $pct ?>%"></div>
      </div>
      <span style="font-size:12px;font-weight:700;min-width:50px;text-align:right"><?= number_format((float)$w['total_kg'],1) ?> kg</span>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty-state"><div class="es-icon">📦</div><div class="es-text">Belum ada data item request</div></div>
    <?php endif; ?>
  </div>
</div>

<!-- ── CHARTS ROW 2 ── -->
<div class="grid-2 mb-24">
  <!-- Tren Request per Minggu -->
  <div class="card">
    <div class="card-title"><div class="ct-icon">📈</div> Tren Request (12 Minggu Terakhir)</div>
    <?php if ($trendData): ?>
    <div class="bar-chart">
      <?php
      $maxT = max(array_column($trendData,'cnt') ?: [1]);
      foreach ($trendData as $idx => $t):
        $h  = round(($t['cnt']/$maxT)*120);
        $op = round(0.5 + 0.5*($idx/count($trendData)), 2);
      ?>
      <div class="bar-item">
        <span class="bar-val" style="font-size:10px"><?= $t['cnt'] ?></span>
        <div class="bar" style="height:<?= max($h,4) ?>px;opacity:<?= $op ?>"></div>
        <span>W<?= $idx+1 ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state"><div class="es-icon">📈</div><div class="es-text">Data tren belum tersedia</div></div>
    <?php endif; ?>
  </div>

  <!-- Distribusi Status Request -->
  <div class="card">
    <div class="card-title"><div class="ct-icon">📋</div> Distribusi Status Request</div>
    <?php
    $totalAllReq  = max(array_sum(array_column($statusData,'cnt')), 1);
    $statusColors = [
        'menunggu'=>'#e65100','dikonfirmasi'=>'#1565c0','dijadwalkan'=>'#6a1b9a',
        'sedang_diproses'=>'#bf360c','selesai'=>'#2e7d32','dibatalkan'=>'#c62828'
    ];
    foreach ($statusData as $s):
      $pct = round(($s['cnt']/$totalAllReq)*100);
      $col = $statusColors[$s['status']] ?? '#aaa';
    ?>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
      <div style="width:10px;height:10px;border-radius:50%;background:<?= $col ?>;flex-shrink:0"></div>
      <span style="flex:1;font-size:12px"><?= ucfirst(str_replace('_',' ',$s['status'])) ?></span>
      <div style="width:120px;background:#f0f0f0;border-radius:4px;height:8px;overflow:hidden">
        <div style="height:100%;border-radius:4px;width:<?= $pct ?>%;background:<?= $col ?>"></div>
      </div>
      <span style="font-size:12px;font-weight:700;min-width:28px;text-align:right"><?= $s['cnt'] ?></span>
      <span style="font-size:11px;color:#aaa;min-width:32px"><?= $pct ?>%</span>
    </div>
    <?php endforeach; ?>
    <?php if (!$statusData): ?>
    <div class="empty-state"><div class="es-icon">📋</div><div class="es-text">Tidak ada data</div></div>
    <?php endif; ?>
  </div>
</div>

<!-- ── CHARTS ROW 3 (DATA COLLECTING) ── -->
<div class="grid-2 mb-24">
  <div class="card" style="grid-column:1/-1">
    <div class="card-title"><div class="ct-icon">👷</div> Performa Data Collecting (Petugas, Bin, Sack)</div>
    <?php if ($officerPerf): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px">
      <?php
      $maxKg = max(array_column($officerPerf,'total_kg') ?: [1]);
      foreach ($officerPerf as $op):
        $pct = $maxKg > 0 ? round(($op['total_kg']/$maxKg)*100) : 0;
        $isWadah = (stripos($op['nama'], 'bin') !== false || stripos($op['nama'], 'sack') !== false);
        $icon = $isWadah ? '🗑️' : '👷';
        $color = $isWadah ? '#0ea5e9' : '#22c55e';
      ?>
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;display:flex;align-items:center;gap:12px">
        <div style="font-size:24px;width:32px;text-align:center"><?= $icon ?></div>
        <div style="flex:1">
          <div style="font-weight:700;font-size:13px;color:#1e293b"><?= htmlspecialchars($op['nama']) ?></div>
          <div style="font-size:11px;color:#64748b"><?= $op['total_tugas'] ?> request selesai</div>
          <div style="margin-top:6px;background:#e2e8f0;border-radius:4px;height:6px;overflow:hidden">
            <div style="height:100%;background:<?= $color ?>;border-radius:4px;width:<?= $pct ?>%"></div>
          </div>
        </div>
        <div style="text-align:right">
          <div style="font-weight:800;font-size:14px;color:<?= $color ?>"><?= number_format((float)$op['total_kg'],1) ?></div>
          <div style="font-size:10px;font-weight:700;color:#94a3b8">KG</div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state"><div class="es-icon">👷</div><div class="es-text">Belum ada data performa petugas atau wadah.</div></div>
    <?php endif; ?>
  </div>
</div>

<!-- ── SURVEY CHARTS ROW ── -->
<div class="grid-2 mb-24">
  <!-- Hasil Kuesioner — Progress Bar -->
  <div class="card">
    <div class="card-title"><div class="ct-icon">📝</div> Hasil Kuesioner — Kesadaran 3R</div>
    <?php if ($totalKuesioner > 0): ?>
    <div style="margin-bottom:12px;font-size:12px;color:#888">
      Dari <strong><?= $totalKuesioner ?></strong> responden
    </div>
    <?php foreach ($surveyStats as $sq): ?>
    <div style="margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px;gap:8px">
        <span style="flex:1"><?= htmlspecialchars($sq['label']) ?></span>
        <span>
          <span class="badge badge-ya">Ya: <?= $sq['ya'] ?></span>
          <span class="badge badge-tidak" style="margin-left:3px">Tidak: <?= $sq['tidak'] ?></span>
          <span class="badge badge-green" style="margin-left:3px"><?= $sq['pct'] ?>%</span>
        </span>
      </div>
      <div class="progress-bar">
        <div class="progress-fill" style="width:<?= $sq['pct'] ?>%"></div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty-state"><div class="es-icon">📝</div><div class="es-text">Belum ada data kuesioner</div></div>
    <?php endif; ?>
  </div>

  <!-- Jenis Sampah Daur Ulang dari Kuesioner Q5 -->
  <div class="card">
    <div class="card-title"><div class="ct-icon">♻️</div> Jenis Sampah Didaur Ulang (Kuesioner Q5)</div>
    <?php if (!empty($jenisSampahCount)): ?>
    <div style="margin-bottom:10px;font-size:12px;color:#888">Dari jawaban checklist responden</div>
    <?php
    $maxJS = max($jenisSampahCount);
    foreach ($jenisSampahCount as $jenis => $cnt):
      $pctJS = round(($cnt/$maxJS)*100);
    ?>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
      <span style="min-width:100px;font-size:12px;font-weight:600"><?= htmlspecialchars($jenis) ?></span>
      <div style="flex:1;background:#f0f0f0;border-radius:4px;height:8px;overflow:hidden">
        <div style="height:100%;background:#4caf50;border-radius:4px;width:<?= $pctJS ?>%"></div>
      </div>
      <span style="font-size:12px;font-weight:700;min-width:30px;text-align:right"><?= $cnt ?></span>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty-state"><div class="es-icon">♻️</div><div class="es-text">Belum ada data jawaban Q5</div></div>
    <?php endif; ?>
  </div>
</div>

<!-- ── TREN KUESIONER + REQUEST TERBARU ── -->
<div class="grid-2 mb-24">
  <!-- Tren Kuesioner per Minggu -->
  <div class="card">
    <div class="card-title"><div class="ct-icon">📉</div> Tren Kuesioner (12 Minggu Terakhir)</div>
    <?php if ($surveyTrend): ?>
    <div class="bar-chart">
      <?php
      $maxST = max(array_column($surveyTrend,'cnt') ?: [1]);
      foreach ($surveyTrend as $idx => $t):
        $h  = round(($t['cnt']/$maxST)*120);
        $op = round(0.5 + 0.5*($idx/count($surveyTrend)), 2);
      ?>
      <div class="bar-item">
        <span class="bar-val" style="font-size:10px"><?= $t['cnt'] ?></span>
        <div class="bar" style="height:<?= max($h,4) ?>px;opacity:<?= $op ?>;background:linear-gradient(180deg,#2196c4,#0d47a1)"></div>
        <span>W<?= $idx+1 ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state"><div class="es-icon">📉</div><div class="es-text">Data tren kuesioner belum tersedia</div></div>
    <?php endif; ?>
  </div>

  <!-- Request Terbaru -->
  <div class="card">
    <div class="card-title"><div class="ct-icon">🕐</div> Request Terbaru</div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>ID</th><th>Place & Partner Name</th><th>Sub-district</th><th>Status</th><th>Timestamp</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($topRecent as $r): ?>
          <tr>
            <td><span style="font-size:11px;font-weight:700;color:#2e7d32"><?= htmlspecialchars($r['request_code']) ?></span></td>
            <td style="font-size:12px"><?= htmlspecialchars($r['partner_name'] ?: $r['nama_pemohon']) ?></td>
            <td style="font-size:12px"><?= htmlspecialchars($r['kecamatan'] ?? '-') ?></td>
            <td><?php if(function_exists('statusBadge')) echo statusBadge($r['status']); else echo htmlspecialchars($r['status']); ?></td>
            <td style="font-size:11px;color:#888"><?= function_exists('fmtDate') ? fmtDate($r['created_at'],'d M H:i') : date('d M H:i', strtotime($r['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$topRecent): ?>
          <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:20px">Belum ada request</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div style="margin-top:12px;text-align:right">
      <a href="req_management.php" class="btn btn-outline" style="font-size:12px">Lihat Semua →</a>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     SECTION: MANAJEMEN DATA KUESIONER
     Sinkron penuh dengan kuesioner.php — data user masuk langsung
════════════════════════════════════════════════════════════════ -->
<div class="section-title">
  📋 Data Responden Kuesioner
  <div class="sc-line"></div>
  <span style="font-size:12px;color:#94a3b8;font-weight:600;white-space:nowrap">
    <?= $totalKuesioner ?> total responden
  </span>
</div>

<div class="card mb-24">
  <!-- Sync indicator -->
  <div class="sync-bar">
    <span class="sync-dot"></span>
    <strong>Sinkron real-time</strong> — setiap responden kuesioner langsung masuk ke tabel ini
    <span id="lastRefresh" style="color:#cbd5e1"></span>
  </div>

  <!-- Filter Bar -->
  <form method="GET" id="filterForm">
    <div class="filter-bar">
      <input class="search-input" name="sq" type="text"
             placeholder="🔍 Cari nama / email / WA / kode..."
             value="<?= htmlspecialchars($sqSearch) ?>">
      <select class="filter-select" name="sq_q1" onchange="this.form.submit()">
        <option value="">Q1 — Semua</option>
        <option value="Ya"    <?= $sqQ1==='Ya'    ? 'selected':'' ?>>Q1: Ya</option>
        <option value="Tidak" <?= $sqQ1==='Tidak' ? 'selected':'' ?>>Q1: Tidak</option>
      </select>
      <select class="filter-select" name="sq_q2" onchange="this.form.submit()">
        <option value="">Q2 — Semua</option>
        <option value="Ya"    <?= $sqQ2==='Ya'    ? 'selected':'' ?>>Q2: Ya</option>
        <option value="Tidak" <?= $sqQ2==='Tidak' ? 'selected':'' ?>>Q2: Tidak</option>
      </select>
      <select class="filter-select" name="sq_q7" onchange="this.form.submit()">
        <option value="">Q7 — Semua</option>
        <option value="Ya"    <?= $sqQ7==='Ya'    ? 'selected':'' ?>>Q7: Ya</option>
        <option value="Tidak" <?= $sqQ7==='Tidak' ? 'selected':'' ?>>Q7: Tidak</option>
      </select>
      <button type="submit" class="btn btn-outline">Cari</button>
      <?php if ($sqSearch || $sqQ1 || $sqQ2 || $sqQ7): ?>
        <a href="analisis_data.php" class="btn btn-outline">✕ Reset</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- Tabel Kuesioner -->
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Kode</th>
          <th>Nama / Kontak</th>
          <th style="text-align:center">Q1<br><span style="font-size:9px;font-weight:500">Sampah mendesak?</span></th>
          <th style="text-align:center">Q2<br><span style="font-size:9px;font-weight:500">Paham 3R?</span></th>
          <th style="text-align:center">Q3<br><span style="font-size:9px;font-weight:500">Daur ulang?</span></th>
          <th style="text-align:center">Q4<br><span style="font-size:9px;font-weight:500">Pilah organik?</span></th>
          <th style="text-align:center">Q7<br><span style="font-size:9px;font-weight:500">Bersedia pilah?</span></th>
          <th>Jenis Daur Ulang</th>
          <th>Waktu</th>
          <th style="min-width:90px">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($surveyList): ?>
        <?php
if (!function_exists('ansPill')) {
    function ansPill($val) {
        if ($val === null || $val === '') return '<span class="ans-pill ans-none">—</span>';
        $cls = $val === 'Ya' ? 'ans-ya' : 'ans-tidak';
        $ico = $val === 'Ya' ? '✓' : '✗';
        return '<span class="ans-pill '.$cls.'">'.$ico.' '.htmlspecialchars($val).'</span>';
    }
}
?>
<?php foreach ($surveyList as $sv): ?>
        <tr>
          <!-- Kode -->
          <td>
            <span style="font-weight:800;color:#2196c4;font-size:11px">
              <?= htmlspecialchars($sv['response_code']) ?>
            </span>
          </td>
          <!-- Nama / Kontak -->
          <td>
            <?php if (!empty($sv['nama'])): ?>
            <div style="font-weight:700;font-size:12px"><?= htmlspecialchars($sv['nama']) ?></div>
            <?php endif; ?>
            <?php if (!empty($sv['nomor_wa'])): ?>
            <div style="font-size:10px;color:#64748b">📱 <?= htmlspecialchars($sv['nomor_wa']) ?></div>
            <?php endif; ?>
            <?php if (!empty($sv['email'])): ?>
            <div style="font-size:10px;color:#64748b">✉️ <?= htmlspecialchars($sv['email']) ?></div>
            <?php endif; ?>
            <?php if (empty($sv['nama']) && empty($sv['nomor_wa']) && empty($sv['email'])): ?>
            <span style="color:#cbd5e1;font-size:11px;font-style:italic">Anonim</span>
            <?php endif; ?>
          </td>
          <!-- Q1–Q4, Q7 -->
          <?php
          $qCols = [
            $sv['q1_sampah_mendesak'],
            $sv['q2_paham_3r'],
            $sv['q3_daur_ulang_rumah'],
            $sv['q4_pilah_organik_anorganik'],
            $sv['q7_bersedia_pilah'],
          ];
          foreach ($qCols as $qv):
            $cls = ($qv === 'Ya') ? 'ans-ya' : (($qv === 'Tidak') ? 'ans-tidak' : 'ans-none');
            $ico = ($qv === 'Ya') ? '✓' : (($qv === 'Tidak') ? '✗' : '—');
          ?>
          <td style="text-align:center">
            <span class="ans-pill <?= $cls ?>"><?= $ico ?> <?= htmlspecialchars($qv ?? '—') ?></span>
          </td>
          <?php endforeach; ?>
          <!-- Jenis Q5 -->
          <td style="font-size:11px;max-width:140px;color:#64748b">
            <?php if (!empty($sv['q5_jenis_sampah_didaur_ulang'])): ?>
              <span title="<?= htmlspecialchars($sv['q5_jenis_sampah_didaur_ulang']) ?>"
                    style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px">
                <?= htmlspecialchars($sv['q5_jenis_sampah_didaur_ulang']) ?>
              </span>
            <?php else: ?>
              <span style="color:#e2e8f0">—</span>
            <?php endif; ?>
          </td>
          <!-- Waktu -->
          <td style="font-size:11px;color:#94a3b8;white-space:nowrap">
            <?= date('d M Y', strtotime($sv['created_at'])) ?><br>
            <span style="color:#cbd5e1;font-size:10px"><?= date('H:i', strtotime($sv['created_at'])) ?></span>
          </td>
          <!-- Aksi -->
          <td>
            <div style="display:flex;gap:4px;align-items:center">
              <a class="btn-icon" href="analisis_data.php?preview_survey=<?= $sv['id'] ?>" title="Detail">👁️</a>
              <form method="POST" style="display:inline"
                    onsubmit="return confirm('Hapus response <?= htmlspecialchars($sv['response_code']) ?>?')">
                <input type="hidden" name="action" value="delete_survey">
                <input type="hidden" name="id"     value="<?= $sv['id'] ?>">
                <button type="submit" class="btn-icon" title="Hapus"
                        style="color:#dc2626;border-color:#fca5a5" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fff'">🗑️</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php else: ?>
        <tr>
          <td colspan="10" style="padding:0">
            <div class="empty-state">
              <div class="es-icon">📭</div>
              <div class="es-text">Tidak ada data kuesioner<?= ($sqSearch||$sqQ1||$sqQ2||$sqQ7) ? ' yang cocok dengan filter' : '' ?>.</div>
              <?php if ($sqSearch||$sqQ1||$sqQ2||$sqQ7): ?>
              <a href="analisis_data.php" style="color:#2e7d32;font-size:12px;margin-top:6px;display:inline-block">Tampilkan semua</a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div style="margin-top:12px;display:flex;align-items:center;justify-content:space-between;font-size:12px;color:#94a3b8;flex-wrap:wrap;gap:8px">
    <span>Menampilkan <strong style="color:#334155"><?= count($surveyList) ?></strong> dari <?= $totalKuesioner ?> responden</span>
    <span style="font-size:11px">🔄 Auto-refresh setiap 60 detik</span>
  </div>
</div>

<!-- ═══════════════════════════════════════════════
     MODAL: PREVIEW DETAIL KUESIONER
     Sinkron penuh dengan kuesioner.php
═══════════════════════════════════════════════ -->
<?php if ($surveyPreview): ?>
<div class="modal-overlay open" id="modalSurveyPreview">
  <div class="modal" style="max-width:600px">
    <div class="modal-header">
      <h3>📋 Detail Kuesioner — <?= htmlspecialchars($surveyPreview['response_code']) ?></h3>
      <a href="analisis_data.php" class="modal-close">✕</a>
    </div>
    <div class="modal-body">

      <!-- Identitas Responden -->
      <div style="font-size:10px;font-weight:800;color:#64748b;letter-spacing:.6px;margin-bottom:8px">IDENTITAS RESPONDEN</div>
      <div class="preview-card" style="margin-bottom:16px">
        <?php
        $identity = [
          'Kode Response' => '<span style="color:#2196c4;font-weight:900;font-size:14px">'.htmlspecialchars($surveyPreview['response_code']).'</span>',
          'Nama'          => htmlspecialchars($surveyPreview['nama']     ?? '—'),
          'Email'         => htmlspecialchars($surveyPreview['email']    ?? '—'),
          'WA / Telepon'  => htmlspecialchars($surveyPreview['nomor_wa'] ?? '—'),
          'Alamat'        => htmlspecialchars($surveyPreview['alamat']   ?? '—'),
          'Waktu Isi'     => date('d M Y H:i', strtotime($surveyPreview['created_at'])),
        ];
        foreach ($identity as $lbl => $val): ?>
        <div class="preview-row">
          <span class="pl"><?= $lbl ?></span>
          <span class="pv"><?= $val ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Jawaban Kuesioner -->
      <div style="font-size:10px;font-weight:800;color:#64748b;letter-spacing:.6px;margin-bottom:8px">JAWABAN KUESIONER</div>
      <div class="preview-card">
        <?php
        function previewAns($val, $label) {
            if ($val === null || $val === '') {
                echo '<div class="preview-row"><span class="pl">'.$label.'</span><span class="pv"><span class="ans-pill ans-none">Tidak dijawab</span></span></div>';
                return;
            }
            $cls = ($val === 'Ya') ? 'ans-ya' : (($val === 'Tidak') ? 'ans-tidak' : '');
            $ico = ($val === 'Ya') ? '✓ ' : (($val === 'Tidak') ? '✗ ' : '');
            echo '<div class="preview-row"><span class="pl">'.$label.'</span><span class="pv"><span class="ans-pill '.$cls.'">'.$ico.htmlspecialchars($val).'</span></span></div>';
        }
        previewAns($surveyPreview['q1_sampah_mendesak'],         'Q1 — Sampah mendesak?');
        previewAns($surveyPreview['q2_paham_3r'],                'Q2 — Paham 3R?');
        previewAns($surveyPreview['q3_daur_ulang_rumah'],        'Q3 — Daur ulang di rumah?');
        previewAns($surveyPreview['q4_pilah_organik_anorganik'], 'Q4 — Pilah organik?');
        ?>

        <!-- Q5 — Jenis Daur Ulang (checkbox) -->
        <div class="preview-row" style="align-items:flex-start">
          <span class="pl">Q5 — Jenis daur ulang</span>
          <span class="pv">
            <?php if (!empty($surveyPreview['q5_jenis_sampah_didaur_ulang'])): ?>
            <div class="tag-cloud">
              <?php foreach (explode(', ', $surveyPreview['q5_jenis_sampah_didaur_ulang']) as $tag): ?>
              <span class="tag"><?= htmlspecialchars(trim($tag)) ?></span>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <span class="ans-pill ans-none">Tidak diisi</span>
            <?php endif; ?>
          </span>
        </div>

        <!-- Q6 — Kesulitan (textarea) -->
        <div class="preview-row" style="align-items:flex-start">
          <span class="pl">Q6 — Kesulitan daur ulang</span>
          <span class="pv">
            <?php if (!empty($surveyPreview['q6_kesulitan'])): ?>
            <div class="quote-box"><?= nl2br(htmlspecialchars($surveyPreview['q6_kesulitan'])) ?></div>
            <?php else: ?>
            <span class="ans-pill ans-none">Tidak diisi</span>
            <?php endif; ?>
          </span>
        </div>

        <?php previewAns($surveyPreview['q7_bersedia_pilah'], 'Q7 — Bersedia pilah?'); ?>
      </div>

    </div>
    <div class="modal-footer">
      <a href="analisis_data.php" class="btn btn-outline">Tutup</a>
      <form method="POST" style="display:inline"
            onsubmit="return confirm('Hapus response ini?')">
        <input type="hidden" name="action" value="delete_survey">
        <input type="hidden" name="id"     value="<?= $surveyPreview['id'] ?>">
        <button type="submit" class="btn btn-danger">🗑️ Hapus Response</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
/* ── Modal helpers ── */
function openModal(id){
  document.getElementById(id).style.display='flex';
  document.body.style.overflow='hidden';
}
function closeModal(id){
  document.getElementById(id).style.display='none';
  document.body.style.overflow='';
}
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape')
    document.querySelectorAll('.modal-overlay[style*="display:flex"]').forEach(m => closeModal(m.id));
});

/* ── Last refresh indicator ── */
function updateLastRefresh() {
  const el = document.getElementById('lastRefresh');
  if (el) el.textContent = '· diperbarui ' + new Date().toLocaleTimeString('id-ID', { hour:'2-digit', minute:'2-digit' });
}
updateLastRefresh();

/* ── Auto-refresh 60 detik ── */
setInterval(() => {
  const anyOpen = document.querySelector('.modal-overlay[style*="display:flex"],.modal-overlay.open');
  if (!anyOpen && !document.hidden) location.reload();
}, 60000);
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
