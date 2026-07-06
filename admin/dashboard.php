<?php
// ============================================================
//  dashboard.php — Admin Console: Dashboard Utama
//  Manado Recycle Hub
//  - Visualisasi 7 chart interaktif (Chart.js)
//  - Live stats polling AJAX
//  - Operational control (quick status change)
//  - Data analysis: tren, komparasi, efisiensi petugas
//  - Priority-Rule scheduling preview
//  - Google Maps mini-map (jika API key tersedia)
// ============================================================
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/auth.php';
requireRole('admin');
$page_id    = 'dashboard';
$page_title = 'Dashboard';
$db = getDB();

// ── Deteksi Petugas Tidak Aktif ──────────────────────────────
$thresholdDays = 7;
try {
    $thresholdVal = $db->query("SELECT setting_value FROM site_settings WHERE setting_key='inactivity_threshold_days'")->fetchColumn();
    if ($thresholdVal !== false) {
        $thresholdDays = (int)$thresholdVal;
    }
} catch (Exception $e) {}

$alertOfficers = [];
try {
    $inactiveOfficers = $db->query("
        SELECT o.id, o.nama, o.officer_code, o.status,
               COALESCE(
                   (
                       SELECT MAX(COALESCE(completed_at, updated_at)) 
                       FROM (
                           SELECT completed_at, updated_at, officer_id FROM pickup_requests WHERE status='selesai'
                           UNION ALL
                           SELECT completed_at, updated_at, officer_id FROM cleanup_requests WHERE status='selesai'
                       ) t2 
                       WHERE t2.officer_id = o.id
                   ), 
                   o.tanggal_bergabung, 
                   o.created_at
               ) AS last_active
        FROM officers o
        WHERE o.status = 'aktif'
    ")->fetchAll();

    foreach ($inactiveOfficers as $io) {
        if ($io['last_active']) {
            $lastTime = strtotime($io['last_active']);
            $diff = time() - $lastTime;
            $days = (int)floor($diff / (60 * 60 * 24));
            if ($days >= $thresholdDays) {
                $io['inactive_days'] = $days;
                $alertOfficers[] = $io;
            }
        }
    }
} catch (Exception $e) {}


if (isset($_GET['ajax']) && $_GET['ajax'] === 'stats') {
    header('Content-Type: application/json');
    $total_req    = (int)$db->query("SELECT (SELECT COUNT(*) FROM pickup_requests) + (SELECT COUNT(*) FROM cleanup_requests)")->fetchColumn();
    $pending_req  = (int)$db->query("SELECT (SELECT COUNT(*) FROM pickup_requests WHERE status='menunggu') + (SELECT COUNT(*) FROM cleanup_requests WHERE status='menunggu')")->fetchColumn();
    $active_off   = (int)$db->query("SELECT COUNT(*) FROM officers WHERE status='aktif'")->fetchColumn();
    $done_today   = (int)$db->query("SELECT (SELECT COUNT(*) FROM pickup_requests WHERE status='selesai' AND DATE(COALESCE(completed_at, updated_at))=CURDATE()) + (SELECT COUNT(*) FROM cleanup_requests WHERE status='selesai' AND DATE(COALESCE(completed_at, updated_at))=CURDATE())")->fetchColumn();
    $in_progress  = (int)$db->query("SELECT (SELECT COUNT(*) FROM pickup_requests WHERE status NOT IN ('menunggu','selesai','dibatalkan')) + (SELECT COUNT(*) FROM cleanup_requests WHERE status NOT IN ('menunggu','selesai','dibatalkan'))")->fetchColumn();
    $unassigned   = (int)$db->query("SELECT (SELECT COUNT(*) FROM pickup_requests WHERE (officer_id IS NULL OR officer_id = 0) AND status NOT IN ('selesai','dibatalkan')) + (SELECT COUNT(*) FROM cleanup_requests WHERE (officer_id IS NULL OR officer_id = 0) AND status NOT IN ('selesai','dibatalkan'))")->fetchColumn();
    $total_kg     = (float)$db->query("SELECT (SELECT COALESCE(SUM(berat_total_kg),0) FROM pickup_requests WHERE status='selesai' AND MONTH(COALESCE(completed_at, updated_at))=MONTH(CURDATE()) AND YEAR(COALESCE(completed_at, updated_at))=YEAR(CURDATE())) + (SELECT COALESCE(SUM(berat_kg),0) FROM cleanup_items ci JOIN cleanup_requests cr ON cr.id=ci.cleanup_id WHERE cr.status='selesai' AND MONTH(COALESCE(cr.completed_at, cr.updated_at))=MONTH(CURDATE()) AND YEAR(COALESCE(cr.completed_at, cr.updated_at))=YEAR(CURDATE()))")->fetchColumn();
    $with_gps     = (int)$db->query("SELECT (SELECT COUNT(*) FROM pickup_requests WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND latitude != 0 AND longitude != 0) + (SELECT COUNT(*) FROM cleanup_requests WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND latitude != 0 AND longitude != 0)")->fetchColumn();
    $gps_coverage = $total_req > 0 ? round($with_gps / $total_req * 100) : 0;

    echo json_encode([
        'total_req'    => $total_req,
        'pending'      => $pending_req,
        'active_off'   => $active_off,
        'done_today'   => $done_today,
        'in_progress'  => $in_progress,
        'unassigned'   => $unassigned,
        'total_kg'     => $total_kg,
        'gps_coverage' => $gps_coverage,
        'with_gps'     => $with_gps,
        'ts'           => date('H:i:s'),
    ]);
    exit;
}

// ── AJAX: quick status update (Admin: hanya verifikasi) ──────
if (isset($_POST['ajax_status'])) {
    header('Content-Type: application/json');
    $id     = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    // Admin dashboard hanya boleh: konfirmasi atau batalkan
    $valid  = ['dikonfirmasi', 'dibatalkan'];
    if ($id && in_array($status, $valid)) {
        $extra = '';
        if ($status === 'dikonfirmasi') $extra = ', confirmed_at=IF(confirmed_at IS NULL,NOW(),confirmed_at)';
        $db->prepare("UPDATE pickup_requests SET status=?, is_kendala=0, updated_at=NOW()$extra WHERE id=?")->execute([$status,$id]);
        triggerWhatsAppOnStatusChange($db, $id, $status, 'daur_ulang');
        logActivity($db, 1, "dashboard_verifikasi #$id → $status", 'pickup_requests', $id);
        echo json_encode(['ok'=>true, 'status'=>$status]);
    } else {
        echo json_encode(['ok'=>false, 'msg'=>'Status tidak diizinkan dari dashboard. Gunakan modul Rute & Jadwal.']);
    }
    exit;
}


// ── Statistik Utama ───────────────────────────────────────────
$total_req    = (int)$db->query("SELECT (SELECT COUNT(*) FROM pickup_requests) + (SELECT COUNT(*) FROM cleanup_requests)")->fetchColumn();
$pending_req  = (int)$db->query("SELECT (SELECT COUNT(*) FROM pickup_requests WHERE status='menunggu') + (SELECT COUNT(*) FROM cleanup_requests WHERE status='menunggu')")->fetchColumn();
$active_off   = (int)$db->query("SELECT COUNT(*) FROM officers WHERE status='aktif'")->fetchColumn();
$total_off    = (int)$db->query("SELECT COUNT(*) FROM officers")->fetchColumn();
$done_today   = (int)$db->query("SELECT (SELECT COUNT(*) FROM pickup_requests WHERE status='selesai' AND DATE(COALESCE(completed_at, updated_at))=CURDATE()) + (SELECT COUNT(*) FROM cleanup_requests WHERE status='selesai' AND DATE(COALESCE(completed_at, updated_at))=CURDATE())")->fetchColumn();
$in_progress  = (int)$db->query("SELECT (SELECT COUNT(*) FROM pickup_requests WHERE status NOT IN ('menunggu','selesai','dibatalkan')) + (SELECT COUNT(*) FROM cleanup_requests WHERE status NOT IN ('menunggu','selesai','dibatalkan'))")->fetchColumn();
$done_month   = (int)$db->query("SELECT (SELECT COUNT(*) FROM pickup_requests WHERE status='selesai' AND MONTH(COALESCE(completed_at, updated_at))=MONTH(CURDATE()) AND YEAR(COALESCE(completed_at, updated_at))=YEAR(CURDATE())) + (SELECT COUNT(*) FROM cleanup_requests WHERE status='selesai' AND MONTH(COALESCE(completed_at, updated_at))=MONTH(CURDATE()) AND YEAR(COALESCE(completed_at, updated_at))=YEAR(CURDATE()))")->fetchColumn();
$total_kg     = (float)$db->query("SELECT (SELECT COALESCE(SUM(berat_total_kg),0) FROM pickup_requests WHERE status='selesai' AND MONTH(COALESCE(completed_at, updated_at))=MONTH(CURDATE()) AND YEAR(COALESCE(completed_at, updated_at))=YEAR(CURDATE())) + (SELECT COALESCE(SUM(berat_kg),0) FROM cleanup_items ci JOIN cleanup_requests cr ON cr.id=ci.cleanup_id WHERE cr.status='selesai' AND MONTH(COALESCE(cr.completed_at, cr.updated_at))=MONTH(CURDATE()) AND YEAR(COALESCE(cr.completed_at, cr.updated_at))=YEAR(CURDATE()))")->fetchColumn();
$unassigned   = (int)$db->query("SELECT (SELECT COUNT(*) FROM pickup_requests WHERE (officer_id IS NULL OR officer_id = 0) AND status NOT IN ('selesai','dibatalkan')) + (SELECT COUNT(*) FROM cleanup_requests WHERE (officer_id IS NULL OR officer_id = 0) AND status NOT IN ('selesai','dibatalkan'))")->fetchColumn();
$with_gps     = (int)$db->query("SELECT (SELECT COUNT(*) FROM pickup_requests WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND latitude != 0 AND longitude != 0) + (SELECT COUNT(*) FROM cleanup_requests WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND latitude != 0 AND longitude != 0)")->fetchColumn();
$avg_complete = round((float)$db->query("SELECT AVG(diff) FROM (SELECT TIMESTAMPDIFF(HOUR,created_at,COALESCE(completed_at, updated_at)) as diff FROM pickup_requests WHERE status='selesai' UNION ALL SELECT TIMESTAMPDIFF(HOUR,created_at,COALESCE(completed_at, updated_at)) as diff FROM cleanup_requests WHERE status='selesai') t")->fetchColumn(), 1);

// ── Chart 1: Tren Request 30 Hari ────────────────────────────
$trendRows = $db->query("SELECT tgl, SUM(cnt) AS cnt FROM (SELECT DATE(created_at) AS tgl, COUNT(*) AS cnt FROM pickup_requests WHERE created_at >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(created_at) UNION ALL SELECT DATE(created_at) AS tgl, COUNT(*) AS cnt FROM cleanup_requests WHERE created_at >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(created_at)) t GROUP BY tgl ORDER BY tgl ASC")->fetchAll();
$trendMap = [];
foreach ($trendRows as $t) $trendMap[$t['tgl']] = $t['cnt'];
$trendLabels = []; $trendValues = []; $trendSelesai = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trendLabels[] = date('d/m', strtotime($d));
    $trendValues[] = $trendMap[$d] ?? 0;
}
$trendSelesaiRows = $db->query("SELECT tgl, SUM(cnt) AS cnt FROM (SELECT DATE(updated_at) AS tgl, COUNT(*) AS cnt FROM pickup_requests WHERE status='selesai' AND updated_at >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(updated_at) UNION ALL SELECT DATE(updated_at) AS tgl, COUNT(*) AS cnt FROM cleanup_requests WHERE status='selesai' AND updated_at >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(updated_at)) t GROUP BY tgl ORDER BY tgl ASC")->fetchAll();
$trendSelesaiMap = [];
foreach ($trendSelesaiRows as $t) $trendSelesaiMap[$t['tgl']] = $t['cnt'];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trendSelesai[] = $trendSelesaiMap[$d] ?? 0;
}

