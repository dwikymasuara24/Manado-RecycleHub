<?php
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/algorithms.php'; // defines DEPOT_LAT/LNG, all algorithm functions
require_once __DIR__ . '/../include/auth.php';
requireRole('admin');
$page_id    = 'rute_jadwal';
$page_title = 'Rute & Jadwal';
$db         = getDB();

// --- AUTO-MIGRATION: Add cleanup columns to schedules, routes, and schedule_requests if needed ---
try {
    // 1. Add tipe_layanan to schedules if not exists
    $columns = $db->query("SHOW COLUMNS FROM schedules")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('tipe_layanan', $columns)) {
        $db->exec("ALTER TABLE schedules ADD COLUMN tipe_layanan VARCHAR(20) DEFAULT 'pickup' AFTER status");
    }

    // 2. Add cleanup_request_id to routes if not exists
    $routeColumns = $db->query("SHOW COLUMNS FROM routes")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('cleanup_request_id', $routeColumns)) {
        $db->exec("ALTER TABLE routes ADD COLUMN cleanup_request_id BIGINT UNSIGNED DEFAULT NULL AFTER pickup_request_id");
        try {
            $db->exec("ALTER TABLE routes ADD CONSTRAINT fk_routes_cleanup FOREIGN KEY (cleanup_request_id) REFERENCES cleanup_requests(id) ON DELETE SET NULL");
        } catch(Exception $e) {}
    }

    // 3. Add cleanup_request_id to schedule_requests if not exists
    $srColumns = $db->query("SHOW COLUMNS FROM schedule_requests")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('cleanup_request_id', $srColumns)) {
        $db->exec("ALTER TABLE schedule_requests ADD COLUMN cleanup_request_id BIGINT UNSIGNED DEFAULT NULL AFTER request_id");
        try {
            $db->exec("ALTER TABLE schedule_requests MODIFY COLUMN request_id BIGINT UNSIGNED DEFAULT NULL");
        } catch(Exception $e) {}
        try {
            $db->exec("ALTER TABLE schedule_requests ADD CONSTRAINT fk_sr_cleanup FOREIGN KEY (cleanup_request_id) REFERENCES cleanup_requests(id) ON DELETE CASCADE");
        } catch(Exception $e) {}
    }
} catch (Exception $e) {
    // ignore or log
}

