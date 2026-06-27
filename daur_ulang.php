<?php
// ============================================================
//  daur_ulang.php — Permintaan Jemput Sampah
//  Manado Recycle Hub · v4.1
//  5-step wizard · Desktop responsive · Order tracking
// ============================================================
require_once __DIR__ . '/include/config.php';
require_once __DIR__ . '/include/auth.php';

// ── CONFIGURATION: MASUKKAN GOOGLE MAPS API KEY ANDA DI SINI ──
$google_maps_api_key = getGmapsKey(); // Silakan masukkan Google Maps API Key Anda di sini

$pdo = getDB();

if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_live_location') {
    header('Content-Type: application/json');
    $id = intval($_GET['id'] ?? 0);
    $type = $_GET['type'] ?? 'daur_ulang';
    $canSeeLiveLocation = isLoggedIn() && in_array(currentUserRole() ?? '', ['admin', 'officer'], true);
    if ($id) {
        if ($type === 'daur_ulang') {
            $stmt = $pdo->prepare("
                SELECT pr.status, pr.latitude, pr.longitude,
                       o.nama AS officer_nama, o.last_lat, o.last_lng, o.last_seen_at
                FROM   pickup_requests pr
                LEFT   JOIN officers o ON o.id = pr.officer_id
                WHERE  pr.id = ?
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT cr.status, cr.latitude, cr.longitude,
                       o.nama AS officer_nama, o.last_lat, o.last_lng, o.last_seen_at
                FROM   cleanup_requests cr
                LEFT   JOIN officers o ON o.id = cr.officer_id
                WHERE  cr.id = ?
            ");
        }
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($data) {
            // Jika bukan admin/petugas, pastikan status tugas sedang aktif agar bisa dilacak oleh warga/mitra
            if (!$canSeeLiveLocation) {
                $allowedStatus = ['dalam_perjalanan', 'sedang_diproses', 'sedang_cleanup'];
                if (!in_array($data['status'], $allowedStatus, true)) {
                    $data['officer_nama'] = null;
                    $data['last_lat'] = null;
                    $data['last_lng'] = null;
                    $data['last_seen_at'] = null;
                }
            }
            echo json_encode(['ok' => true, 'data' => $data]);
            exit;
        }
    }
    echo json_encode(['ok' => false, 'error' => 'Not found']);
    exit;
}

// ── Migrasi kolom (idempotent) ────────────────────────────────
foreach ([
    "ALTER TABLE pickup_requests ADD COLUMN kecamatan        VARCHAR(100)  NULL",
    "ALTER TABLE pickup_requests ADD COLUMN kelurahan        VARCHAR(100)  NULL",
    "ALTER TABLE pickup_requests ADD COLUMN alamat_jemput    TEXT          NULL",
    "ALTER TABLE pickup_requests ADD COLUMN berat_total_kg   DECIMAL(10,2) NULL",
    "ALTER TABLE pickup_requests ADD COLUMN berat_kg         VARCHAR(50)   NULL",
    "ALTER TABLE pickup_requests ADD COLUMN tanggal_jemput   DATE          NULL",
    "ALTER TABLE pickup_requests ADD COLUMN jam_jemput       TIME          NULL",
    "ALTER TABLE pickup_requests ADD COLUMN catatan          TEXT          NULL",
    "ALTER TABLE pickup_requests ADD COLUMN place_name       VARCHAR(255)  NULL",
    "ALTER TABLE pickup_requests ADD COLUMN place_type       VARCHAR(100)  NULL",
    "ALTER TABLE pickup_requests ADD COLUMN partner_name     VARCHAR(255)  NULL",
    "ALTER TABLE pickup_requests ADD COLUMN pickup_type      VARCHAR(50)   NULL",
    "ALTER TABLE pickup_requests ADD COLUMN service_type     VARCHAR(50)   NULL",
    "ALTER TABLE pickup_requests ADD COLUMN price_per_kg     DECIMAL(10,2) NULL",
    "ALTER TABLE pickup_requests ADD COLUMN latitude         DECIMAL(10,8) NULL",
    "ALTER TABLE pickup_requests ADD COLUMN longitude        DECIMAL(11,8) NULL",
    "ALTER TABLE pickup_requests ADD COLUMN koordinat_manual TINYINT(1)    NOT NULL DEFAULT 0",
    "ALTER TABLE pickup_request_items ADD COLUMN estimasi_kg DECIMAL(10,2) NULL",
    "ALTER TABLE pickup_request_items ADD COLUMN aktual_kg   DECIMAL(10,2) NULL",
    "ALTER TABLE pickup_request_items ADD COLUMN catatan     TEXT          NULL",
] as $mq) {
    try { $pdo->exec($mq); }
    catch (PDOException $e) {
        if (strpos($e->getMessage(), '1060') === false) error_log('[MRH] ' . $e->getMessage());
    }
}

// ── Kategori sampah ───────────────────────────────────────────
$dbCats      = $pdo->query("SELECT kode, name AS label, ikon_emoji AS icon FROM waste_categories WHERE is_active=1 ORDER BY id")->fetchAll();
$barang_opts = [];
foreach ($dbCats as $c) $barang_opts[$c['kode']] = ['label' => $c['label'], 'icon' => $c['icon']];
if (empty($barang_opts)) $barang_opts = [
    'kertas_hvs'      => ['label' => 'Kertas HVS',     'icon' => '📄'],
    'kardus'          => ['label' => 'Kardus',          'icon' => '📦'],
    'botol_plastik'   => ['label' => 'Botol Plastik',   'icon' => '🍶'],
    'gelas_plastik'   => ['label' => 'Gelas Plastik',   'icon' => '🥤'],
    'plastik_lain'    => ['label' => 'Plastik Lain',    'icon' => '🛍️'],
    'buku_bekas'      => ['label' => 'Buku Bekas',      'icon' => '📚'],
    'furniture_bekas' => ['label' => 'Furniture Bekas', 'icon' => '🪑'],
];

// ── Kecamatan ─────────────────────────────────────────────────
$kec_opts = [
    'bunaken'           => 'Bunaken',
    'bunaken_kepulauan' => 'Bunaken Kepulauan',
    'malalayang'        => 'Malalayang',
    'mapanget'          => 'Mapanget',
    'paal_dua'          => 'Paal Dua',
    'paal_empat'        => 'Paal Empat',
    'sario'             => 'Sario',
    'singkil'           => 'Singkil',
    'tikala'            => 'Tikala',
    'tuminting'         => 'Tuminting',
    'wanea'             => 'Wanea',
    'wenang'            => 'Wenang',
];

// ── Tab state ─────────────────────────────────────────────────
$active_tab  = $_GET['tab'] ?? 'form';
$track_query = '';
$track_res   = [];

if ($active_tab === 'track') {
    $track_query = trim($_GET['q'] ?? '');
    if ($track_query !== '') {
        // Normalize search term: remove non-digits for phone searching
        $norm_q = preg_replace('/\D/', '', $track_query);
        // Strip leading 62 or 0 if present to get local digits
        if (strpos($norm_q, '62') === 0) {
            $norm_q = substr($norm_q, 2);
        } elseif (strpos($norm_q, '0') === 0) {
            $norm_q = substr($norm_q, 1);
        }
        $is_code = preg_match('/[A-Za-z]/', $track_query);
        try {
            if ($is_code) {
                $st = $pdo->prepare("
                    SELECT pr.*,
                           o.nama AS officer_nama,
                           o.last_lat AS officer_lat,
                           o.last_lng AS officer_lng,
                           o.last_seen_at AS officer_last_seen,
                           GROUP_CONCAT(wc.name ORDER BY wc.id SEPARATOR ', ') AS sampah_list
                    FROM   pickup_requests pr
                    LEFT   JOIN officers o ON o.id = pr.officer_id
                    LEFT   JOIN pickup_request_items pri ON pri.pickup_id = pr.id
                    LEFT   JOIN waste_categories wc ON wc.id = pri.category_id
                    WHERE  pr.request_code = :code
                    GROUP  BY pr.id
                    ORDER  BY pr.created_at DESC
                    LIMIT  20
                ");
                $st->execute([':code' => $track_query]);
            } else {
                $st = $pdo->prepare("
                    SELECT pr.*,
                           o.nama AS officer_nama,
                           o.last_lat AS officer_lat,
                           o.last_lng AS officer_lng,
                           o.last_seen_at AS officer_last_seen,
                           GROUP_CONCAT(wc.name ORDER BY wc.id SEPARATOR ', ') AS sampah_list
                    FROM   pickup_requests pr
                    LEFT   JOIN officers o ON o.id = pr.officer_id
                    LEFT   JOIN pickup_request_items pri ON pri.pickup_id = pr.id
                    LEFT   JOIN waste_categories wc ON wc.id = pri.category_id
                    WHERE  pr.nomor_wa = :wa1
                        OR pr.nomor_wa LIKE :wa2
                        OR (:norm1 <> '' AND (pr.nomor_wa = :norm2 OR pr.nomor_wa LIKE :norm_like))
                    GROUP  BY pr.id
                    ORDER  BY pr.created_at DESC
                    LIMIT  20
                ");
                $st->execute([
                    ':wa1' => $track_query,
                    ':wa2' => '%' . $track_query . '%',
                    ':norm1' => $norm_q,
                    ':norm2' => $norm_q,
                    ':norm_like' => '%' . $norm_q . '%'
                ]);
            }
            $track_res = $st->fetchAll();
        } catch (Exception $e) {
            error_log('[MRH Track] ' . $e->getMessage());
        }
    }
}

// ── POST handler ─────────────────────────────────────────────
$submitted    = false;
$errors       = [];
$db_error_msg = '';
$request_code = '';
$sub_data     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'submit_form') {
    if (empty($_POST['barang']))                         $errors[] = 'barang';
    if (empty(trim($_POST['nama']          ?? '')))      $errors[] = 'nama';
    if (empty(trim($_POST['nomor_wa']      ?? '')))      $errors[] = 'nomor_wa';
    if (empty(trim($_POST['kecamatan']     ?? '')))      $errors[] = 'kecamatan';
    if (empty(trim($_POST['kelurahan']     ?? '')))      $errors[] = 'kelurahan';
    if (empty(trim($_POST['alamat_jemput'] ?? '')))      $errors[] = 'alamat_jemput';
    if (empty(trim($_POST['berat_kg']      ?? '')))      $errors[] = 'berat_kg';

    $lat_raw = trim($_POST['latitude']  ?? '');
    $lng_raw = trim($_POST['longitude'] ?? '');
    if ($lat_raw !== '' && (!is_numeric($lat_raw) || floatval($lat_raw) < -90  || floatval($lat_raw) > 90))   $errors[] = 'latitude';
    if ($lng_raw !== '' && (!is_numeric($lng_raw) || floatval($lng_raw) < -180 || floatval($lng_raw) > 180))  $errors[] = 'longitude';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $pkt  = trim(clean($_POST['pickup_type'] ?? ''));
            $pfx  = $pkt ?: 'S';
            $request_code = generateSmartCode($pdo, 'pickup_requests', 'request_code', 'MRH-' . $pfx);

            $nm  = trim(clean($_POST['nama']          ?? ''));
            $ac  = trim(clean($_POST['area_code']     ?? '+62'));
            $wa  = trim(clean($_POST['nomor_wa']      ?? ''));
            $kec = trim(clean($_POST['kecamatan']     ?? ''));
            $kel = trim(clean($_POST['kelurahan']     ?? ''));
            $al  = trim(clean($_POST['alamat_jemput'] ?? ''));
            $cat = trim(clean($_POST['catatan']       ?? ''));
            $pn  = trim(clean($_POST['place_name']    ?? ''));
            $pt  = trim(clean($_POST['place_type']    ?? ''));
            $par = trim(clean($_POST['partner_name']  ?? ''));
            $svc = trim(clean($_POST['service_type']  ?? 'Free'));
            $ppk = trim(clean($_POST['price_per_kg']  ?? ''));
            $ppv = ($ppk !== '') ? (float)str_replace(',', '.', $ppk) : null;

            $lat = ($lat_raw !== '' && is_numeric($lat_raw)) ? floatval($lat_raw) : null;
            $lng = ($lng_raw !== '' && is_numeric($lng_raw)) ? floatval($lng_raw) : null;
            $km  = intval($_POST['koordinat_manual'] ?? 0);

            $brat = trim(clean($_POST['berat_kg'] ?? ''));
            $bnum = preg_replace('/[^\d.]/', '', str_replace(',', '.', $brat));
            $bdec = ($bnum !== '') ? (float)$bnum : null;

            // Tanggal dan jam ditentukan oleh admin/officer, tidak lagi diisi oleh user
            $tgl = null;
            $jam = null;

            $pdo->prepare("
                INSERT INTO pickup_requests
                    (request_code, nama_pemohon, area_code, nomor_wa,
                     kecamatan, kelurahan, alamat_jemput,
                     place_name, place_type, partner_name, pickup_type, service_type, price_per_kg,
                     latitude, longitude, koordinat_manual,
                     berat_total_kg, berat_kg, tanggal_jemput, jam_jemput, catatan,
                     status, created_at, updated_at)
                VALUES
                    (:rc,:nm,:ac,:wa,:kec,:kel,:al,:pn,:pt,:par,:pkt,:svc,:ppv,
                     :lat,:lng,:km,:bkd,:bkr,:tgl,:jam,:cat,'menunggu',NOW(),NOW())"
            )->execute([
                ':rc'  => $request_code, ':nm' => $nm,  ':ac' => $ac,  ':wa' => $wa,
                ':kec' => $kec,          ':kel' => $kel, ':al' => $al,
                ':pn'  => ($pn  ?: null), ':pt' => ($pt ?: null), ':par' => ($par ?: null),
                ':pkt' => ($pkt ?: null), ':svc' => $svc, ':ppv' => $ppv,
                ':lat' => $lat, ':lng' => $lng, ':km' => $km,
                ':bkd' => $bdec, ':bkr' => $brat, ':tgl' => $tgl, ':jam' => $jam,
                ':cat' => ($cat !== '' ? $cat : null),
            ]);
            $pid = (int)$pdo->lastInsertId();

            // Kirim notifikasi sistem ke Admin
            createNotification($pdo, 'admin', 'Request Penjemputan Baru', "Permohonan penjemputan sampah ($request_code) oleh " . $nm . " telah diajukan.", 'pickup', $pid, 'pickup_requests');

            if (!empty($_POST['barang'])) {
                $ni  = count($_POST['barang']);
                $est = ($bdec !== null && $ni > 0) ? round($bdec / $ni, 2) : null;
                $si  = $pdo->prepare("
                    INSERT INTO pickup_request_items (pickup_id, category_id, estimasi_kg, aktual_kg, catatan)
                    SELECT :pid, id, :est, NULL, NULL FROM waste_categories
                    WHERE kode = :kode AND is_active = 1 LIMIT 1");
                foreach ($_POST['barang'] as $bk) {
                    $kd = trim(clean($bk));
                    if ($kd !== '') $si->execute([':pid' => $pid, ':est' => $est, ':kode' => $kd]);
                }
            }

            // Kirim notifikasi email otomatis saat order masuk
            triggerNewOrderEmail($pdo, $pid);

            $pdo->commit();
            $submitted = true;

            // Store display values for success screen
            $sub_data = [
                'nama'   => $nm,
                'wa'     => $ac . $wa,
                'kec'    => $kec_opts[$kec] ?? $kec,
                'berat'  => $brat,
                'lat'    => $lat,
                'lng'    => $lng,
                'barang' => implode(', ', array_map(
                    fn($k) => $barang_opts[$k]['label'] ?? $k,
                    array_map('trim', (array)($_POST['barang'] ?? []))
                )),
            ];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[]     = 'database_error';
            $db_error_msg = $e->getMessage();
            error_log('[MRH] ' . $e->getMessage());
        }
    }
}