// ── Chart 2: Distribusi Status (Donut) ───────────────────────
$statusRows = $db->query("SELECT status, SUM(cnt) as cnt FROM (SELECT status, COUNT(*) as cnt FROM pickup_requests GROUP BY status UNION ALL SELECT status, COUNT(*) as cnt FROM cleanup_requests GROUP BY status) t GROUP BY status ORDER BY FIELD(status,'menunggu','dikonfirmasi','dijadwalkan','dalam_perjalanan','sedang_diproses','selesai','dibatalkan')")->fetchAll();
$statusColorMap    = ['menunggu'=>'#f59e0b','dikonfirmasi'=>'#3b82f6','dijadwalkan'=>'#8b5cf6','dalam_perjalanan'=>'#eab308','sedang_diproses'=>'#f97316','selesai'=>'#22c55e','dibatalkan'=>'#ef4444'];
$statusDisplayMap  = ['menunggu'=>'Menunggu','dikonfirmasi'=>'Dikonfirmasi','dijadwalkan'=>'Dijadwalkan','dalam_perjalanan'=>'Dalam Perjalanan','sedang_diproses'=>'Sedang Diproses','selesai'=>'Selesai','dibatalkan'=>'Dibatalkan'];
$statusLabels      = array_column($statusRows,'status');
$statusCounts      = array_column($statusRows,'cnt');
$statusColors      = array_map(fn($s)=>$statusColorMap[$s]??'#aaa', $statusLabels);
$statusDisplayLbls = array_map(fn($s)=>$statusDisplayMap[$s]??$s, $statusLabels);

// ── Chart 3: Request per Kecamatan (Grouped Bar) ─────────────
$kecData = $db->query("SELECT kecamatan, SUM(cnt) as cnt, SUM(selesai) as selesai, SUM(aktif) as aktif FROM (
    SELECT kecamatan, COUNT(*) as cnt, SUM(CASE WHEN status='selesai' THEN 1 ELSE 0 END) as selesai, SUM(CASE WHEN status NOT IN ('selesai','dibatalkan') THEN 1 ELSE 0 END) as aktif FROM pickup_requests WHERE kecamatan IS NOT NULL GROUP BY kecamatan
    UNION ALL
    SELECT kecamatan, COUNT(*) as cnt, SUM(CASE WHEN status='selesai' THEN 1 ELSE 0 END) as selesai, SUM(CASE WHEN status NOT IN ('selesai','dibatalkan') THEN 1 ELSE 0 END) as aktif FROM cleanup_requests WHERE kecamatan IS NOT NULL GROUP BY kecamatan
) t GROUP BY kecamatan ORDER BY cnt DESC LIMIT 8")->fetchAll();
$kecLabels  = array_column($kecData,'kecamatan');
$kecCounts  = array_column($kecData,'cnt');
$kecSelesai = array_column($kecData,'selesai');
$kecAktif   = array_column($kecData,'aktif');

// ── Chart 4: Jenis Sampah kg (Horizontal Bar) ────────────────
$wasteData = $db->query("SELECT wc.name, wc.ikon_emoji, SUM(t.total_kg) as total_kg FROM (
    SELECT pri.category_id, COALESCE(SUM(COALESCE(pri.aktual_kg, pri.estimasi_kg)),0) as total_kg FROM pickup_request_items pri JOIN pickup_requests pr ON pr.id=pri.pickup_id WHERE pr.status='selesai' GROUP BY pri.category_id
    UNION ALL
    SELECT ci.category_id, COALESCE(SUM(ci.berat_kg),0) as total_kg FROM cleanup_items ci JOIN cleanup_requests cr ON cr.id=ci.cleanup_id WHERE cr.status='selesai' GROUP BY ci.category_id
) t JOIN waste_categories wc ON wc.id=t.category_id GROUP BY wc.id,wc.name,wc.ikon_emoji ORDER BY total_kg DESC LIMIT 7")->fetchAll();
$wasteLabels = array_map(fn($w)=>$w['ikon_emoji'].' '.$w['name'], $wasteData);
$wasteKg     = array_column($wasteData,'total_kg');

// ── Chart 5: Tren Mingguan 12 Minggu ─────────────────────────
$weeklyRows = $db->query("SELECT yw, MIN(tgl) AS tgl, SUM(cnt) AS cnt FROM (
    SELECT YEARWEEK(created_at,1) AS yw, DATE(created_at) AS tgl, COUNT(*) AS cnt FROM pickup_requests WHERE created_at >= CURDATE() - INTERVAL 12 WEEK GROUP BY yw, tgl
    UNION ALL
    SELECT YEARWEEK(created_at,1) AS yw, DATE(created_at) AS tgl, COUNT(*) AS cnt FROM cleanup_requests WHERE created_at >= CURDATE() - INTERVAL 12 WEEK GROUP BY yw, tgl
) t GROUP BY yw ORDER BY yw ASC")->fetchAll();
$weeklyLabels = array_map(fn($w)=>'Mg '.date('d/m',strtotime($w['tgl'])), $weeklyRows);
$weeklyValues = array_column($weeklyRows,'cnt');