// ── AJAX: Generate Schedule ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_schedule') {
    header('Content-Type: application/json');
    try {
        $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
        $tipe = $_POST['tipe'] ?? 'pickup';
        $result  = generateSchedule($db, $tanggal, $tipe);
        echo json_encode(['ok' => true, 'result' => $result]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: Reset jadwal (hapus schedules draft untuk tanggal) ─
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_schedule') {
    header('Content-Type: application/json');
    try {
        $tanggal = $_POST['tanggal'] ?? '';
        if (!$tanggal) throw new Exception('Tanggal kosong.');
        $tipe = $_POST['tipe'] ?? 'pickup';
        
        // Kembalikan status request ke 'dikonfirmasi'
        if ($tipe === 'cleanup') {
            $db->prepare("UPDATE cleanup_requests SET status='dikonfirmasi' WHERE id IN (
                SELECT cleanup_request_id FROM schedule_requests WHERE schedule_id IN (
                    SELECT id FROM schedules WHERE tanggal >= ? AND status='draft' AND tipe_layanan='cleanup'
                ))
            ")->execute([$tanggal]);
        } else {
            $db->prepare("UPDATE pickup_requests SET status='dikonfirmasi' WHERE id IN (
                SELECT request_id FROM schedule_requests WHERE schedule_id IN (
                    SELECT id FROM schedules WHERE tanggal >= ? AND status='draft' AND tipe_layanan='pickup'
                ))
            ")->execute([$tanggal]);
        }
        
        $db->prepare("DELETE FROM schedule_requests WHERE schedule_id IN (SELECT id FROM schedules WHERE tanggal >= ? AND status='draft' AND tipe_layanan=?)")->execute([$tanggal, $tipe]);
        $db->prepare("DELETE FROM routes WHERE schedule_id IN (SELECT id FROM schedules WHERE tanggal >= ? AND status='draft' AND tipe_layanan=?)")->execute([$tanggal, $tipe]);
        $db->prepare("DELETE FROM schedules WHERE tanggal >= ? AND status='draft' AND tipe_layanan=?")->execute([$tanggal, $tipe]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Pusat tiap kecamatan — koordinat nyata Kota Manado ───────
$kecCenters = [
    'Wenang'            => ['lat'=>1.4748,  'lng'=>124.8421],
    'Malalayang'        => ['lat'=>1.4522,  'lng'=>124.8015],
    'Tikala'            => ['lat'=>1.4930,  'lng'=>124.8610],
    'Paal Dua'          => ['lat'=>1.5012,  'lng'=>124.8700],
    'Bunaken'           => ['lat'=>1.6100,  'lng'=>124.7500],
    'Bunaken Kepulauan' => ['lat'=>1.6800,  'lng'=>124.7200],
    'Singkil'           => ['lat'=>1.4600,  'lng'=>124.8100],
    'Mapanget'          => ['lat'=>1.5500,  'lng'=>124.8900],
    'Wanea'             => ['lat'=>1.4800,  'lng'=>124.8500],
    'Sario'             => ['lat'=>1.4650,  'lng'=>124.8300],
    'Tuminting'         => ['lat'=>1.5100,  'lng'=>124.8200],
    'Paal Empat'        => ['lat'=>1.5150,  'lng'=>124.8750],
];

function namaHariID(DateTime $dt): string {
    $map = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa',
            'Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
    return $map[$dt->format('l')] ?? $dt->format('l');
}

$gmapsKey = getGmapsKey();
$tipe = $_GET['tipe'] ?? 'pickup';

// ── Priority data dari DB + algoritma PHP ────────────────────
if ($tipe === 'pickup') {
    $confirmedRaw = $db->query("SELECT id, request_code, nama_pemohon, kecamatan, latitude, longitude, 'pickup' as tipe_layanan
        FROM pickup_requests WHERE status='dikonfirmasi' AND kecamatan IS NOT NULL")->fetchAll();
} elseif ($tipe === 'cleanup') {
    $confirmedRaw = $db->query("SELECT id, request_code, nama_pemohon, kecamatan, latitude, longitude, 'cleanup' as tipe_layanan
        FROM cleanup_requests WHERE status='dikonfirmasi' AND kecamatan IS NOT NULL")->fetchAll();
} else {
    $confirmedRaw = $db->query("
        SELECT id, request_code, nama_pemohon, kecamatan, latitude, longitude, 'pickup' as tipe_layanan
        FROM pickup_requests WHERE status='dikonfirmasi' AND kecamatan IS NOT NULL
        UNION ALL
        SELECT id, request_code, nama_pemohon, kecamatan, latitude, longitude, 'cleanup' as tipe_layanan
        FROM cleanup_requests WHERE status='dikonfirmasi' AND kecamatan IS NOT NULL
    ")->fetchAll();
}

foreach ($confirmedRaw as &$r) {
    $r['lat'] = (float)($r['latitude']  ?? 0);
    $r['lng'] = (float)($r['longitude'] ?? 0);
}
unset($r);
$prioritizedKec = priorityRule($confirmedRaw); // Priority Rule algorithm

// Data untuk tabel priority (semua status aktif, bukan hanya dikonfirmasi)
// Terintegrasi dengan jadwal real-time
if ($tipe === 'pickup') {
    $priorityData = $db->query("
        SELECT r.kecamatan, 
               COUNT(*) as cnt,
               MAX(r.tanggal_jemput) as last_tgl,
               GROUP_CONCAT(DISTINCT o.nama) as officer_names,
               SUM(CASE WHEN r.status = 'dikonfirmasi' THEN 1 ELSE 0 END) as count_siap,
               SUM(CASE WHEN r.status = 'dijadwalkan' THEN 1 ELSE 0 END) as count_jadwal
        FROM pickup_requests r
        LEFT JOIN officers o ON o.id = r.officer_id
        WHERE r.status NOT IN ('selesai','dibatalkan') AND r.kecamatan IS NOT NULL
        GROUP BY r.kecamatan 
        ORDER BY cnt DESC LIMIT 12
    ")->fetchAll();
} elseif ($tipe === 'cleanup') {
    $priorityData = $db->query("
        SELECT r.kecamatan, 
               COUNT(*) as cnt,
               MAX(r.tanggal_tugas) as last_tgl,
               GROUP_CONCAT(DISTINCT o.nama) as officer_names,
               SUM(CASE WHEN r.status = 'dikonfirmasi' THEN 1 ELSE 0 END) as count_siap,
               SUM(CASE WHEN r.status = 'dijadwalkan' THEN 1 ELSE 0 END) as count_jadwal
        FROM cleanup_requests r
        LEFT JOIN officers o ON o.id = r.officer_id
        WHERE r.status NOT IN ('selesai','dibatalkan') AND r.kecamatan IS NOT NULL
        GROUP BY r.kecamatan 
        ORDER BY cnt DESC LIMIT 12
    ")->fetchAll();
} else {
    $priorityData = $db->query("
        SELECT kecamatan, COUNT(*) as cnt, MAX(tanggal) as last_tgl, GROUP_CONCAT(DISTINCT officer_name) as officer_names,
               SUM(CASE WHEN status = 'dikonfirmasi' THEN 1 ELSE 0 END) as count_siap,
               SUM(CASE WHEN status = 'dijadwalkan' THEN 1 ELSE 0 END) as count_jadwal
        FROM (
            SELECT r.kecamatan, r.status, r.tanggal_jemput as tanggal, o.nama as officer_name
            FROM pickup_requests r
            LEFT JOIN officers o ON o.id = r.officer_id
            WHERE r.status NOT IN ('selesai','dibatalkan') AND r.kecamatan IS NOT NULL
            UNION ALL
            SELECT r.kecamatan, r.status, r.tanggal_tugas as tanggal, o.nama as officer_name
            FROM cleanup_requests r
            LEFT JOIN officers o ON o.id = r.officer_id
            WHERE r.status NOT IN ('selesai','dibatalkan') AND r.kecamatan IS NOT NULL
        ) combined
        GROUP BY kecamatan
        ORDER BY cnt DESC LIMIT 12
    ")->fetchAll();
}

// Sort $priorityData in PHP to align with the new tie-breaker logic (distance to Depot first, then alphabet)
usort($priorityData, function($a, $b) {
    if ($a['cnt'] !== $b['cnt']) {
        return $b['cnt'] <=> $a['cnt'];
    }
    $centerA = getKecCenter($a['kecamatan']);
    $centerB = getKecCenter($b['kecamatan']);
    $distA = haversineDistance((float)DEPOT_LAT, (float)DEPOT_LNG, (float)$centerA['lat'], (float)$centerA['lng']);
    $distB = haversineDistance((float)DEPOT_LAT, (float)DEPOT_LNG, (float)$centerB['lat'], (float)$centerB['lng']);
    if (abs($distA - $distB) > 0.0001) {
        return $distA <=> $distB;
    }
    return strcmp($a['kecamatan'], $b['kecamatan']);
});

// ── Officer matching per kecamatan ───────────────────────────
$allOfficers = $db->query("SELECT id, nama FROM officers WHERE status='aktif' ORDER BY id ASC")->fetchAll();
function officerForKec(int $index, array $officers): string {
    $count = count($officers);
    if ($count > 0) {
        return htmlspecialchars($officers[$index % $count]['nama']);
    }
    return '<span style="color:#ccc">Belum ditugaskan</span>';
}

// ── Request untuk kecamatan terpilih (NN preview) ────────────
$selectedKec = $_GET['kec'] ?? ($prioritizedKec[0]['kecamatan'] ?? ($priorityData[0]['kecamatan'] ?? ''));
$kecRequests = [];
if ($selectedKec) {
    if ($selectedKec === 'semua') {
        if ($tipe === 'pickup') {
            $s = $db->prepare("SELECT id, request_code, nama_pemohon, alamat_jemput,
                kecamatan, kelurahan, status, latitude, longitude, place_name, partner_name, pickup_type, 'pickup' as tipe_layanan
                FROM pickup_requests
                WHERE status NOT IN ('selesai','dibatalkan')
                ORDER BY created_at ASC");
            $s->execute();
            $kecRequests = $s->fetchAll();
        } elseif ($tipe === 'cleanup') {
            $s = $db->prepare("SELECT id, request_code, nama_pemohon, alamat_jemput,
                kecamatan, kelurahan, status, latitude, longitude, NULL as place_name, NULL as partner_name, NULL as pickup_type, 'cleanup' as tipe_layanan
                FROM cleanup_requests
                WHERE status NOT IN ('selesai','dibatalkan')
                ORDER BY created_at ASC");
            $s->execute();
            $kecRequests = $s->fetchAll();
        } else {
            $s = $db->prepare("
                SELECT id, request_code, nama_pemohon, alamat_jemput, kecamatan, kelurahan, status, latitude, longitude, place_name, partner_name, pickup_type, 'pickup' as tipe_layanan, created_at
                FROM pickup_requests
                WHERE status NOT IN ('selesai','dibatalkan')
                UNION ALL
                SELECT id, request_code, nama_pemohon, alamat_jemput, kecamatan, kelurahan, status, latitude, longitude, NULL as place_name, NULL as partner_name, NULL as pickup_type, 'cleanup' as tipe_layanan, created_at
                FROM cleanup_requests
                WHERE status NOT IN ('selesai','dibatalkan')
                ORDER BY created_at ASC
            ");
            $s->execute();
            $kecRequests = $s->fetchAll();
        }
    } else {
        if ($tipe === 'pickup') {
            $s = $db->prepare("SELECT id, request_code, nama_pemohon, alamat_jemput,
                kecamatan, kelurahan, status, latitude, longitude, place_name, partner_name, pickup_type, 'pickup' as tipe_layanan
                FROM pickup_requests
                WHERE kecamatan=? AND status NOT IN ('selesai','dibatalkan')
                ORDER BY created_at ASC");
            $s->execute([$selectedKec]);
            $kecRequests = $s->fetchAll();
        } elseif ($tipe === 'cleanup') {
            $s = $db->prepare("SELECT id, request_code, nama_pemohon, alamat_jemput,
                kecamatan, kelurahan, status, latitude, longitude, NULL as place_name, NULL as partner_name, NULL as pickup_type, 'cleanup' as tipe_layanan
                FROM cleanup_requests
                WHERE kecamatan=? AND status NOT IN ('selesai','dibatalkan')
                ORDER BY created_at ASC");
            $s->execute([$selectedKec]);
            $kecRequests = $s->fetchAll();
        } else {
            $s = $db->prepare("
                SELECT id, request_code, nama_pemohon, alamat_jemput, kecamatan, kelurahan, status, latitude, longitude, place_name, partner_name, pickup_type, 'pickup' as tipe_layanan, created_at
                FROM pickup_requests
                WHERE kecamatan=? AND status NOT IN ('selesai','dibatalkan')
                UNION ALL
                SELECT id, request_code, nama_pemohon, alamat_jemput, kecamatan, kelurahan, status, latitude, longitude, NULL as place_name, NULL as partner_name, NULL as pickup_type, 'cleanup' as tipe_layanan, created_at
                FROM cleanup_requests
                WHERE kecamatan=? AND status NOT IN ('selesai','dibatalkan')
                ORDER BY created_at ASC
            ");
            $s->execute([$selectedKec, $selectedKec]);
            $kecRequests = $s->fetchAll();
        }
    }
    // Gunakan koordinat nyata; fallback ke pusat kecamatan + jitter
    foreach ($kecRequests as &$req) {
        $center = $kecCenters[$req['kecamatan']] ?? ['lat' => DEPOT_LAT, 'lng' => DEPOT_LNG];
        $req['lat'] = (float)($req['latitude'])  ?: $center['lat'] + (mt_rand(-50,50)/10000);
        $req['lng'] = (float)($req['longitude']) ?: $center['lng'] + (mt_rand(-50,50)/10000);
    }
    unset($req);
}

// ── Jadwal yang sudah di-generate (dari tabel schedules) ──────
$existingSchedules = [];
try {
    $whereClause = "";
    if ($tipe === 'pickup') {
        $whereClause = "WHERE s.tipe_layanan = 'pickup' OR s.tipe_layanan IS NULL";
    } elseif ($tipe === 'cleanup') {
        $whereClause = "WHERE s.tipe_layanan = 'cleanup'";
    }
    $existingSchedules = $db->query("
        SELECT s.id, s.tanggal, s.status,
               COALESCE(k.nama_kecamatan, s.kecamatan, CAST(s.kecamatan_id AS CHAR), '—') AS kecamatan_nama,
               COUNT(sr.id) AS jumlah_req,
               o.nama AS officer_nama
        FROM schedules s
        LEFT JOIN kecamatan k ON k.id = s.kecamatan_id
        LEFT JOIN schedule_requests sr ON sr.schedule_id = s.id
        LEFT JOIN officers o ON o.id = s.officer_id
        $whereClause
        GROUP BY s.id
        ORDER BY s.tanggal DESC, s.id DESC
        LIMIT 50
    ")->fetchAll();
} catch (PDOException $e) { /* tabel belum ada */ }

// Stats ringkas
$totalDikonfirmasi = count($confirmedRaw);
if ($tipe === 'pickup') {
    $totalDijadwalkan  = (int)$db->query("SELECT COUNT(*) FROM pickup_requests WHERE status='dijadwalkan'")->fetchColumn();
} elseif ($tipe === 'cleanup') {
    $totalDijadwalkan  = (int)$db->query("SELECT COUNT(*) FROM cleanup_requests WHERE status='dijadwalkan'")->fetchColumn();
} else {
    $totalDijadwalkan  = (int)$db->query("SELECT (SELECT COUNT(*) FROM pickup_requests WHERE status='dijadwalkan') + (SELECT COUNT(*) FROM cleanup_requests WHERE status='dijadwalkan')")->fetchColumn();
}
$totalSchedules    = count($existingSchedules);

require_once __DIR__ . '/layout/header.php';
?>

<style>
/* KPI style to match dashboard */
.kpi-strip {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
@media (max-width: 900px) {
    .kpi-strip {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 480px) {
    .kpi-strip {
        grid-template-columns: 1fr;
    }
}
.kpi-card {
    background: #fff;
    border-radius: 10px;
    padding: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,.07);
    border-left: 4px solid #e0e0e0;
    transition: transform .15s, box-shadow .2s;
    text-decoration: none;
}
.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,0,0,.1);
}
.kpi-label {
    font-size: 10px;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: .06em;
    margin-bottom: 6px;
}
.kpi-value {
    font-size: 26px;
    font-weight: 800;
    color: #1e293b;
    line-height: 1;
}
.kpi-sub {
    font-size: 11px;
    color: #cbd5e1;
    margin-top: 4px;
    font-weight: 600;
}
.kpi-card.green { border-left-color: #22c55e; }
.kpi-card.green .kpi-value { color: #16a34a; }
.kpi-card.amber { border-left-color: #f59e0b; }
.kpi-card.amber .kpi-value { color: #d97706; }
.kpi-card.blue { border-left-color: #3b82f6; }
.kpi-card.blue .kpi-value { color: #1d4ed8; }
.kpi-card.purple { border-left-color: #8b5cf6; }
.kpi-card.purple .kpi-value { color: #7c3aed; }

/* Custom Centered Alert Dialog Style */
.custom-alert-overlay {
    position: fixed;
    top: 0; left: 0; width: 100vw; height: 100vh;
    background: rgba(0,0,0,0.4);
    display: flex; align-items: center; justify-content: center;
    z-index: 9999;
    animation: fadeIn 0.15s ease-out;
}
.custom-alert-box {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    padding: 24px;
    max-width: 480px;
    width: 90%;
    text-align: center;
    border-top: 5px solid #ef4444; /* Red accent for alerts */
    animation: scaleIn 0.15s ease-out;
}
.custom-alert-message {
    font-size: 14px;
    font-weight: 600;
    color: #1e293b;
    line-height: 1.6;
    margin-bottom: 20px;
    text-align: center;
}
.custom-alert-btn {
    background: #ef4444;
    color: #ffffff;
    border: none;
    border-radius: 8px;
    padding: 8px 24px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s;
}
.custom-alert-btn:hover {
    background: #dc2626;
}
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
@keyframes scaleIn {
    from { transform: scale(0.95); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}
</style>

<div class="page-header">
  <h1>Rute &amp; Jadwal Penjemputan</h1>
  <p>Penjadwalan berbasis <strong>Priority Rule</strong> + Penentuan rute <strong>Nearest Neighbor</strong> (Haversine Formula)</p>
</div>

<!-- Tipe Layanan Filter -->
<div style="display:flex;gap:6px;margin-bottom:16px;background:#fff;padding:6px;border-radius:12px;border:1.5px solid var(--gml);max-width:300px;">
  <a href="?tipe=pickup&kec=<?= urlencode($selectedKec) ?>" class="btn <?= $tipe==='pickup'?'btn-green':'btn-outline' ?> btn-sm" style="flex:1;text-align:center;font-weight:700;">♻️ Daur Ulang</a>
  <a href="?tipe=cleanup&kec=<?= urlencode($selectedKec) ?>" class="btn <?= $tipe==='cleanup'?'btn-green':'btn-outline' ?> btn-sm" style="flex:1;text-align:center;font-weight:700;">🧹 Clean Up</a>
</div>

<!-- Stat Cards -->
<div class="kpi-strip">
  <div class="kpi-card amber">
    <div class="kpi-label">Dikonfirmasi</div>
    <div class="kpi-value"><?= $totalDikonfirmasi ?></div>
    <div class="kpi-sub">siap dijadwalkan</div>
  </div>
  <div class="kpi-card purple">
    <div class="kpi-label">Sudah Dijadwalkan</div>
    <div class="kpi-value"><?= $totalDijadwalkan ?></div>
    <div class="kpi-sub">penugasan aktif</div>
  </div>
  <div class="kpi-card green">
    <div class="kpi-label">Jadwal Tersimpan</div>
    <div class="kpi-value"><?= $totalSchedules ?></div>
    <div class="kpi-sub">total di database</div>
  </div>
  <div class="kpi-card blue">
    <div class="kpi-label">Kecamatan Aktif</div>
    <div class="kpi-value"><?= count($prioritizedKec) ?></div>
    <div class="kpi-sub">Priority Rule</div>
  </div>
</div>

<!-- Admin quick action bar -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center">
  <a href="dashboard.php"          class="btn btn-outline btn-sm">🖥️ Dashboard</a>
  <a href="req_management.php"     class="btn btn-outline btn-sm">📋 Manajemen Request</a>
  <a href="officer_management.php" class="btn btn-outline btn-sm">👷 Kelola Petugas</a>
  <?php
    $unassignedRuteCount = (int)$db->query("SELECT COUNT(*) FROM pickup_requests WHERE officer_id IS NULL AND status NOT IN ('selesai','dibatalkan')")->fetchColumn();
    if ($unassignedRuteCount > 0): ?>
  <a href="officer_management.php" class="btn btn-sm" style="background:#fff3cd;color:#856404;border:1.5px solid #ffc107;font-weight:700">
    ⚠️ <?= $unassignedRuteCount ?> request belum di-assign
  </a>
  <?php endif; ?>

  <!-- Generate Schedule -->
  <div style="display:flex;gap:6px;align-items:center;margin-left:auto;flex-wrap:wrap">
    <input type="date" id="genSchedDate"
           value="<?= date('Y-m-d') ?>"
           min="<?= date('Y-m-d') ?>"
           style="padding:6px 10px;border:1.5px solid #d1d5db;border-radius:8px;font-size:12px;font-family:inherit">
    <button class="btn btn-primary btn-sm" id="btnGenSched" onclick="generateScheduleNow()">
      ⚙️ Generate Jadwal
    </button>
    <button class="btn btn-sm" id="btnReset" onclick="resetSchedule()"
            style="background:#fff3f3;color:#ef4444;border:1px solid #fecaca">
      🗑️ Reset Draft
    </button>
    <span id="genSchedStatus" style="font-size:12px;color:#555"></span>
  </div>
</div>

<!-- PRIORITY + JADWAL -->
<div class="grid-2 mb-24">
  <div class="card">
    <div class="card-title"><div class="ct-icon">🏆</div> Priority Kecamatan — <em>Priority Rule Algorithm</em></div>
    <div class="algo-box">
      <strong>Metode: Priority Rule</strong> — Kecamatan dengan jumlah request <em>dikonfirmasi</em> terbanyak diprioritaskan.
      <?php if (!empty($prioritizedKec)): ?>
      Urutan aktif: <?= implode(' → ', array_column($prioritizedKec, 'kecamatan')) ?>
      <?php else: ?>
      <em>Tidak ada request berstatus <strong>dikonfirmasi</strong> saat ini.</em>
      <?php endif; ?>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Rank</th><th>Kecamatan</th><th>Req Aktif</th><th>Proporsi</th><th>Jadwal</th><th>Petugas</th></tr></thead>
        <tbody>
          <?php
          $maxCnt = max(array_column($priorityData,'cnt') ?: [1]);
          foreach ($priorityData as $i => $p):
            $pct      = round(($p['cnt'] / $maxCnt) * 100);
            $rankCls  = $i===0 ? 'rank-1' : ($i===1 ? 'rank-2' : ($i===2 ? 'rank-3' : 'rank-n'));
            $rowCls   = $i===0 ? 'priority-row-1' : ($i===1 ? 'priority-row-2' : '');
            
            // Logika Jadwal Real-time
            $hasJadwal = !empty($p['last_tgl']) && $p['count_jadwal'] > 0;
            if ($hasJadwal) {
              $jadwalDt = new DateTime($p['last_tgl']);
              $jadwalText = "<strong>" . namaHariID($jadwalDt) . ", " . $jadwalDt->format('d M Y') . "</strong>";
              $jadwalStatus = "<span style='color:#16a34a;font-size:10px;font-weight:700'>● TERJADWAL</span>";
            } else {
              $jadwalDt = (new DateTime())->modify('+' . $i . ' days');
              $jadwalText = "<span style='color:#94a3b8'>" . namaHariID($jadwalDt) . ", " . $jadwalDt->format('d M Y') . "</span>";
              $jadwalStatus = "<span style='color:#f59e0b;font-size:10px;font-weight:700'>⚡ SIAP GENERATE</span>";
            }

            // Logika Petugas Real-time
            $petugasList = !empty($p['officer_names']) ? $p['officer_names'] : officerForKec($i, $allOfficers);
            if ($p['count_jadwal'] == 0) {
              $petugasText = "<span style='color:#94a3b8;font-style:italic'>" . $petugasList . " (Saran)</span>";
            } else {
              $petugasText = "<strong>" . $petugasList . "</strong>";
            }
          ?>
          <tr class="<?= $rowCls ?>">
            <td><span class="priority-rank <?= $rankCls ?>"><?= $i+1 ?></span></td>
            <td>
              <a href="rute_jadwal.php?kec=<?= urlencode($p['kecamatan']) ?>&tipe=<?= $tipe ?>" style="color:var(--green-700);font-weight:700">
                Kec. <?= htmlspecialchars($p['kecamatan']) ?>
              </a>
              <div style="margin-top:2px"><?= $jadwalStatus ?></div>
            </td>
            <td>
               <div style="font-weight:700;color:var(--green-700)"><?= $p['cnt'] ?> <small style="font-weight:400;color:#94a3b8">titik</small></div>
               <div style="font-size:10px;color:#64748b"><?= $p['count_siap'] ?> baru · <?= $p['count_jadwal'] ?> aktif</div>
            </td>
            <td style="min-width:100px">
              <div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%"></div></div>
            </td>
            <td style="font-size:11px"><?= $jadwalText ?></td>
            <td style="font-size:12px"><?= $petugasText ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$priorityData): ?>
          <tr><td colspan="6" style="text-align:center;color:#aaa;padding:20px">Tidak ada request aktif</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-title"><div class="ct-icon">🗓️</div> Jadwal Harian</div>
    <div style="margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <button class="btn btn-outline" id="prevDayBtn" onclick="shiftDay(-1)">◀ Sebelumnya</button>
      <span id="dayLabel" style="font-weight:700;font-size:13px"></span>
      <button class="btn btn-outline" id="nextDayBtn" onclick="shiftDay(1)">Berikutnya ▶</button>
    </div>
    <div id="scheduleList">
      <?php foreach (array_slice($priorityData,0,5) as $i => $p):
        $oName = officerForKec($i, $allOfficers);
        $est   = round($p['cnt'] * 0.35, 1);
      ?>
      <div style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid #f0f0f0">
        <div style="flex:1">
          <div style="font-weight:700;font-size:13px"><?= htmlspecialchars($p['kecamatan']) ?></div>
          <div style="font-size:11px;color:#888;margin-top:2px"><?= $p['cnt'] ?> titik &bull; <?= strip_tags($oName) ?> &bull; ±<?= $est ?> jam</div>
        </div>
        <span class="badge <?= $i===0?'badge-green':($i===1?'badge-blue':'badge-gray') ?>"><?= $i===0?'Prioritas 1':($i===1?'Prioritas 2':'Antrian') ?></span>
      </div>
      <?php endforeach; ?>
      <?php if (!$priorityData): ?>
      <p style="text-align:center;color:#aaa;padding:20px 0">Tidak ada request aktif</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- GENERATED SCHEDULES TABLE -->
<?php if (!empty($existingSchedules)): ?>
<div class="card mb-24">
  <div class="card-title"><div class="ct-icon">✅</div> Jadwal Tersimpan di Database</div>
  <div class="algo-box">Semua jadwal yang telah di-generate. Status <strong>draft</strong> = belum dikonfirmasi ke petugas.</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#ID</th><th>Tanggal</th><th>Kecamatan</th><th>Req</th><th>Petugas</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($existingSchedules as $sc): ?>
        <tr>
          <td style="font-size:11px;color:#888"><?= $sc['id'] ?></td>
          <td><?= date('d M Y', strtotime($sc['tanggal'])) ?></td>
          <td style="font-weight:700"><?= htmlspecialchars($sc['kecamatan_nama']) ?></td>
          <td><?= $sc['jumlah_req'] ?> titik</td>
          <td><?= $sc['officer_nama'] ? htmlspecialchars($sc['officer_nama']) : '<span style="color:#ccc">—</span>' ?></td>
          <td><?= statusBadge($sc['status']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- NEAREST NEIGHBOR MAP -->
<div class="card mb-24">
  <div class="card-title" style="display:flex;justify-content:between;align-items:center;">
    <div style="display:flex;align-items:center;gap:6px;">
      <div class="ct-icon">🗺️</div> Visualisasi Rute — Nearest Neighbor &amp; Real Traffic
    </div>
      <span style="font-size:10px;background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:10px;font-weight:700;margin-left:auto;">OPENSTREETMAP</span>
  </div>
  <div class="algo-box">
    <strong>Metode: Nearest Neighbor</strong> — Dari titik depot, sistem memilih lokasi terdekat secara berurutan hingga semua titik terkunjungi.
  </div>
  


  <div style="margin-bottom:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <form method="GET" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" name="tipe" value="<?= $tipe ?>">
      <select class="filter-select" name="kec" onchange="this.form.submit()">
        <option value="semua" <?= $selectedKec==='semua'?'selected':'' ?>>
          Semua Kecamatan (<?= array_sum(array_column($priorityData, 'cnt')) ?> titik)
        </option>
        <?php foreach ($priorityData as $p): ?>
        <option value="<?= htmlspecialchars($p['kecamatan']) ?>" <?= $selectedKec===$p['kecamatan']?'selected':'' ?>>
          Kec. <?= htmlspecialchars($p['kecamatan']) ?> (<?= $p['cnt'] ?> titik)
        </option>
        <?php endforeach; ?>
      </select>
    </form>
    <button class="btn btn-primary" onclick="runNN()">▶ Jalankan Algoritma NN</button>
    <button class="btn btn-blue" onclick="openGoogleMapsTraffic()">🚗 Buka Google Maps Traffic</button>
    <span id="nnStatus" style="font-size:12px;color:#888"></span>
  </div>

  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

  <div id="ruteMap" style="width:100%;height:420px;border-radius:var(--radius);overflow:hidden;border:1px solid #e0e0e0;background:#f5f5f5"></div>
  <div id="nnResult" style="margin-top:14px;font-size:13px;color:#555"></div>
</div>

<!-- DETAIL RUTE -->
<div class="card">
  <div class="card-title"><div class="ct-icon">📋</div> Detail Rute — <?= $selectedKec === 'semua' ? 'Semua Kecamatan' : 'Kecamatan ' . htmlspecialchars($selectedKec) ?></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Urutan</th><th>Tipe</th><th>ID Request</th><th>Nama Pemohon</th><th>Alamat</th><th>Jarak dari Sebelumnya</th><th>Status</th></tr>
      </thead>
      <tbody id="routeDetailTbody">
        <?php foreach ($kecRequests as $i => $r): ?>
        <tr>
          <td><strong><?= $i+1 ?></strong></td>
          <td>
            <span style="font-size:10px; padding:2px 6px; border-radius:10px; background:<?= $r['tipe_layanan'] === 'cleanup' ? '#fef3c7' : '#dbeafe' ?>; color:<?= $r['tipe_layanan'] === 'cleanup' ? '#b45309' : '#1e40af' ?>; font-weight:700;">
              <?= $r['tipe_layanan'] === 'cleanup' ? '🧹 Clean Up' : '♻️ Daur Ulang' ?>
            </span>
          </td>
          <td><span style="font-size:11px;font-weight:700;color:var(--green-700)"><?= htmlspecialchars($r['request_code']) ?></span></td>
          <td><?= htmlspecialchars($r['nama_pemohon']) ?></td>
          <td style="font-size:12px;max-width:200px"><?= htmlspecialchars($r['alamat_jemput'] ?? '-') ?></td>
          <td id="dist_<?= $i ?>" style="font-size:12px;color:#888">— (jalankan NN)</td>
          <td><?= statusBadge($r['status']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$kecRequests): ?>
        <tr><td colspan="7" style="text-align:center;color:#aaa;padding:20px">Tidak ada request aktif untuk kecamatan ini.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
const DEPOT_LAT = <?= DEPOT_LAT ?>;
const DEPOT_LNG = <?= DEPOT_LNG ?>;
const DEPOT_NAME = '<?= addslashes(DEPOT_NAME) ?>';
const gmapsActive = false;

const kecNodes = [
  {id:0, code:'DEPOT', nama:DEPOT_NAME, lat:DEPOT_LAT, lng:DEPOT_LNG, isDepot:true, tipe:'depot'}
  <?php foreach ($kecRequests as $r): ?>
  ,{id:<?= $r['id'] ?>, code:'<?= $r['request_code'] ?>', nama:<?= json_encode($r['nama_pemohon']) ?>, place_name:<?= json_encode($r['place_name'] ?? '') ?>, partner_name:<?= json_encode($r['partner_name'] ?? '') ?>, pickup_type:<?= json_encode($r['pickup_type'] ?? '') ?>, lat:<?= $r['lat'] ?>, lng:<?= $r['lng'] ?>, isDepot:false, tipe:'<?= $r['tipe_layanan'] ?>'}
  <?php endforeach; ?>
];

let mapInstance = null;
let markers = [];
let routePolyline = null;
let directionsRendererInstance = null;
let nnRoute = [];

function initMap() {
  if (gmapsActive && typeof google !== 'undefined' && google.maps) {
    initGoogleMap();
  } else {
    initLeafletMap();
  }
}

function initGoogleMap() {
  const depotLoc = { lat: DEPOT_LAT, lng: DEPOT_LNG };
  mapInstance = new google.maps.Map(document.getElementById('ruteMap'), {
    center: depotLoc,
    zoom: 13,
    styles: [
      {
        "featureType": "poi",
        "stylers": [{ "visibility": "off" }]
      }
    ]
  });

  // Enable Traffic Layer
  const trafficLayer = new google.maps.TrafficLayer();
  trafficLayer.setMap(mapInstance);

  // Depot Marker
  new google.maps.Marker({
    position: depotLoc,
    map: mapInstance,
    title: DEPOT_NAME,
    icon: {
      url: "https://maps.google.com/mapfiles/ms/icons/green-dot.png"
    }
  });

  // Plot Request Markers
  kecNodes.filter(n => !n.isDepot).forEach((n, i) => {
    const pinColor = n.tipe === 'cleanup' ? 'orange' : 'blue';
    const marker = new google.maps.Marker({
      position: { lat: n.lat, lng: n.lng },
      map: mapInstance,
      label: (i + 1).toString(),
      icon: `https://maps.google.com/mapfiles/ms/icons/${pinColor}-dot.png`
    });

    let popupHtml = `<div style="font-family:'Nunito',sans-serif;padding:4px;min-width:180px;">`;
    popupHtml += `<strong style="color:#1c6434">${n.code}</strong>`;
    popupHtml += `<span style="font-size:10px;padding:2px 6px;border-radius:10px;margin-left:6px;background:${n.tipe==='cleanup'?'#fef08a':'#dbeafe'};color:${n.tipe==='cleanup'?'#854d0e':'#1e40af'}">${n.tipe==='cleanup'?'Clean Up':'Daur Ulang'}</span>`;
    popupHtml += `<div style="font-size:12px;font-weight:700;margin-top:4px;">Pemohon: ${n.nama}</div>`;
    if (n.place_name) {
      popupHtml += `<div style="color:#1d4ed8;font-size:11px;margin-top:2px"><strong>Place:</strong> ${n.place_name}</div>`;
    }
    if (n.partner_name) {
      popupHtml += `<div style="color:#1e293b;font-size:11px;margin-top:2px"><strong>PIC/Partner:</strong> ${n.partner_name}</div>`;
    }
    if (n.pickup_type) {
      const pkg = n.pickup_type === 'B' ? 'Keranjang' : (n.pickup_type === 'S' ? 'Karung' : n.pickup_type);
      popupHtml += `<div style="color:#64748b;font-size:11px;margin-top:2px"><strong>Wadah:</strong> ${pkg}</div>`;
    }
    popupHtml += `<a href="https://www.google.com/maps/dir/?api=1&origin=${DEPOT_LAT},${DEPOT_LNG}&destination=${n.lat},${n.lng}&travelmode=driving" target="_blank" style="font-size:11px;color:#1c6434;font-weight:600;display:block;margin-top:6px">🧭 Navigasi dari Depot</a></div>`;

    const info = new google.maps.InfoWindow({
      content: popupHtml
    });

    marker.addListener('click', () => info.open(mapInstance, marker));
    markers.push(marker);
  });
}

function initLeafletMap() {
  mapInstance = L.map('ruteMap').setView([DEPOT_LAT, DEPOT_LNG], 13);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap', maxZoom: 19
  }).addTo(mapInstance);

  // Depot marker
  const depotIcon = L.divIcon({className:'',html:'<div style="background:#1c6434;color:#fff;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:12px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3)">D</div>',iconSize:[26,26],iconAnchor:[13,13]});
  L.marker([DEPOT_LAT, DEPOT_LNG], {icon:depotIcon, zIndexOffset:1000})
    .addTo(mapInstance)
    .bindPopup('<strong style="color:#1c6434">🏭 '+DEPOT_NAME+'</strong><br><small>'+DEPOT_LAT.toFixed(6)+', '+DEPOT_LNG.toFixed(6)+'</small><br><a href="https://www.google.com/maps?q='+DEPOT_LAT+','+DEPOT_LNG+'" target="_blank" style="color:#1c6434;font-weight:600;font-size:12px">Buka di Google Maps →</a>');

  // Plot request nodes
  kecNodes.filter(n=>!n.isDepot&&n.lat&&n.lng).forEach((n,i)=>{
    const pinColor = n.tipe === 'cleanup' ? '#f59e0b' : '#3b82f6';
    const icon = L.divIcon({className:'',html:`<div style="background:${pinColor};color:#fff;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:10px;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.25)">${i+1}</div>`,iconSize:[22,22],iconAnchor:[11,11]});
    
    let popupHtml = `<div style="font-family:'Nunito',sans-serif;padding:4px;min-width:180px;">`;
    popupHtml += `<strong style="color:#1c6434">${n.code}</strong>`;
    popupHtml += `<span style="font-size:10px;padding:2px 6px;border-radius:10px;margin-left:6px;background:${n.tipe==='cleanup'?'#fef08a':'#dbeafe'};color:${n.tipe==='cleanup'?'#854d0e':'#1e40af'}">${n.tipe==='cleanup'?'Clean Up':'Daur Ulang'}</span>`;
    popupHtml += `<div style="font-size:12px;font-weight:700;margin-top:4px;">Pemohon: ${n.nama}</div>`;
    if (n.place_name) {
      popupHtml += `<div style="color:#1d4ed8;font-size:11px;margin-top:2px"><strong>Place:</strong> ${n.place_name}</div>`;
    }
    if (n.partner_name) {
      popupHtml += `<div style="color:#1e293b;font-size:11px;margin-top:2px"><strong>PIC/Partner:</strong> ${n.partner_name}</div>`;
    }
    if (n.pickup_type) {
      const pkg = n.pickup_type === 'B' ? 'Keranjang' : (n.pickup_type === 'S' ? 'Karung' : n.pickup_type);
      popupHtml += `<div style="color:#64748b;font-size:11px;margin-top:2px"><strong>Wadah:</strong> ${pkg}</div>`;
    }
    popupHtml += `<a href="https://www.google.com/maps/dir/?api=1&origin=${DEPOT_LAT},${DEPOT_LNG}&destination=${n.lat},${n.lng}" target="_blank" style="font-size:12px;color:#1c6434;font-weight:600;display:block;margin-top:8px">🧭 Navigasi dari Depot</a></div>`;

    const m = L.marker([n.lat, n.lng], {icon})
      .addTo(mapInstance)
      .bindPopup(popupHtml);
    markers.push(m);
  });

  // Fit bounds
  if(kecNodes.length>1){
    const bounds = L.latLngBounds(kecNodes.map(n=>[n.lat,n.lng]));
    mapInstance.fitBounds(bounds.pad(0.15));
  }
}

// Distance helper
function dist(a,b){
  const R=6371,dLat=(b.lat-a.lat)*Math.PI/180,dLng=(b.lng-a.lng)*Math.PI/180;
  const x=Math.sin(dLat/2)**2+Math.cos(a.lat*Math.PI/180)*Math.cos(b.lat*Math.PI/180)*Math.sin(dLng/2)**2;
  return R*2*Math.atan2(Math.sqrt(x),Math.sqrt(1-x));
}

function runNN() {
  if(kecNodes.length<2){showToast('danger','Tidak ada request untuk di-rute-kan!');return;}
  const nodes=JSON.parse(JSON.stringify(kecNodes));
  const depot=nodes.find(n=>n.isDepot);
  const waypoints=nodes.filter(n=>!n.isDepot);
  const visited=[depot], unvisited=[...waypoints];
  let totalDist=0;

  while(unvisited.length){
    const last=visited[visited.length-1];
    let nearest=null,nearestD=Infinity,nearestIdx=-1;
    unvisited.forEach((n,i)=>{const d=dist(last,n);if(d<nearestD){nearestD=d;nearest=n;nearestIdx=i;}});
    visited.push(nearest); totalDist+=nearestD; unvisited.splice(nearestIdx,1);
  }
  visited.push({...depot,isReturn:true});
  totalDist+=dist(visited[visited.length-2],depot);
  nnRoute=visited;

  // Update distance column in details table
  visited.slice(1,-1).forEach((n,i)=>{
    const el=document.getElementById('dist_'+i);
    if(el){ el.textContent=dist(visited[i],n).toFixed(3)+' km'; el.style.color='var(--green-700)';el.style.fontWeight='600'; }
  });

  if (gmapsActive) {
    drawGmapsRoute(visited);
  } else {
    // Recolor Leaflet markers
    markers.forEach(m=>mapInstance.removeLayer(m)); markers=[];
    const routeNodes=visited.filter(n=>!n.isDepot&&!n.isReturn);
    routeNodes.forEach((n,i)=>{
      const pinColor = n.tipe === 'cleanup' ? '#f59e0b' : '#1c6434';
      const icon=L.divIcon({className:'',html:`<div style="background:${pinColor};color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:10px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3)">${i+1}</div>`,iconSize:[24,24],iconAnchor:[12,12]});
      const m=L.marker([n.lat,n.lng],{icon,zIndexOffset:20+i}).addTo(mapInstance)
        .bindPopup(`<strong style="color:#1c6434">Stop #${i+1} — ${n.code}</strong><div style="font-size:13px;margin-top:3px">${n.nama}</div><a href="https://www.google.com/maps/dir/?api=1&origin=${DEPOT_LAT},${DEPOT_LNG}&destination=${n.lat},${n.lng}" target="_blank" style="font-size:12px;color:#1c6434;font-weight:600;display:block;margin-top:8px">🧭 Navigasi</a>`);
      markers.push(m);
    });

    drawOSRMRoute(visited);
  }
  showToast('success','Algoritma Nearest Neighbor selesai!');
}

function drawGmapsRoute(waypoints) {
  if (directionsRendererInstance) {
    directionsRendererInstance.setMap(null);
  }

  const directionsService = new google.maps.DirectionsService();
  directionsRendererInstance = new google.maps.DirectionsRenderer({
    map: mapInstance,
    suppressMarkers: true,
    polylineOptions: {
      strokeColor: '#1c6434',
      strokeOpacity: 0.8,
      strokeWeight: 6
    }
  });

  const origin = waypoints[0];
  const destination = waypoints[waypoints.length - 1];
  const gWaypoints = waypoints.slice(1, -1).map(w => ({
    location: new google.maps.LatLng(w.lat, w.lng),
    stopover: true
  }));

  directionsService.route({
    origin: new google.maps.LatLng(origin.lat, origin.lng),
    destination: new google.maps.LatLng(destination.lat, destination.lng),
    waypoints: gWaypoints,
    travelMode: google.maps.TravelMode.DRIVING,
    drivingOptions: {
      departureTime: new Date(),
      trafficModel: google.maps.TrafficModel.BEST_GUESS
    }
  }, (response, status) => {
    if (status === 'OK') {
      directionsRendererInstance.setDirections(response);
      const route = response.routes[0];
      let totalDistance = 0;
      let totalDuration = 0;
      route.legs.forEach(leg => {
        totalDistance += leg.distance.value;
        totalDuration += leg.duration_in_traffic ? leg.duration_in_traffic.value : leg.duration.value;
      });
      const km = (totalDistance / 1000).toFixed(2);
      const mins = Math.round(totalDuration / 60);
      document.getElementById('nnStatus').textContent = `Jarak Nyata: ${km} km | Est. Waktu: ${mins} mnt (Live Traffic)`;
    } else {
      showToast('danger', 'Directions request failed: ' + status);
    }
  });
}

function drawOSRMRoute(visited){
  if(routePolyline){mapInstance.removeLayer(routePolyline);routePolyline=null;}
  const coords = visited.map(n=>n.lng+','+n.lat).join(';');
  const url = 'https://router.project-osrm.org/route/v1/driving/'+coords+'?overview=full&geometries=geojson';
  fetch(url).then(r=>r.json()).then(data=>{
    if(data.code!=='Ok'||!data.routes.length)return;
    const route=data.routes[0];
    routePolyline=L.geoJSON(route.geometry,{style:{color:'#1c6434',weight:5,opacity:0.8}}).addTo(mapInstance);
    const distKm=(route.distance/1000).toFixed(2);
    const durMin=Math.round(route.duration/60);
    const el=document.getElementById('nnStatus');
    if(el) el.textContent=`Jarak nyata: ${distKm} km | Est. waktu: ${durMin} menit (pulang-pergi)`;
    mapInstance.fitBounds(routePolyline.getBounds().pad(0.1));
  }).catch(e=>console.warn('OSRM error:',e));
}

function openGoogleMapsTraffic() {
  let routeToUse = [];
  if (nnRoute && nnRoute.length > 0) {
    routeToUse = nnRoute;
  } else {
    // default
    routeToUse = kecNodes;
  }

  if (routeToUse.length < 2) {
    showToast('danger', 'Tidak ada rute yang tersedia untuk dinavigasi.');
    return;
  }

  const origin = `${routeToUse[0].lat},${routeToUse[0].lng}`;
  const destination = `${routeToUse[routeToUse.length - 1].lat},${routeToUse[routeToUse.length - 1].lng}`;
  // If destination is return to depot, handle it
  const isReturn = routeToUse[routeToUse.length - 1].isReturn || routeToUse[routeToUse.length - 1].isDepot;
  const targetDest = isReturn ? origin : destination;

  const waypoints = routeToUse.slice(1, -1).map(w => `${w.lat},${w.lng}`).join('|');
  const mapsUrl = `https://www.google.com/maps/dir/?api=1&origin=${encodeURIComponent(origin)}&destination=${encodeURIComponent(targetDest)}&waypoints=${encodeURIComponent(waypoints)}&travelmode=driving`;
  window.open(mapsUrl, '_blank');
}

// Jadwal day navigation
const HARI_ID = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
let dayOffset = 0;
function shiftDay(d){ dayOffset += d; updateDayLabel(); }
function updateDayLabel(){
  const today=new Date(), target=new Date(today);
  target.setDate(today.getDate()+dayOffset);
  document.getElementById('dayLabel').textContent = HARI_ID[target.getDay()]+', '+target.toLocaleDateString('id-ID',{day:'numeric',month:'long',year:'numeric'});
}

window.addEventListener('DOMContentLoaded',()=>{
  updateDayLabel();
  if (typeof initMap === 'function') {
    if (!gmapsActive) {
      initMap();
    }
  }
});

// ── Generate Jadwal (calls PHP generateSchedule()) ──────────
async function generateScheduleNow() {
  const btn    = document.getElementById('btnGenSched');
  const status = document.getElementById('genSchedStatus');
  const tanggal= document.getElementById('genSchedDate')?.value || '';
  if (!tanggal) { customAlert('Pilih tanggal terlebih dahulu.'); return; }
  btn.disabled = true; btn.textContent = '⏳ Memproses…';
  status.textContent = '';
  try {
    const urlParams = new URLSearchParams(window.location.search);
    const tipe = urlParams.get('tipe') || 'pickup';

    const fd = new FormData();
    fd.append('action',  'generate_schedule');
    fd.append('tanggal', tanggal);
    fd.append('tipe', tipe);

    const r  = await fetch('rute_jadwal.php', { method:'POST', body:fd });
    const d  = await r.json();
    if (d.ok) {
      const res = d.result;
      status.textContent = `✅ ${res.schedules_created} jadwal dibuat.`;
      status.style.color = '#166534';
      showToast('success', `${res.schedules_created} jadwal berhasil dibuat.`);
      setTimeout(() => location.reload(), 1800);
    } else {
      status.textContent = ''; // Tidak menampilkan error panjang disebelah tombol
      customAlert(d.error || 'Gagal membuat jadwal.');
    }
  } catch(e) {
    status.textContent = '';
    customAlert('Koneksi error: ' + e.message);
  } finally {
    btn.disabled = false; btn.textContent = '⚙️ Generate Jadwal';
  }
}

// ── Reset Draft Jadwal ────────────────────────────────────────
async function resetSchedule() {
  const tanggal = document.getElementById('genSchedDate')?.value || '';
  if (!tanggal) { customAlert('Pilih tanggal terlebih dahulu.'); return; }
  if (!confirm(`Reset semua jadwal DRAFT untuk tanggal ${tanggal}?\nStatus request akan kembali ke "dikonfirmasi".`)) return;
  const status = document.getElementById('genSchedStatus');
  
  const urlParams = new URLSearchParams(window.location.search);
  const tipe = urlParams.get('tipe') || 'pickup';

  const fd = new FormData();
  fd.append('action','reset_schedule'); 
  fd.append('tanggal', tanggal);
  fd.append('tipe', tipe);

  try {
    const r = await fetch('rute_jadwal.php', {method:'POST', body:fd});
    const d = await r.json();
    if (d.ok) {
      status.textContent = '✅ Draft jadwal dihapus.'; status.style.color='#166534';
      showToast('success', 'Draft jadwal berhasil di-reset.');
      setTimeout(()=>location.reload(), 1200);
    } else {
      status.textContent = '';
      customAlert(d.error || 'Gagal mereset jadwal.');
    }
  } catch(e) { 
    status.textContent = '';
    customAlert('Koneksi error: ' + e.message);
  }
}

// ── Custom Centered Alert Dialog ──────────────────────────────
function customAlert(msg) {
  const existing = document.getElementById('customAlertOverlay');
  if (existing) existing.remove();

  const overlay = document.createElement('div');
  overlay.id = 'customAlertOverlay';
  overlay.className = 'custom-alert-overlay';

  const box = document.createElement('div');
  box.className = 'custom-alert-box';

  const message = document.createElement('div');
  message.className = 'custom-alert-message';
  message.textContent = msg;

  const btn = document.createElement('button');
  btn.className = 'custom-alert-btn';
  btn.textContent = 'OK';
  btn.onclick = () => overlay.remove();

  box.appendChild(message);
  box.appendChild(btn);
  overlay.appendChild(box);
  document.body.appendChild(overlay);
}
</script>
<?php require_once __DIR__ . '/layout/footer.php'; ?>