// ── Form values (preserved after validation errors) ───────────
$f = fn($k, $d = '') => clean($_POST[$k] ?? $d);
$v = [
    'barang'  => $_POST['barang']  ?? [],
    'nama'    => $f('nama'),
    'ac'      => $f('area_code', '+62'),
    'wa'      => $f('nomor_wa'),
    'kec'     => $f('kecamatan'),
    'kel'     => $f('kelurahan'),
    'al'      => $f('alamat_jemput'),
    'lat'     => $f('latitude'),
    'lng'     => $f('longitude'),
    'km'      => intval($_POST['koordinat_manual'] ?? 0),
    'berat'   => $f('berat_kg'),
    'cat'     => $f('catatan'),
    'pname'   => $f('place_name'),
    'ptype'   => $f('place_type'),
    'partner' => $f('partner_name'),
    'pkt'     => $f('pickup_type'),
    'svc'     => $f('service_type', 'Free'),
    'ppkg'    => $f('price_per_kg'),
];

// Map errors back to the step they belong to
$init_step = 1;
if (!empty($errors)) {
    if (in_array('berat_kg', $errors) || in_array('database_error', $errors))                           $init_step = 4;
    elseif (in_array('kecamatan', $errors) || in_array('kelurahan', $errors) || in_array('alamat_jemput', $errors)) $init_step = 3;
    elseif (in_array('nama', $errors) || in_array('nomor_wa', $errors))                                 $init_step = 2;
    else $init_step = 1;
}

// ── Tracking: status → step index ────────────────────────────
$status_step_map = [
    'menunggu'        => 1,
    'dikonfirmasi'    => 2,
    'dijadwalkan'     => 3,
    'dalam_perjalanan'=> 4,
    'sedang_diproses' => 4,
    'selesai'         => 5,
];
$status_label_map = [
    'menunggu'        => 'Menunggu',
    'dikonfirmasi'    => 'Dikonfirmasi',
    'dijadwalkan'     => 'Dijadwalkan',
    'dalam_perjalanan'=> 'Dalam Perjalanan',
    'sedang_diproses' => 'Sedang Diproses',
    'selesai'         => 'Selesai',
    'dibatalkan'      => 'Dibatalkan',
];
$track_step_defs = [
    ['icon' => '📥', 'label' => 'Menunggu',     'desc' => 'Request diterima'],
    ['icon' => '✅', 'label' => 'Dikonfirmasi', 'desc' => 'Admin verifikasi'],
    ['icon' => '📅', 'label' => 'Dijadwalkan',  'desc' => 'Jadwal ditetapkan'],
    ['icon' => '🚛', 'label' => 'Diproses',     'desc' => 'Petugas berangkat'],
    ['icon' => '🎉', 'label' => 'Selesai',      'desc' => 'Sampah diangkut'],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Daur Ulang Sekarang — Manado Recycle Hub</title>
<link rel="icon" type="image/png" href="<?= baseUrl('Title.png') ?>">
<link href="https://fonts.googleapis.com/css2?family=Comfortaa:wght@400;600;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
/* ── Reset & Variables ───────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --gd:   #1c6434;
    --gm:   #6aa84f;
    --gl:   #e8f5e9;
    --gml:  #c8e6c9;
    --red:  #e53935;
    --blu:  #1976d2;
    --blul: #e3f2fd;
    --blum: #bbdefb;
    --td:   #1b2b1c;
    --tm:   #4a6649;
    --tl:   #7a9b7a;
    --wh:   #ffffff;
    --bg:   #f4faf4;
    --shad: 0 4px 24px rgba(46,125,50,.10);
    --rad:  16px;
    --spring-transit: cubic-bezier(0.34, 1.56, 0.64, 1);
    --smooth-transit: cubic-bezier(0.16, 1, 0.3, 1);
}

body {
    font-family: 'Comfortaa', sans-serif;
    background: var(--bg);
    min-height: 100vh;
    color: var(--td);
    display: flex;
    flex-direction: column;
}

/* ── Top Navbar ──────────────────────────────────────────────── */
.top-nav {
    background: var(--gd);
    padding: 12px 24px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,.18);
    position: sticky;
    top: 0;
    z-index: 100;
}
.top-nav a.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: rgba(255,255,255,.80);
    text-decoration: none;
    font-size: .82rem;
    font-weight: 700;
    padding: 5px 10px;
    border: 1.5px solid rgba(255,255,255,.30);
    border-radius: 8px;
    transition: background .2s;
}
.top-nav a.back-btn:hover { background: rgba(255,255,255,.12); color: #fff; }
.top-nav .site-name {
    font-size: 1rem;
    font-weight: 900;
    color: #fff;
    flex: 1;
    letter-spacing: .02em;
}

/* ── Tab Switcher ────────────────────────────────────────────── */
.tabs {
    display: flex;
    background: var(--wh);
    border-bottom: 2px solid var(--gml);
    padding: 0 20px;
    gap: 4px;
    justify-content: center;
}
.tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 13px 22px;
    font-size: .9rem;
    font-weight: 800;
    color: var(--tl);
    text-decoration: none;
    border-bottom: 3px solid transparent;
    transition: color .2s, border-color .2s;
    white-space: nowrap;
    letter-spacing: .02em;
}
.tab-btn:hover { color: var(--gm); border-bottom-color: var(--gml); }
.tab-btn.active { color: var(--gd); border-bottom-color: var(--gd); }

/* ── Page Wrapper ────────────────────────────────────────────── */
.page-wrapper {
    max-width: 680px;
    margin: 0 auto;
    padding: 28px 16px 60px;
}

/* ── Sidebar stepper (hidden on mobile, shown on desktop) ───── */
.sidebar-stepper { display: none; }