// ── Chart 6: Performa Petugas (Horizontal Bar) ───────────────
$officerPerf = $db->query("SELECT o.nama, o.officer_code,
    SUM(CASE WHEN t.status='selesai' THEN 1 ELSE 0 END) as selesai,
    SUM(CASE WHEN t.status NOT IN ('selesai','dibatalkan') THEN 1 ELSE 0 END) as aktif,
    COUNT(t.id) as total
    FROM officers o
    LEFT JOIN (
        SELECT officer_id, status, id FROM pickup_requests
        UNION ALL
        SELECT officer_id, status, id FROM cleanup_requests
    ) t ON t.officer_id=o.id
    WHERE o.status='aktif'
    GROUP BY o.id ORDER BY selesai DESC LIMIT 6")->fetchAll();
$offNames   = array_map(fn($o)=>$o['nama'], $officerPerf);
$offSelesai = array_map(fn($o)=>(int)$o['selesai'], $officerPerf);
$offAktif   = array_map(fn($o)=>(int)$o['aktif'], $officerPerf);

// ── Chart 7: Penerimaan per Bulan 6 Bulan ────────────────────
$monthlyRows = $db->query("SELECT bln, lbl, SUM(cnt) as cnt FROM (
    SELECT DATE_FORMAT(created_at,'%Y-%m') as bln, DATE_FORMAT(created_at,'%b %Y') as lbl, COUNT(*) as cnt FROM pickup_requests WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY bln, lbl
    UNION ALL
    SELECT DATE_FORMAT(created_at,'%Y-%m') as bln, DATE_FORMAT(created_at,'%b %Y') as lbl, COUNT(*) as cnt FROM cleanup_requests WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY bln, lbl
) t GROUP BY bln, lbl ORDER BY bln ASC")->fetchAll();
$monthlyLabels = array_column($monthlyRows,'lbl');
$monthlyValues = array_column($monthlyRows,'cnt');

// ── Chart 8: Tren Sampah Terkumpul per Bulan 6 Bulan (kg) ──────
$monthlyWeightRows = $db->query("SELECT bln, lbl, SUM(total_kg) as total_kg FROM (
    SELECT DATE_FORMAT(COALESCE(completed_at, updated_at), '%Y-%m') as bln, 
           DATE_FORMAT(COALESCE(completed_at, updated_at), '%b %Y') as lbl, 
           SUM(berat_total_kg) as total_kg 
    FROM pickup_requests 
    WHERE status='selesai' AND COALESCE(completed_at, updated_at) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY bln, lbl
    UNION ALL
    SELECT DATE_FORMAT(COALESCE(cr.completed_at, cr.updated_at), '%Y-%m') as bln, 
           DATE_FORMAT(COALESCE(cr.completed_at, cr.updated_at), '%b %Y') as lbl, 
           SUM(ci.berat_kg) as total_kg 
    FROM cleanup_items ci 
    JOIN cleanup_requests cr ON cr.id=ci.cleanup_id 
    WHERE cr.status='selesai' AND COALESCE(cr.completed_at, cr.updated_at) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY bln, lbl
) t GROUP BY bln, lbl ORDER BY bln ASC")->fetchAll();
$monthlyWeightLabels = array_column($monthlyWeightRows, 'lbl');
$monthlyWeightValues = array_map(fn($v) => (float)$v, array_column($monthlyWeightRows, 'total_kg'));



// ── Tabel: Pesanan SELESAI (10 terbaru) ──────────────────────
$pesananSelesai = $db->query("
    SELECT id, request_code, nama_pemohon, place_name, partner_name, kecamatan, status, updated_at, berat_total_kg, officer_nama FROM (
        SELECT pr.id, pr.request_code, pr.nama_pemohon, pr.place_name, pr.partner_name, pr.kecamatan, pr.status, pr.updated_at, pr.berat_total_kg, o.nama AS officer_nama
        FROM pickup_requests pr LEFT JOIN officers o ON o.id=pr.officer_id WHERE pr.status IN ('selesai','dibatalkan')
        UNION ALL
        SELECT cr.id, cr.request_code, cr.nama_pemohon, NULL as place_name, NULL as partner_name, cr.kecamatan, cr.status, cr.updated_at, (SELECT SUM(berat_kg) FROM cleanup_items WHERE cleanup_id=cr.id) as berat_total_kg, o.nama AS officer_nama
        FROM cleanup_requests cr LEFT JOIN officers o ON o.id=cr.officer_id WHERE cr.status IN ('selesai','dibatalkan')
    ) t ORDER BY updated_at DESC LIMIT 10
")->fetchAll();

// ── Tabel: Request perlu verifikasi (hanya menunggu) ───────
$needVerification = $db->query("
    (SELECT 'pickup' AS type, id, request_code, nama_pemohon, COALESCE(partner_name, place_name, '') as entity_name, kecamatan, status, created_at 
     FROM pickup_requests WHERE status = 'menunggu')
    UNION ALL
    (SELECT 'cleanup' AS type, id, request_code, nama_pemohon, service_type as entity_name, kecamatan, status, created_at 
     FROM cleanup_requests WHERE status = 'menunggu')
    ORDER BY created_at ASC LIMIT 8
")->fetchAll();

// ── Tabel: Pesanan Aktif (Terintegrasi Algoritma) ────────────────
$activeOrders = $db->query("
    SELECT id, request_code, nama_pemohon, place_name, partner_name, kecamatan, status, created_at, tanggal_jemput, jam_jemput, officer_nama, officer_code FROM (
        SELECT pr.id, pr.request_code, pr.nama_pemohon, pr.place_name, pr.partner_name, pr.kecamatan, pr.status, pr.created_at, pr.tanggal_jemput, pr.jam_jemput, o.nama AS officer_nama, o.officer_code
        FROM pickup_requests pr LEFT JOIN officers o ON o.id=pr.officer_id WHERE pr.status NOT IN ('selesai','dibatalkan')
        UNION ALL
        SELECT cr.id, cr.request_code, cr.nama_pemohon, NULL as place_name, NULL as partner_name, cr.kecamatan, cr.status, cr.created_at, cr.tanggal_tugas AS tanggal_jemput, cr.jam_mulai AS jam_jemput, o.nama AS officer_nama, o.officer_code
        FROM cleanup_requests cr LEFT JOIN officers o ON o.id=cr.officer_id WHERE cr.status NOT IN ('selesai','dibatalkan')
    ) t ORDER BY FIELD(status, 'sedang_diproses', 'sedang_cleanup', 'dalam_perjalanan', 'dijadwalkan', 'dikonfirmasi', 'menunggu'), created_at DESC LIMIT 15
")->fetchAll();

// ── Tabel: Laporan Kendala (is_kendala = 1) ─────────────────────
$kendalaReports = $db->query("
    SELECT id, request_code, nama_pemohon, kecamatan, status, catatan_officer, updated_at, officer_nama FROM (
        SELECT pr.id, pr.request_code, pr.nama_pemohon, pr.kecamatan, pr.status, pr.catatan_officer, pr.updated_at, o.nama AS officer_nama
        FROM pickup_requests pr LEFT JOIN officers o ON o.id=pr.officer_id WHERE pr.is_kendala = 1 AND pr.status NOT IN ('selesai','dibatalkan')
        UNION ALL
        SELECT cr.id, cr.request_code, cr.nama_pemohon, cr.kecamatan, cr.status, NULL as catatan_officer, cr.updated_at, o.nama AS officer_nama
        FROM cleanup_requests cr LEFT JOIN officers o ON o.id=cr.officer_id WHERE 1=0
    ) t ORDER BY updated_at DESC LIMIT 5
")->fetchAll();


// ── Beban Petugas ─────────────────────────────────────────────
$officerLoad = $db->query("SELECT o.id, o.nama, o.officer_code, COUNT(t.id) AS aktif_tugas FROM officers o
    LEFT JOIN (
        SELECT id, officer_id, status FROM pickup_requests WHERE status IN ('dikonfirmasi','dijadwalkan','dalam_perjalanan','sedang_diproses')
        UNION ALL
        SELECT id, officer_id, status FROM cleanup_requests WHERE status IN ('dikonfirmasi','dijadwalkan','dalam_perjalanan','sedang_diproses','sedang_cleanup')
    ) t ON t.officer_id=o.id
    WHERE o.status='aktif' GROUP BY o.id ORDER BY aktif_tugas DESC LIMIT 6")->fetchAll();

// ── Aktivitas ─────────────────────────────────────────────────
$activities = $db->query("SELECT aksi, entitas, created_at FROM activity_logs ORDER BY created_at DESC LIMIT 5")->fetchAll();

// ── Request Terbaru ───────────────────────────────────────────
$recent_req = $db->query("
    SELECT id, request_code, nama_pemohon, place_name, partner_name, kecamatan, status, created_at, officer_nama FROM (
        SELECT pr.id, pr.request_code, pr.nama_pemohon, pr.place_name, pr.partner_name, pr.kecamatan, pr.status, pr.created_at, o.nama AS officer_nama FROM pickup_requests pr LEFT JOIN officers o ON o.id=pr.officer_id
        UNION ALL
        SELECT cr.id, cr.request_code, cr.nama_pemohon, NULL as place_name, NULL as partner_name, cr.kecamatan, cr.status, cr.created_at, o.nama AS officer_nama FROM cleanup_requests cr LEFT JOIN officers o ON o.id=cr.officer_id
    ) t ORDER BY created_at DESC LIMIT 10
")->fetchAll();

// ── Priority Rule: Kecamatan ──────────────────────────────────
$prio_kec = $db->query("SELECT kecamatan, SUM(cnt) as cnt FROM (
    SELECT kecamatan, COUNT(*) as cnt FROM pickup_requests WHERE status NOT IN ('selesai','dibatalkan') AND kecamatan IS NOT NULL GROUP BY kecamatan
    UNION ALL
    SELECT kecamatan, COUNT(*) as cnt FROM cleanup_requests WHERE status NOT IN ('selesai','dibatalkan') AND kecamatan IS NOT NULL GROUP BY kecamatan
) t GROUP BY kecamatan ORDER BY cnt DESC LIMIT 4")->fetchAll();

// ── ADMIN: Evaluasi performa officer ─────────────────────────
$officerEvalRows = $db->query("SELECT o.id, o.nama, o.officer_code, o.status,
    COUNT(t.id) AS total,
    SUM(CASE WHEN t.status='selesai' THEN 1 ELSE 0 END) AS selesai,
    SUM(CASE WHEN t.status NOT IN ('selesai','dibatalkan') THEN 1 ELSE 0 END) AS aktif,
    SUM(CASE WHEN t.status='selesai' AND DATE(t.updated_at)=CURDATE() THEN 1 ELSE 0 END) AS selesai_hari_ini
    FROM officers o
    LEFT JOIN (
        SELECT officer_id, status, updated_at, id FROM pickup_requests
        UNION ALL
        SELECT officer_id, status, updated_at, id FROM cleanup_requests
    ) t ON t.officer_id=o.id
    GROUP BY o.id ORDER BY selesai DESC")->fetchAll();

// ── KPI Analysis ─────────────────────────────────────────────
$completion_rate = $total_req > 0 ? round($done_month / max($total_req, 1) * 100) : 0;
$gps_coverage    = $total_req > 0 ? round($with_gps / $total_req * 100) : 0;

function nextSaturday(int $offset=0): string {
    $today=(new DateTime()); $dow=(int)$today->format('N');
    $diff=6-($dow%7); if($diff===0)$diff=7;
    return (clone $today)->modify("+{$diff} days")->modify("+{$offset} weeks")->format('d M Y');
}

require_once __DIR__ . '/layout/header.php';
?>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<style>
/* ── Dashboard-specific styles ── */
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
@keyframes fadeIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:translateY(0)} }

.db-section-title {
    font-size:11px; font-weight:800; color:#94a3b8;
    text-transform:uppercase; letter-spacing:.08em;
    margin:24px 0 12px; display:flex; align-items:center; gap:8px;
}
.db-section-title::after {
    content:''; flex:1; height:1px; background:#e5e7eb;
}

/* KPI strip */
.kpi-strip {
    display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr));
    gap:12px; margin-bottom:20px;
}
.kpi-card {
    background:#fff; border-radius:10px; padding:16px;
    box-shadow:0 2px 8px rgba(0,0,0,.07); border-left:4px solid #e0e0e0;
    animation:fadeIn .4s ease both;
    transition:transform .15s, box-shadow .2s;
}
.kpi-card:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(0,0,0,.1); }
.kpi-label { font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.06em; margin-bottom:6px; }
.kpi-value { font-size:26px; font-weight:800; color:#1e293b; line-height:1; }
.kpi-sub   { font-size:11px; color:#cbd5e1; margin-top:4px; font-weight:600; }
.kpi-card.green  { border-left-color:#22c55e; } .kpi-card.green  .kpi-value { color:#16a34a; }
.kpi-card.amber  { border-left-color:#f59e0b; } .kpi-card.amber  .kpi-value { color:#d97706; }
.kpi-card.blue   { border-left-color:#3b82f6; } .kpi-card.blue   .kpi-value { color:#1d4ed8; }
.kpi-card.red    { border-left-color:#ef4444; } .kpi-card.red    .kpi-value { color:#dc2626; }
.kpi-card.purple { border-left-color:#8b5cf6; } .kpi-card.purple .kpi-value { color:#7c3aed; }
.kpi-card.teal   { border-left-color:#0ea5e9; } .kpi-card.teal   .kpi-value { color:#0284c7; }

/* Live indicator */
.live-badge {
    display:inline-flex; align-items:center; gap:6px;
    font-size:11px; color:#888; background:#f5f5f5;
    padding:5px 11px; border-radius:20px;
}
.live-dot { width:7px; height:7px; border-radius:50%; background:#22c55e; animation:pulse 1.5s infinite; }

/* Action needed badge */
.action-chip {
    display:inline-flex; align-items:center; gap:5px;
    background:#fff3cd; color:#856404; border:1px solid #ffc107;
    border-radius:20px; padding:2px 9px; font-size:10px; font-weight:800;
}
.action-chip.danger { background:#fee2e2; color:#991b1b; border-color:#ef4444; }

/* Quick status select */
.quick-status {
    font-size:11px; padding:4px 7px; border:1.5px solid #e2e8f0;
    border-radius:6px; outline:none; cursor:pointer; font-family:inherit;
    background:#fff; transition:border .15s;
}
.quick-status:focus { border-color:#2e7d32; }

/* Chart card */
.chart-card { background:#fff; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,.07); padding:18px; margin-bottom:16px; }
.chart-card-title { display:flex; align-items:center; gap:7px; font-size:13px; font-weight:700; color:#1e293b; margin-bottom:14px; }
.chart-card-title .ct-icon { font-size:15px; }

/* Officer load bar */
.officer-load-bar { height:7px; background:#e5e7eb; border-radius:4px; overflow:hidden; margin-top:4px; }
.officer-load-fill { height:100%; border-radius:4px; transition:width .6s ease; }

/* Heatmap grid */
.kec-heatmap { display:grid; grid-template-columns:repeat(auto-fill,minmax(110px,1fr)); gap:8px; }
.hm-cell {
    border-radius:8px; padding:10px 12px; cursor:pointer;
    transition:transform .15s, box-shadow .15s;
    color:#fff; font-weight:700;
}
@media(max-width:768px) {
    .kpi-strip {
        grid-template-columns: repeat(2,1fr);
        gap: 8px;
    }
    .kpi-card {
        padding: 12px;
    }
    .kpi-value { font-size: 22px; }
    .kec-heatmap { grid-template-columns: repeat(2,1fr); }
    .db-section-title {
        flex-wrap: wrap;
        font-size: 10px;
        line-height: 1.5;
        margin: 16px 0 10px;
    }
    .db-section-title::after {
        display: none;
    }
    /* Chart cards */
    .chart-card {
        padding: 12px;
        margin-bottom: 10px;
    }
    /* Page header on mobile: stack vertically */
    .dash-page-header {
        flex-direction: column;
        align-items: flex-start !important;
        gap: 8px;
    }
    .dash-header-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        width: 100%;
    }
    /* Tabel: sembunyikan kolom non-esensial */
    .tbl-hide-mobile { display: none !important; }
    /* Tabel font lebih kecil */
    table { font-size: 11px; }
    thead th { padding: 6px 7px !important; font-size: 9px !important; }
    tbody td { padding: 7px 7px !important; }
    /* Verifikasi tabel: wrapping aksi vertikal */
    .verif-action-btns { flex-direction: column; gap: 3px; }
    .verif-action-btns button {
        width: 100%;
        text-align: center;
        padding: 5px 6px !important;
    }
    /* Status flow: smaller on mobile */
    .status-flow {
        padding: 6px 10px;
        font-size: 10px;
        gap: 4px;
    }
    .flow-badge { font-size: 9px; padding: 1px 6px; }
    .flow-sep { font-size: 9px; }
}
.hm-cell:hover { transform:scale(1.04); box-shadow:0 4px 14px rgba(0,0,0,.18); }
.hm-cell .hm-kec { font-size:11px; font-weight:800; opacity:.9; }
.hm-cell .hm-cnt { font-size:22px; font-weight:800; line-height:1.1; margin-top:4px; }
.hm-cell .hm-sub { font-size:9px; opacity:.75; margin-top:2px; font-weight:600; }

/* Scrollable activity */
.activity-feed { display:flex; flex-direction:column; gap:0; max-height:220px; overflow-y:auto; }
.activity-feed::-webkit-scrollbar { width:4px; }
.activity-feed::-webkit-scrollbar-thumb { background:#e0e0e0; border-radius:2px; }

/* Priority timeline */
.priority-timeline { display:flex; flex-direction:column; gap:0; }
.pt-item { display:flex; gap:10px; }
.pt-dot-wrap { display:flex; flex-direction:column; align-items:center; width:18px; flex-shrink:0; }
.pt-dot  { width:10px; height:10px; border-radius:50%; flex-shrink:0; margin-top:3px; }
.pt-line { flex:1; width:2px; background:#e5e7eb; margin:3px 0; min-height:16px; }
.pt-body { flex:1; padding-bottom:12px; }
.pt-title { font-size:12px; font-weight:700; color:#1e293b; }
.pt-sub   { font-size:11px; color:#94a3b8; margin-top:1px; }


/* Status Flow */
.status-flow {
    display: flex; align-items: center; gap: 8px;
    background: #fff; padding: 8px 15px; border-radius: 30px;
    margin-bottom: 15px; font-size: 11px; font-weight: 700;
    color: #94a3b8; border: 1px solid #f1f5f9; box-shadow: 0 2px 5px rgba(0,0,0,0.03);
    width: fit-content;
    max-width: 100%;
    overflow-x: auto;
    white-space: nowrap;
    -webkit-overflow-scrolling: touch;
    box-sizing: border-box;
}
.status-flow::-webkit-scrollbar {
    height: 4px;
}
.status-flow::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 2px;
}
.flow-step { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.flow-sep  { color: #e2e8f0; font-weight: 400; flex-shrink: 0; }
.flow-badge { padding: 2px 10px; border-radius: 20px; font-size: 10px; flex-shrink: 0; }

</style>

<!-- ══ PAGE HEADER ══ -->
<div class="page-header dash-page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:16px">
  <div>
    <h1>Dashboard</h1>
    <p><?= SITE_NAME ?> · <?= date('l, d F Y') ?> · Sistem Manajemen Pengangkutan Sampah</p>
  </div>
  <div class="dash-header-actions" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
    <div class="live-badge"><span class="live-dot"></span> Live · <span id="lastRefresh">--:--:--</span></div>
    <?php if ($unassigned > 0): ?>
    <a href="req_management.php" class="action-chip">⚠️ <?= $unassigned ?> belum di-assign</a>
    <?php endif; ?>
    <?php if ($pending_req > 0): ?>
    <a href="req_management.php?status=menunggu" class="action-chip danger">🔴 <?= $pending_req ?> menunggu</a>
    <?php endif; ?>
    <a href="live_tracking.php" class="btn btn-outline btn-sm">🛵 Pelacakan Live</a>
    <a href="req_management.php" class="btn btn-primary btn-sm">+ Tambah Request</a>
  </div>
</div>

<!-- Inactive Officers Alert Box -->
<?php if (count($alertOfficers) > 0): ?>
<div style="margin-bottom: 20px; background: #fff5f5; border: 1.5px solid #fecaca; border-radius: 12px; padding: 14px 18px; display: flex; align-items: flex-start; gap: 12px; box-shadow: 0 4px 12px rgba(220,38,38,0.05); animation: fadeIn 0.4s ease both;">
  <span style="font-size: 22px; line-height: 1; margin-top: 2px;">⚠️</span>
  <div style="flex: 1;">
    <h4 style="margin: 0 0 4px; font-size: 13.5px; font-weight: 800; color: #991b1b;">Peringatan Ketidakaktifan Petugas</h4>
    <p style="margin: 0; font-size: 12.5px; color: #7f1d1d; line-height: 1.5;">
      Ditemukan <strong><?= count($alertOfficers) ?> petugas aktif</strong> yang tidak menyelesaikan penjemputan sampah selama <strong><?= $thresholdDays ?> hari berturut-turut</strong> atau lebih:
    </p>
    <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px;">
      <?php foreach ($alertOfficers as $ao): ?>
        <a href="officer_management.php?preview=<?= $ao['id'] ?>" 
           style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: background 0.15s;" 
           onmouseover="this.style.background='#fca5a5'" 
           onmouseout="this.style.background='#fee2e2'">
          👷 <?= htmlspecialchars($ao['nama']) ?> (<?= htmlspecialchars($ao['officer_code']) ?>) · <strong style="color: #ef4444;"><?= $ao['inactive_days'] ?> Hari</strong>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <a href="officer_management.php" class="btn btn-outline btn-sm" style="border-color: #fca5a5; color: #991b1b; background: #fff; flex-shrink: 0; margin-left: auto;">Kelola Petugas</a>
</div>
<?php endif; ?>

<!-- ══ KPI STRIP ══ -->
<div class="kpi-strip">
  <div class="kpi-card green" id="sc-total">
    <div class="kpi-label">Total Request</div>
    <div class="kpi-value"><?= number_format($total_req) ?></div>
    <div class="kpi-sub">semua waktu</div>
  </div>
  <div class="kpi-card amber" id="sc-pending">
    <div class="kpi-label">Menunggu</div>
    <div class="kpi-value"><?= $pending_req ?></div>
    <div class="kpi-sub">perlu konfirmasi</div>
  </div>
  <div class="kpi-card red" id="sc-prog">
    <div class="kpi-label">Pesanan Aktif</div>
    <div class="kpi-value"><?= $in_progress ?></div>
    <div class="kpi-sub">sedang dalam rute</div>
  </div>
  <div class="kpi-card green" id="sc-today">
    <div class="kpi-label">Selesai Hari Ini</div>
    <div class="kpi-value"><?= $done_today ?></div>
    <div class="kpi-sub">penjemputan berhasil</div>
  </div>
  <div class="kpi-card blue" id="sc-officers">
    <div class="kpi-label">Petugas Aktif</div>
    <div class="kpi-value"><?= $active_off ?></div>
    <div class="kpi-sub">dari <?= $total_off ?> terdaftar</div>
  </div>
  <div class="kpi-card purple">
    <div class="kpi-label">Unassigned</div>
    <div class="kpi-value" id="sc-unassigned"><?= $unassigned ?></div>
    <div class="kpi-sub">belum ada petugas</div>
  </div>
  <div class="kpi-card teal">
    <div class="kpi-label">Berat Bulan Ini</div>
    <div class="kpi-value" id="sc-weight"><?= number_format($total_kg,1) ?></div>
    <div class="kpi-sub">kg terkumpul</div>
  </div>
  <a href="live_tracking.php" class="kpi-card teal" style="text-decoration:none; border-left:4px solid var(--green-500);">
    <div class="kpi-label">Pelacakan Kurir</div>
    <div class="kpi-value">📡 Live</div>
    <div class="kpi-sub">Peta Posisi Kurir</div>
  </a>
  <div class="kpi-card green">
    <div class="kpi-label">GPS Coverage</div>
    <div class="kpi-value" id="sc-gps"><?= $gps_coverage ?>%</div>
    <div class="kpi-sub" id="sc-gps-sub"><?= $with_gps ?> dari <?= $total_req ?> request</div>
  </div>
</div>

<!-- ══ SECTION: DATA ANALYSIS ══ -->
<div class="db-section-title">📊 Data Analysis & Visualisasi</div>

<!-- Charts Row 1: Tren + Status -->
<div class="grid-2 mb-24">
  <div class="chart-card">
    <div class="chart-card-title"><span class="ct-icon">📈</span> Tren Request Masuk & Selesai — 30 Hari</div>
    <div style="height:200px;position:relative"><canvas id="chartTrend"></canvas></div>
  </div>
  <div class="chart-card">
    <div class="chart-card-title"><span class="ct-icon">🍩</span> Distribusi Status Request</div>
    <div style="display:flex;align-items:center;gap:16px">
      <div style="height:170px;max-width:170px;flex-shrink:0;position:relative"><canvas id="chartStatus"></canvas></div>
      <div style="flex:1;display:flex;flex-direction:column;gap:5px">
        <?php foreach ($statusRows as $s):
          $clr = $statusColorMap[$s['status']] ?? '#aaa';
          $lbl = $statusDisplayMap[$s['status']] ?? $s['status'];
          $pct = $total_req > 0 ? round($s['cnt']/$total_req*100) : 0;
        ?>
        <div style="display:flex;align-items:center;gap:7px;font-size:12px">
          <span style="width:9px;height:9px;border-radius:50%;background:<?= $clr ?>;flex-shrink:0"></span>
          <span style="flex:1;color:#555;font-weight:600"><?= $lbl ?></span>
          <span style="font-weight:800;min-width:26px;text-align:right;color:#1e293b"><?= $s['cnt'] ?></span>
          <span style="color:#cbd5e1;min-width:32px;text-align:right;font-size:11px"><?= $pct ?>%</span>
        </div>
        <?php endforeach; ?>
        <div style="margin-top:8px;padding-top:8px;border-top:1px solid #f0f0f0;font-size:11px;color:#94a3b8">
          Completion rate: <strong style="color:#16a34a"><?= $completion_rate ?>%</strong>
          <?php if($avg_complete): ?> · Avg <?= $avg_complete ?>j/request<?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Charts Row 2: Kecamatan + Jenis Sampah -->
<div class="grid-2 mb-24">
  <div class="chart-card">
    <div class="chart-card-title"><span class="ct-icon">🏙️</span> Request per Kecamatan (Total / Selesai / Aktif)</div>
    <div style="height:230px;position:relative"><canvas id="chartKec"></canvas></div>
  </div>
  <div class="chart-card">
    <div class="chart-card-title"><span class="ct-icon">♻️</span> Jenis Sampah Terkumpul (kg estimasi)</div>
    <div style="height:230px;position:relative"><canvas id="chartWaste"></canvas></div>
  </div>
</div>

<!-- Charts Row 3: Mingguan + Penerimaan Request per Bulan -->
<div class="grid-2 mb-24">
  <div class="chart-card">
    <div class="chart-card-title"><span class="ct-icon">📊</span> Tren Mingguan — 12 Minggu Terakhir</div>
    <div style="height:180px;position:relative"><canvas id="chartWeekly"></canvas></div>
  </div>
  <div class="chart-card">
    <div class="chart-card-title"><span class="ct-icon">📅</span> Penerimaan Request per Bulan</div>
    <div style="height:180px;position:relative"><canvas id="chartMonthly"></canvas></div>
  </div>
</div>

<!-- Charts Row 4: Sampah Bulanan (kg) + Performa Petugas -->
<div class="grid-2 mb-24">
  <div class="chart-card">
    <div class="chart-card-title"><span class="ct-icon">⚖️</span> Total Sampah Terkumpul per Bulan (kg)</div>
    <div style="height:200px;position:relative"><canvas id="chartMonthlyWeight"></canvas></div>
  </div>
  <div class="chart-card">
    <div class="chart-card-title"><span class="ct-icon">👷</span> Performa Petugas — Request Selesai vs Aktif</div>
    <div style="height:200px;position:relative"><canvas id="chartOfficerPerf"></canvas></div>
  </div>
</div>

<!-- ══ SECTION: HEATMAP KECAMATAN ══ -->
<div class="db-section-title">🗺️ Heatmap Permintaan per Kecamatan</div>
<div class="chart-card mb-24">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px">
    <span style="font-size:12px;color:#94a3b8">Intensitas warna = jumlah request aktif. Klik sel untuk filter.</span>
    <a href="rute_jadwal.php" class="btn btn-outline btn-sm">Lihat Rute & Jadwal →</a>
  </div>
  <div class="kec-heatmap">
    <?php
    $maxKec = max(array_column($kecData,'cnt') ?: [1]);
    $heatPalette = ['#dcfce7','#bbf7d0','#86efac','#4ade80','#22c55e','#16a34a','#15803d','#166534'];
    foreach ($kecData as $k):
      $intensity = round(($k['cnt'] / $maxKec) * 7);
      $bg = $heatPalette[$intensity] ?? $heatPalette[7];
      $txtClr = $intensity >= 4 ? '#fff' : '#1e293b';
      $activeKec = $k['aktif'];
    ?>
    <div class="hm-cell" style="background:<?= $bg ?>;color:<?= $txtClr ?>"
         onclick="location.href='req_management.php?kecamatan=<?= urlencode($k['kecamatan']) ?>'"
         title="Klik untuk lihat request <?= htmlspecialchars($k['kecamatan']) ?>">
      <div class="hm-kec"><?= htmlspecialchars($k['kecamatan']) ?></div>
      <div class="hm-cnt"><?= $k['cnt'] ?></div>
      <div class="hm-sub"><?= $activeKec ?> aktif · <?= $k['selesai'] ?> selesai</div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ══ SECTION: PETA LOKASI (Leaflet + OpenStreetMap) ══ -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<div class="db-section-title">📍 Peta Lokasi Request Aktif</div>
<div class="chart-card mb-24">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:8px">
    <span style="font-size:12px;color:#94a3b8">Menampilkan request yang memiliki koordinat GPS. Klik marker untuk detail.</span>
    <div style="display:flex;gap:6px">
      <button class="btn btn-outline btn-sm" onclick="filterMapStatus('all')">Semua</button>
      <button class="btn btn-outline btn-sm" onclick="filterMapStatus('menunggu')" style="color:#d97706;border-color:#fde68a">Menunggu</button>
      <button class="btn btn-outline btn-sm" onclick="filterMapStatus('dijadwalkan')" style="color:#7c3aed;border-color:#c4b5fd">Dijadwalkan</button>
    </div>
  </div>
  <div id="dashMiniMap" style="width:100%;height:280px;border-radius:8px;border:1px solid #e2e8f0"></div>
  <div style="margin-top:8px;font-size:11px;color:#94a3b8;display:flex;gap:14px;flex-wrap:wrap">
    <span>🟡 Menunggu</span><span>🟠 Dikonfirmasi</span><span>🟣 Dijadwalkan</span><span>🟠 Diproses</span>
    <span style="margin-left:auto"><a href="rute_jadwal.php" style="color:#1c6434;font-weight:700">Buka Peta Rute Lengkap →</a></span>
  </div>
</div>

<!-- ══ SECTION: OPERASIONAL CONTROL ══ -->
<div class="db-section-title">⚡ Kontrol Operasional</div>

<div class="grid-2 mb-24" style="--cols: 1.2fr 0.8fr">
  <!-- Perlu Verifikasi -->
  <div class="chart-card">
    <div class="chart-card-title">
        <span class="ct-icon">🚨</span> Request Perlu Verifikasi
        <span style="margin-left:auto;font-size:10px;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:12px;font-weight:700">
            Admin: Hanya Verifikasi
        </span>
    </div>
    <div style="font-size:11px;color:#94a3b8;margin-bottom:12px;padding:8px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0">
        ℹ️ Jadwal & Petugas Daur Ulang otomatis diatur algoritma. Untuk Clean Up Service, atur langsung di modulnya. Admin hanya verifikasi data masuk atau batalkan jika tidak valid.
    </div>
    <?php if ($needVerification): ?>
    <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead><tr>
          <th style="padding:7px 10px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;border-bottom:2px solid #f0f0f0;text-transform:uppercase">ID</th>
          <th style="padding:7px 10px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;border-bottom:2px solid #f0f0f0;text-transform:uppercase">Pemohon / Layanan</th>
          <th class="tbl-hide-mobile" style="padding:7px 10px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;border-bottom:2px solid #f0f0f0;text-transform:uppercase">Sub-district</th>
          <th style="padding:7px 10px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;border-bottom:2px solid #f0f0f0;text-transform:uppercase">Status</th>
          <th style="padding:7px 10px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;border-bottom:2px solid #f0f0f0;text-transform:uppercase">Aksi</th>
        </tr></thead>
        <tbody>
          <?php foreach ($needVerification as $r): ?>
          <tr style="border-bottom:1px solid #f5f5f5">
            <td style="padding:8px 10px;font-weight:800;color:#1c6434;font-family:monospace;font-size:11px"><?= htmlspecialchars($r['request_code']) ?></td>
            <td style="padding:8px 10px;font-weight:600;color:#1e293b">
                <?= htmlspecialchars($r['nama_pemohon']) ?>
                <div style="font-size:10px;color:#94a3b8"><?= htmlspecialchars(ucfirst($r['entity_name'])) ?></div>
            </td>
            <td class="tbl-hide-mobile" style="padding:8px 10px;color:#64748b;font-size:11px"><?= htmlspecialchars($r['kecamatan']??'-') ?></td>
            <td style="padding:8px 10px">
              <span style="background:#fef3c7;color:#d97706;border:1px solid #fde68a;border-radius:10px;padding:2px 8px;font-size:10px;font-weight:800">Menunggu</span>
              <div style="font-size:9px;color:#64748b;margin-top:4px;font-weight:700"><?= $r['type'] === 'pickup' ? '🚛 Pickup' : '🧹 Clean Up' ?></div>
            </td>
            <td style="padding:8px 10px">
              <?php if ($r['type'] === 'pickup'): ?>
              <div style="display:flex;gap:4px" class="verif-action-btns">
                <button onclick="quickStatus(<?= $r['id'] ?>,'dikonfirmasi',this)" data-prev="menunggu"
                  style="padding:4px 12px;background:#dbeafe;color:#1e40af;border:none;border-radius:6px;font-size:10px;font-weight:700;cursor:pointer">
                  ✔ Konfirmasi
                </button>
                <button onclick="quickStatus(<?= $r['id'] ?>,'dibatalkan',this)" data-prev="menunggu"
                  style="padding:4px 12px;background:#fee2e2;color:#991b1b;border:none;border-radius:6px;font-size:10px;font-weight:700;cursor:pointer">
                  ✕ Batalkan
                </button>
              </div>
              <?php else: ?>
              <a href="cleanup_management.php?status=menunggu" class="btn btn-outline btn-sm" style="font-size:10px;padding:3px 10px">Set Biaya →</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="margin-top:10px;text-align:right">
        <a href="req_management.php?status=menunggu" class="btn btn-outline btn-sm" style="margin-right:4px">Semua Pickup →</a>
        <a href="cleanup_management.php?status=menunggu" class="btn btn-outline btn-sm">Semua Clean Up →</a>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:28px;color:#94a3b8"><div style="font-size:32px;margin-bottom:8px">✅</div><div style="font-weight:700;font-size:13px">Semua request sudah diverifikasi!</div></div>
    <?php endif; ?>
  </div>

  <!-- Beban Petugas -->
  <div class="chart-card">
    <div class="chart-card-title"><span class="ct-icon">👷</span> Beban Tugas Petugas Real-time</div>
    <?php if ($officerLoad):
      $maxLoad = max(1, max(array_column($officerLoad,'aktif_tugas') ?: [0]));
      foreach ($officerLoad as $o):
        $pct = round($o['aktif_tugas'] / $maxLoad * 100);
        $barClr = $o['aktif_tugas'] >= 6 ? '#ef4444' : ($o['aktif_tugas'] >= 3 ? '#f59e0b' : '#22c55e');
    ?>
    <div style="margin-bottom:12px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px">
        <span style="font-size:13px;font-weight:700;color:#1e293b"><?= htmlspecialchars($o['nama']) ?></span>
        <span style="font-size:12px;font-weight:800;color:<?= $barClr ?>"><?= $o['aktif_tugas'] ?> tugas</span>
      </div>
      <div class="officer-load-bar"><div class="officer-load-fill" style="width:<?= $pct ?>%;background:<?= $barClr ?>"></div></div>
    </div>
    <?php endforeach; else: ?>
    <p style="color:#aaa;font-size:13px;text-align:center;padding:20px">Belum ada petugas aktif.</p>
    <?php endif; ?>
    <div style="margin-top:10px"><a href="officer_management.php" class="btn btn-outline btn-sm">Kelola Petugas →</a></div>
  </div>
</div>

<!-- ══ SECTION: KENDALA LAPANGAN (INTEGRASI PETUGAS) ══ -->
<?php if ($kendalaReports): ?>
<div class="db-section-title" style="color:#ef4444">🚨 Laporan Kendala Lapangan</div>
<div class="grid-1 mb-24">
    <div class="chart-card" style="border:1.5px solid #fecaca; background:#fffcfc">
        <div style="overflow-x:auto">
          <table style="width:100%;border-collapse:collapse;font-size:12px">
            <thead><tr>
              <th style="padding:10px;text-align:left;color:#991b1b;border-bottom:1px solid #fee2e2;text-transform:uppercase;font-size:10px">Waktu</th>
              <th style="padding:10px;text-align:left;color:#991b1b;border-bottom:1px solid #fee2e2;text-transform:uppercase;font-size:10px">Request</th>
              <th style="padding:10px;text-align:left;color:#991b1b;border-bottom:1px solid #fee2e2;text-transform:uppercase;font-size:10px">Petugas</th>
              <th style="padding:10px;text-align:left;color:#991b1b;border-bottom:1px solid #fee2e2;text-transform:uppercase;font-size:10px">Detail Kendala</th>
              <th style="padding:10px;text-align:center;color:#991b1b;border-bottom:1px solid #fee2e2;text-transform:uppercase;font-size:10px">Aksi</th>
            </tr></thead>
            <tbody>
              <?php foreach ($kendalaReports as $k): ?>
              <tr style="border-bottom:1px solid #fff1f2">
                <td style="padding:12px 10px;color:#991b1b;font-size:11px"><?= date('H:i', strtotime($k['updated_at'])) ?></td>
                <td style="padding:12px 10px">
                    <div style="font-weight:800;color:#991b1b"><?= htmlspecialchars($k['request_code']) ?></div>
                    <div style="font-size:10px;color:#ef4444"><?= htmlspecialchars($k['nama_pemohon']) ?></div>
                </td>
                <td style="padding:12px 10px;font-weight:700;color:#991b1b"><?= htmlspecialchars($k['officer_nama']) ?></td>
                <td style="padding:12px 10px">
                    <div style="background:#fee2e2;padding:6px 10px;border-radius:6px;color:#991b1b;font-size:11px;border-left:3px solid #ef4444">
                        <?= htmlspecialchars($k['catatan_officer']) ?>
                    </div>
                </td>
                <td style="padding:12px 10px;text-align:center">
                    <a href="req_management.php?edit=<?= $k['id'] ?>" class="btn btn-sm" style="background:#991b1b;color:#fff;border:none">Reschedule</a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ SECTION: PESANAN AKTIF (TERINTEGRASI) ══ -->
<div class="db-section-title">📦 Pesanan Aktif <span class="action-chip" style="margin-left:8px"><?= count($activeOrders) ?></span> — Admin: Hanya Verifikasi · Jadwal Ditentukan Algoritma</div>

<div class="chart-card mb-24">
    <!-- Status Flow Indicator (Image 2) -->
    <div class="status-flow">
        <div class="flow-step">
            <span class="flow-badge" style="background:#fef3c7;color:#d97706">🟡 Menunggu</span>
            <span class="flow-sep">→ Admin verifikasi →</span>
        </div>
        <div class="flow-step">
            <span class="flow-badge" style="background:#dbeafe;color:#1e40af">🔵 Dikonfirmasi</span>
            <span class="flow-sep">→ Algoritma →</span>
        </div>
        <div class="flow-step">
            <span class="flow-badge" style="background:#ede9fe;color:#7c3aed">🟣 Dijadwalkan</span>
            <span class="flow-sep">→</span>
        </div>
        <div class="flow-step">
            <span class="flow-badge" style="background:#ffedd5;color:#ea580c">🟠 Diproses</span>
            <span class="flow-sep">→</span>
        </div>
        <div class="flow-step">
            <span class="flow-badge" style="background:#dcfce7;color:#16a34a">🟢 Selesai</span>
        </div>
    </div>

    <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead><tr>
          <th style="padding:10px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;border-bottom:1px solid #f0f0f0;text-transform:uppercase">ID</th>
          <th style="padding:10px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;border-bottom:1px solid #f0f0f0;text-transform:uppercase">Place & Partner</th>
          <th style="padding:10px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;border-bottom:1px solid #f0f0f0;text-transform:uppercase">Sub-district</th>
          <th style="padding:10px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;border-bottom:1px solid #f0f0f0;text-transform:uppercase">Status</th>
          <th style="padding:10px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;border-bottom:1px solid #f0f0f0;text-transform:uppercase">Staff ID</th>
          <th style="padding:10px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;border-bottom:1px solid #f0f0f0;text-transform:uppercase">Date</th>
          <th style="padding:10px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;border-bottom:1px solid #f0f0f0;text-transform:uppercase">Verifikasi Admin</th>
        </tr></thead>
        <tbody>
          <?php foreach ($activeOrders as $r):
            $stClr = $statusColorMap[$r['status']] ?? '#aaa';
            $oNama = $r['officer_nama'] ?: '—';
            $tgl   = $r['tanggal_jemput'] ? date('d M Y', strtotime($r['tanggal_jemput'])) : '<span style="color:#cbd5e1">Belum dijadwalkan</span>';
          ?>
          <tr style="border-bottom:1px solid #f8fafc">
            <td style="padding:12px 10px;font-weight:700;color:#1c6434"><?= htmlspecialchars($r['request_code']) ?></td>
            <td style="padding:12px 10px;font-weight:700;color:#1e293b"><?= htmlspecialchars($r['partner_name'] ?: $r['nama_pemohon']) ?></td>
            <td style="padding:12px 10px;color:#64748b"><?= htmlspecialchars($r['kecamatan']??'-') ?></td>
            <td style="padding:12px 10px">
                <span style="background:<?= $stClr ?>22;color:<?= $stClr ?>;border:1px solid <?= $stClr ?>44;border-radius:10px;padding:2px 10px;font-size:10px;font-weight:800">
                    <?= $statusDisplayMap[$r['status']] ?? $r['status'] ?>
                </span>
            </td>
            <td style="padding:12px 10px;color:#64748b;font-size:11px"><?= htmlspecialchars($oNama) ?></td>
            <td style="padding:12px 10px;font-size:11px;color:#64748b">📅 <?= $tgl ?></td>
            <td style="padding:12px 10px">
              <?php if ($r['status'] === 'menunggu'): ?>
                <span style="color:#94a3b8;font-size:10px;font-style:italic">Menunggu Verifikasi</span>
              <?php elseif (in_array($r['status'], ['dikonfirmasi','dijadwalkan'])): ?>
                <a href="req_management.php?edit=<?= $r['id'] ?>" class="btn btn-outline btn-sm" style="border-color:#bbf7d0;color:#166534;background:#f0fdf4;padding:3px 10px">
                  📝 Edit Jadwal
                </a>
              <?php else: ?>
                <span style="color:#16a34a;font-weight:700;font-size:10px">🚚 Sedang Dijemput</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="margin-top:14px;display:flex;justify-content:space-between;align-items:center">
        <span style="font-size:11px;color:#94a3b8">Jadwal otomatis diatur algoritma di <a href="rute_jadwal.php" style="color:#1c6434;font-weight:700">Rute & Jadwal</a></span>
        <a href="req_management.php" class="btn btn-outline btn-sm">Semua Request →</a>
    </div>
</div>

<!-- ══ SECTION: JADWAL & AKTIVITAS ══ -->
<div class="grid-2 mb-24">
  <!-- Priority Jadwal -->
  <div class="chart-card">
    <div class="chart-card-title"><span class="ct-icon">🗓️</span> Jadwal Prioritas Sabtu Ini
      <span style="margin-left:auto;font-size:11px;color:#94a3b8;font-weight:500">Priority Rule</span>
    </div>
    <?php if ($prio_kec): ?>
    <div class="priority-timeline">
      <?php foreach ($prio_kec as $i => $k):
        $jam = sprintf('%02d:00', 8 + $i);
        $est = round($k['cnt'] * 0.35, 1);
        $dotClr = ['#22c55e','#3b82f6','#8b5cf6','#94a3b8'][$i] ?? '#94a3b8';
      ?>
      <div class="pt-item">
        <div class="pt-dot-wrap">
          <div class="pt-dot" style="background:<?= $dotClr ?>"></div>
          <?php if ($i < count($prio_kec)-1): ?><div class="pt-line"></div><?php endif; ?>
        </div>
        <div class="pt-body">
          <div class="pt-title">
            <span style="background:<?= $dotClr ?>22;color:<?= $dotClr ?>;border-radius:4px;padding:1px 6px;font-size:10px;font-weight:800;margin-right:5px">P<?= $i+1 ?></span>
            Kec. <?= htmlspecialchars($k['kecamatan']) ?> — <?= $k['cnt'] ?> titik
          </div>
          <div class="pt-sub"><?= $jam ?> · ±<?= $est ?> jam · <?= nextSaturday(0) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p style="color:#aaa;font-size:13px;text-align:center;padding:16px">Tidak ada request aktif.</p>
    <?php endif; ?>
    <div style="margin-top:14px">
      <a href="rute_jadwal.php" class="btn btn-primary btn-sm">Lihat Rute & Jadwal →</a>
    </div>
  </div>

  <!-- Aktivitas Terkini -->
  <div class="chart-card">
    <div class="chart-card-title"><span class="ct-icon">⚡</span> Aktivitas Terkini</div>
    <?php if ($activities): ?>
    <div class="activity-feed">
      <?php foreach ($activities as $i => $a): ?>
      <div class="tl-item" style="margin-bottom:2px">
        <div class="tl-dot-wrap">
          <div class="tl-dot" style="background:#3b82f6"></div>
          <?php if ($i < count($activities)-1): ?><div class="tl-line"></div><?php endif; ?>
        </div>
        <div class="tl-body">
          <div class="tl-title"><?= htmlspecialchars($a['aksi']) ?></div>
          <div class="tl-sub"><?= fmtDate($a['created_at'],'d M Y H:i') ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p style="color:#aaa;font-size:13px;text-align:center;padding:16px">Belum ada aktivitas.</p>
    <?php endif; ?>
  </div>
</div>


<!-- ══ SECTION: PESANAN SELESAI ══ -->
<div class="db-section-title">✅ Pesanan Selesai
  <span style="margin-left:auto;font-size:10px;color:#94a3b8;font-weight:500">10 terbaru</span>
</div>
<div class="chart-card mb-24">
  <?php if ($pesananSelesai): ?>
  <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
      <thead><tr>
        <?php foreach (['ID','Place & Partner','Sub-district','Status','Staff ID','Date (Done)','Weight (kg)'] as $th): ?>
        <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;border-bottom:2px solid #f0f0f0;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap"><?= $th ?></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
        <?php foreach ($pesananSelesai as $r):
          $stClr = $r['status']==='selesai' ? '#22c55e' : '#ef4444';
          $stLbl = $r['status']==='selesai' ? '✅ Selesai' : '❌ Dibatalkan';
        ?>
        <tr style="border-bottom:1px solid #f5f5f5;transition:background .12s" onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background=''">
          <td style="padding:8px 10px"><a href="req_management.php?preview=<?= $r['id'] ?>" style="font-weight:800;color:#1c6434;font-size:11px;font-family:monospace"><?= htmlspecialchars($r['request_code']) ?></a></td>
          <td style="padding:8px 10px;font-weight:600;color:#1e293b"><?= htmlspecialchars($r['partner_name'] ?: $r['nama_pemohon']) ?></td>
          <td style="padding:8px 10px;color:#64748b"><?= htmlspecialchars($r['kecamatan']??'-') ?></td>
          <td style="padding:8px 10px">
            <span style="background:<?= $stClr ?>22;color:<?= $stClr ?>;border:1px solid <?= $stClr ?>44;border-radius:10px;padding:2px 8px;font-size:10px;font-weight:800"><?= $stLbl ?></span>
          </td>
          <td style="padding:8px 10px;color:#64748b;font-size:11px"><?= htmlspecialchars($r['officer_nama']??'—') ?></td>
          <td style="padding:8px 10px;color:#94a3b8;font-size:11px;white-space:nowrap"><?= fmtDate($r['updated_at'],'d M Y H:i') ?></td>
          <td style="padding:8px 10px;font-weight:700;color:#1c6434"><?= $r['berat_total_kg'] ? number_format((float)$r['berat_total_kg'],1).' kg' : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div style="margin-top:10px;text-align:right">
    <a href="req_management.php?status=selesai" class="btn btn-outline btn-sm">Lihat Semua Selesai →</a>
  </div>
  <?php else: ?>
  <div style="text-align:center;padding:28px;color:#94a3b8">
    <div style="font-size:32px;margin-bottom:8px">📋</div>
    <div style="font-size:13px">Belum ada pesanan yang selesai.</div>
  </div>
  <?php endif; ?>
</div>

<!-- ══ SECTION: REQUEST TERBARU ══ -->
<div class="db-section-title">📋 Request Terbaru</div>
<div class="chart-card mb-24">
  <div style="overflow-x:auto">

    <table style="width:100%;border-collapse:collapse;font-size:12px">
      <thead><tr>
        <?php foreach (['ID','Place & Partner','Sub-district','Status','Staff ID','Timestamp'] as $th): ?>
        <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;border-bottom:2px solid #f0f0f0;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap"><?= $th ?></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
        <?php foreach ($recent_req as $r):
          $stClr = $statusColorMap[$r['status']] ?? '#aaa';
          $stLbl = $statusDisplayMap[$r['status']] ?? $r['status'];
        ?>
        <tr style="border-bottom:1px solid #f5f5f5;transition:background .12s" onmouseover="this.style.background='#f8fffe'" onmouseout="this.style.background=''">
          <td style="padding:9px 12px"><a href="req_management.php?preview=<?= $r['id'] ?>" style="font-weight:800;color:#1c6434;font-size:11px;font-family:monospace"><?= htmlspecialchars($r['request_code']) ?></a></td>
          <td style="padding:9px 12px;font-weight:600;color:#1e293b"><?= htmlspecialchars($r['partner_name'] ?: $r['nama_pemohon']) ?></td>
          <td style="padding:9px 12px;color:#64748b"><?= htmlspecialchars($r['kecamatan']??'-') ?></td>
          <td style="padding:9px 12px">
            <span style="background:<?= $stClr ?>22;color:<?= $stClr ?>;border:1px solid <?= $stClr ?>44;border-radius:10px;padding:2px 8px;font-size:10px;font-weight:800"><?= $stLbl ?></span>
          </td>
          <td style="padding:9px 12px;color:#94a3b8;font-size:11px"><?= htmlspecialchars($r['officer_nama']??'—') ?></td>
          <td style="padding:9px 12px;color:#cbd5e1;font-size:11px;white-space:nowrap"><?= fmtDate($r['created_at'],'d M H:i') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div style="margin-top:12px;text-align:right">
    <a href="req_management.php" class="btn btn-outline btn-sm">Lihat Semua Request →</a>
  </div>
</div>

<!-- ══ SECTION: ADMIN — EVALUASI OFFICER ══ -->
<div class="db-section-title">👷 Evaluasi Performa Officer</div>
<div class="chart-card mb-24">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px">
    <span style="font-size:12px;color:#94a3b8">Pantau efisiensi officer — klik nama untuk detail</span>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <a href="officer_management.php" class="btn btn-outline btn-sm">⚙️ Kelola Petugas</a>
      <a href="rute_jadwal.php" class="btn btn-outline btn-sm">🗺️ Atur Rute</a>
    </div>
  </div>

  <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
      <thead><tr>
        <?php foreach (['Officer','Kode','Status','Total','Selesai','Aktif','Selesai Hari Ini','Completion Rate','Aksi'] as $th): ?>
        <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;border-bottom:2px solid #f0f0f0;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap"><?= $th ?></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
        <?php foreach ($officerEvalRows as $oe):
          $oTotal   = (int)$oe['total'];
          $oSelesai = (int)$oe['selesai'];
          $oRate    = $oTotal > 0 ? round($oSelesai / $oTotal * 100) : 0;
          $oStClr   = match($oe['status']) { 'aktif'=>'#22c55e', 'cuti'=>'#f59e0b', default=>'#ef4444' };
          $oStLbl   = match($oe['status']) { 'aktif'=>'✅ Aktif', 'cuti'=>'🟡 Cuti', default=>'🔴 Nonaktif' };
          $rateClr  = $oRate >= 70 ? '#16a34a' : ($oRate >= 40 ? '#d97706' : '#dc2626');
        ?>
        <tr style="border-bottom:1px solid #f5f5f5">
          <td style="padding:9px 12px;font-weight:700;color:#1e293b"><?= htmlspecialchars($oe['nama']) ?></td>
          <td style="padding:9px 12px;font-family:monospace;font-size:11px;color:#1c6434;font-weight:700"><?= htmlspecialchars($oe['officer_code']) ?></td>

          <td style="padding:9px 12px">
            <span style="background:<?= $oStClr ?>22;color:<?= $oStClr ?>;border:1px solid <?= $oStClr ?>44;border-radius:10px;padding:2px 8px;font-size:10px;font-weight:800"><?= $oStLbl ?></span>
          </td>
          <td style="padding:9px 12px;font-weight:700"><?= $oTotal ?></td>
          <td style="padding:9px 12px;color:#16a34a;font-weight:800"><?= $oSelesai ?></td>
          <td style="padding:9px 12px;color:#d97706;font-weight:700"><?= (int)$oe['aktif'] ?></td>
          <td style="padding:9px 12px;font-weight:700;color:#1c6434"><?= (int)$oe['selesai_hari_ini'] ?></td>
          <td style="padding:9px 12px">
            <div style="display:flex;align-items:center;gap:8px">
              <div style="width:60px;height:5px;background:#f0f0f0;border-radius:3px;overflow:hidden">
                <div style="width:<?= $oRate ?>%;height:100%;background:<?= $rateClr ?>;border-radius:3px"></div>
              </div>
              <span style="font-weight:800;color:<?= $rateClr ?>;font-size:11px"><?= $oRate ?>%</span>
            </div>
          </td>
          <td style="padding:9px 12px">
            <div style="display:flex;gap:5px">
              <a href="officer_management.php?preview=<?= $oe['id'] ?>" class="btn btn-outline btn-sm" style="padding:3px 8px;font-size:10px">👁️</a>
              <a href="officer_management.php?edit=<?= $oe['id'] ?>" class="btn btn-outline btn-sm" style="padding:3px 8px;font-size:10px">✏️</a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
    <a href="officer_management.php" class="btn btn-primary btn-sm">Manajemen Lengkap Petugas →</a>
  </div>
</div>

<!-- ══ SECTION: RINGKASAN TARIF ══ -->
<div class="chart-card mb-24">
  <div class="chart-card-title"><span class="ct-icon">💰</span> Ringkasan Layanan & KPI Bulan Ini</div>
  <div class="grid-3" style="gap:10px;margin-bottom:14px">
    <div style="background:#f0fdf4;border-radius:8px;padding:14px;border:1px solid #bbf7d0">
      <div style="font-size:10px;color:#666;font-weight:700;text-transform:uppercase;margin-bottom:5px">Reguler (Warga)</div>
      <div style="font-size:20px;font-weight:800;color:#16a34a">GRATIS</div>
    </div>
    <div style="background:#e8f5e9;border-radius:8px;padding:14px;border:1px solid #c8e6c9">
      <div style="font-size:10px;color:#666;font-weight:700;text-transform:uppercase;margin-bottom:5px">Clean Up Service</div>
      <div style="font-size:20px;font-weight:800;color:#1565c0">Rp 50K<span style="font-size:12px;font-weight:500;color:#888">/jam</span></div>
    </div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;font-size:12px">
    <?php foreach ([
      ['Selesai Bulan Ini',    "$done_month request",         '#16a34a'],
      ['Total Berat Bulan Ini',"$total_kg kg",                '#0284c7'],
      ['Completion Rate',      "$completion_rate%",           '#7c3aed'],
      ['GPS Coverage',         "$gps_coverage% request",      '#d97706'],
      ['Rata2 Waktu Selesai',  $avg_complete ? "{$avg_complete} jam" : '—', '#64748b'],
    ] as [$lbl, $val, $clr]): ?>
    <div style="background:#f9fafb;border-radius:7px;padding:10px 12px;display:flex;justify-content:space-between;align-items:center">
      <span style="color:#64748b;font-weight:600"><?= $lbl ?></span>
      <strong style="color:<?= $clr ?>"><?= $val ?></strong>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
    <a href="laporan_bulanan.php"  class="btn btn-outline btn-sm">Laporan Bulanan</a>
    <a href="laporan_mingguan.php" class="btn btn-outline btn-sm">Laporan Mingguan</a>
    <a href="analisis_data.php"    class="btn btn-outline btn-sm">Analisis Data Lengkap</a>
  </div>
</div>

<!-- ══ JAVASCRIPT ══ -->
<script>
Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#666';

const GREEN  = '#1c6434', GREEN2 = '#22c55e', BLUE = '#3b82f6';
const AMBER  = '#f59e0b', PURPLE = '#8b5cf6', RED  = '#ef4444';

// ── Chart 1: Tren 30 hari (line dual) ────────────────────────
new Chart(document.getElementById('chartTrend'), {
  type:'line',
  data:{
    labels: <?= json_encode($trendLabels) ?>,
    datasets:[
      { label:'Request Masuk', data:<?= json_encode($trendValues) ?>,
        fill:true, backgroundColor:'rgba(28,100,52,.1)', borderColor:GREEN,
        borderWidth:2, pointRadius:2, tension:.4 },
      { label:'Selesai', data:<?= json_encode($trendSelesai) ?>,
        fill:false, borderColor:GREEN2, borderWidth:2,
        pointRadius:2, tension:.4, borderDash:[4,2] }
    ]
  },
  options:{responsive:true,maintainAspectRatio:false,
    plugins:{legend:{position:'top',labels:{boxWidth:10,padding:10}}},
    scales:{x:{grid:{display:false},ticks:{maxRotation:0,maxTicksLimit:8}},
            y:{beginAtZero:true,grid:{color:'#f5f5f5'},ticks:{stepSize:1}}}}
});

// ── Chart 2: Status Donut ─────────────────────────────────────
new Chart(document.getElementById('chartStatus'), {
  type:'doughnut',
  data:{
    labels: <?= json_encode($statusDisplayLbls) ?>,
    datasets:[{ data:<?= json_encode($statusCounts) ?>, backgroundColor:<?= json_encode($statusColors) ?>, borderWidth:2, borderColor:'#fff' }]
  },
  options:{responsive:true,maintainAspectRatio:false,cutout:'68%',
    plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>` ${ctx.label}: ${ctx.raw}`}}}}
});

// ── Chart 3: Kecamatan Grouped Bar ───────────────────────────
new Chart(document.getElementById('chartKec'), {
  type:'bar',
  data:{
    labels: <?= json_encode($kecLabels) ?>,
    datasets:[
      { label:'Total',   data:<?= json_encode($kecCounts) ?>,  backgroundColor:GREEN+'bb',  borderRadius:3 },
      { label:'Selesai', data:<?= json_encode($kecSelesai) ?>, backgroundColor:GREEN2+'99', borderRadius:3 },
      { label:'Aktif',   data:<?= json_encode($kecAktif) ?>,   backgroundColor:AMBER+'99',  borderRadius:3 }
    ]
  },
  options:{responsive:true,maintainAspectRatio:false,
    plugins:{legend:{position:'top',labels:{boxWidth:10,padding:10}}},
    scales:{x:{grid:{display:false},ticks:{maxRotation:30}},y:{beginAtZero:true,grid:{color:'#f5f5f5'}}}}
});

// ── Chart 4: Jenis Sampah Horizontal ─────────────────────────
new Chart(document.getElementById('chartWaste'), {
  type:'bar',
  data:{
    labels: <?= json_encode($wasteLabels) ?>,
    datasets:[{ label:'kg', data:<?= json_encode($wasteKg) ?>,
      backgroundColor:['#166534','#16a34a','#22c55e','#4ade80','#86efac','#bbf7d0','#dcfce7'],
      borderRadius:4 }]
  },
  options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,
    plugins:{legend:{display:false}},
    scales:{x:{beginAtZero:true,grid:{color:'#f5f5f5'}},y:{grid:{display:false}}}}
});

// ── Chart 5: Tren Mingguan ────────────────────────────────────
new Chart(document.getElementById('chartWeekly'), {
  type:'bar',
  data:{
    labels: <?= json_encode($weeklyLabels) ?>,
    datasets:[{ label:'Request', data:<?= json_encode($weeklyValues) ?>, backgroundColor:BLUE+'bb', borderRadius:3 }]
  },
  options:{responsive:true,maintainAspectRatio:false,
    plugins:{legend:{display:false}},
    scales:{x:{grid:{display:false},ticks:{maxRotation:40}},y:{beginAtZero:true,grid:{color:'#f5f5f5'}}}}
});

// ── Chart 6: Monthly ─────────────────────────────────────────
new Chart(document.getElementById('chartMonthly'), {
  type:'line',
  data:{
    labels: <?= json_encode($monthlyLabels) ?>,
    datasets:[{ label:'Request/Bulan', data:<?= json_encode($monthlyValues) ?>,
      fill:true, backgroundColor:'rgba(59,130,246,.12)', borderColor:BLUE,
      borderWidth:2, pointRadius:4, pointBackgroundColor:BLUE, tension:.3 }]
  },
  options:{responsive:true,maintainAspectRatio:false,
    plugins:{legend:{display:false}},
    scales:{x:{grid:{display:false}},y:{beginAtZero:true,grid:{color:'#f5f5f5'}}}}
});

// ── Chart 8: Monthly Weight (kg) ─────────────────────────────
new Chart(document.getElementById('chartMonthlyWeight'), {
  type:'line',
  data:{
    labels: <?= json_encode($monthlyWeightLabels) ?>,
    datasets:[{ label:'Berat (kg)', data:<?= json_encode($monthlyWeightValues) ?>,
      fill:true, backgroundColor:'rgba(34,197,94,.12)', borderColor:GREEN2,
      borderWidth:2, pointRadius:4, pointBackgroundColor:GREEN2, tension:.3 }]
  },
  options:{responsive:true,maintainAspectRatio:false,
    plugins:{legend:{display:false}},
    scales:{x:{grid:{display:false}},y:{beginAtZero:true,grid:{color:'#f5f5f5'}}}}
});

// ── Chart 7: Performa Officer ─────────────────────────────────
new Chart(document.getElementById('chartOfficerPerf'), {
  type:'bar',
  data:{
    labels: <?= json_encode($offNames) ?>,
    datasets:[
      { label:'Selesai', data:<?= json_encode($offSelesai) ?>, backgroundColor:GREEN2+'cc', borderRadius:4 },
      { label:'Aktif',   data:<?= json_encode($offAktif) ?>,  backgroundColor:AMBER+'cc',  borderRadius:4 }
    ]
  },
  options:{responsive:true,maintainAspectRatio:false,
    plugins:{legend:{position:'top',labels:{boxWidth:10,padding:10}}},
    scales:{x:{grid:{display:false}},y:{beginAtZero:true,grid:{color:'#f5f5f5'}}}}
});

// ── Quick Status Update ───────────────────────────────────────
function quickStatus(id, status, el) {
  fetch('dashboard.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:`ajax_status=1&id=${id}&status=${encodeURIComponent(status)}`
  }).then(r=>r.json()).then(d=>{
    if (d.ok) {
      showToast('success','Status diperbarui!');
      // Hilangkan row dari tabel action
      const row = el.closest('tr');
      if (row) { row.style.opacity='0'; row.style.transition='opacity .4s'; setTimeout(()=>row.remove(), 400); }
    } else {
      showToast('danger','Gagal update status.'); el.value = el.dataset.prev || el.value;
    }
  }).catch(()=>showToast('danger','Koneksi error.'));
}

// ── Live Stats Polling (60 detik) ─────────────────────────────
function refreshStats() {
  fetch('dashboard.php?ajax=stats').then(r=>r.json()).then(d=>{
    document.querySelector('#sc-total .kpi-value').textContent    = d.total_req.toLocaleString();
    document.querySelector('#sc-pending .kpi-value').textContent  = d.pending;
    document.querySelector('#sc-officers .kpi-value').textContent = d.active_off;
    document.querySelector('#sc-today .kpi-value').textContent    = d.done_today;
    document.querySelector('#sc-prog .kpi-value').textContent     = d.in_progress;
    document.getElementById('sc-unassigned').textContent          = d.unassigned;
    const scWeight = document.getElementById('sc-weight');
    if (scWeight) scWeight.textContent = d.total_kg.toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits: 1});
    const scGps = document.getElementById('sc-gps');
    if (scGps) scGps.textContent = d.gps_coverage + '%';
    const scGpsSub = document.getElementById('sc-gps-sub');
    if (scGpsSub) scGpsSub.textContent = d.with_gps + ' dari ' + d.total_req + ' request';
    document.getElementById('lastRefresh').textContent            = d.ts;
  }).catch(()=>{});
}
setInterval(refreshStats, 60000);
refreshStats();

// ── Leaflet Mini Map ──────────────────────────────────────────
<?php
  $gpsReqs = $db->query("
    SELECT id, request_code, nama_pemohon, kecamatan, status, latitude, longitude, place_name, partner_name, pickup_type FROM (
        SELECT id, request_code, nama_pemohon, kecamatan, status, latitude, longitude, created_at, place_name, partner_name, pickup_type FROM pickup_requests WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND status NOT IN ('selesai','dibatalkan')
        UNION ALL
        SELECT id, request_code, nama_pemohon, kecamatan, status, latitude, longitude, created_at, NULL as place_name, NULL as partner_name, NULL as pickup_type FROM cleanup_requests WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND status NOT IN ('selesai','dibatalkan')
    ) t ORDER BY created_at DESC LIMIT 50
  ")->fetchAll();
?>
const GPS_REQS = <?= json_encode($gpsReqs, JSON_UNESCAPED_UNICODE) ?>;
const DEPOT_LAT_DASH = <?= defined('DEPOT_LAT') ? DEPOT_LAT : 1.476362 ?>;
const DEPOT_LNG_DASH = <?= defined('DEPOT_LNG') ? DEPOT_LNG : 124.832498 ?>;
const STATUS_CLR = {menunggu:'#f59e0b',dikonfirmasi:'#3b82f6',dijadwalkan:'#8b5cf6',dalam_perjalanan:'#eab308',sedang_diproses:'#f97316',selesai:'#22c55e',dibatalkan:'#ef4444'};

let dashMap = null, dashMarkers = [], _filterStatus = 'all';

function initDashMap() {
  const mapEl = document.getElementById('dashMiniMap');
  if (!mapEl || dashMap) return;
  dashMap = L.map('dashMiniMap').setView([DEPOT_LAT_DASH, DEPOT_LNG_DASH], 12);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution:'© OpenStreetMap', maxZoom:19
  }).addTo(dashMap);

  // Depot marker
  const depotIcon = L.divIcon({className:'',html:'<div style="background:#1c6434;color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:11px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3)">D</div>',iconSize:[24,24],iconAnchor:[12,12]});
  L.marker([DEPOT_LAT_DASH, DEPOT_LNG_DASH], {icon:depotIcon, zIndexOffset:1000}).addTo(dashMap).bindPopup('<strong style="color:#1c6434">🏭 Depot MRH</strong>');

  renderDashMarkers();
}

function renderDashMarkers() {
  if (!dashMap) return;
  dashMarkers.forEach(m=>dashMap.removeLayer(m)); dashMarkers=[];
  GPS_REQS.forEach(r=>{
    if (_filterStatus !== 'all' && r.status !== _filterStatus) return;
    const clr = STATUS_CLR[r.status] || '#aaa';
    
    let popupHtml = '<div style="font-family:sans-serif;min-width:180px;font-size:13px"><strong style="color:#1c6434">'+r.request_code+'</strong>';
    popupHtml += '<div style="margin-top:4px"><strong>Pemohon:</strong> '+r.nama_pemohon+'</div>';
    if (r.place_name) {
      popupHtml += '<div style="color:#1d4ed8;font-size:12px;margin-top:2px"><strong>Place:</strong> '+r.place_name+'</div>';
    }
    if (r.partner_name) {
      popupHtml += '<div style="color:#1e293b;font-size:12px;margin-top:2px"><strong>PIC/Partner:</strong> '+r.partner_name+'</div>';
    }
    if (r.pickup_type) {
      const pkg = r.pickup_type === 'B' ? 'Keranjang' : (r.pickup_type === 'S' ? 'Karung' : r.pickup_type);
      popupHtml += '<div style="color:#64748b;font-size:11px;margin-top:2px"><strong>Wadah:</strong> '+pkg+'</div>';
    }
    popupHtml += '<div style="color:#94a3b8;font-size:11px;margin-top:2px">📍 '+(r.kecamatan||'-')+'</div>';
    popupHtml += '<div style="margin-top:6px"><span style="background:'+clr+'22;color:'+clr+';border:1px solid '+clr+'44;border-radius:8px;padding:2px 7px;font-size:10px;font-weight:700">'+r.status+'</span></div>';
    popupHtml += '<a href="req_management.php?preview='+r.id+'" style="font-size:11px;color:#1c6434;font-weight:700;display:block;margin-top:6px">Lihat Detail →</a></div>';

    const m = L.circleMarker([parseFloat(r.latitude), parseFloat(r.longitude)], {
      radius:8, fillColor:clr, fillOpacity:0.9, color:'#fff', weight:2
    }).addTo(dashMap)
      .bindPopup(popupHtml);
    dashMarkers.push(m);
  });
}

function filterMapStatus(s) { _filterStatus=s; renderDashMarkers(); }
</script>

<!-- Leaflet dimuat SETELAH semua JS map siap, lalu init dipanggil -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>window.addEventListener('load', initDashMap);</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