/* ── Top stepper (mobile) ────────────────────────────────────── */
.top-stepper {
    margin-bottom: 18px;
}
.stepper-bar {
    display: flex;
    align-items: center;
    gap: 0;
    margin-bottom: 10px;
}
.stp-node {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--gml);
    color: var(--tl);
    font-size: .78rem;
    font-weight: 900;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: background .3s, color .3s;
    position: relative;
    z-index: 1;
}
.stp-node.done   { background: var(--gm); color: #fff; }
.stp-node.active { background: var(--gd); color: #fff; box-shadow: 0 0 0 4px rgba(46,125,50,.18); }
.stp-line {
    flex: 1;
    height: 3px;
    background: var(--gml);
    transition: background .3s;
}
.stp-line.done { background: var(--gm); }
.stepper-labels {
    display: flex;
    justify-content: space-between;
    padding: 0 4px;
}
.stp-lbl {
    font-size: .65rem;
    font-weight: 700;
    color: var(--tl);
    text-align: center;
    width: 60px;
    line-height: 1.3;
    transition: color .3s;
}
.stp-lbl.active { color: var(--gd); }
.stp-lbl.done   { color: var(--gm); }

/* Step counter */
.step-counter {
    text-align: right;
    font-size: .75rem;
    font-weight: 700;
    color: var(--tl);
    margin-bottom: 4px;
}

/* ── Card ────────────────────────────────────────────────────── */
.card {
    background: var(--wh);
    border-radius: var(--rad);
    box-shadow: var(--shad);
    overflow: hidden;
    transition: transform .25s var(--spring-transit), box-shadow .25s ease;
}
.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 32px rgba(46,125,50,.15);
}

/* ── Form Step ───────────────────────────────────────────────── */
.form-step { display: none; padding: 28px 24px 8px; }
.form-step.active {
    display: block;
    animation: stepIn 0.45s var(--spring-transit) both;
}
@keyframes stepIn {
    from { opacity: 0; transform: scale(0.96) translateY(15px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}

.step-title {
    font-size: 1.35rem;
    font-weight: 900;
    color: var(--td);
    margin-bottom: 4px;
}
.step-sub {
    font-size: .88rem;
    color: var(--tm);
    font-weight: 600;
    margin-bottom: 20px;
    line-height: 1.5;
}

/* ── Barang Grid ─────────────────────────────────────────────── */
.barang-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 8px;
}
.barang-item { position: relative; cursor: pointer; }
.barang-item input[type="checkbox"] {
    position: absolute;
    top: 7px; left: 7px;
    width: 15px; height: 15px;
    accent-color: var(--gm);
    cursor: pointer;
}
.barang-label {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 5px;
    border: 2px solid var(--gml);
    border-radius: 12px;
    padding: 12px 5px 10px;
    font-size: .7rem;
    font-weight: 700;
    color: var(--tm);
    background: var(--gl);
    transition: border-color .2s, background-color .2s, transform .25s var(--spring-transit), box-shadow .25s;
    cursor: pointer;
    min-height: 84px;
    text-align: center;
    line-height: 1.3;
}
.barang-label .bico { font-size: 1.75rem; }
.barang-item input:checked + .barang-label {
    border-color: var(--gd);
    background: var(--gml);
    color: var(--gd);
    transform: scale(1.04);
}
.barang-item:hover .barang-label { border-color: var(--gm); }

/* ── Field Wrap ──────────────────────────────────────────────── */
.fw { margin-top: 14px; text-align: left; }
.fw:first-of-type { margin-top: 0; }
.fw label {
    display: block;
    font-size: .78rem;
    font-weight: 700;
    color: var(--tl);
    margin-bottom: 5px;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.fw input[type="text"],
.fw input[type="tel"],
.fw input[type="date"],
.fw input[type="time"],
.fw input[type="number"],
.fw select,
.fw textarea {
    width: 100%;
    border: 2px solid var(--gml);
    border-radius: 10px;
    padding: 11px 13px;
    font-family: 'Comfortaa', sans-serif;
    font-size: .95rem;
    font-weight: 600;
    color: var(--td);
    background: var(--gl);
    outline: none;
    transition: border-color .25s var(--smooth-transit), background-color .25s, box-shadow .25s var(--smooth-transit);
    appearance: none;
    -webkit-appearance: none;
}
.fw input:focus, .fw select:focus, .fw textarea:focus {
    border-color: var(--gm); background: #fff;
    box-shadow: 0 0 0 4px rgba(74, 102, 73, 0.12);
}
.fw input.err, .fw select.err, .fw textarea.err {
    border-color: var(--red); background: #fff5f5;
}
.fw textarea { resize: vertical; min-height: 80px; line-height: 1.55; }

.sel-wrap { position: relative; }
.sel-wrap::after {
    content: '▾'; position: absolute; right: 13px; top: 50%;
    transform: translateY(-50%); color: var(--gm);
    font-size: 1rem; pointer-events: none;
}
.sel-wrap select { padding-right: 34px; cursor: pointer; }

.sfx-wrap { position: relative; }
.sfx-wrap input { padding-right: 50px; }
.sfx {
    position: absolute; right: 13px; top: 50%; transform: translateY(-50%);
    font-size: .82rem; font-weight: 800; color: var(--gm); pointer-events: none;
}

.frow { display: flex; gap: 13px; margin-top: 14px; }
.frow .fw { flex: 1; margin-top: 0; }

.wa-row { display: flex; gap: 8px; }
.wa-row input:first-child { width: 76px; flex-shrink: 0; }

.fhint { font-size: .73rem; font-weight: 600; color: var(--tl); margin-top: 4px; }

/* ── Advanced Toggle ─────────────────────────────────────────── */
.adv-toggle {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 16px 0 0;
    padding: 10px 14px;
    background: #f0fdf0;
    border: 1.5px dashed var(--gml);
    border-radius: 9px;
    cursor: pointer;
    font-size: .82rem;
    font-weight: 700;
    color: var(--tl);
    user-select: none;
    transition: background .2s, border-color .2s;
}
.adv-toggle:hover { background: var(--gl); border-color: var(--gm); color: var(--gm); }
.adv-toggle .adv-arrow { transition: transform .25s; font-style: normal; }
.adv-toggle.open .adv-arrow { transform: rotate(180deg); }
.adv-panel { display: none; }
.adv-panel.open { display: block; }

/* ── Error Messages ──────────────────────────────────────────── */
.errmsg {
    display: inline-block;
    background: var(--red); color: #fff;
    font-size: .75rem; font-weight: 700;
    padding: 3px 10px; border-radius: 5px; margin-top: 6px;
}
.err-banner {
    background: #fff3f3;
    border: 2px solid var(--red);
    border-radius: 10px;
    padding: 12px 16px;
    margin: 16px 24px 0;
    font-size: .84rem; font-weight: 700; color: var(--red);
}
.db-err-detail {
    font-family: monospace; font-size: .74rem; font-weight: 600;
    color: #b71c1c; background: #ffebee; border-radius: 6px;
    padding: 5px 9px; margin-top: 7px; word-break: break-word;
}

/* ── GPS ─────────────────────────────────────────────────────── */
.btn-gps {
    width: 100%; display: flex; align-items: center; justify-content: center;
    gap: 9px; padding: 12px 16px; background: var(--blu); color: #fff;
    border: none; border-radius: 10px; font-family: 'Comfortaa', sans-serif;
    font-size: .9rem; font-weight: 800; cursor: pointer;
    transition: background .2s, transform .15s; margin-bottom: 10px;
}
.btn-gps:hover  { background: #1565c0; transform: scale(1.01); }
.btn-gps.loading { background: #64b5f6; cursor: wait; }
.btn-gps.ok     { background: var(--gm); }
.btn-gps.fail   { background: var(--red); }
.btn-gps svg    { width: 17px; height: 17px; stroke: currentColor; fill: none; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }

.gps-status {
    display: none; align-items: center; gap: 8px;
    font-size: .78rem; font-weight: 700; padding: 8px 12px;
    border-radius: 8px; margin-bottom: 10px; line-height: 1.45;
}
.gps-status.show { display: flex; }
.gps-status.detecting { background: var(--blul); color: var(--blu); }
.gps-status.found     { background: var(--gl); color: var(--gd); border: 1px solid var(--gml); }
.gps-status.denied    { background: #fff3e0; color: #e65100; border: 1px solid #ffe0b2; }
.gps-status.gpsfail   { background: #ffebee; color: var(--red); border: 1px solid #ffcdd2; }

#pickerMap {
    height: 240px; width: 100%; margin-bottom: 4px;
    border-radius: 10px; border: 2px solid var(--blum); z-index: 1;
}
.map-hint { font-size: .72rem; color: var(--tl); font-weight: 700; margin-bottom: 10px; line-height: 1.4; text-align: center; }
.search-results-dropdown {
    box-sizing: border-box;
}
.search-result-item {
    padding: 10px 14px;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--td);
    cursor: pointer;
    border-bottom: 1px solid var(--gl);
    transition: background 0.2s;
    text-align: left;
}
.search-result-item:last-child {
    border-bottom: none;
}
.search-result-item:hover {
    background: var(--gl);
}
.search-result-item strong {
    color: var(--gd);
    display: block;
    font-size: 0.88rem;
    margin-bottom: 2px;
}

.kd-box {
    display: none; background: var(--blul); border: 1.5px solid var(--blum);
    border-radius: 9px; padding: 9px 13px; margin-bottom: 12px;
}
.kd-box.show { display: block; }
.kd-row { display: flex; justify-content: space-between; padding: 2px 0; font-size: .8rem; }
.kd-lbl { color: var(--blu); font-weight: 700; }
.kd-val { color: #0d47a1; font-weight: 800; font-family: monospace; font-size: .82rem; }
.kd-link {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: .75rem; font-weight: 800; color: var(--blu);
    text-decoration: none; margin-top: 7px; padding: 4px 11px;
    background: #fff; border: 1.5px solid var(--blum); border-radius: 6px; transition: background .2s;
}
.kd-link:hover { background: var(--blum); }

.latlng-row { display: flex; gap: 12px; }
.latlng-row .fw { flex: 1; margin-top: 0; }
.fw.gps-inp input[type="text"] { border-color: var(--blum); background: var(--blul); }
.fw.gps-inp input[type="text"]:focus { border-color: var(--blu); background: #fff; }
.fw.gps-inp label { color: #5c85d6; }

/* ── Review (Step 5) ─────────────────────────────────────────── */
.review-box {
    background: var(--gl);
    border: 1.5px solid var(--gml);
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 14px;
}
.review-box .rb-title {
    font-size: .73rem; font-weight: 800; color: var(--tl);
    text-transform: uppercase; letter-spacing: .06em; margin-bottom: 10px;
}
.review-row {
    display: flex; justify-content: space-between; align-items: flex-start;
    gap: 8px; padding: 4px 0;
    border-bottom: 1px solid var(--gml); font-size: .82rem;
}
.review-row:last-child { border-bottom: none; }
.rl { color: var(--tl); font-weight: 700; flex-shrink: 0; min-width: 110px; }
.rv { color: var(--td); font-weight: 700; text-align: right; word-break: break-word; }

/* ── Card Footer / Nav Buttons ───────────────────────────────── */
.card-footer {
    display: flex;
    align-items: center;
    background: var(--gm);
    padding: 0;
    border-top: none;
    border-radius: 0 0 var(--rad) var(--rad);
}
.btn-nav {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 17px 24px;
    font-family: 'Comfortaa', sans-serif;
    font-size: .88rem; font-weight: 800;
    letter-spacing: .07em; text-transform: uppercase;
    border: none; cursor: pointer; background: transparent; color: #fff;
    transition: background .2s, transform .25s var(--spring-transit);
}
.btn-nav:hover { background: rgba(0,0,0,.10); transform: translateY(-1px); }
.btn-nav:active:not(:disabled) { transform: scale(0.96); }
.btn-nav:disabled { opacity: .45; cursor: not-allowed; }
.btn-prev { color: rgba(255,255,255,.80); }
.btn-next, .btn-submit { margin-left: auto; }
.btn-submit { background: rgba(0,0,0,.10); }
.btn-nav svg { width: 17px; height: 17px; stroke: currentColor; fill: none; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }
#btnPrev { display: none; }
#btnSubmit { display: none; }

/* ── Tracking Tab ────────────────────────────────────────────── */
.track-wrap { padding: 28px 16px 60px; max-width: 680px; margin: 0 auto; }
.track-search {
    background: var(--wh); border-radius: var(--rad);
    box-shadow: var(--shad); padding: 24px; margin-bottom: 24px;
}
.track-search h2 { font-size: 1.2rem; font-weight: 900; color: var(--td); margin-bottom: 6px; }
.track-search p  { font-size: .88rem; color: var(--tm); margin-bottom: 16px; }
.track-form { display: flex; gap: 10px; }
.track-form input {
    flex: 1; border: 2px solid var(--gml); border-radius: 10px;
    padding: 11px 14px; font-family: 'Comfortaa', sans-serif;
    font-size: .95rem; font-weight: 600; color: var(--td);
    background: var(--gl); outline: none;
    transition: border-color .2s, background .2s;
}
.track-form input:focus { border-color: var(--gm); background: #fff; }
.track-form button {
    padding: 11px 22px; background: var(--gd); color: #fff;
    border: none; border-radius: 10px; font-family: 'Comfortaa', sans-serif;
    font-size: .92rem; font-weight: 800; cursor: pointer;
    transition: background .2s; white-space: nowrap;
}
.track-form button:hover { background: var(--gm); }

.track-empty {
    text-align: center; padding: 32px 20px;
    background: var(--wh); border-radius: var(--rad);
    box-shadow: var(--shad); color: var(--tm);
    font-size: .95rem; font-weight: 700;
}
.track-empty .te-icon { font-size: 2.5rem; margin-bottom: 12px; }

.track-card {
    background: var(--wh); border-radius: var(--rad);
    box-shadow: var(--shad); margin-bottom: 16px; overflow: hidden;
}
.track-card-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 2px solid var(--gl);
    flex-wrap: wrap; gap: 8px;
}
.track-code {
    font-size: 1rem; font-weight: 900; color: var(--gd);
    letter-spacing: .05em; font-family: monospace;
}
.track-date { font-size: .78rem; color: var(--tl); font-weight: 600; }

/* Status badge */
.sbadge {
    padding: 4px 12px; border-radius: 20px;
    font-size: .75rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em;
}
.sbadge.menunggu        { background: #fff8e1; color: #e65100; }
.sbadge.dikonfirmasi    { background: #e3f2fd; color: #1565c0; }
.sbadge.dijadwalkan     { background: #f3e5f5; color: #6a1b9a; }
.sbadge.dalam_perjalanan { background: #fef08a; color: #854d0e; }
.sbadge.sedang_diproses { background: #fff3e0; color: #e65100; }
.sbadge.selesai         { background: #e8f5e9; color: var(--gd); }
.sbadge.dibatalkan      { background: #ffebee; color: var(--red); }

.track-card-body { padding: 18px 20px; }

/* Timeline */
.tl-wrap { margin-bottom: 16px; }
.tl-bar {
    display: flex;
    align-items: center;
    margin-bottom: 8px;
}
.tl-node {
    width: 34px; height: 34px; border-radius: 50%;
    background: var(--gml); color: var(--tl);
    font-size: .78rem; font-weight: 900;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; transition: background .3s, color .3s;
    border: 2px solid transparent;
}
.tl-node.done    { background: var(--gm); color: #fff; border-color: var(--gm); }
.tl-node.current { background: var(--gd); color: #fff; border-color: var(--gd); box-shadow: 0 0 0 4px rgba(46,125,50,.18); }
.tl-node.cancel  { background: #ffebee; color: var(--red); border-color: var(--red); }
.tl-conn { flex: 1; height: 3px; background: var(--gml); transition: background .3s; }
.tl-conn.done { background: var(--gm); }
.tl-labels {
    display: flex;
    justify-content: space-between;
}
.tl-lbl {
    font-size: .68rem; font-weight: 700; color: var(--tl);
    text-align: center; width: 76px; line-height: 1.3;
    margin: 0 -11px;
}
.tl-lbl.done    { color: var(--gm); }
.tl-lbl.current { color: var(--gd); }

.track-detail {
    font-size: .83rem; color: var(--tm); font-weight: 600;
    display: flex; flex-wrap: wrap; gap: 6px 20px;
}
.track-detail span strong { color: var(--td); }

.track-schedule {
    margin-top: 10px; padding: 10px 14px;
    background: var(--gl); border-radius: 9px;
    font-size: .83rem; font-weight: 700; color: var(--tm);
}
.track-schedule strong { color: var(--gd); }

/* ── Success Screen ──────────────────────────────────────────── */
.success-wrap { max-width: 620px; margin: 0 auto; padding: 28px 16px 60px; }
.success-card {
    background: var(--wh); border-radius: var(--rad);
    box-shadow: var(--shad); overflow: hidden;
}
.success-head {
    background: linear-gradient(135deg, var(--gd), var(--gm));
    padding: 36px 24px 28px; text-align: center;
}
.success-head .s-icon { font-size: 52px; margin-bottom: 14px; }
.success-head h1 { color: #fff; font-size: 1.75rem; font-weight: 900; margin-bottom: 8px; }
.success-head p  { color: rgba(255,255,255,.88); font-size: .95rem; font-weight: 600; line-height: 1.6; }
.req-code-box {
    display: inline-block; margin: 16px auto 0;
    background: rgba(255,255,255,.2); border: 2px solid rgba(255,255,255,.45);
    border-radius: 9px; padding: 9px 22px;
    font-size: 1.2rem; font-weight: 900; color: #fff;
    letter-spacing: .08em; font-family: monospace;
}
.req-code-note { font-size: .78rem; color: rgba(255,255,255,.72); margin-top: 7px; font-weight: 600; }

.success-body { padding: 22px 22px 28px; }

/* Success timeline */
.s-timeline { margin-bottom: 20px; }
.s-tl-bar { display: flex; align-items: center; margin-bottom: 7px; }
.s-tl-node {
    width: 30px; height: 30px; border-radius: 50%;
    font-size: .72rem; font-weight: 900;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.s-tl-node.done    { background: var(--gm); color: #fff; }
.s-tl-node.pending { background: var(--gml); color: var(--tl); }
.s-tl-node.current { background: var(--gd); color: #fff; box-shadow: 0 0 0 4px rgba(46,125,50,.18); }
.s-tl-conn { flex: 1; height: 3px; background: var(--gml); }
.s-tl-conn.done { background: var(--gm); }
.s-tl-labels { display: flex; justify-content: space-between; }
.s-tl-lbl {
    font-size: .68rem; font-weight: 700; color: var(--tl);
    text-align: center; width: 76px; line-height: 1.3;
    margin: 0 -11px;
}
.s-tl-lbl.current { color: var(--gd); }

.detail-recap {
    background: var(--gl); border: 1.5px solid var(--gml);
    border-radius: 11px; padding: 12px 15px; margin-bottom: 18px;
}
.dr-row {
    display: flex; justify-content: space-between; align-items: flex-start;
    padding: 4px 0; border-bottom: 1px solid var(--gml); font-size: .82rem; gap: 8px;
}
.dr-row:last-child { border-bottom: none; }
.dr-lbl { color: var(--tl); font-weight: 700; flex-shrink: 0; }
.dr-val { color: var(--td); font-weight: 800; text-align: right; word-break: break-word; }

.success-actions { display: flex; flex-direction: column; gap: 10px; }
.btn-track {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    padding: 14px 20px; background: var(--gd); color: #fff;
    border-radius: 11px; text-decoration: none;
    font-size: .92rem; font-weight: 800; transition: background .2s;
}
.btn-track:hover { background: var(--gm); }
.btn-new {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    padding: 12px 20px; background: transparent;
    color: var(--gm); border: 2px solid var(--gml);
    border-radius: 11px; text-decoration: none;
    font-size: .88rem; font-weight: 800; transition: background .2s, border-color .2s;
}
.btn-new:hover { background: var(--gl); border-color: var(--gm); }

.step-3-layout {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.step-3-left, .step-3-right {
    width: 100%;
}

/* ── ─── ─── DESKTOP LANDSCAPE (≥900px) ─── ─── ── */
@media (min-width: 900px) {

    body { padding: 0; }

    .page-wrapper {
        max-width: 1020px;
        display: grid;
        grid-template-columns: 260px 1fr;
        gap: 0;
        padding: 32px 24px 60px;
        align-items: start;
    }

    /* Sidebar stepper visible on desktop */
    .sidebar-stepper {
        display: flex;
        flex-direction: column;
        background: var(--wh);
        border-radius: var(--rad) 0 0 var(--rad);
        box-shadow: var(--shad);
        padding: 28px 20px;
        position: sticky;
        top: 80px;
        min-height: 420px;
    }

    .sidebar-stepper .ss-title {
        font-size: .72rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: var(--tl);
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 1.5px solid var(--gml);
    }

    .ss-item {
        display: flex;
        align-items: flex-start;
        gap: 13px;
        padding: 10px 0;
        position: relative;
    }
    .ss-item:not(:last-child)::after {
        content: '';
        position: absolute;
        left: 17px;
        top: 42px;
        width: 2px;
        height: calc(100% - 12px);
        background: var(--gml);
        transition: background .3s;
    }
    .ss-item.done:not(:last-child)::after { background: var(--gm); }

    .ss-num {
        width: 36px; height: 36px; border-radius: 50%;
        background: var(--gml); color: var(--tl);
        font-size: .82rem; font-weight: 900;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0; transition: background .3s, color .3s, transform .25s var(--spring-transit);
        position: relative; z-index: 1;
    }
    .ss-item.done   .ss-num { background: var(--gm); color: #fff; }
    .ss-item.active .ss-num { background: var(--gd); color: #fff; box-shadow: 0 0 0 4px rgba(46,125,50,.18); transform: scale(1.08); }

    .ss-info { flex: 1; padding-top: 6px; }
    .ss-label {
        font-size: .88rem; font-weight: 800; color: var(--tl);
        transition: color .3s; line-height: 1.2; margin-bottom: 2px;
    }
    .ss-item.active .ss-label { color: var(--gd); }
    .ss-item.done   .ss-label { color: var(--gm); }
    .ss-desc { font-size: .72rem; font-weight: 600; color: var(--tl); line-height: 1.3; }

    /* Hide mobile stepper on desktop */
    .top-stepper { display: none; }

    /* Card takes full right column */
    .form-main { width: 100%; }
    .form-main .card {
        border-radius: 0 var(--rad) var(--rad) 0;
        box-shadow: none;
        border: none;
        border-left: none;
    }
    /* Outer box-shadow comes from a wrapper */
    .form-main-inner {
        box-shadow: var(--shad);
        border-radius: 0 var(--rad) var(--rad) 0;
        overflow: hidden;
    }
    /* Desktop card footer: only right-side bottom radius */
    .card-footer {
        border-radius: 0 0 var(--rad) 0;
    }

    /* Wider barang grid on desktop */
    .barang-grid { grid-template-columns: repeat(5, 1fr); }

    /* Wider track/success wrappers */
    .track-wrap  { max-width: 860px; }
    .success-wrap { max-width: 1020px; }
    .success-card {
        display: flex;
        flex-direction: row;
        align-items: stretch;
    }
    .success-head {
        flex: 0.8;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        padding: 40px;
    }
    .success-body {
        flex: 1.6;
        padding: 40px;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
    .step-3-layout {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
        align-items: start;
    }
}

/* ── Mobile ──────────────────────────────────────────────────── */
@media (max-width: 420px) {
    .barang-grid { grid-template-columns: repeat(3, 1fr); }
    .frow { flex-direction: column; gap: 14px; }
    .latlng-row { flex-direction: column; gap: 12px; }
    .track-form { flex-direction: column; }
}

@media (max-width: 360px) {
    .barang-grid { grid-template-columns: repeat(2, 1fr); }
    .tl-lbl, .s-tl-lbl, .stp-lbl { font-size: .55rem; width: 64px; margin: 0 -8px; }
}
/* ===== FOOTER ===== */
.site-footer {
    padding: 24px 0 32px;
    background: var(--wh);
    margin-top: auto;
    width: 100%;
}
.footer-text {
    font-family: 'Comfortaa', sans-serif;
    font-size: 8pt;
    font-weight: 700;
    text-align: center;
    color: var(--td);
    margin-bottom: 4px;
}
.footer-container {
    max-width: 1280px;
    margin: 0 auto;
    padding: 0 48px;
}
/* ── Centered Flash Notification Overlay Style ── */
.flash-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    animation: alert-fade-in 0.25s ease-out;
}
.flash {
    background: #ffffff !important;
    border-radius: 16px !important;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04) !important;
    padding: 24px 32px !important;
    max-width: 400px;
    width: 90%;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 16px;
    border: none !important;
    animation: alert-scale-in 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
    font-family: 'Comfortaa', sans-serif;
}
.flash-icon {
    font-size: 48px;
    line-height: 1;
}
.flash-msg {
    font-size: 14px;
    font-weight: 700;
    color: #1b2b1c;
    line-height: 1.5;
}
.flash-close-btn {
    background: var(--gd);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 8px 24px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    width: 100%;
    transition: background 0.15s;
    font-family: 'Comfortaa', sans-serif;
}
.flash-close-btn:hover {
    background: var(--gm);
}

@keyframes alert-scale-in {
    from { transform: scale(0.9) translateY(10px); }
    to { transform: scale(1) translateY(0); }
}

@keyframes alert-fade-in {
    from { opacity: 0; }
    to { opacity: 1; }
}
@keyframes map-pulse {
    0% { transform: scale(0.95); opacity: 0.9; }
    50% { transform: scale(1.1); opacity: 1; }
    100% { transform: scale(0.95); opacity: 0.9; }
}
.pulse-marker-icon {
    animation: map-pulse 1.8s infinite ease-in-out;
}
</style>
</head>
<body>

<!-- ── Top Navbar ────────────────────────────────────────────── -->
<nav class="top-nav">
    <a href="home.php" class="back-btn">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        Beranda
    </a>
    <span class="site-name" style="display: inline-flex; align-items: center; gap: 8px;">
        <img src="Home.png" alt="Logo" style="width: 24px; height: 24px; object-fit: cover; border-radius: 4px;" onerror="this.style.display='none'">
        Manado Recycle Hub
    </span>
</nav>

<?php if ($submitted): ?>
<!-- ══════════════════════════════════════════════════════════
     SUCCESS SCREEN
══════════════════════════════════════════════════════════ -->
<div class="success-wrap">
    <div class="success-card">
        <div class="success-head">
            <div class="s-icon">♻️</div>
            <h1>Terima Kasih!</h1>
            <p>Permintaan Anda sudah kami terima.<br>Tim kami akan segera menghubungi Anda.</p>
            <div class="req-code-box"><?= htmlspecialchars($request_code) ?></div>
            <div class="req-code-note">Nomor Request — simpan sebagai referensi cek status</div>
        </div>
        <div class="success-body">
            <!-- Status timeline showing step 1 active -->
            <div class="s-timeline">
                <div class="s-tl-bar">
                    <?php foreach ($track_step_defs as $i => $ts): ?>
                        <div class="s-tl-node <?= $i === 0 ? 'current' : 'pending' ?>"><?= $i === 0 ? '●' : ($i + 1) ?></div>
                        <?php if ($i < count($track_step_defs) - 1): ?>
                        <div class="s-tl-conn"></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="s-tl-labels">
                    <?php foreach ($track_step_defs as $i => $ts): ?>
                    <div class="s-tl-lbl <?= $i === 0 ? 'current' : '' ?>"><?= htmlspecialchars($ts['label']) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="detail-recap">
                <div class="dr-row"><span class="dr-lbl">Nama</span><span class="dr-val"><?= htmlspecialchars($sub_data['nama'] ?? '') ?></span></div>
                <div class="dr-row"><span class="dr-lbl">WhatsApp</span><span class="dr-val"><?= htmlspecialchars($sub_data['wa'] ?? '') ?></span></div>
                <div class="dr-row"><span class="dr-lbl">Kecamatan</span><span class="dr-val"><?= htmlspecialchars($sub_data['kec'] ?? '') ?></span></div>
                <div class="dr-row"><span class="dr-lbl">Sampah</span><span class="dr-val"><?= htmlspecialchars($sub_data['barang'] ?? '') ?></span></div>
                <div class="dr-row"><span class="dr-lbl">Perkiraan Berat</span><span class="dr-val"><?= htmlspecialchars($sub_data['berat'] ?? '') ?> kg</span></div>
            </div>

            <div class="success-actions">
                <a href="?tab=track&q=<?= urlencode($request_code) ?>" class="btn-track">
                    🔍 Cek Status Permintaan
                </a>
                <a href="?tab=form" class="btn-new">
                    ♻️ Buat Request Baru
                </a>
            </div>
        </div>
    </div>
</div>

<?php else: ?>

<!-- ── Tab Switcher ──────────────────────────────────────────── -->
<div class="tabs">
    <a href="?tab=form" class="tab-btn <?= $active_tab === 'form' ? 'active' : '' ?>">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
        Buat Request
    </a>
    <a href="?tab=track" class="tab-btn <?= $active_tab === 'track' ? 'active' : '' ?>">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        Cek Status
    </a>
</div>

<?php if ($active_tab === 'track'): ?>
<!-- ══════════════════════════════════════════════════════════
     TRACKING TAB
══════════════════════════════════════════════════════════ -->
<div class="track-wrap">
    <div class="track-search">
        <h2>🔍 Cek Status Permintaan</h2>
        <p>Masukkan nomor request (contoh: MRH-S-001) atau nomor WhatsApp Anda.</p>
        <form method="GET" action="" class="track-form">
            <input type="hidden" name="tab" value="track">
            <input type="text" name="q"
                   value="<?= htmlspecialchars($track_query) ?>"
                   placeholder="Nomor request atau nomor WA..."
                   autocomplete="off" autofocus>
            <button type="submit">Cari</button>
        </form>
    </div>

    <?php if ($track_query !== ''): ?>
        <?php if (empty($track_res)): ?>
        <div class="track-empty">
            <div class="te-icon">🔎</div>
            <div>Tidak ada permintaan ditemukan untuk</div>
            <div style="color:var(--gd);font-weight:900;margin-top:6px">"<?= htmlspecialchars($track_query) ?>"</div>
            <div style="margin-top:10px;font-size:.82rem;color:var(--tl)">Pastikan nomor request atau nomor WA sudah benar.</div>
        </div>
        <?php else: ?>
            <?php foreach ($track_res as $row): ?>
            <?php
                $cur_step  = $status_step_map[$row['status']] ?? 0;
                $cancelled = $row['status'] === 'dibatalkan';
            ?>
            <div class="track-card">
                <div class="track-card-head">
                    <div>
                        <div class="track-code"><?= htmlspecialchars($row['request_code']) ?></div>
                        <div class="track-date">Dikirim: <?= date('d M Y H:i', strtotime($row['created_at'])) ?></div>
                    </div>
                    <span class="sbadge <?= htmlspecialchars($row['status']) ?>">
                        <?= htmlspecialchars($status_label_map[$row['status']] ?? $row['status']) ?>
                    </span>
                </div>
                <div class="track-card-body">
                    <?php if (!$cancelled): ?>
                    <!-- Timeline -->
                    <div class="tl-wrap">
                        <div class="tl-bar">
                            <?php foreach ($track_step_defs as $i => $ts): ?>
                            <?php
                                $n    = $i + 1;
                                $done = $cur_step > $n;
                                $cur  = $cur_step === $n;
                            ?>
                            <div class="tl-node <?= $done ? 'done' : ($cur ? 'current' : '') ?>">
                                <?= $done ? '✓' : ($cur ? '●' : $n) ?>
                            </div>
                            <?php if ($i < count($track_step_defs) - 1): ?>
                            <div class="tl-conn <?= $done ? 'done' : '' ?>"></div>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="tl-labels">
                            <?php foreach ($track_step_defs as $i => $ts): ?>
                            <?php $n = $i + 1; $done = $cur_step > $n; $cur = $cur_step === $n; ?>
                            <div class="tl-lbl <?= $done ? 'done' : ($cur ? 'current' : '') ?>"><?= htmlspecialchars($ts['label']) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div style="background:#ffebee;border-radius:9px;padding:10px 14px;font-size:.83rem;font-weight:700;color:var(--red);margin-bottom:14px">
                        ✕ Permintaan ini telah dibatalkan.
                    </div>
                    <?php endif; ?>

                    <div class="track-detail">
                        <span><strong><?= htmlspecialchars($row['nama_pemohon']) ?></strong></span>
                        <?php if ($row['kecamatan']): ?><span>📍 <?= htmlspecialchars($kec_opts[$row['kecamatan']] ?? $row['kecamatan']) ?></span><?php endif; ?>
                        <?php if ($row['sampah_list']): ?><span>♻️ <?= htmlspecialchars($row['sampah_list']) ?></span><?php endif; ?>
                        <?php if ($row['berat_total_kg'] || $row['berat_kg']): ?>
                        <span>⚖️ ~<?= htmlspecialchars($row['berat_total_kg'] ?? $row['berat_kg']) ?> kg</span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($row['catatan'])): ?>
                    <div style="margin-top:10px;font-size:.82rem;color:#666;background:#fefefe;border:1px solid #e8e8e8;border-left:3.5px solid #2e7d32;padding:8px 12px;border-radius:6px">
                        <strong>💬 Catatan Anda:</strong><br>
                        <div style="margin-top:4px"><?= nl2br(htmlspecialchars($row['catatan'])) ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($row['catatan_officer']) && in_array($row['status'], ['dalam_perjalanan', 'sedang_diproses', 'selesai'])): ?>
                    <div style="margin-top:8px;font-size:.82rem;color:#666;background:#fffde7;border:1px solid #fff59d;border-left:3.5px solid #fbc02d;padding:8px 12px;border-radius:6px">
                        <strong>👷 Catatan Petugas/Tim:</strong><br>
                        <div style="margin-top:4px"><?= nl2br(htmlspecialchars($row['catatan_officer'])) ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($row['tanggal_jemput'] && in_array($row['status'], ['dijadwalkan','dalam_perjalanan','sedang_diproses','selesai'])): ?>
                    <div class="track-schedule">
                        📅 Dijadwalkan: <strong><?= date('d M Y', strtotime($row['tanggal_jemput'])) ?></strong>
                    </div>
                    <?php endif; ?>

                    <?php if (in_array($row['status'], ['dalam_perjalanan', 'sedang_diproses'])): ?>
                    <div class="live-map-wrap" style="margin-top: 14px;">
                        <div style="font-size: 0.8rem; font-weight: 700; color: #1c6434; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                            <span class="pulse-marker-icon" style="width: 8px; height: 8px; background: #22c55e; border-radius: 50%; display: inline-block;"></span>
                            Pelacakan Posisi Petugas (Real-time Live GPS)
                        </div>
                        <div id="liveMap-<?= $row['id'] ?>" class="live-tracking-map" data-request-id="<?= $row['id'] ?>" data-request-type="daur_ulang" data-customer-lat="<?= floatval($row['latitude']) ?>" data-customer-lng="<?= floatval($row['longitude']) ?>" style="height: 250px; border-radius: 12px; border: 2px solid var(--gml); position: relative; z-index: 1;"></div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════════
     FORM TAB — 5-Step Wizard
══════════════════════════════════════════════════════════ -->

<?php if (!empty($errors) && in_array('database_error', $errors)): ?>
<div class="flash-overlay" id="flashOverlay">
    <div class="flash flash-danger">
        <div class="flash-icon">⚠️</div>
        <div class="flash-msg">
            Gagal menyimpan data. Silakan coba lagi.
            <?php if ($db_error_msg): ?><div style="font-size: 11px; color: #dc2626; margin-top: 6px; font-family: monospace; background: #fef2f2; padding: 6px; border-radius: 4px;"><?= htmlspecialchars($db_error_msg) ?></div><?php endif; ?>
        </div>
        <button type="button" class="flash-close-btn" onclick="document.getElementById('flashOverlay').style.display='none'">Tutup</button>
    </div>
</div>
<?php endif; ?>

<div class="page-wrapper">

    <!-- ── Sidebar Stepper (desktop) ──────────────────────────── -->
    <aside class="sidebar-stepper" id="sidebarStepper">
        <div class="ss-title">Langkah Pengisian</div>
        <?php
        $ss_steps = [
            ['icon'=>'📦','label'=>'Jenis Sampah',  'desc'=>'Pilih jenis sampah'],
            ['icon'=>'👤','label'=>'Identitas',      'desc'=>'Nama & kontak'],
            ['icon'=>'📍','label'=>'Lokasi',         'desc'=>'Alamat penjemputan'],
            ['icon'=>'⚖️','label'=>'Berat',          'desc'=>'Perkiraan berat & catatan'],
            ['icon'=>'✅','label'=>'Konfirmasi',     'desc'=>'Tinjau & kirim'],
        ];
        foreach ($ss_steps as $i => $ss): ?>
        <div class="ss-item" id="ss-item-<?= $i+1 ?>">
            <div class="ss-num" id="ss-num-<?= $i+1 ?>"><?= $i+1 ?></div>
            <div class="ss-info">
                <div class="ss-label"><?= htmlspecialchars($ss['label']) ?></div>
                <div class="ss-desc"><?= htmlspecialchars($ss['desc']) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </aside>

    <!-- ── Form Main ──────────────────────────────────────────── -->
    <main class="form-main">
        <!-- Top stepper (mobile only) -->
        <div class="top-stepper" id="topStepper">
            <div class="step-counter"><span id="stepCounterText">Langkah 1 dari 5</span></div>
            <div class="stepper-bar">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <div class="stp-node <?= $i === 1 ? 'active' : '' ?>" id="stp-node-<?= $i ?>"><?= $i ?></div>
                <?php if ($i < 5): ?><div class="stp-line" id="stp-line-<?= $i ?>"></div><?php endif; ?>
                <?php endfor; ?>
            </div>
            <div class="stepper-labels">
                <?php
                $step_lbls = ['Sampah','Identitas','Lokasi','Berat','Konfirmasi'];
                foreach ($step_lbls as $i => $lbl): ?>
                <div class="stp-lbl <?= $i === 0 ? 'active' : '' ?>" id="stp-lbl-<?= $i+1 ?>"><?= $lbl ?></div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Card -->
        <div class="form-main-inner">
        <div class="card">
        <form id="mainForm" method="POST" action="" novalidate>
            <input type="hidden" name="_action" value="submit_form">

            <!-- ══════════════════════════════
                 STEP 1 — JENIS SAMPAH
            ══════════════════════════════ -->
            <div class="form-step <?= $init_step === 1 ? 'active' : '' ?>" id="step-1">
                <div class="step-title">📦 Jenis Sampah</div>
                <div class="step-sub">Pilih satu atau lebih jenis sampah yang akan dijemput.</div>

                <?php if (in_array('barang', $errors)): ?>
                <span class="errmsg" style="display:block;margin-bottom:12px">Pilih minimal 1 jenis sampah.</span>
                <?php endif; ?>

                <div class="barang-grid">
                    <?php foreach ($barang_opts as $key => $opt): ?>
                    <div class="barang-item">
                        <input type="checkbox"
                               name="barang[]"
                               id="b_<?= htmlspecialchars($key) ?>"
                               value="<?= htmlspecialchars($key) ?>"
                               <?= in_array($key, $v['barang']) ? 'checked' : '' ?>>
                        <label class="barang-label" for="b_<?= htmlspecialchars($key) ?>">
                            <span class="bico"><?= htmlspecialchars($opt['icon']) ?></span>
                            <?= htmlspecialchars($opt['label']) ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="height:20px"></div>
            </div>

            <!-- ══════════════════════════════
                 STEP 2 — IDENTITAS
            ══════════════════════════════ -->
            <div class="form-step <?= $init_step === 2 ? 'active' : '' ?>" id="step-2">
                <div class="step-title">👤 Identitas</div>
                <div class="step-sub">Data diri untuk keperluan penjemputan dan konfirmasi.</div>

                <div class="frow" style="margin-top:0">
                    <div class="fw">
                        <label for="nama">Nama Lengkap *</label>
                        <input type="text" id="nama" name="nama"
                               value="<?= htmlspecialchars($v['nama']) ?>"
                               placeholder="Nama lengkap Anda"
                               class="<?= in_array('nama',$errors)?'err':'' ?>"
                               autocomplete="name">
                        <?php if (in_array('nama',$errors)): ?><span class="errmsg">Nama wajib diisi.</span><?php endif; ?>
                    </div>
                    <div class="fw">
                        <label>Nomor WhatsApp *</label>
                        <div class="wa-row">
                            <input type="text" name="area_code"
                                   value="<?= htmlspecialchars($v['ac']) ?>"
                                   placeholder="+62" autocomplete="tel-country-code">
                            <input type="tel" id="nomor_wa" name="nomor_wa"
                                   value="<?= htmlspecialchars($v['wa']) ?>"
                                   placeholder="81234567890"
                                   class="<?= in_array('nomor_wa',$errors)?'err':'' ?>"
                                   autocomplete="tel-national">
                        </div>
                        <?php if (in_array('nomor_wa',$errors)): ?><span class="errmsg">Nomor WA wajib diisi.</span><?php endif; ?>
                    </div>
                </div>

                <!-- Advanced fields (partner/admin data) -->
                <div class="adv-toggle" id="advToggle" onclick="toggleAdv()">
                    <span>⚙️</span>
                    <span style="flex:1">Data Tambahan (Mitra / Instansi)</span>
                    <em class="adv-arrow">▾</em>
                </div>
                <div class="adv-panel" id="advPanel">
                    <div class="frow">
                        <div class="fw">
                            <label for="place_name">Nama Tempat</label>
                            <input type="text" id="place_name" name="place_name"
                                   value="<?= htmlspecialchars($v['pname']) ?>"
                                   placeholder="Contoh: Sekolah, Kantor, Restoran">
                        </div>
                        <div class="fw">
                            <label for="place_type">Tipe Tempat</label>
                            <div class="sel-wrap">
                                <select id="place_type" name="place_type">
                                    <option value="">— Pilih —</option>
                                    <option value="Mitra"        <?= $v['ptype']==='Mitra'?'selected':'' ?>>Mitra</option>
                                    <option value="Sekolah"      <?= $v['ptype']==='Sekolah'?'selected':'' ?>>Sekolah</option>
                                    <option value="Rumah Makan"  <?= $v['ptype']==='Rumah Makan'?'selected':'' ?>>Rumah Makan (F&B)</option>
                                    <option value="Rumah Tangga" <?= $v['ptype']==='Rumah Tangga'?'selected':'' ?>>Rumah Tangga</option>
                                    <option value="Umum"         <?= $v['ptype']==='Umum'?'selected':'' ?>>Umum (Public)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="frow">
                        <div class="fw">
                            <label for="partner_name">Nama Mitra / PIC</label>
                            <input type="text" id="partner_name" name="partner_name"
                                   value="<?= htmlspecialchars($v['partner']) ?>"
                                   placeholder="Contoh: Budi, PT ABC">
                        </div>
                        <div class="fw">
                            <label for="pickup_type">Tipe Pickup (Wadah/Kemasan)</label>
                            <div class="sel-wrap">
                                <select id="pickup_type" name="pickup_type">
                                    <option value="">— Pilih —</option>
                                    <option value="B" <?= $v['pkt']==='B'?'selected':'' ?>>Keranjang (Baskets)</option>
                                    <option value="S" <?= $v['pkt']==='S'?'selected':'' ?>>Karung (Sacks)</option>
                                    <option value="Lainnya" <?= $v['pkt']==='Lainnya'?'selected':'' ?>>Lainnya</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="frow">
                        <div class="fw">
                            <label>Jenis Layanan & Harga</label>
                            <div style="font-size:.82rem;color:var(--tl);font-weight:600;background:var(--gl);padding:10px 14px;border-radius:10px;border:1.5px dashed var(--gml)">
                                ℹ️ Jenis layanan (Gratis/Berbayar) dan harga per kg akan ditentukan oleh petugas/admin setelah verifikasi.
                            </div>
                        </div>
                    </div>
                </div>
                <div style="height:20px"></div>
            </div>

            <!-- ══════════════════════════════
                 STEP 3 — LOKASI
            ══════════════════════════════ -->
            <div class="form-step <?= $init_step === 3 ? 'active' : '' ?>" id="step-3">
                <div class="step-title">📍 Lokasi Penjemputan</div>
                <div class="step-sub">Alamat lengkap tempat sampah akan dijemput.</div>

                <div class="step-3-layout">
                    <div class="step-3-left">
                        <div class="frow" style="margin-top:0">
                            <div class="fw">
                                <label for="kecamatan">Kecamatan *</label>
                                <div class="sel-wrap">
                                    <select id="kecamatan" name="kecamatan"
                                            class="<?= in_array('kecamatan',$errors)?'err':'' ?>">
                                        <option value="" <?= $v['kec']===''?'selected':'' ?> disabled>— Pilih —</option>
                                        <?php foreach ($kec_opts as $kd => $kl): ?>
                                        <option value="<?= htmlspecialchars($kd) ?>" <?= $v['kec']===$kd?'selected':'' ?>>
                                            <?= htmlspecialchars($kl) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div id="kecInfo" style="margin-top:6px; font-size:12px; font-weight:600; display:none;"></div>
                                <?php if (in_array('kecamatan',$errors)): ?><span class="errmsg">Kecamatan wajib dipilih.</span><?php endif; ?>
                            </div>
                            <div class="fw">
                                <label for="kelurahan">Kelurahan *</label>
                                <input type="text" id="kelurahan" name="kelurahan"
                                       value="<?= htmlspecialchars($v['kel']) ?>"
                                       placeholder="Nama kelurahan"
                                       class="<?= in_array('kelurahan',$errors)?'err':'' ?>"
                                       autocomplete="address-level3">
                                <?php if (in_array('kelurahan',$errors)): ?><span class="errmsg">Kelurahan wajib diisi.</span><?php endif; ?>
                            </div>
                        </div>

                        <div class="fw">
                            <label for="alamat_jemput">Nama Jalan / Alamat Lengkap *</label>
                            <textarea id="alamat_jemput" name="alamat_jemput"
                                      rows="2" placeholder="Contoh: Jl. Piere Tendean No. 12, depan toko biru"
                                      class="<?= in_array('alamat_jemput',$errors)?'err':'' ?>"><?= htmlspecialchars($v['al']) ?></textarea>
                            <?php if (in_array('alamat_jemput',$errors)): ?><span class="errmsg">Alamat wajib diisi.</span><?php endif; ?>
                        </div>
                    </div>
                    <div class="step-3-right">
                        <!-- GPS Map Picker -->
                        <div class="fw" style="margin-top:0">
                            <label>📍 Titik Lokasi di Peta <span style="font-size:.72rem;font-weight:600;color:var(--tl);text-transform:none;letter-spacing:0">(opsional)</span></label>

                            <button type="button" class="btn-gps" id="btnGPS">
                                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><path d="M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M4.93 19.07l2.12-2.12M16.95 7.05l2.12-2.12"/></svg>
                                <span id="gpsText">Deteksi Lokasi Saya</span>
                            </button>

                            <div class="gps-status" id="gpsStatus">
                                <span id="gpsIco"></span>
                                <span id="gpsTxt"></span>
                            </div>

                            <div class="search-map-container" style="position: relative; margin-bottom: 12px;">
                                <input type="text" id="mapSearchInput" class="form-input" placeholder="🔍 Cari lokasi (contoh: Unsrat, Indomaret...)" style="padding-right: 36px; font-family: inherit; font-size: 0.95rem; font-weight: 600; border-radius: 10px; border: 2px solid var(--gml); width: 100%;">
                                <button type="button" id="clearSearchBtn" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); border: none; background: transparent; cursor: pointer; display: none; font-size: 14px; color: var(--tl); z-index: 10;">✖</button>
                                <div id="mapSearchResults" class="search-results-dropdown" style="display: none; position: absolute; left: 0; right: 0; top: 100%; background: #ffffff; border: 2px solid var(--gml); border-top: none; border-radius: 0 0 10px 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 1000; max-height: 200px; overflow-y: auto;"></div>
                            </div>
                            <div id="pickerMap"></div>
                            <div class="map-hint">Klik peta atau geser pin untuk menentukan titik jemput yang tepat</div>

                            <div class="kd-box" id="kdBox">
                                <div class="kd-row"><span class="kd-lbl">Latitude</span><span class="kd-val" id="kdLat">—</span></div>
                                <div class="kd-row"><span class="kd-lbl">Longitude</span><span class="kd-val" id="kdLng">—</span></div>
                                <div class="kd-row"><span class="kd-lbl">Akurasi</span><span class="kd-val" id="kdAcc">—</span></div>
                                <a class="kd-link" id="kdMapLink" href="#" target="_blank" rel="noopener">📍 Buka di Google Maps</a>
                            </div>

                            <div class="latlng-row" style="margin-top:10px">
                                <div class="fw gps-inp"><label>Latitude</label><input type="text" id="latitude" name="latitude" value="<?= htmlspecialchars($v['lat']) ?>" placeholder="—" readonly></div>
                                <div class="fw gps-inp"><label>Longitude</label><input type="text" id="longitude" name="longitude" value="<?= htmlspecialchars($v['lng']) ?>" placeholder="—" readonly></div>
                            </div>
                            <input type="hidden" id="koordinatManual" name="koordinat_manual" value="<?= htmlspecialchars($v['km']) ?>">

                            <?php if (in_array('latitude',$errors)||in_array('longitude',$errors)): ?>
                            <span class="errmsg">Format koordinat tidak valid. Gunakan pin peta.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div style="height:20px"></div>
            </div>

            <!-- ══════════════════════════════
                 STEP 4 — JADWAL & BERAT
            ══════════════════════════════ -->
            <div class="form-step <?= $init_step === 4 ? 'active' : '' ?>" id="step-4">
                <div class="step-title">⚖️ Berat &amp; Catatan</div>
                <div class="step-sub">Tentukan perkiraan berat sampah dan catatan tambahan.</div>

                <div class="fw">
                    <label for="berat_kg">Perkiraan Berat Sampah *</label>
                    <div class="sfx-wrap">
                        <input type="text" id="berat_kg" name="berat_kg"
                               value="<?= htmlspecialchars($v['berat']) ?>"
                               placeholder="Contoh: 5 atau 2.5"
                               class="<?= in_array('berat_kg',$errors)?'err':'' ?>"
                               inputmode="decimal" autocomplete="off">
                        <span class="sfx">KG</span>
                    </div>
                    <?php if (in_array('berat_kg',$errors)): ?><span class="errmsg">Perkiraan berat wajib diisi.</span><?php endif; ?>
                </div>

                <div class="fw">
                    <label for="catatan">Catatan untuk Tim <span style="font-size:.72rem;font-weight:600;text-transform:none;letter-spacing:0;color:var(--tl)">(opsional)</span></label>
                    <textarea id="catatan" name="catatan" rows="3"
                              placeholder="Misal: akses jalan, tangga, waktu yang bisa dihubungi, dll."><?= htmlspecialchars($v['cat']) ?></textarea>
                </div>
                <div style="height:20px"></div>
            </div>

            <!-- ══════════════════════════════
                 STEP 5 — KONFIRMASI
            ══════════════════════════════ -->
            <div class="form-step" id="step-5">
                <div class="step-title">✅ Konfirmasi</div>
                <div class="step-sub">Tinjau kembali data Anda sebelum mengirimkan permintaan.</div>

                <div class="review-box" id="reviewBox">
                    <div class="rb-title">Ringkasan Permintaan</div>
                    <div id="reviewRows"><!-- populated by JS --></div>
                </div>

                <p style="font-size:.80rem;color:var(--tl);font-weight:600;margin-bottom:16px;line-height:1.5">
                    Dengan mengirimkan formulir ini, Anda menyetujui bahwa data yang diberikan adalah benar dan bersedia dihubungi oleh tim Manado Recycle Hub.
                </p>
                <div style="height:8px"></div>
            </div>

        </form><!-- end #mainForm -->
        </div><!-- end .card -->

        <!-- Card Footer Navigation -->
        <div class="card-footer" id="cardFooter">
            <button type="button" class="btn-nav btn-prev" id="btnPrev" onclick="goStep(-1)">
                <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                Sebelumnya
            </button>
            <button type="button" class="btn-nav btn-next" id="btnNext" onclick="goStep(1)">
                Selanjutnya
                <svg viewBox="0 0 24 24"><polyline points="9 6 15 12 9 18"/></svg>
            </button>
            <button type="submit" form="mainForm" class="btn-nav btn-submit" id="btnSubmit">
                Kirim Permintaan
                <svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
            </button>
        </div>

        </div><!-- end .form-main-inner -->
    </main>
</div><!-- end .page-wrapper -->

<?php endif; // end track/form tab ?>
<?php endif; // end submitted/not ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    window.initLeafletMapFallback();
});
</script>
<script>
'use strict';

// ── Step Navigation ───────────────────────────────────────────
var currentStep = <?= $init_step ?>;
var totalSteps  = 5;

var stepLabels = ['Sampah','Identitas','Lokasi','Berat','Konfirmasi'];

function goStep(dir) {
    if (dir === 1 && !validateStep(currentStep)) return;
    var next = currentStep + dir;
    if (next < 1 || next > totalSteps) return;
    if (next === 5) buildReview();
    document.getElementById('step-' + currentStep).classList.remove('active');
    currentStep = next;
    document.getElementById('step-' + currentStep).classList.add('active');
    updateStepperUI();
    updateButtons();
    // Invalidate Leaflet Map when step 3 becomes visible
    if (currentStep === 3 && window._leaflet_map_instance) {
        setTimeout(function() {
            window._leaflet_map_instance.invalidateSize();
            var inpLat  = document.getElementById('latitude');
            var inpLng  = document.getElementById('longitude');
            if (inpLat && inpLng && inpLat.value !== '' && inpLng.value !== '') {
                var center = [parseFloat(inpLat.value), parseFloat(inpLng.value)];
                window._leaflet_map_instance.setView(center, 16);
            }
        }, 100);
    }
    // Scroll to top of card
    var pg = document.querySelector('.page-wrapper') || document.querySelector('.track-wrap');
    if (pg) pg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    window.scrollTo({ top: Math.max(0, (document.querySelector('.top-nav') || {offsetTop:0}).offsetTop - 16), behavior: 'smooth' });
}

function updateButtons() {
    var prev   = document.getElementById('btnPrev');
    var next   = document.getElementById('btnNext');
    var submit = document.getElementById('btnSubmit');
    if (!prev) return;
    prev.style.display   = currentStep > 1  ? 'inline-flex' : 'none';
    next.style.display   = currentStep < totalSteps ? 'inline-flex' : 'none';
    submit.style.display = currentStep === totalSteps ? 'inline-flex' : 'none';
}

function updateStepperUI() {
    // Mobile top stepper
    for (var i = 1; i <= totalSteps; i++) {
        var node = document.getElementById('stp-node-' + i);
        var lbl  = document.getElementById('stp-lbl-' + i);
        if (!node) continue;
        node.classList.toggle('done',   i < currentStep);
        node.classList.toggle('active', i === currentStep);
        node.textContent = i < currentStep ? '✓' : i;
        if (lbl) {
            lbl.classList.toggle('done',   i < currentStep);
            lbl.classList.toggle('active', i === currentStep);
        }
        if (i < totalSteps) {
            var line = document.getElementById('stp-line-' + i);
            if (line) line.classList.toggle('done', i < currentStep);
        }
    }
    var counter = document.getElementById('stepCounterText');
    if (counter) counter.textContent = 'Langkah ' + currentStep + ' dari ' + totalSteps;

    // Desktop sidebar stepper
    for (var j = 1; j <= totalSteps; j++) {
        var item = document.getElementById('ss-item-' + j);
        var num  = document.getElementById('ss-num-' + j);
        if (!item) continue;
        item.classList.toggle('done',   j < currentStep);
        item.classList.toggle('active', j === currentStep);
        if (num) num.textContent = j < currentStep ? '✓' : j;
    }
}

// ── Validation ────────────────────────────────────────────────
function validateStep(step) {
    var ok = true;
    if (step === 1) {
        var checked = document.querySelectorAll('input[name="barang[]"]:checked');
        if (checked.length === 0) {
            showInlineErr('step-1', 'Pilih minimal 1 jenis sampah.');
            ok = false;
        }
    }
    if (step === 2) {
        var nama = document.getElementById('nama');
        var wa   = document.getElementById('nomor_wa');
        if (!nama.value.trim()) { markErr(nama, 'Nama wajib diisi.'); ok = false; }
        else                    { clearErr(nama); }
        if (!wa.value.trim())   { markErr(wa,   'Nomor WA wajib diisi.'); ok = false; }
        else                    { clearErr(wa); }
    }
    if (step === 3) {
        var kec = document.getElementById('kecamatan');
        var kel = document.getElementById('kelurahan');
        var al  = document.getElementById('alamat_jemput');
        if (!kec.value.trim()) { markErr(kec, 'Kecamatan wajib dipilih.'); ok = false; } else { clearErr(kec); }
        if (!kel.value.trim()) { markErr(kel, 'Kelurahan wajib diisi.'); ok = false; }  else { clearErr(kel); }
        if (!al.value.trim())  { markErr(al,  'Alamat wajib diisi.'); ok = false; }     else { clearErr(al); }
    }
    if (step === 4) {
        var berat = document.getElementById('berat_kg');
        if (!berat.value.trim()) { markErr(berat, 'Perkiraan berat wajib diisi.'); ok = false; } else { clearErr(berat); }
    }
    return ok;
}

function markErr(el, msg) {
    el.classList.add('err');
    var old = el.parentNode.querySelector('.errmsg-js');
    if (!old) {
        var sp = document.createElement('span');
        sp.className = 'errmsg errmsg-js';
        sp.textContent = msg;
        el.parentNode.appendChild(sp);
    }
}
function clearErr(el) {
    el.classList.remove('err');
    var old = el.parentNode.querySelector('.errmsg-js');
    if (old) old.remove();
}
function showInlineErr(stepId, msg) {
    var existing = document.querySelector('#' + stepId + ' .errmsg-inline');
    if (!existing) {
        var sp = document.createElement('span');
        sp.className = 'errmsg errmsg-inline';
        sp.style.cssText = 'display:block;margin-bottom:12px';
        sp.textContent = msg;
        var grid = document.querySelector('#' + stepId + ' .barang-grid');
        if (grid) grid.parentNode.insertBefore(sp, grid);
    }
    setTimeout(function() {
        var el = document.querySelector('#' + stepId + ' .errmsg-inline');
        if (el) el.remove();
    }, 3000);
}

// ── Review Builder (Step 5) ───────────────────────────────────
function buildReview() {
    var rows = document.getElementById('reviewRows');
    if (!rows) return;

    var kecEl  = document.getElementById('kecamatan');
    var kecTxt = kecEl ? kecEl.options[kecEl.selectedIndex].text : '—';

    var checked = Array.from(document.querySelectorAll('input[name="barang[]"]:checked'))
                       .map(function(c) { return c.closest('.barang-item').querySelector('.barang-label').innerText.trim(); });

    var latEl = document.getElementById('latitude');
    var lngEl = document.getElementById('longitude');
    var gpsTxt = (latEl && latEl.value && lngEl && lngEl.value)
        ? latEl.value + ', ' + lngEl.value : '—';

    var data = [
        ['Jenis Sampah',   checked.length ? checked.join(', ') : '—'],
        ['Nama',           (document.getElementById('nama') || {}).value || '—'],
        ['WhatsApp',       ((document.querySelector('[name="area_code"]') || {}).value || '') + ((document.getElementById('nomor_wa') || {}).value || '')],
        ['Kecamatan',      kecTxt],
        ['Kelurahan',      (document.getElementById('kelurahan') || {}).value || '—'],
        ['Alamat',         (document.getElementById('alamat_jemput') || {}).value || '—'],
        ['Koordinat GPS',  gpsTxt],
        ['Perkiraan Berat',(document.getElementById('berat_kg') || {}).value ? (document.getElementById('berat_kg').value + ' kg') : '—'],
        ['Catatan',        (document.getElementById('catatan') || {}).value || '—'],
    ];

    rows.innerHTML = data.map(function(r) {
        return '<div class="review-row"><span class="rl">' + r[0] + '</span><span class="rv">' + escHtml(String(r[1])) + '</span></div>';
    }).join('');
}
function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Advanced Toggle ───────────────────────────────────────────
function toggleAdv() {
    var toggle = document.getElementById('advToggle');
    var panel  = document.getElementById('advPanel');
    if (!toggle) return;
    toggle.classList.toggle('open');
    panel.classList.toggle('open');
}

// ── GPS / Google Maps ─────────────────────────────────────────
window.initGoogleMap = function() {
    var mapEl = document.getElementById('pickerMap');
    if (!mapEl) return;

    var btnGPS  = document.getElementById('btnGPS');
    var gpsText = document.getElementById('gpsText');
    var statBox = document.getElementById('gpsStatus');
    var statIco = document.getElementById('gpsIco');
    var statTxt = document.getElementById('gpsTxt');
    var kdBox   = document.getElementById('kdBox');
    var kdLat   = document.getElementById('kdLat');
    var kdLng   = document.getElementById('kdLng');
    var kdAcc   = document.getElementById('kdAcc');
    var kdLink  = document.getElementById('kdMapLink');
    var inpLat  = document.getElementById('latitude');
    var inpLng  = document.getElementById('longitude');
    var inpKM   = document.getElementById('koordinatManual');

    var center = { lat: 1.4748, lng: 124.8421 };
    var zoom   = 13;
    if (inpLat.value !== '' && inpLng.value !== '') {
        center = { lat: parseFloat(inpLat.value), lng: parseFloat(inpLng.value) };
        zoom   = 16;
    }

    var map = new google.maps.Map(mapEl, {
        center: center,
        zoom: zoom,
        mapTypeControl: false,
        streetViewControl: false
    });
    window._google_map_instance = map;

    var marker = new google.maps.Marker({
        position: center,
        map: map,
        draggable: true,
        title: 'Seret ke lokasi penjemputan'
    });
    window._google_marker_instance = marker;

    if (inpLat.value !== '' && inpLng.value !== '') {
        renderCoords(parseFloat(inpLat.value), parseFloat(inpLng.value), null);
        setStatus('found', '✅', 'Koordinat tersimpan.');
    }

    map.addListener('click', function(e) {
        var latLng = e.latLng;
        marker.setPosition(latLng);
        setCoords(latLng.lat(), latLng.lng(), 1);
        setStatus('found', '✅', 'Lokasi dipilih dari peta.');
    });

    marker.addListener('dragend', function() {
        var pos = marker.getPosition();
        setCoords(pos.lat(), pos.lng(), 1);
        setStatus('found', '✅', 'Lokasi diperbarui.');
    });

    function getIPLocationFallback(successCallback, errorCallback) {
        setStatus('detecting', '⏳', 'Mencoba deteksi lokasi alternatif via IP…');
        fetch('https://ipapi.co/json/')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.latitude && data.longitude) {
                    successCallback(data.latitude, data.longitude, 'IP-based (Estimasi)');
                } else {
                    throw new Error('Invalid data');
                }
            })
            .catch(function() {
                fetch('https://ipinfo.io/json')
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data && data.loc) {
                            var parts = data.loc.split(',');
                            var lat = parseFloat(parts[0]);
                            var lng = parseFloat(parts[1]);
                            if (!isNaN(lat) && !isNaN(lng)) {
                                successCallback(lat, lng, 'IP-based (Estimasi)');
                            } else {
                                throw new Error('Invalid format');
                            }
                        } else {
                            throw new Error('Invalid data');
                        }
                    })
                    .catch(function(e) {
                        errorCallback(e);
                    });
            });
    }

    if (btnGPS) btnGPS.addEventListener('click', function() {
        if (!navigator.geolocation) {
            getIPLocationFallback(function(lat, lng, source) {
                setCoords(lat, lng, 0);
                var latLng = new google.maps.LatLng(lat, lng);
                marker.setPosition(latLng);
                map.setCenter(latLng);
                map.setZoom(15);
                setStatus('found', '✅', 'Lokasi terdeteksi via IP (Estimasi)!');
                renderCoords(lat, lng, source);
                setLoading(false, 'ok');
            }, function(err) {
                setLoading(false, 'fail');
                setStatus('denied', '🚫', 'Browser tidak mendukung GPS & gagal deteksi IP. Pilih dari peta.');
            });
            return;
        }
        setLoading(true);
        setStatus('detecting', '⏳', 'Mendeteksi lokasi Anda…');
        navigator.geolocation.getCurrentPosition(function(pos) {
            var lat = pos.coords.latitude, lng = pos.coords.longitude, acc = Math.round(pos.coords.accuracy);
            setCoords(lat, lng, 0);
            var latLng = new google.maps.LatLng(lat, lng);
            marker.setPosition(latLng);
            map.setCenter(latLng);
            map.setZoom(17);
            setStatus('found', '✅', 'Lokasi terdeteksi! Akurasi ±' + acc + ' m.');
            renderCoords(lat, lng, '±' + acc + ' m');
            setLoading(false, 'ok');
        }, function(err) {
            getIPLocationFallback(function(lat, lng, source) {
                setCoords(lat, lng, 0);
                var latLng = new google.maps.LatLng(lat, lng);
                marker.setPosition(latLng);
                map.setCenter(latLng);
                map.setZoom(15);
                setStatus('found', '✅', 'Lokasi terdeteksi via IP (Estimasi)!');
                renderCoords(lat, lng, source);
                setLoading(false, 'ok');
            }, function() {
                setLoading(false, 'fail');
                var msgs = {1:'Akses lokasi ditolak.', 2:'Posisi tidak tersedia.', 3:'Waktu habis — coba lagi.'};
                setStatus('gpsfail', '⚠️', (msgs[err.code] || 'GPS error.') + ' Pilih lokasi dari peta.');
            });
        }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 });
    });

    function setCoords(lat, lng, manual) {
        inpLat.value = lat.toFixed(8);
        inpLng.value = lng.toFixed(8);
        if (inpKM) inpKM.value = manual;
        renderCoords(lat, lng, null);
    }
    function renderCoords(lat, lng, acc) {
        if (kdLat) kdLat.textContent = lat.toFixed(7);
        if (kdLng) kdLng.textContent = lng.toFixed(7);
        if (kdAcc) kdAcc.textContent = acc || 'Manual (Peta)';
        if (kdLink) kdLink.href = 'https://www.google.com/maps?q=' + lat + ',' + lng;
        if (kdBox) kdBox.classList.add('show');
    }
    function setStatus(type, icon, text) {
        statBox.className = 'gps-status show ' + type;
        statIco.textContent = icon;
        statTxt.textContent = text;
    }
    function setLoading(on, result) {
        btnGPS.disabled = on;
        btnGPS.classList.toggle('loading', on);
        if (on) { gpsText.textContent = 'Mendeteksi…'; return; }
        if (result === 'ok')   { btnGPS.classList.add('ok');   gpsText.textContent = 'Lokasi Terdeteksi ✓'; }
        if (result === 'fail') {
            btnGPS.classList.add('fail'); gpsText.textContent = 'Gagal — Coba Lagi';
            setTimeout(function() { btnGPS.classList.remove('fail'); gpsText.textContent = 'Deteksi Lokasi Saya'; }, 3000);
        }
    }
};

window.gm_authFailure = function() {
    console.warn("Google Maps Auth Failed. Switching to Leaflet OpenStreetMap.");
    window.initLeafletMapFallback();
};

window.initLeafletMapFallback = function() {
    var mapEl = document.getElementById('pickerMap');
    if (!mapEl) return;
    
    // Clear Google Maps container error elements or overlay
    mapEl.innerHTML = '';
    
    var btnGPS  = document.getElementById('btnGPS');
    var gpsText = document.getElementById('gpsText');
    var statBox = document.getElementById('gpsStatus');
    var statIco = document.getElementById('gpsIco');
    var statTxt = document.getElementById('gpsTxt');
    var kdBox   = document.getElementById('kdBox');
    var kdLat   = document.getElementById('kdLat');
    var kdLng   = document.getElementById('kdLng');
    var kdAcc   = document.getElementById('kdAcc');
    var kdLink  = document.getElementById('kdMapLink');
    var inpLat  = document.getElementById('latitude');
    var inpLng  = document.getElementById('longitude');
    var inpKM   = document.getElementById('koordinatManual');

    var latVal = parseFloat(inpLat.value);
    var lngVal = parseFloat(inpLng.value);
    var center = [1.4748, 124.8421];
    var zoom   = 13;
    if (!isNaN(latVal) && !isNaN(lngVal)) {
        center = [latVal, lngVal];
        zoom   = 16;
    }

    var map = L.map('pickerMap').setView(center, zoom);
    window._leaflet_map_instance = map;

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);

    var marker = L.marker(center, { draggable: true }).addTo(map);
    window._leaflet_marker_instance = marker;

    if (!isNaN(latVal) && !isNaN(lngVal)) {
        renderCoords(latVal, lngVal, null);
        setStatus('found', '✅', 'Koordinat tersimpan.');
    }

    map.on('click', function(e) {
        var latlng = e.latlng;
        marker.setLatLng(latlng);
        setCoords(latlng.lat, latlng.lng, 1);
        setStatus('found', '✅', 'Lokasi dipilih dari peta.');
    });

    marker.on('dragend', function() {
        var latlng = marker.getLatLng();
        setCoords(latlng.lat, latlng.lng, 1);
        setStatus('found', '✅', 'Lokasi diperbarui.');
    });

    function getIPLocationFallback(successCallback, errorCallback) {
        setStatus('detecting', '⏳', 'Mencoba deteksi lokasi alternatif via IP…');
        fetch('https://ipapi.co/json/')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.latitude && data.longitude) {
                    successCallback(data.latitude, data.longitude, 'IP-based (Estimasi)');
                } else {
                    throw new Error('Invalid data');
                }
            })
            .catch(function() {
                fetch('https://ipinfo.io/json')
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data && data.loc) {
                            var parts = data.loc.split(',');
                            var lat = parseFloat(parts[0]);
                            var lng = parseFloat(parts[1]);
                            if (!isNaN(lat) && !isNaN(lng)) {
                                successCallback(lat, lng, 'IP-based (Estimasi)');
                            } else {
                                throw new Error('Invalid format');
                            }
                        } else {
                            throw new Error('Invalid data');
                        }
                    })
                    .catch(function(e) {
                        errorCallback(e);
                    });
            });
    }

    if (btnGPS) {
        var newBtnGPS = btnGPS.cloneNode(true);
        btnGPS.parentNode.replaceChild(newBtnGPS, btnGPS);
        btnGPS = newBtnGPS;
        
        btnGPS.addEventListener('click', function() {
            if (!navigator.geolocation) {
                getIPLocationFallback(function(lat, lng, source) {
                    setCoords(lat, lng, 0);
                    marker.setLatLng([lat, lng]);
                    map.setView([lat, lng], 15);
                    setStatus('found', '✅', 'Lokasi terdeteksi via IP (Estimasi)!');
                    renderCoords(lat, lng, source);
                    setLoading(false, 'ok');
                }, function(err) {
                    setLoading(false, 'fail');
                    setStatus('denied', '🚫', 'Browser tidak mendukung GPS & gagal deteksi IP. Pilih dari peta.');
                });
                return;
            }
            setLoading(true);
            setStatus('detecting', '⏳', 'Mendeteksi lokasi Anda…');
            navigator.geolocation.getCurrentPosition(function(pos) {
                var lat = pos.coords.latitude, lng = pos.coords.longitude, acc = Math.round(pos.coords.accuracy);
                setCoords(lat, lng, 0);
                marker.setLatLng([lat, lng]);
                map.setView([lat, lng], 17);
                setStatus('found', '✅', 'Lokasi terdeteksi! Akurasi ±' + acc + ' m.');
                renderCoords(lat, lng, '±' + acc + ' m');
                setLoading(false, 'ok');
            }, function(err) {
                getIPLocationFallback(function(lat, lng, source) {
                    setCoords(lat, lng, 0);
                    marker.setLatLng([lat, lng]);
                    map.setView([lat, lng], 15);
                    setStatus('found', '✅', 'Lokasi terdeteksi via IP (Estimasi)!');
                    renderCoords(lat, lng, source);
                    setLoading(false, 'ok');
                }, function() {
                    setLoading(false, 'fail');
                    var msgs = {1:'Akses lokasi ditolak.', 2:'Posisi tidak tersedia.', 3:'Waktu habis — coba lagi.'};
                    setStatus('gpsfail', '⚠️', (msgs[err.code] || 'GPS error.') + ' Pilih lokasi dari peta.');
                });
            }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 });
        });
    }

    function setCoords(lat, lng, manual) {
        inpLat.value = lat.toFixed(8);
        inpLng.value = lng.toFixed(8);
        if (inpKM) inpKM.value = manual;
        renderCoords(lat, lng, null);
    }
    function renderCoords(lat, lng, acc) {
        if (kdLat) kdLat.textContent = lat.toFixed(7);
        if (kdLng) kdLng.textContent = lng.toFixed(7);
        if (kdAcc) kdAcc.textContent = acc || 'Manual (Peta)';
        if (kdLink) kdLink.href = 'https://www.google.com/maps?q=' + lat + ',' + lng;
        if (kdBox) kdBox.classList.add('show');
    }
    function setStatus(type, icon, text) {
        statBox.className = 'gps-status show ' + type;
        statIco.textContent = icon;
        statTxt.textContent = text;
    }
    function setLoading(on, result) {
        btnGPS.disabled = on;
        btnGPS.classList.toggle('loading', on);
        if (on) { gpsText.textContent = 'Mendeteksi…'; return; }
        if (result === 'ok')   { btnGPS.classList.add('ok');   gpsText.textContent = 'Lokasi Terdeteksi ✓'; }
        if (result === 'fail') {
            btnGPS.classList.add('fail'); gpsText.textContent = 'Gagal — Coba Lagi';
            setTimeout(function() { btnGPS.classList.remove('fail'); gpsText.textContent = 'Deteksi Lokasi Saya'; }, 3000);
        }
    }
};



(function init() {
    updateStepperUI();
    updateButtons();
    // If returning from server error, populate review
    if (currentStep === 5) buildReview();

    // ── Map Location Search Feature ──
    (function initMapSearch() {
        var searchInput = document.getElementById('mapSearchInput');
        var clearBtn = document.getElementById('clearSearchBtn');
        var resultsDropdown = document.getElementById('mapSearchResults');
        if (!searchInput || !resultsDropdown) return;

        var searchTimeout = null;

        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            var query = this.value.trim();
            if (query === '') {
                if (clearBtn) clearBtn.style.display = 'none';
                resultsDropdown.style.display = 'none';
                resultsDropdown.innerHTML = '';
                return;
            }
            if (clearBtn) clearBtn.style.display = 'block';
            searchTimeout = setTimeout(function() {
                performSearch(query);
            }, 500);
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                searchInput.value = '';
                clearBtn.style.display = 'none';
                resultsDropdown.style.display = 'none';
                resultsDropdown.innerHTML = '';
                searchInput.focus();
            });
        }

        document.addEventListener('click', function(e) {
            if (e.target !== searchInput && e.target !== resultsDropdown && !resultsDropdown.contains(e.target)) {
                resultsDropdown.style.display = 'none';
            }
        });

        function performSearch(query) {
            resultsDropdown.innerHTML = '<div style="padding: 10px 14px; font-size: 0.82rem; color: var(--tl); font-weight: 700; text-align: center;">⏳ Mencari lokasi...</div>';
            resultsDropdown.style.display = 'block';
            
            var searchQuery = query;
            if (searchQuery.toLowerCase().indexOf('manado') === -1) {
                searchQuery += ' Manado';
            }
            
            var url = 'https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(searchQuery) + '&countrycodes=id&limit=5&addressdetails=1';
            
            fetch(url, {
                headers: {
                    'Accept-Language': 'id-ID,id;q=0.9'
                }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                resultsDropdown.innerHTML = '';
                if (!data || data.length === 0) {
                    resultsDropdown.innerHTML = '<div style="padding: 10px 14px; font-size: 0.82rem; color: var(--red); font-weight: 700; text-align: center;">❌ Lokasi tidak ditemukan</div>';
                    return;
                }
                
                data.forEach(function(item) {
                    var div = document.createElement('div');
                    div.className = 'search-result-item';
                    
                    var displayName = item.display_name;
                    var parts = displayName.split(',');
                    var mainName = parts[0].trim();
                    var subName = parts.slice(1).join(',').trim();
                    div.innerHTML = '<strong>' + escHtml(mainName) + '</strong><span style="font-size:0.75rem;color:var(--tm);">' + escHtml(subName) + '</span>';
                    
                    div.addEventListener('click', function() {
                        var lat = parseFloat(item.lat);
                        var lon = parseFloat(item.lon);
                        
                        var inpLat = document.getElementById('latitude');
                        var inpLng = document.getElementById('longitude');
                        var inpKM = document.getElementById('koordinatManual');
                        if (inpLat) inpLat.value = lat.toFixed(8);
                        if (inpLng) inpLng.value = lon.toFixed(8);
                        if (inpKM) inpKM.value = 1;
                        
                        // Update Leaflet marker
                        if (window._leaflet_map_instance && window._leaflet_marker_instance) {
                            window._leaflet_marker_instance.setLatLng([lat, lon]);
                            window._leaflet_map_instance.setView([lat, lon], 17);
                        }
                        // Update Google Maps marker
                        if (window._google_map_instance && window._google_marker_instance) {
                            var latLng = new google.maps.LatLng(lat, lon);
                            window._google_marker_instance.setPosition(latLng);
                            window._google_map_instance.setCenter(latLng);
                            window._google_map_instance.setZoom(17);
                        }
                        
                        // Show coordinates box
                        var kdLat = document.getElementById('kdLat');
                        var kdLng = document.getElementById('kdLng');
                        var kdAcc = document.getElementById('kdAcc');
                        var kdLink = document.getElementById('kdMapLink');
                        var kdBox = document.getElementById('kdBox');
                        if (kdLat) kdLat.textContent = lat.toFixed(7);
                        if (kdLng) kdLng.textContent = lon.toFixed(7);
                        if (kdAcc) kdAcc.textContent = 'Hasil Pencarian';
                        if (kdLink) kdLink.href = 'https://www.google.com/maps?q=' + lat + ',' + lon;
                        if (kdBox) kdBox.classList.add('show');
                        
                        // Update status box
                        var statBox = document.getElementById('gpsStatus');
                        var statIco = document.getElementById('gpsIco');
                        var statTxt = document.getElementById('gpsTxt');
                        if (statBox && statIco && statTxt) {
                            statBox.className = 'gps-status show found';
                            statIco.textContent = '✅';
                            statTxt.textContent = 'Lokasi dipilih: ' + mainName;
                        }
                        
                        // Auto fill Kecamatan
                        var kecOpts = ['bunaken', 'malalayang', 'mapanget', 'sario', 'singkil', 'tikala', 'tuminting', 'wanea', 'wenang', 'paal dua', 'paal_dua', 'paal empat', 'paal_empat'];
                        var foundKec = '';
                        var displayNameLower = displayName.toLowerCase();
                        for (var i = 0; i < kecOpts.length; i++) {
                            if (displayNameLower.indexOf(kecOpts[i]) !== -1) {
                                foundKec = kecOpts[i];
                                break;
                            }
                        }
                        
                        if (foundKec) {
                            if (foundKec === 'paal dua') foundKec = 'paal_dua';
                            if (foundKec === 'paal empat') foundKec = 'paal_empat';
                            var kecEl = document.getElementById('kecamatan');
                            if (kecEl) {
                                kecEl.value = foundKec;
                                var event = new Event('change');
                                kecEl.dispatchEvent(event);
                            }
                        }
                        
                        // Auto fill Kelurahan
                        var kelEl = document.getElementById('kelurahan');
                        if (kelEl) {
                            var address = item.address || {};
                            var suburb = address.village || address.suburb || address.neighbourhood || address.hamlet || '';
                            if (suburb) {
                                kelEl.value = suburb;
                            } else {
                                var guessedKel = '';
                                if (parts.length > 1) {
                                    for (var j = 0; j < Math.min(parts.length, 3); j++) {
                                        var p = parts[j].trim();
                                        var pLower = p.toLowerCase();
                                        if (j > 0 && pLower.indexOf('manado') === -1 && pLower.indexOf('sulawesi') === -1 && pLower.indexOf('indonesia') === -1) {
                                            guessedKel = p;
                                            break;
                                        }
                                    }
                                }
                                if (guessedKel) kelEl.value = guessedKel;
                            }
                        }
                        
                        // Auto fill Alamat Lengkap
                        var alEl = document.getElementById('alamat_jemput');
                        if (alEl) {
                            alEl.value = mainName + ', ' + (parts[1] ? parts[1].trim() : '');
                        }
                        
                        searchInput.value = mainName;
                        resultsDropdown.style.display = 'none';
                    });
                    resultsDropdown.appendChild(div);
                });
            })
            .catch(function(err) {
                console.error(err);
                resultsDropdown.innerHTML = '<div style="padding: 10px 14px; font-size: 0.82rem; color: var(--red); font-weight: 700; text-align: center;">⚠️ Gagal memuat hasil pencarian</div>';
            });
        }
        
        function escHtml(s) {
            return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
    })();
    // Invalidate map size after potential CSS layout change
    setTimeout(function() {
        if (window._leaflet_map_instance) {
            window._leaflet_map_instance.invalidateSize();
            var inpLat  = document.getElementById('latitude');
            var inpLng  = document.getElementById('longitude');
            if (inpLat && inpLng && inpLat.value !== '' && inpLng.value !== '') {
                var center = [parseFloat(inpLat.value), parseFloat(inpLng.value)];
                window._leaflet_map_instance.setView(center, 16);
            }
        } else {
            window.initLeafletMapFallback();
        }
    }, 300);

    var kecEl = document.getElementById('kecamatan');
    var kecInfo = document.getElementById('kecInfo');
    if (kecEl && kecInfo) {
        var checkKec = function() {
            var val = kecEl.value.toLowerCase();
            if (val) {
                kecInfo.style.display = 'block';
                kecInfo.style.color = '#166534';
                kecInfo.innerHTML = '✅ Wilayah Operasional (Jadwal Otomatis)';
            } else {
                kecInfo.style.display = 'none';
            }
        };
        kecEl.addEventListener('change', checkKec);
        checkKec();
    }

    // ── Live Tracking Maps Initialization ──
    (function initLiveTrackingMaps() {
        var mapsList = document.querySelectorAll('.live-tracking-map');
        mapsList.forEach(function(mapEl) {
            var reqId = mapEl.getAttribute('data-request-id');
            var reqType = mapEl.getAttribute('data-request-type');
            var custLat = parseFloat(mapEl.getAttribute('data-customer-lat'));
            var custLng = parseFloat(mapEl.getAttribute('data-customer-lng'));
            
            if (isNaN(custLat) || isNaN(custLng) || custLat === 0 || custLng === 0) {
                mapEl.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;font-size:12px;color:#999;">Koordinat jemput tidak diset</div>';
                return;
            }

            // Initialize Leaflet map centered at customer
            var map = L.map(mapEl.id).setView([custLat, custLng], 14);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors',
                maxZoom: 19
            }).addTo(map);

            // Custom icon for customer location
            var custIcon = L.divIcon({
                html: '<div style="font-size: 24px; text-shadow: 0 2px 4px rgba(0,0,0,0.3);">📍</div>',
                iconSize: [24, 24],
                iconAnchor: [12, 24]
            });
            
            var custMarker = L.marker([custLat, custLng], { icon: custIcon }).addTo(map)
                .bindPopup('<b>Lokasi Anda</b>').openPopup();

            var officerMarker = null;
            var routeLine = null;

            function updateLiveLocation() {
                var url = (reqType === 'cleanup' ? 'cleanup_service.php' : 'daur_ulang.php') + '?ajax_action=get_live_location&id=' + reqId + '&type=' + reqType;
                fetch(url)
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.ok && res.data) {
                            var oLat = parseFloat(res.data.last_lat);
                            var oLng = parseFloat(res.data.last_lng);
                            var oName = res.data.officer_nama || 'Petugas Lapangan';
                            
                            if (!isNaN(oLat) && !isNaN(oLng) && oLat !== 0 && oLng !== 0) {
                                var oPos = [oLat, oLng];
                                
                                var officerIcon = L.divIcon({
                                    html: '<div class="pulse-marker-icon" style="font-size: 26px; text-shadow: 0 2px 5px rgba(0,0,0,0.4); background: white; width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid #1c6434; box-shadow: 0 2px 8px rgba(0,0,0,0.25);">🚚</div>',
                                    iconSize: [34, 34],
                                    iconAnchor: [17, 17]
                                });

                                if (!officerMarker) {
                                    officerMarker = L.marker(oPos, { icon: officerIcon }).addTo(map)
                                        .bindPopup('<b>Petugas: ' + oName + '</b><br>Sedang dalam perjalanan ke lokasi Anda.').openPopup();
                                } else {
                                    officerMarker.setLatLng(oPos);
                                }

                                // Gambarkan garis rute/koneksi antara petugas dengan lokasi penjemputan warga
                                if (routeLine) {
                                    map.removeLayer(routeLine);
                                }
                                routeLine = L.polyline([oPos, [custLat, custLng]], {
                                    color: '#1c6434',
                                    weight: 4,
                                    opacity: 0.8,
                                    dashArray: '8, 8'
                                }).addTo(map);

                                var bounds = L.latLngBounds([custMarker.getLatLng(), officerMarker.getLatLng()]);
                                map.fitBounds(bounds, { padding: [40, 40] });
                            }
                        }
                    })
                    .catch(function(err) { console.error('[Live GPS Map Error]', err); });
            }

            updateLiveLocation();
            var poller = setInterval(updateLiveLocation, 10000);
            
            // Clean up when element is removed
            mapEl.addEventListener('DOMNodeRemoved', function() {
                clearInterval(poller);
            });
        });
    })();
})();
</script>

<footer class="site-footer" aria-label="Footer">
    <div class="footer-container">
        <small class="footer-text" style="display:block;">
            Manado Recycle Hub 2026&nbsp;
        </small>
        <small class="footer-text" style="display:block;">
            Images are free licensed from pexels.com.
        </small>
    </div>
</footer>
</body>
</html>
