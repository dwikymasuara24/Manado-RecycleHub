<?php
// ============================================================
//  req_management.php — Admin Panel: Manajemen Request Jemput Sampah
//  Manado Recycle Hub
//  Sinkron penuh dengan daur_ulang_form.php (user console)
//  berat_total_kg (DECIMAL) tampil langsung di tabel
//  Preview: estimasi_kg tampil dari data yang disimpan user console
// ============================================================
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/auth.php';
requireRole('admin');
$page_id    = 'req_management';
$page_title = 'Manajemen Request';
$db         = getDB();
$csrfToken  = csrfToken();

// ── Auto-migrasi kolom ───────────────────────────────────────
$itemMigrations = [
    "ALTER TABLE pickup_request_items ADD COLUMN estimasi_kg DECIMAL(10,2) NULL",
    "ALTER TABLE pickup_request_items ADD COLUMN aktual_kg   DECIMAL(10,2) NULL",
    "ALTER TABLE pickup_request_items ADD COLUMN catatan     TEXT          NULL",
    // GPS + geo
    "ALTER TABLE pickup_requests ADD COLUMN latitude          DECIMAL(10,8) NULL",
    "ALTER TABLE pickup_requests ADD COLUMN longitude         DECIMAL(11,8) NULL",
    "ALTER TABLE pickup_requests ADD COLUMN place_id          VARCHAR(255)  NULL",
    "ALTER TABLE pickup_requests ADD COLUMN formatted_address TEXT          NULL",
    "ALTER TABLE pickup_requests ADD COLUMN koordinat_manual  TINYINT(1) NOT NULL DEFAULT 0",
];
foreach ($itemMigrations as $mq) {
    try { $db->exec($mq); } catch (PDOException $e) {
        if (strpos($e->getMessage(), '1060') === false) {
            error_log('[MRH Admin Migration] ' . $e->getMessage());
        }
    }
}

// ── Google Maps API Key ───────────────────────────────────────
// Leaflet + Nominatim — tidak perlu API Key

// ── AJAX: simpan koordinat dari Places Autocomplete ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_geo') {
    requireCsrfToken();
    header('Content-Type: application/json');
    $gid  = (int)($_POST['id']                ?? 0);
    $glat = trim($_POST['latitude']           ?? '');
    $glng = trim($_POST['longitude']          ?? '');
    $gpid = trim($_POST['place_id']           ?? '');
    $gadr = trim($_POST['formatted_address']  ?? '');
    if ($gid && $glat && $glng) {
        $db->prepare("UPDATE pickup_requests SET latitude=?,longitude=?,place_id=?,formatted_address=?,koordinat_manual=0 WHERE id=?")
           ->execute([$glat,$glng,$gpid,$gadr,$gid]);
    }
    echo json_encode(['ok'=>true]); exit;
}

// ── AJAX / POST handler ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCsrfToken();
    $action = $_POST['action'];

    // ── SIMPAN / EDIT ──
    if ($action === 'save') {
        $id      = (int)($_POST['id'] ?? 0);
        $nama    = trim($_POST['nama_pemohon']  ?? '');
        $wa      = trim($_POST['nomor_wa']      ?? '');
        $kec     = trim($_POST['kecamatan']     ?? '');
        $kel     = trim($_POST['kelurahan']     ?? '');
        $alamat  = trim($_POST['alamat_jemput'] ?? '');
        $catatan = trim($_POST['catatan']       ?? '');
        $catatan_officer = trim($_POST['catatan_officer'] ?? '');
        $status  = $_POST['status']             ?? 'menunggu';
        $tgl     = $_POST['tanggal_jemput']     ?: null;
        $jam     = $_POST['jam_jemput']         ?: null;
        
        // ── New Fields from Excel Format ──
        $place_name   = trim($_POST['place_name']   ?? '');
        $place_type   = trim($_POST['place_type']   ?? '');
        $partner_name = trim($_POST['partner_name'] ?? '');
        $pickup_type  = 'R'; // default
        if ($place_type === 'Household') {
            $pickup_type = 'R';
        } elseif ($place_type === 'Public') {
            $pickup_type = 'P';
        } elseif (in_array($place_type, ['F&B', 'Hospitality', 'Retail'])) {
            $pickup_type = 'B';
        }
        $service_type = trim($_POST['service_type'] ?? 'Free');
        $price_per_kg = trim($_POST['price_per_kg'] ?? '');
        $price_val    = ($price_per_kg !== '') ? (float)str_replace(',', '.', $price_per_kg) : null;
        $layanan      = $_POST['jenis_layanan']      ?? 'gratis';
        // Geo fields dari Places Autocomplete
        $gLat    = trim($_POST['latitude']          ?? '');
        $gLng    = trim($_POST['longitude']         ?? '');
        $gPlid   = trim($_POST['place_id']          ?? '');
        $gAddr   = trim($_POST['formatted_address'] ?? '');
        $lat     = ($gLat !== '' && is_numeric($gLat)) ? (float)$gLat : null;
        $lng     = ($gLng !== '' && is_numeric($gLng)) ? (float)$gLng : null;

        $beratInput = trim($_POST['berat_total_kg'] ?? '');
        $berat      = ($beratInput !== '') ? (float)str_replace(',', '.', $beratInput) : null;

        if (!empty($layanan) && $layanan !== 'gratis') {
            $prefix  = $layanan === 'mitra' ? '[MITRA] ' : '[CLEANUP] ';
            $catatan = $prefix . $catatan;
        }

        if (!$nama || !$wa || !$kec || !$alamat) {
            flash('danger', 'Field wajib belum diisi!');
        } elseif ($id) {
            $stmt = $db->prepare("UPDATE pickup_requests SET
                nama_pemohon=?, nomor_wa=?, kecamatan=?, kelurahan=?,
                alamat_jemput=?, catatan=?, status=?,
                tanggal_jemput=?, jam_jemput=?,
                place_name=?, place_type=?, partner_name=?, pickup_type=?, service_type=?, price_per_kg=?,
                berat_total_kg=?,
                berat_kg=?,
                latitude=COALESCE(NULLIF(?,0),latitude),
                longitude=COALESCE(NULLIF(?,0),longitude),
                place_id=COALESCE(NULLIF(?,''),place_id),
                formatted_address=COALESCE(NULLIF(?,''),formatted_address),
                confirmed_at = IF(status='dikonfirmasi' AND confirmed_at IS NULL, NOW(), confirmed_at),
                completed_at  = IF(status='selesai'       AND completed_at IS NULL,  NOW(), completed_at),
                catatan_officer=?
                WHERE id=?");
            $stmt->execute([
                $nama, $wa, $kec, $kel, $alamat, $catatan, $status,
                $tgl, $jam,
                $place_name, $place_type, $partner_name, $pickup_type, $service_type, $price_val,
                $berat, ($berat !== null ? (string)$berat : null),
                $lat ?? 0, $lng ?? 0, $gPlid, $gAddr,
                $catatan_officer,
                $id
            ]);
            recordWeighing($db, $id);
            triggerWhatsAppOnStatusChange($db, $id, $status, 'daur_ulang');
            logActivity($db, 1, "edit_request #$id", 'pickup_requests', $id, [], ['status'=>$status]);
            flash('success', "Request diperbarui!");
        } else {
            $pfx = ($pickup_type === 'B') ? 'MRH-B' : 'MRH-S';
            $request_code = generateSmartCode($db, 'pickup_requests', 'request_code', $pfx);
            
            $stmt = $db->prepare("INSERT INTO pickup_requests
                (request_code, nama_pemohon, area_code, nomor_wa, kecamatan, kelurahan,
                 alamat_jemput, catatan, status, tanggal_jemput, jam_jemput,
                 place_name, place_type, partner_name, pickup_type, service_type, price_per_kg,
                 berat_total_kg, berat_kg,
                 latitude, longitude, place_id, formatted_address,
                 catatan_officer,
                 created_at, updated_at)
                VALUES (?,?,'+62',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
            $stmt->execute([
                $request_code, $nama, $wa, $kec, $kel, $alamat, $catatan, $status,
                $tgl, $jam,
                $place_name, $place_type, $partner_name, $pickup_type, $service_type, $price_val,
                $berat, ($berat !== null ? (string)$berat : null),
                $lat, $lng, ($gPlid ?: null), ($gAddr ?: null),
                $catatan_officer
            ]);
            $newId = (int)$db->lastInsertId();
            recordWeighing($db, $newId);
            
            // Kirim notifikasi email otomatis saat order masuk secara manual
            triggerNewOrderEmail($db, $newId);

            $code  = $db->query("SELECT request_code FROM pickup_requests WHERE id=$newId")->fetchColumn();
            logActivity($db, 1, "tambah_request $code", 'pickup_requests', $newId, [], ['kecamatan'=>$kec]);
            flash('success', "Request $code berhasil ditambahkan!");
        }
        header('Location: req_management.php');
        exit;
    }

    // ── KONFIRMASI ──
    if ($action === 'confirm') {
        $id   = (int)($_POST['id'] ?? 0);
        $code = $db->query("SELECT request_code FROM pickup_requests WHERE id=$id")->fetchColumn();
        $db->prepare("UPDATE pickup_requests SET status='dikonfirmasi', confirmed_at=NOW() WHERE id=? AND status='menunggu'")->execute([$id]);
        triggerWhatsAppOnStatusChange($db, $id, 'dikonfirmasi', 'daur_ulang');
        logActivity($db, 1, "konfirmasi_request $code", 'pickup_requests', $id);
        flash('success', "Request $code dikonfirmasi!");
        header('Location: req_management.php');
        exit;
    }

    // ── HAPUS ──
    if ($action === 'delete') {
        $id   = (int)($_POST['id'] ?? 0);
        $code = $db->query("SELECT request_code FROM pickup_requests WHERE id=$id")->fetchColumn();
        $db->prepare("DELETE FROM pickup_requests WHERE id=?")->execute([$id]);
        $db->prepare("DELETE FROM weighing_records WHERE pickup_request_id=?")->execute([$id]);
        logActivity($db, 1, "hapus_request $code", 'pickup_requests', $id);
        flash('success', "Request $code dihapus.");
        header('Location: req_management.php');
        exit;
    }

    // ── INLINE EDIT BERAT (AJAX) ──
    if ($action === 'update_berat') {
        $id        = (int)($_POST['id']    ?? 0);
        $beratRaw  = trim($_POST['berat']  ?? '');
        $berat     = ($beratRaw !== '') ? (float)str_replace(',', '.', $beratRaw) : null;
        $db->prepare("UPDATE pickup_requests SET berat_total_kg=?, berat_kg=?, updated_at=NOW() WHERE id=?")
           ->execute([$berat, ($berat !== null ? (string)$berat : null), $id]);
        recordWeighing($db, $id);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'berat' => $berat]);
        exit;
    }

    // ── INLINE EDIT ITEM (AJAX) — admin update estimasi/aktual/catatan per item ──
    if ($action === 'update_item') {
        $item_id    = (int)($_POST['item_id']    ?? 0);
        $estimasi   = trim($_POST['estimasi_kg'] ?? '');
        $aktual     = trim($_POST['aktual_kg']   ?? '');
        $price      = trim($_POST['price_per_kg'] ?? '');
        $cat        = trim($_POST['catatan']      ?? '');
        $est_val    = ($estimasi !== '') ? (float)str_replace(',', '.', $estimasi) : null;
        $akt_val    = ($aktual   !== '') ? (float)str_replace(',', '.', $aktual)   : null;
        $price_val  = ($price    !== '') ? (float)str_replace(',', '.', $price)    : null;
        $db->prepare("UPDATE pickup_request_items SET estimasi_kg=?, aktual_kg=?, catatan=? WHERE id=?")
           ->execute([$est_val, $akt_val, ($cat !== '' ? $cat : null), $item_id]);
        
        $pickup_id = (int)$db->query("SELECT pickup_id FROM pickup_request_items WHERE id = $item_id")->fetchColumn();
        if ($pickup_id) {
            if ($price_val !== null) {
                $db->prepare("UPDATE pickup_requests SET price_per_kg = ? WHERE id = ?")
                   ->execute([$price_val, $pickup_id]);
            }
            $total_akt = $db->query("SELECT SUM(aktual_kg) FROM pickup_request_items WHERE pickup_id = $pickup_id")->fetchColumn();
            if ($total_akt !== null) {
                $db->prepare("UPDATE pickup_requests SET berat_total_kg=?, berat_kg=?, updated_at=NOW() WHERE id=?")
                   ->execute([$total_akt, (string)$total_akt, $pickup_id]);
            }
            recordWeighing($db, $pickup_id);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ── FILTER & SEARCH ─────────────────────────────────────────
$where   = '1=1';
$params  = [];
$search  = trim($_GET['q']      ?? '');
$fStatus = $_GET['status']      ?? '';
$fKec    = $_GET['kecamatan']   ?? '';

if ($search) {
    $where   .= " AND (pr.nama_pemohon LIKE ? OR pr.request_code LIKE ? OR pr.kecamatan LIKE ? OR pr.nomor_wa LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($fStatus) {
    $where   .= " AND pr.status = ?";
    $params[] = $fStatus;
}
if ($fKec) {
    $where   .= " AND pr.kecamatan = ?";
    $params[] = $fKec;
}

// ── BASE SELECT ─────────────────────────────────────────────
$baseSql = "SELECT pr.*,
        COALESCE(
            NULLIF(pr.berat_total_kg, 0),
            (SELECT SUM(COALESCE(pri2.aktual_kg, pri2.estimasi_kg))
             FROM pickup_request_items pri2 WHERE pri2.pickup_id = pr.id),
            CASE WHEN pr.berat_kg REGEXP '^[0-9]+([.][0-9]+)?$'
                 THEN CAST(pr.berat_kg AS DECIMAL(10,2))
                 ELSE NULL END
        ) AS berat_display,
        COALESCE(pr.berat_kg, '') AS berat_raw,
        (SELECT GROUP_CONCAT(wc.name ORDER BY wc.name SEPARATOR ', ')
         FROM pickup_request_items pri
         JOIN waste_categories wc ON wc.id = pri.category_id
         WHERE pri.pickup_id = pr.id) AS jenis_sampah,
        o.nama AS officer_nama,
        o.officer_code AS officer_code_val,
        NULL AS officer_zona
        FROM pickup_requests pr
        LEFT JOIN officers o ON o.id = pr.officer_id";

// ── QUERY AKTIF (belum selesai/dibatalkan) ──────────────────
$activeWhere  = "pr.status NOT IN ('selesai','dibatalkan') AND $where";
$stmtActive   = $db->prepare("$baseSql WHERE $activeWhere ORDER BY pr.created_at DESC");
$stmtActive->execute($params);
$activeRequests = $stmtActive->fetchAll();

// ── QUERY SELESAI / DIBATALKAN ───────────────────────────────
$doneWhere  = "pr.status IN ('selesai','dibatalkan') AND $where";
$stmtDone   = $db->prepare("$baseSql WHERE $doneWhere ORDER BY pr.updated_at DESC LIMIT 200");
$stmtDone->execute($params);
$doneRequests = $stmtDone->fetchAll();

// (legacy alias agar kode preview/edit tetap jalan)
$requests = array_merge($activeRequests, $doneRequests);

// ── STATISTIK ────────────────────────────────────────────────
$stats = $db->query("SELECT
    COUNT(*)                   AS total,
    SUM(status='menunggu')     AS menunggu,
    SUM(status='dikonfirmasi') AS dikonfirmasi,
    SUM(status='dijadwalkan')  AS dijadwalkan,
    SUM(status='selesai')      AS selesai,
    ROUND(SUM(COALESCE(berat_total_kg,
        CASE WHEN berat_kg REGEXP '^[0-9]+([.][0-9]+)?$' THEN CAST(berat_kg AS DECIMAL(10,2)) ELSE 0 END
    ,0)),1) AS total_berat
    FROM pickup_requests")->fetch();

// ── PREVIEW ─────────────────────────────────────────────────
$previewData  = null;
$previewItems = [];
if (!empty($_GET['preview'])) {
    $pid = (int)$_GET['preview'];
    $previewData = $db->query("SELECT pr.*,
        COALESCE(
            NULLIF(pr.berat_total_kg,0),
            CASE WHEN pr.berat_kg REGEXP '^[0-9]+([.][0-9]+)?$'
                 THEN CAST(pr.berat_kg AS DECIMAL(10,2)) ELSE NULL END
        ) AS berat_display,
        COALESCE(pr.berat_kg,'') AS berat_raw,
        o.nama AS officer_nama, o.officer_code AS officer_code_val
        FROM pickup_requests pr
        LEFT JOIN officers o ON o.id=pr.officer_id
        WHERE pr.id=$pid")->fetch();
    if ($previewData) {
        // Jalankan recordWeighing untuk memicu auto-distribusi jika ada data aktual yang kosong pada status selesai
        if ($previewData['status'] === 'selesai' && (float)$previewData['berat_total_kg'] > 0) {
            recordWeighing($db, $pid);
        }

        // Ambil items + kolom lengkap: estimasi_kg, aktual_kg, catatan
        $previewItems = $db->query("SELECT pri.id AS item_id, pri.estimasi_kg, pri.aktual_kg, pri.catatan AS item_catatan,
            wc.name AS kat_nama, wc.ikon_emoji
            FROM pickup_request_items pri
            JOIN waste_categories wc ON wc.id = pri.category_id
            WHERE pri.pickup_id = $pid ORDER BY wc.name")->fetchAll();
    }
}

// ── EDIT ────────────────────────────────────────────────────
$editData = null;
if (!empty($_GET['edit'])) {
    $eid      = (int)$_GET['edit'];
    $editData = $db->query("SELECT * FROM pickup_requests WHERE id=$eid")->fetch();
}

$waste_cats = $db->query("SELECT id, name, ikon_emoji FROM waste_categories WHERE is_active=1 ORDER BY name")->fetchAll();

$statuses   = ['menunggu','dikonfirmasi','dijadwalkan','dalam_perjalanan','sedang_diproses','selesai','dibatalkan'];
$kecamatans = ['Wenang','Malalayang','Tikala','Paal Dua','Bunaken','Singkil','Mapanget','Wanea','Sario','Tuminting'];

require_once __DIR__ . '/layout/header.php';
?>

<style>
/* ═══════════════════════════════════════
   STAT CARDS
═══════════════════════════════════════ */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.stat-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px 18px;display:flex;flex-direction:column;gap:3px;box-shadow:0 1px 4px rgba(0,0,0,.05);transition:box-shadow .2s,transform .15s}
.stat-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.1);transform:translateY(-1px)}
.stat-card .sc-label{font-size:10px;font-weight:700;color:#94a3b8;letter-spacing:.6px;text-transform:uppercase}
.stat-card .sc-val{font-size:24px;font-weight:800;color:var(--green-700,#2e7d32);line-height:1.1}
.stat-card .sc-sub{font-size:10px;color:#cbd5e1;font-weight:600}
.stat-card.warn .sc-val{color:#d97706}
.stat-card.info .sc-val{color:#0284c7}
.stat-card.ok   .sc-val{color:#16a34a}
.stat-card.purple .sc-val{color:#7c3aed}

/* ═══════════════════════════════════════
   TABLE
═══════════════════════════════════════ */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px}
thead th{background:#f8fafc;padding:10px 12px;text-align:left;font-size:10px;font-weight:700;color:#64748b;letter-spacing:.5px;text-transform:uppercase;border-bottom:2px solid #e2e8f0;white-space:nowrap}
tbody tr{border-bottom:1px solid #f1f5f9;transition:background .15s}
tbody tr:hover{background:#f8fffe}
tbody td{padding:10px 12px;vertical-align:middle;color:#334155}

/* ═══════════════════════════════════════
   BERAT CELL
═══════════════════════════════════════ */
.berat-cell{display:flex;align-items:center;gap:5px;min-width:100px}
.berat-badge{background:linear-gradient(135deg,#dcfce7,#bbf7d0);color:#15803d;border-radius:8px;padding:3px 10px;font-size:12px;font-weight:800;white-space:nowrap;border:1px solid #86efac}
.berat-na{color:#cbd5e1;font-size:11px;font-style:italic}
.berat-edit-btn{background:none;border:1px solid #e2e8f0;border-radius:6px;padding:2px 6px;font-size:11px;cursor:pointer;color:#94a3b8;transition:all .15s;line-height:1.4}
.berat-edit-btn:hover{border-color:var(--green-400,#4ade80);color:var(--green-600,#16a34a);background:#f0fdf4}

/* ═══════════════════════════════════════
   STATUS BADGES
═══════════════════════════════════════ */
.badge{display:inline-flex;align-items:center;gap:4px;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:700;white-space:nowrap}
.badge-menunggu{background:#fef3c7;color:#92400e}
.badge-dikonfirmasi{background:#dbeafe;color:#1e40af}
.badge-dijadwalkan{background:#ede9fe;color:#5b21b6}
.badge-dalam_perjalanan{background:#fef08a;color:#854d0e}
.badge-sedang_diproses{background:#ffedd5;color:#c2410c}
.badge-selesai{background:#dcfce7;color:#166534}
.badge-dibatalkan{background:#fee2e2;color:#991b1b}

/* ═══════════════════════════════════════
   TOOLBAR
═══════════════════════════════════════ */
.toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.toolbar-left{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.search-input,.filter-select{border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 12px;font-size:13px;background:#fff;outline:none;transition:border .2s}
.search-input:focus,.filter-select:focus{border-color:var(--green-500,#22c55e)}
.search-input{min-width:200px}

/* ═══════════════════════════════════════
   ACTION BUTTONS
═══════════════════════════════════════ */
.btn-icon{padding:5px 8px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;cursor:pointer;transition:all .15s;font-size:13px;line-height:1;display:inline-flex;align-items:center;justify-content:center}
.btn-icon:hover{border-color:var(--green-400,#4ade80);background:#f0fdf4}
.btn-danger.btn-icon:hover{border-color:#fca5a5;background:#fff5f5}
.actions-cell{display:flex;gap:4px;align-items:center;flex-wrap:nowrap}

/* ═══════════════════════════════════════
   MODAL
═══════════════════════════════════════ */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:1000;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(2px)}
.modal-overlay.open,.modal-overlay[style*="display:flex"]{display:flex}
.modal{background:#fff;border-radius:16px;width:100%;box-shadow:0 8px 48px rgba(0,0,0,.2);max-height:90vh;display:flex;flex-direction:column;animation:modalIn .2s ease;overflow:hidden}
.modal form{display:flex;flex-direction:column;flex:1;overflow:hidden;min-height:0}
@keyframes modalIn{from{opacity:0;transform:scale(.97) translateY(8px)}to{opacity:1;transform:scale(1) translateY(0)}}
.modal-header{padding:18px 24px 14px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;background:#fff;border-radius:16px 16px 0 0}
.modal-header h3{font-size:15px;font-weight:800;color:#1e293b;margin:0}
.modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:#94a3b8;line-height:1;padding:4px 6px;border-radius:6px;transition:all .15s}
.modal-close:hover{color:#ef4444;background:#fee2e2}
.modal-body{padding:20px 24px;flex:1;overflow-y:auto}
.modal-footer{padding:14px 24px;border-top:1px solid #f1f5f9;display:flex;gap:8px;justify-content:flex-end;background:#fafafa;border-radius:0 0 16px 16px}

.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:12px}
.form-label{font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px}
.form-input{border:1.5px solid #e2e8f0;border-radius:8px;padding:9px 12px;font-size:13px;outline:none;transition:border .2s,box-shadow .2s;font-family:inherit;width:100%;box-sizing:border-box;background:#f8fafc}
.form-input:focus{border-color:var(--green-500,#22c55e);box-shadow:0 0 0 3px rgba(34,197,94,.12);background:#fff}

/* ═══════════════════════════════════════
   PREVIEW CARD
═══════════════════════════════════════ */
.preview-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin-bottom:12px}
.preview-title { font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; margin-bottom: 10px; letter-spacing: 0.5px; }
.preview-row{display:flex;align-items:flex-start;gap:12px;padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:13px}
.preview-row:last-child{border-bottom:none}
.pl{min-width:130px;font-weight:700;color:#64748b;font-size:11px;padding-top:2px;text-transform:uppercase;letter-spacing:.3px}
.pv{color:#1e293b;flex:1;word-break:break-word;font-weight:600}

/* ═══════════════════════════════════════
   ITEMS TABLE (preview) — Sinkron dengan user console
═══════════════════════════════════════ */
.items-section{margin-top:16px}
.items-section-title{font-size:10px;font-weight:800;color:#64748b;margin-bottom:8px;letter-spacing:.6px;text-transform:uppercase;display:flex;align-items:center;gap:6px}
.items-table{width:100%;border-collapse:collapse;font-size:12px;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0}
.items-table thead th{background:#f1f5f9;padding:9px 12px;text-align:left;color:#64748b;font-size:10px;font-weight:800;letter-spacing:.5px;border-bottom:2px solid #e2e8f0;white-space:nowrap;line-height:1.2}
.items-table thead th:not(:first-child){text-align:center}
.items-table tbody tr{border-bottom:1px solid #f1f5f9;transition:background .15s}
.items-table tbody tr:last-child{border-bottom:none}
.items-table tbody tr:hover{background:#f0fdf4}
.items-table td{padding:9px 12px;vertical-align:middle}
.items-table td:not(:first-child){text-align:center}

/* Nilai kg badge */
.kg-val{display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;border-radius:8px;padding:3px 10px;min-width:56px}
.kg-est{background:#fef9c3;color:#854d0e;border:1px solid #fde047}  /* estimasi: kuning */
.kg-akt{background:#dcfce7;color:#15803d;border:1px solid #86efac}  /* aktual: hijau */
.kg-none{color:#cbd5e1;font-style:italic;font-size:11px;font-weight:500}

/* Inline edit item */
.item-edit-btn{background:none;border:1px solid #e2e8f0;border-radius:6px;padding:2px 7px;font-size:10px;cursor:pointer;color:#94a3b8;line-height:1.5;transition:all .15s;white-space:nowrap}
.item-edit-btn:hover{border-color:#4ade80;color:#16a34a;background:#f0fdf4}

/* Catatan item */
.item-cat-note{font-size:11px;color:#94a3b8;font-style:italic;max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.item-cat-note.has-note{color:#475569;font-style:normal;font-weight:600}

/* Kategori cell */
.item-kat-cell{display:flex;align-items:center;gap:6px;font-weight:700;color:#1e293b}
.item-kat-emoji{font-size:16px;line-height:1}

/* ═══════════════════════════════════════
   PAGE HEADER
═══════════════════════════════════════ */
.page-header{margin-bottom:20px}
.page-header h1{font-size:22px;font-weight:800;color:#1e293b;margin:0 0 4px}
.page-header p{font-size:13px;color:#94a3b8;margin:0}

/* ═══════════════════════════════════════
   ASSIGN OFFICER PILLS & BATCH BAR
═══════════════════════════════════════ */
/* Tombol assign di kolom tabel */
.assign-pill {
    display:inline-flex;align-items:center;gap:5px;
    border-radius:20px;padding:4px 10px;
    font-size:11px;font-weight:800;cursor:pointer;
    border:1.5px solid;transition:all .15s;
    font-family:inherit;line-height:1.4;white-space:nowrap;
}
.assign-pill.unassigned {
    background:#fffbeb;color:#b45309;border-color:#fde68a;
}
.assign-pill.unassigned:hover {
    background:#fef3c7;border-color:#f59e0b;transform:scale(1.03);
}
.assign-pill.assigned {
    background:#f0fdf4;color:#166534;border-color:#bbf7d0;
}
.assign-pill.assigned:hover {
    background:#dcfce7;border-color:#86efac;
}
.assign-pill:disabled {
    opacity:.4;cursor:not-allowed;
}

/* Tombol assign di header toolbar */
.btn-assign {
    background:#1c6434;color:#fff;border:none;
    border-radius:8px;padding:8px 16px;
    font-size:13px;font-weight:700;cursor:pointer;
    display:inline-flex;align-items:center;gap:6px;
    transition:background .15s;font-family:inherit;
}
.btn-assign:hover { background:#155229; }

/* Batch action bar (muncul saat ada checkbox dipilih) */
.batch-bar {
    display:none;
    position:sticky;top:60px;z-index:30;
    background:linear-gradient(135deg,#1c6434,#2e7d32);
    color:#fff;border-radius:10px;padding:12px 18px;
    margin-bottom:14px;
    align-items:center;gap:12px;flex-wrap:wrap;
    box-shadow:0 4px 20px rgba(28,100,52,.35);
    animation:slideDown .2s ease;
}
.batch-bar.show { display:flex; }
@keyframes slideDown {from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.batch-bar .batch-count {
    font-size:13px;font-weight:800;
    background:rgba(255,255,255,.2);
    padding:4px 12px;border-radius:20px;
}
.batch-bar .btn-batch-assign {
    background:#fff;color:#1c6434;
    border:none;border-radius:7px;padding:7px 14px;
    font-size:12px;font-weight:800;cursor:pointer;
    display:inline-flex;align-items:center;gap:5px;
    transition:all .15s;font-family:inherit;
}
.batch-bar .btn-batch-assign:hover { background:#dcfce7; }
.batch-bar .btn-cancel {
    background:rgba(255,255,255,.15);color:#fff;
    border:1px solid rgba(255,255,255,.3);border-radius:7px;
    padding:7px 12px;font-size:12px;font-weight:700;
    cursor:pointer;font-family:inherit;
}
.batch-bar .btn-cancel:hover { background:rgba(255,255,255,.25); }

/* ─── Select-all checkbox header ─── */
#checkAll {
    width:15px;height:15px;
    accent-color:var(--green-dark);cursor:pointer;
}

/* ═══════════════════════════════════════
   SYNC INDICATOR
═══════════════════════════════════════ */
.sync-bar{display:flex;align-items:center;gap:6px;font-size:11px;color:#64748b;margin-bottom:12px;flex-wrap:wrap}
.sync-dot{width:7px;height:7px;border-radius:50%;background:#22c55e;display:inline-block;flex-shrink:0;animation:pulseDot 2s infinite}
@keyframes pulseDot{0%,100%{opacity:1;box-shadow:0 0 0 0 rgba(34,197,94,.4)}50%{opacity:.7;box-shadow:0 0 0 4px rgba(34,197,94,0)}}

/* ═══════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════ */
@media(max-width:768px){
  .form-row{grid-template-columns:1fr}
  .stat-grid{grid-template-columns:repeat(2,1fr)}
  #modalPreview .grid-2 {
    grid-template-columns: 1fr !important;
  }
  .preview-row {
    flex-direction: column !important;
    align-items: flex-start !important;
    gap: 4px !important;
  }
  .preview-row .pl {
    min-width: auto !important;
  }
  .modal{max-height:95vh;margin:10px;width:calc(100% - 20px)}
  .modal-body{padding:16px}
  .modal-footer{padding:12px 16px}
  .modal-header{padding:14px 16px}
}
</style>

<div class="page-header">
  <h1>🗂️ Manajemen Request Jemput Sampah</h1>
  <p>Semua data dari user console masuk langsung — berat, lokasi, jenis sampah dan status tampil real-time</p>
</div>

<!-- ── STAT CARDS ── -->
<div class="stat-grid">
  <div class="stat-card">
    <span class="sc-label">Total Request</span>
    <span class="sc-val"><?= number_format((int)$stats['total']) ?></span>
    <span class="sc-sub">semua waktu</span>
  </div>
  <div class="stat-card warn">
    <span class="sc-label">Menunggu</span>
    <span class="sc-val"><?= (int)$stats['menunggu'] ?></span>
    <span class="sc-sub">perlu konfirmasi</span>
  </div>
  <div class="stat-card info">
    <span class="sc-label">Dikonfirmasi</span>
    <span class="sc-val"><?= (int)$stats['dikonfirmasi'] ?></span>
    <span class="sc-sub">siap dijemput</span>
  </div>
  <div class="stat-card purple">
    <span class="sc-label">Dijadwalkan</span>
    <span class="sc-val"><?= (int)$stats['dijadwalkan'] ?></span>
    <span class="sc-sub">terjadwal</span>
  </div>
  <div class="stat-card ok">
    <span class="sc-label">Selesai</span>
    <span class="sc-val"><?= (int)$stats['selesai'] ?></span>
    <span class="sc-sub">berhasil dijemput</span>
  </div>
  <div class="stat-card">
    <span class="sc-label">Total Berat</span>
    <span class="sc-val"><?= number_format((float)($stats['total_berat']??0), 1) ?><small style="font-size:14px;font-weight:600"> kg</small></span>
    <span class="sc-sub">seluruh request</span>
  </div>
</div>

<?php
// ── Reusable table-row renderer ──────────────────────────────
function renderRow(array $r, bool $isDone = false): string {
    $sc  = 'badge-'.str_replace(' ','_',$r['status']);
    $sl  = ucfirst(str_replace('_',' ',$r['status']));
    $berat    = $r['berat_display'];
    $beratRaw = $r['berat_raw'];
    $dot = match($r['status']) {
        'menunggu'       => '🟡','dikonfirmasi'  => '🔵','dijadwalkan'  => '🟣',
        'dalam_perjalanan'=> '🛵','sedang_diproses'=> '🟠','selesai'       => '🟢','dibatalkan'   => '🔴', default => '⚪'
    };
    $id   = $r['id'];
    $code = htmlspecialchars($r['request_code'] ?? '-');

    $showEdit = !in_array($r['status'], ['selesai', 'dibatalkan']);

    $beratHtml = '';
    if ($beratRaw !== '') {
        $beratHtml = "<span class='berat-badge' id='berat-display-$id'>".htmlspecialchars($beratRaw)." kg</span>";
    } elseif ($berat !== null && (float)$berat > 0) {
        $beratHtml = "<span class='berat-badge' id='berat-display-$id'>".((float)$berat)." kg</span>";
    } else {
        $beratHtml = "<span class='berat-na' id='berat-display-$id'>belum diisi</span>";
    }
    $beratEditVal = ($berat !== null ? (float)$berat : (is_numeric($beratRaw) ? (float)$beratRaw : 'null'));
    $beratEditBtn = $showEdit ? " <button class='berat-edit-btn' title='Edit berat' onclick='inlineEditBerat($id,$beratEditVal)' style='position:static;opacity:1;padding:2px'>✏️</button>" : "";

    $payoutHtml = '';
    if ($r['price_per_kg'] !== null && ($berat !== null || $beratRaw !== '')) {
        $w_str = ($beratRaw !== '') ? $beratRaw : (string)(float)$berat;
        $w_val = (float)$w_str;
        $p_val = (float)$r['price_per_kg'];
        $tot_val = $w_val * $p_val;
        $payoutHtml = "<div style='font-size:10px;color:#0284c7;font-weight:700;margin-top:2px;' title='Hasil Payout'>" . htmlspecialchars($w_str) . " kg x Rp" . number_format($p_val, 0, ',', '.') . " = Rp" . number_format($tot_val, 0, ',', '.') . "</div>";
    }

    $tglHtml = '—';
    if (!empty($r['tanggal_jemput']) && $r['tanggal_jemput'] !== '0000-00-00') {
        $tglHtml  = "<span style='font-weight:700;color:#334155'>".date('d M Y', strtotime($r['tanggal_jemput']))."</span>";
    }

    $officerHtml = '';
    if (!empty($r['officer_id']) && !empty($r['officer_nama'])) {
        $oNama = htmlspecialchars($r['officer_nama']);
        $oCode = htmlspecialchars($r['officer_code_val'] ?? '');
        $oZona = '';
        $officerHtml = "<div style='display:flex;flex-direction:column;gap:2px'><span style='font-weight:700;font-size:12px'>$oNama</span><span style='font-size:10px;color:#94a3b8;font-family:monospace'>$oCode</span>$oZona</div>";
    } else {
        $officerHtml = "<span style='color:#cbd5e1;font-size:11px;font-style:italic'>Belum ditugaskan<br><small style=\"font-size:10px;color:#bbb\">(oleh algoritma)</small></span>";
    }

    $confBtn = ''; // Konfirmasi dilakukan via dashboard — tidak ditampilkan di sini

    $editBtn = $showEdit ? "<a class='btn-icon' href='req_management.php?edit=$id' title='Edit'>✏️</a>" : "";
    $deleteBtn = "<button class='btn-icon btn-danger' title='Hapus' onclick=\"openDeleteModal($id,'$code')\">🗑️</button>";

    $waClean = preg_replace('/[^0-9]/', '', $r['nomor_wa'] ?? '');
    if (str_starts_with($waClean, '0')) {
        $waClean = '62' . substr($waClean, 1);
    } elseif (str_starts_with($waClean, '8')) {
        $waClean = '62' . $waClean;
    }
    $waText = urlencode("Halo " . ($r['nama_pemohon'] ?? '') . ", saya Admin dari " . SITE_NAME . ". Kami ingin mengonfirmasi request penjemputan sampah Anda dengan kode " . ($r['request_code'] ?? '') . ".");
    $waLink = "https://wa.me/" . $waClean . "?text=" . $waText;
    $waBtnHtml = "<a href=\"" . $waLink . "\" target=\"_blank\" style=\"color:#16a34a;font-weight:bold;text-decoration:none;margin-left:4px;display:inline-flex;align-items:center;gap:2px\" title=\"Chat WhatsApp\">💬 WA</a>";

    return "
      <tr data-id='$id'>
        <td><div style='display:flex;align-items:center;gap:7px'>
          <span style='font-weight:800;color:#2e7d32;font-size:12px;white-space:nowrap'>$code</span></div></td>
        <td>
            <div style='font-size:11px;color:#94a3b8;font-weight:600;margin-bottom:2px'>👤 ".htmlspecialchars($r['nama_pemohon'])."</div>
            <div style='font-weight:700;font-size:13px;color:#1e293b' title='Partner Name'>".htmlspecialchars($r['partner_name'] ?: '-')."</div>
            ".(!empty($r['place_name']) ? "<div style='font-size:11px;color:#1d4ed8;font-weight:600' title='Place Name'>".htmlspecialchars($r['place_name'])."</div>" : "")."
            ".(!empty($r['place_type']) ? "<div style='font-size:10px;color:#64748b' title='Place Type'>Type: ".htmlspecialchars($r['place_type'])."</div>" : "")."
            <div style='font-weight:700' title='Pickup Type'>".match($r['pickup_type']){'B'=>'Business','P'=>'Public','R'=>'Residential',default=>$r['pickup_type'] ?: '-' }."</div>
            <div style='font-size:10px;color:#94a3b8;margin-top:2px;display:flex;align-items:center;flex-wrap:wrap;gap:4px'>📞 ".htmlspecialchars(($r['area_code']??'+62').$r['nomor_wa'])." $waBtnHtml</div>
        </td>
        <td><div style='font-weight:600;font-size:12px'>".htmlspecialchars($r['kecamatan']??'-')."</div>
            <div style='font-size:10px;color:#16a34a' title='Geo location'>📍 GPS Tersimpan</div>
        </td>
        <td style='font-size:11px;color:#475569'>
            <div style='font-weight:700' title='Pickup Type'>".match($r['pickup_type']){'B'=>'Business','P'=>'Public','R'=>'Residential',default=>$r['pickup_type'] ?: '-' }."</div>
            <div style='font-size:10px;color:#94a3b8' title='Service Type'>".htmlspecialchars($r['service_type'] ?: 'Free')."</div>
        </td>
        <td style='font-size:11px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#64748b' title='Recycled Material'>".($r['jenis_sampah'] ? htmlspecialchars($r['jenis_sampah']) : '<span style="color:#e2e8f0">—</span>')."</td>
        <td><div class='berat-cell' style='flex-direction:column;align-items:flex-start;gap:2px'>
          <div style='display:flex;align-items:center;gap:6px'>
            $beratHtml$beratEditBtn
          </div>
          $payoutHtml
        </div></td>
        <td><span class='badge $sc'>$dot $sl</span></td>
        <td style='font-size:11px;white-space:nowrap'>$tglHtml</td>
        <td style='font-size:11px;color:#94a3b8;white-space:nowrap'>".fmtDate($r['created_at'],'d M Y')."<br><span style='color:#cbd5e1;font-size:10px'>".fmtDate($r['created_at'],'H:i')."</span></td>
        <td>$officerHtml</td>
        <td>
          <div class='actions-cell'>
            <a class='btn-icon' href='req_management.php?preview=$id' title='Preview'>👁️</a>
            $editBtn
            $confBtn
            $deleteBtn
          </div>
        </td>
      </tr>";
}
?>

<div class="card">

  <!-- Toolbar -->
  <div class="toolbar">
    <div class="toolbar-left">
      <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input class="search-input" name="q" type="text"
               placeholder="🔍 Cari nama / ID / WA / kecamatan..."
               value="<?= htmlspecialchars($search) ?>">
        <select class="filter-select" name="status" onchange="this.form.submit()">
          <option value="">Semua Status</option>
          <?php foreach ($statuses as $s): ?>
          <option value="<?= $s ?>" <?= $fStatus===$s?'selected':'' ?>>
            <?= ucfirst(str_replace('_',' ',$s)) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <select class="filter-select" name="kecamatan" onchange="this.form.submit()">
          <option value="">Semua Kecamatan</option>
          <?php foreach ($kecamatans as $k): ?>
          <option value="<?= $k ?>" <?= $fKec===$k?'selected':'' ?>><?= $k ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline">Cari</button>
        <?php if ($search||$fStatus||$fKec): ?>
          <a href="req_management.php" class="btn btn-outline">✕ Reset</a>
        <?php endif; ?>
      </form>
    </div>
    <div class="toolbar-right" style="display:flex;gap:8px">
      <button class="btn btn-outline" onclick="location.reload()" title="Refresh">🔄 Refresh</button>
      <button class="btn btn-primary" onclick="openModal('modalReq')">+ Tambah Request</button>
    </div>
  </div>

  <!-- ══ TABEL 1: ORDER AKTIF ══ -->
  <div style="font-size:13px;font-weight:700;color:#1e293b;margin:8px 0 8px;display:flex;align-items:center;gap:8px">
    📋 Order Aktif
    <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">
      <?= count($activeRequests) ?>
    </span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th><div style='font-size:10px'>ID (Nota)</div></th>
          <th>Pemohon & Partner/Place</th>
          <th>Sub-district</th>
          <th>Type & Service</th>
          <th>Recycled material</th>
          <th style="min-width:130px">⚖️ Weight & Price</th>
          <th>Status</th>
          <th>Date</th>
          <th>Timestamp</th>
          <th>👷 Staff ID (Officer)</th>
          <th style="min-width:140px">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($activeRequests): ?>
        <?php foreach ($activeRequests as $r): ?>
          <?= renderRow($r, false) ?>
        <?php endforeach; ?>
        <?php else: ?>
        <tr><td colspan="11" style="text-align:center;color:#94a3b8;padding:40px 0">
          <div style="font-size:36px;margin-bottom:8px">✅</div>
          <div style="font-weight:600">Tidak ada order aktif<?= ($search||$fStatus||$fKec) ? ' yang cocok dengan filter' : '' ?>.</div>
          <?php if ($search||$fStatus||$fKec): ?><a href="req_management.php" style="color:#16a34a;font-size:12px">Tampilkan semua</a><?php endif; ?>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ══ TABEL 2: RIWAYAT SELESAI / DIBATALKAN ══ -->
<div class="card" style="margin-top:8px">
  <div style="font-size:13px;font-weight:700;color:#1e293b;margin-bottom:12px;display:flex;align-items:center;gap:8px">
    ✅ Riwayat Selesai / Dibatalkan
    <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">
      <?= count($doneRequests) ?>
    </span>
    <span style="margin-left:auto;font-size:11px;font-weight:400;color:#94a3b8">Maks. 200 data terbaru</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Partner Name & Place</th>
          <th>Sub-district</th>
          <th>Type & Service</th>
          <th>Recycled material</th>
          <th style="min-width:130px">⚖️ Weight(kg) & Price</th>
          <th>Status</th>
          <th>Date (Done)</th>
          <th>Timestamp</th>
          <th>👷 Staff ID</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($doneRequests): ?>
        <?php foreach ($doneRequests as $r): ?>
          <?= renderRow($r, true) ?>
        <?php endforeach; ?>
        <?php else: ?>
        <tr><td colspan="11" style="text-align:center;color:#94a3b8;padding:32px 0">
          <div style="font-size:30px;margin-bottom:6px">📂</div>
          <div style="font-weight:600">Belum ada order yang selesai.</div>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div style="margin-top:10px;font-size:11px;color:#94a3b8;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <span>Aktif: <strong style="color:#334155"><?= count($activeRequests) ?></strong> | Selesai/Batal: <strong style="color:#334155"><?= count($doneRequests) ?></strong></span>
    <span>🔄 Auto-refresh setiap 60 detik</span>
  </div>
</div>


<!-- ═══════════════════════════════════════════════
     MODAL: INLINE EDIT BERAT
═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalBerat">
  <div class="modal" style="max-width:380px">
    <div class="modal-header">
      <h3>⚖️ Edit Berat Sampah</h3>
      <button class="modal-close" onclick="closeModal('modalBerat')">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">Berat Total (kg)</label>
        <input class="form-input" id="beratInput" type="number" step="0.01" min="0"
               placeholder="Contoh: 12.5">
        <span style="font-size:11px;color:#94a3b8;margin-top:4px;display:block">Kosongkan untuk hapus nilai berat</span>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modalBerat')">Batal</button>
      <button class="btn btn-primary" onclick="saveBerat()">💾 Simpan</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════
     MODAL: INLINE EDIT ITEM (estimasi/aktual/catatan)
═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalItem" style="z-index: 9999;">
  <div class="modal" style="max-width:420px">
    <div class="modal-header">
      <h3 id="modalItemTitle">✏️ Edit Data Item Sampah</h3>
      <button class="modal-close" onclick="closeModal('modalItem')">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-row" style="margin-bottom:12px">
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Estimasi (kg)</label>
          <input class="form-input" id="itemEstInput" type="number" step="0.01" min="0"
                 placeholder="Dari user">
          <span style="font-size:10px;color:#94a3b8;margin-top:3px;display:block">Diisi otomatis saat user submit</span>
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Aktual (kg)</label>
          <input class="form-input" id="itemAktInput" type="number" step="0.01" min="0"
                 placeholder="Setelah ditimbang" oninput="calculateModalTotal()">
          <span style="font-size:10px;color:#94a3b8;margin-top:3px;display:block">Diisi admin setelah penjemputan</span>
        </div>
      </div>
      <div class="form-row" style="margin-bottom:12px">
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Harga per Kilogram (Rp)</label>
          <input class="form-input" id="itemPriceInput" type="number" step="1" min="0"
                 placeholder="0" oninput="calculateModalTotal()">
          <span style="font-size:10px;color:#94a3b8;margin-top:3px;display:block">Harga per kg request</span>
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Total (Rp)</label>
          <input class="form-input" id="itemTotalInput" type="text" readonly
                 placeholder="0" style="background:#e2e8f0;cursor:not-allowed">
          <span style="font-size:10px;color:#94a3b8;margin-top:3px;display:block">Read-only (Aktual × Harga)</span>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Catatan Item</label>
        <textarea class="form-input" id="itemCatatanInput" rows="2"
                  placeholder="Kondisi, keterangan khusus, dll..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modalItem')">Batal</button>
      <button class="btn btn-primary" onclick="saveItem()">💾 Simpan</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════
     MODAL: TAMBAH / EDIT REQUEST
═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalReq" <?= $editData ? 'style="display:flex"' : '' ?>>
  <div class="modal" style="max-width:640px">
    <div class="modal-header">
      <h3><?= $editData ? '✏️ Edit Request '.htmlspecialchars($editData['request_code']??'') : '➕ Tambah Request Baru' ?></h3>
      <a href="req_management.php" class="modal-close">✕</a>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id"     value="<?= $editData['id'] ?? '' ?>">
      <?= csrfInput() ?>
      <div class="modal-body">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Nama Pemohon *</label>
            <input class="form-input" name="nama_pemohon" required
                   value="<?= htmlspecialchars($editData['nama_pemohon'] ?? '') ?>"
                   placeholder="Nama lengkap">
          </div>
          <div class="form-group">
            <label class="form-label">Nomor WA * <small style="text-transform:none;font-weight:500;color:#94a3b8">(tanpa +62)</small></label>
            <input class="form-input" name="nomor_wa" required
                   value="<?= htmlspecialchars($editData['nomor_wa'] ?? '') ?>"
                   placeholder="8xxx...">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Place Name</label>
            <input class="form-input" name="place_name"
                   value="<?= htmlspecialchars($editData['place_name'] ?? '') ?>"
                   placeholder="Rm Nyuknyang, dll">
          </div>
          <div class="form-group">
            <label class="form-label">Place Type</label>
            <select class="form-input" name="place_type">
              <option value="">-- Pilih Tipe Tempat --</option>
              <option value="F&B" <?= ($editData['place_type']??'')==='F&B'?'selected':'' ?>>F&B</option>
              <option value="Public" <?= ($editData['place_type']??'')==='Public'?'selected':'' ?>>Public</option>
              <option value="Household" <?= ($editData['place_type']??'')==='Household'?'selected':'' ?>>Household</option>
              <option value="Hospitality" <?= ($editData['place_type']??'')==='Hospitality'?'selected':'' ?>>Hospitality</option>
              <option value="Retail" <?= ($editData['place_type']??'')==='Retail'?'selected':'' ?>>Retail</option>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group" style="flex: 1 1 100%;">
            <label class="form-label">Partner Name</label>
            <input class="form-input" name="partner_name"
                   value="<?= htmlspecialchars($editData['partner_name'] ?? '') ?>"
                   placeholder="Esa Massing, Syuaib">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Service Type</label>
            <select class="form-input" name="service_type" id="adminServiceType" onchange="toggleAdminPrice()">
              <option value="Free" <?= ($editData['service_type']??'')==='Free'?'selected':'' ?>>Free</option>
              <option value="Paid" <?= ($editData['service_type']??'')==='Paid'?'selected':'' ?>>Paid</option>
            </select>
          </div>
          <div class="form-group" id="adminPriceGroup">
            <label class="form-label">Price per kg</label>
            <input class="form-input" name="price_per_kg" id="adminPrice" type="number" step="0.01" min="0"
                   value="<?= ($editData['price_per_kg'] !== null && $editData['price_per_kg'] !== '') ? htmlspecialchars($editData['price_per_kg']) : '' ?>"
                   placeholder="0">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Kecamatan *</label>
            <select class="form-input" name="kecamatan" required>
              <option value="">-- Pilih Kecamatan --</option>
              <?php foreach ($kecamatans as $k): ?>
              <option value="<?= $k ?>" <?= ($editData['kecamatan']??'')===$k?'selected':'' ?>><?= $k ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Kelurahan</label>
            <input class="form-input" name="kelurahan"
                   value="<?= htmlspecialchars($editData['kelurahan'] ?? '') ?>"
                   placeholder="Kelurahan / Lingkungan">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">
            Alamat Jemput *
            <span style="font-size:10px;color:#1976d2;font-weight:700;margin-left:6px;text-transform:none;letter-spacing:0">
              📍 Ketik untuk autocomplete (OpenStreetMap)
            </span>
          </label>
          <div style="position:relative">
            <textarea class="form-input" name="alamat_jemput" id="geoAlamatInput" required rows="2"
                      autocomplete="off" oninput="onAlamatInput()"
                      placeholder="Jalan, nomor rumah, patokan..."><?= htmlspecialchars($editData['alamat_jemput'] ?? '') ?></textarea>
            <!-- Suggestions dropdown -->
            <div id="geoSuggestions"
                 style="display:none;position:absolute;left:0;right:0;top:100%;z-index:300;
                        background:#fff;border:1.5px solid #bbdefb;border-top:none;
                        border-radius:0 0 8px 8px;box-shadow:0 6px 18px rgba(0,0,0,.12);
                        max-height:220px;overflow-y:auto"></div>
          </div>
          <!-- Hidden geo fields -->
          <input type="hidden" name="latitude"          id="geoLat"   value="<?= htmlspecialchars($editData['latitude'] ?? '') ?>">
          <input type="hidden" name="longitude"         id="geoLng"   value="<?= htmlspecialchars($editData['longitude'] ?? '') ?>">
          <input type="hidden" name="place_id"          id="geoPlid"  value="<?= htmlspecialchars($editData['place_id'] ?? '') ?>">
          <input type="hidden" name="formatted_address" id="geoFaddr" value="<?= htmlspecialchars($editData['formatted_address'] ?? '') ?>">
          <!-- GPS Status indicator -->
          <div id="geoStatus" style="margin-top:5px;font-size:11px;font-weight:700;display:flex;align-items:center;gap:6px">
            <?php if (!empty($editData['latitude']) && !empty($editData['longitude']) && floatval($editData['latitude']) != 0 && floatval($editData['longitude']) != 0): ?>
            <span style="color:#16a34a">✓ GPS: <?= number_format((float)$editData['latitude'],6) ?>, <?= number_format((float)$editData['longitude'],6) ?></span>
            <a href="https://maps.google.com/?q=<?= $editData['latitude'] ?>,<?= $editData['longitude'] ?>"
               target="_blank" style="color:#1976d2;font-size:10px">Lihat →</a>
            <?php else: ?>
            <span style="color:#94a3b8">GPS: belum ada koordinat</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Jenis Layanan</label>
            <select class="form-input" name="jenis_layanan">
              <option value="gratis">🟢 Penjemputan Reguler (GRATIS)</option>
              <option value="cleanup">🟠 Clean Up Service (Rp50.000/jam)</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <select class="form-input" name="status">
              <?php foreach ($statuses as $s): ?>
              <option value="<?= $s ?>" <?= ($editData['status']??'menunggu')===$s?'selected':'' ?>>
                <?= ucfirst(str_replace('_',' ',$s)) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group" style="grid-column: span 2">
            <label class="form-label">Tanggal Jemput</label>
            <input class="form-input" name="tanggal_jemput" type="date"
                   value="<?= $editData['tanggal_jemput'] ?? '' ?>">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Berat Total (kg)</label>
            <input class="form-input" name="berat_total_kg" type="number" step="0.01" min="0"
                   value="<?= !empty($editData['berat_total_kg']) ? $editData['berat_total_kg'] : (is_numeric($editData['berat_kg']??'') ? $editData['berat_kg'] : '') ?>"
                   placeholder="0.00">
            <span style="font-size:11px;color:#94a3b8;margin-top:3px">Langsung tampil di tabel admin</span>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Catatan</label>
          <textarea class="form-input" name="catatan" rows="2"
                    placeholder="Instruksi khusus, kondisi lokasi, dll..."><?= htmlspecialchars($editData['catatan'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">Catatan Petugas / Tugas</label>
          <textarea class="form-input" name="catatan_officer" rows="2"
                    placeholder="Catatan dari petugas lapangan atau instruksi tugas..."><?= htmlspecialchars($editData['catatan_officer'] ?? '') ?></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <a href="req_management.php" class="btn btn-outline">Batal</a>
        <button type="submit" class="btn btn-primary">💾 Simpan Request</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════
     MODAL: PREVIEW DETAIL
     Sinkron penuh: estimasi_kg dari user, aktual_kg dari admin
═══════════════════════════════════════════════ -->
<?php if ($previewData): ?>
<div class="modal-overlay open" id="modalPreview">
  <div class="modal" style="max-width:1100px; width:95%">
    <div class="modal-header">
      <h3>📋 Detail Request — <?= htmlspecialchars($previewData['request_code']??'') ?></h3>
      <a href="req_management.php" class="modal-close">✕</a>
    </div>
    <div class="modal-body">
      <!-- Hitung total estimasi dan aktual dari items jika ada -->
      <?php
      $totalEst = 0;
      $totalAkt = 0;
      foreach ($previewItems as $pi) {
          $totalEst += (float)($pi['estimasi_kg'] ?? 0);
          $totalAkt += (float)($pi['aktual_kg'] ?? 0);
      }
      if (empty($previewItems)) {
          $totalEst = (float)($previewData['berat_display'] ?? 0);
          $totalAkt = (float)($previewData['berat_display'] ?? 0);
      }
      $price = (float)($previewData['price_per_kg'] ?? 0);
      $totalEstPrice = $totalEst * $price;
      $totalAktPrice = $totalAkt * $price;
      
      $totalEstStr = (empty($previewItems) && isset($previewData['berat_kg']) && $previewData['berat_kg'] !== null && $previewData['berat_kg'] !== '') ? $previewData['berat_kg'] : (string)(float)$totalEst;
      $totalAktStr = (empty($previewItems) && isset($previewData['berat_kg']) && $previewData['berat_kg'] !== null && $previewData['berat_kg'] !== '') ? $previewData['berat_kg'] : (string)(float)$totalAkt;

      $pOfficerHtml = !empty($previewData['officer_nama'])
          ? '<span style="font-weight:700;color:#1e293b">'.htmlspecialchars($previewData['officer_nama']).'</span>'
            .' <span style="font-family:monospace;font-size:11px;color:#94a3b8">('.htmlspecialchars($previewData['officer_code_val']??'').')</span>'
          : '<span style="color:#cbd5e1;font-style:italic;font-size:12px">Belum ditugaskan</span>';
      ?>

      <div class="grid-2" style="margin-bottom: 16px;">
        
        <!-- Kolom Kiri: Informasi Pemohon & Lokasi -->
        <div>
          <div class="preview-card">
            <div class="preview-title">👤 Informasi Pemohon</div>
            <div class="preview-row">
              <span class="pl">ID Request</span>
              <span class="pv"><span style="color:var(--green-700,#2e7d32);font-size:14px;font-weight:900"><?= htmlspecialchars($previewData['request_code']??'') ?></span></span>
            </div>
            <div class="preview-row">
              <span class="pl">Nama Pemohon</span>
              <span class="pv"><?= htmlspecialchars($previewData['nama_pemohon']) ?></span>
            </div>
            <div class="preview-row">
              <span class="pl">Nomor WA</span>
              <span class="pv">
                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', ($previewData['area_code']??'+62').$previewData['nomor_wa']) ?>" target="_blank" style="color:#1976d2;text-decoration:none">
                  <?= htmlspecialchars(($previewData['area_code']??'+62').$previewData['nomor_wa']) ?> ↗
                </a>
              </span>
            </div>
            <div class="preview-row">
              <span class="pl">Tgl Masuk</span>
              <span class="pv" style="color:#64748b"><?= fmtDate($previewData['created_at'], 'd M Y H:i') ?></span>
            </div>
          </div>

          <div class="preview-card">
            <div class="preview-title">📍 Lokasi Penjemputan</div>
            <div class="preview-row">
              <span class="pl">Kecamatan</span>
              <span class="pv"><?= htmlspecialchars($previewData['kecamatan'] ?? '-') ?></span>
            </div>
            <div class="preview-row">
              <span class="pl">Kelurahan</span>
              <span class="pv"><?= htmlspecialchars($previewData['kelurahan'] ?? '-') ?></span>
            </div>
            <div class="preview-row">
              <span class="pl">Alamat Lengkap</span>
              <span class="pv"><?= htmlspecialchars($previewData['alamat_jemput'] ?? '-') ?></span>
            </div>
            <?php if (!empty($previewData['latitude']) && !empty($previewData['longitude']) && floatval($previewData['latitude']) != 0): ?>
            <div class="preview-row">
              <span class="pl">Peta GPS</span>
              <span class="pv">
                <a href="https://maps.google.com/?q=<?= $previewData['latitude'] ?>,<?= $previewData['longitude'] ?>" target="_blank" class="btn btn-outline" style="font-size:10px;padding:2px 8px;display:inline-flex;align-items:center;gap:4px">
                  🗺️ Google Maps ↗
                </a>
              </span>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Kolom Kanan: Status & Rincian Biaya -->
        <div>
          <div class="preview-card">
            <div class="preview-title">⚙️ Status & Layanan</div>
            <div class="preview-row">
              <span class="pl">Jenis Layanan</span>
              <span class="pv">
                <?php if (($previewData['jenis_layanan']??'') === 'cleanup'): ?>
                  <span class="badge" style="background:#ffedd5;color:#c2410c">🧹 Clean Up Service</span>
                <?php else: ?>
                  <span class="badge" style="background:#dcfce7;color:#166534">🟢 Penjemputan Reguler</span>
                <?php endif; ?>
              </span>
            </div>
            <div class="preview-row">
              <span class="pl">Tipe / Layanan</span>
              <span class="pv"><?= htmlspecialchars($previewData['pickup_type'] ?: '-') ?> (<?= htmlspecialchars($previewData['service_type'] ?: 'Free') ?>)</span>
            </div>
            <div class="preview-row">
              <span class="pl">Status</span>
              <span class="pv">
                <?php
                $s = $previewData['status'];
                $dot2 = match($s){'menunggu'=>'🟡','dikonfirmasi'=>'🔵','dijadwalkan'=>'🟣','dalam_perjalanan'=>'🛵','sedang_diproses'=>'🟠','selesai'=>'🟢','dibatalkan'=>'🔴',default=>'⚪'};
                ?>
                <span class="badge badge-<?= str_replace(' ','_',$s) ?>"><?= $dot2 ?> <?= ucfirst(str_replace('_',' ',$s)) ?></span>
              </span>
            </div>
            <div class="preview-row">
              <span class="pl">Petugas</span>
              <span class="pv"><?= $pOfficerHtml ?></span>
            </div>
            <div class="preview-row">
              <span class="pl">Catatan Pemohon</span>
              <span class="pv" style="font-weight:normal;font-style:italic"><?= nl2br(htmlspecialchars($previewData['catatan'] ?? '-')) ?></span>
            </div>
            <div class="preview-row">
              <span class="pl">Catatan Petugas</span>
              <span class="pv" style="font-weight:normal"><?= nl2br(htmlspecialchars($previewData['catatan_officer'] ?? '-')) ?></span>
            </div>
          </div>

          <div class="preview-card">
            <div class="preview-title">⚖️ Rincian Berat & Biaya</div>
            <div class="preview-row">
              <span class="pl">Harga per Kg</span>
              <span class="pv">Rp <?= number_format($price, 0, ',', '.') ?></span>
            </div>
            <div class="preview-row">
              <span class="pl">Total Estimasi</span>
              <span class="pv" style="color:#b45309">
                <?= htmlspecialchars($totalEstStr) ?> kg x Rp <?= number_format($price, 0, ',', '.') ?> = Rp <?= number_format($totalEstPrice, 0, ',', '.') ?>
              </span>
            </div>
            <div class="preview-row">
              <span class="pl">Hasil Payout (Aktual)</span>
              <span class="pv" style="color:#15803d">
                <?= htmlspecialchars($totalAktStr) ?> kg x Rp <?= number_format($price, 0, ',', '.') ?> = Rp <?= number_format($totalAktPrice, 0, ',', '.') ?>
              </span>
            </div>
          </div>
        </div>

      </div>

      <!-- ═══════════════════════════════════════════
           TABEL JENIS SAMPAH
           Sinkron penuh dengan user console:
           - Kategori: dari waste_categories (user pilih)
           - Estimasi kg: dibagi rata saat user submit
           - Aktual kg: admin isi setelah penjemputan
           - Catatan: admin isi per item
      ═══════════════════════════════════════════ -->
      <?php if (!empty($previewItems)): ?>
      <div class="items-section">
        <div class="items-section-title">
          ♻️ Jenis Sampah
          <span style="background:#e0f2fe;color:#0369a1;border-radius:20px;padding:1px 8px;font-size:10px;font-weight:700">
            <?= count($previewItems) ?> kategori
          </span>
          <span style="margin-left:auto;font-size:10px;color:#94a3b8;font-weight:500;text-transform:none;letter-spacing:0">
            🟡 Estimasi dari user &nbsp;·&nbsp; 🟢 Aktual dari admin
          </span>
        </div>

        <div class="table-wrap">
          <table class="items-table" id="itemsTable">
            <thead>
              <tr>
                <th style="text-align:left">Kategori</th>
                <th>Estimasi (kg)</th>
                <th>Aktual (kg)</th>
                <th>Harga/kg</th>
                <th>Total</th>
                <th style="text-align:left">Catatan</th>
                <?php if (true): ?>
                <th style="text-align:center;width:60px">Edit</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($previewItems as $pi):
                $hasEst  = ($pi['estimasi_kg'] !== null && $pi['estimasi_kg'] !== '');
                $hasAkt  = ($pi['aktual_kg']   !== null && $pi['aktual_kg']   !== '');
                $hasCat  = !empty($pi['item_catatan']);
                $price   = (float)($previewData['price_per_kg'] ?? 0);
                $total   = $hasAkt ? ((float)$pi['aktual_kg'] * $price) : 0;
              ?>
              <tr id="item-row-<?= $pi['item_id'] ?>">
                <!-- Kategori -->
                <td>
                  <div class="item-kat-cell">
                    <span class="item-kat-emoji"><?= htmlspecialchars($pi['ikon_emoji'] ?? '♻️') ?></span>
                    <span><?= htmlspecialchars($pi['kat_nama']) ?></span>
                  </div>
                </td>

                <!-- Estimasi kg (dari user, dibagi rata) -->
                <td>
                  <?php if ($hasEst): ?>
                    <span class="kg-val kg-est" id="est-<?= $pi['item_id'] ?>">
                      <?= number_format((float)$pi['estimasi_kg'], 2) ?>
                    </span>
                  <?php else: ?>
                    <span class="kg-none" id="est-<?= $pi['item_id'] ?>">—</span>
                  <?php endif; ?>
                </td>

                <!-- Aktual kg (admin isi setelah penjemputan) -->
                <td>
                  <?php if ($hasAkt): ?>
                    <span class="kg-val kg-akt" id="akt-<?= $pi['item_id'] ?>">
                      <?= number_format((float)$pi['aktual_kg'], 2) ?>
                    </span>
                  <?php else: ?>
                    <span class="kg-none" id="akt-<?= $pi['item_id'] ?>">—</span>
                  <?php endif; ?>
                </td>

                <!-- Harga per Kilogram -->
                <td>
                  <span class="price-val" id="price-<?= $pi['item_id'] ?>">
                    Rp <?= number_format($price, 0, ',', '.') ?>
                  </span>
                </td>

                <!-- Total -->
                <td>
                  <span class="total-val" id="total-<?= $pi['item_id'] ?>" style="font-weight:700">
                    Rp <?= number_format($total, 0, ',', '.') ?>
                  </span>
                </td>

                <!-- Catatan item -->
                <td>
                  <span class="item-cat-note <?= $hasCat ? 'has-note' : '' ?>"
                        id="cat-<?= $pi['item_id'] ?>"
                        title="<?= htmlspecialchars($pi['item_catatan'] ?? '') ?>">
                    <?= $hasCat ? htmlspecialchars($pi['item_catatan']) : '—' ?>
                  </span>
                </td>

                <!-- Tombol edit item -->
                <?php if (true): ?>
                <td style="text-align:center">
                  <button class="item-edit-btn"
                          onclick="openItemEdit(
                            <?= $pi['item_id'] ?>,
                            '<?= htmlspecialchars($pi['kat_nama']) ?>',
                            <?= $hasEst ? (float)$pi['estimasi_kg'] : 'null' ?>,
                            <?= $hasAkt ? (float)$pi['aktual_kg']   : 'null' ?>,
                            '<?= htmlspecialchars(addslashes($pi['item_catatan'] ?? '')) ?>',
                            <?= $price ?>
                          )">
                    ✏️ Edit
                  </button>
                </td>
                <?php endif; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Legend -->
        <?php
        $totalEst = 0;
        $totalAkt = 0;
        foreach ($previewItems as $pi) {
            $totalEst += (float)($pi['estimasi_kg'] ?? 0);
            $totalAkt += (float)($pi['aktual_kg'] ?? 0);
        }
        ?>
        <div style="display:flex;gap:16px;margin-top:8px;font-size:10px;color:#94a3b8;font-weight:600;flex-wrap:wrap">
          <span>🟡 <span class="kg-val kg-est" style="padding:1px 6px;font-size:10px" id="legendTotalEst"><?= number_format($totalEst, 2) ?></span> Estimasi — dihitung otomatis saat user submit (berat total ÷ jumlah kategori)</span>
          <span>🟢 <span class="kg-val kg-akt" style="padding:1px 6px;font-size:10px" id="legendTotalAkt"><?= number_format($totalAkt, 2) ?></span> Aktual — diisi admin setelah penjemputan</span>
        </div>
      </div>
      <?php else: ?>
      <!-- Tidak ada items (request lama sebelum fitur item) -->
      <div class="items-section">
        <div class="items-section-title">♻️ Jenis Sampah</div>
        <div style="background:#f8fafc;border:1px dashed #e2e8f0;border-radius:10px;padding:20px;text-align:center;color:#94a3b8;font-size:12px">
          <div style="font-size:28px;margin-bottom:6px">📦</div>
          <div style="font-weight:600">Tidak ada data kategori sampah untuk request ini.</div>
          <div style="margin-top:4px;font-size:11px">Data kategori tersimpan mulai dari versi terbaru user console.</div>
        </div>
      </div>
      <?php endif; ?>

    </div>
    <div class="modal-footer">
      <a href="req_management.php" class="btn btn-outline">Tutup</a>
      <?php if (!in_array($previewData['status'], ['selesai', 'dibatalkan'])): ?>
      <a href="req_management.php?edit=<?= $previewData['id'] ?>" class="btn btn-outline">✏️ Edit</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════
     MODAL: ASSIGN PETUGAS (SINGLE)
═══════════════════════════════════════════════ -->


<!-- ═══════════════════════════════════════════════
     MODAL: BATCH ASSIGN
═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalBatchAssign">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <h3>👷 Batch Assign — <span id="batchReqCount" style="color:var(--green-dark)">0</span> Request</h3>
      <button class="modal-close" onclick="closeModal('modalBatchAssign')">✕</button>
    </div>
    <div class="modal-body">
      <!-- Daftar request terpilih -->
      <div id="batchSelectedList"
           style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;margin-bottom:14px;max-height:120px;overflow-y:auto;font-size:12px">
      </div>

      <div class="form-group">
        <label class="form-label">Assign ke Petugas *</label>
        <select class="form-input" id="batchOfficerId">
          <option value="">— Pilih Petugas —</option>
          <?php // officers loop removed ?>
        </select>
      </div>

      <!-- Catatan -->
      <div class="form-group" style="margin-top:10px">
        <label class="form-label">Catatan Tugas</label>
        <textarea class="form-input" id="batchCatatan" rows="2"
                  placeholder="Instruksi untuk semua request yang dipilih..."></textarea>
      </div>

      <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;font-size:12px;color:#92400e;font-weight:600">
        ⚠️ Status semua request terpilih akan diubah menjadi <strong>Dijadwalkan</strong>.
        Request yang sudah Selesai atau Dibatalkan tidak akan terpengaruh.
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modalBatchAssign')">Batal</button>
      <button class="btn btn-assign" id="btnDoBatchAssign" onclick="doBatchAssign()">
        👷 Assign Semua
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════
     MODAL: HAPUS
═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalDelete">
  <div class="modal" style="max-width:400px">
    <div class="modal-header">
      <h3>🗑️ Konfirmasi Hapus</h3>
      <button class="modal-close" onclick="closeModal('modalDelete')">✕</button>
    </div>
    <div class="modal-body">
      <p style="font-size:14px;color:#334155;margin:0;font-weight:600" id="deleteMsg">
        Apakah Anda yakin ingin menghapus request ini?
      </p>
      <p style="font-size:12px;color:#ef4444;margin-top:8px;font-weight:600">
        ⚠️ Data yang dihapus tidak dapat dikembalikan.
      </p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modalDelete')">Batal</button>
      <form method="POST" id="deleteForm" style="display:inline">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id"     id="deleteId">
        <?= csrfInput() ?>
        <button type="submit" class="btn btn-danger">Ya, Hapus</button>
      </form>
    </div>
  </div>
</div>

<script>
/* ── Modal helpers ── */
function openModal(id){
  document.getElementById(id).style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function closeModal(id){
  document.getElementById(id).style.display = 'none';
  document.body.style.overflow = '';
}
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape')
    document.querySelectorAll('.modal-overlay[style*="display:flex"]').forEach(m => closeModal(m.id));
});

/* ── Delete modal ── */
function openDeleteModal(id, code) {
  document.getElementById('deleteMsg').textContent =
    'Apakah Anda yakin ingin menghapus request ' + code + '?';
  document.getElementById('deleteId').value = id;
  openModal('modalDelete');
}




/* ── Inline edit berat (AJAX) ── */
let _beratId = null;
function inlineEditBerat(id, current) {
  _beratId = id;
  const inp = document.getElementById('beratInput');
  inp.value = (current !== null && current !== undefined && !isNaN(current)) ? current : '';
  openModal('modalBerat');
  setTimeout(() => inp.focus(), 120);
}
async function saveBerat() {
  if (!_beratId) return;
  const val = document.getElementById('beratInput').value.trim();
  const fd  = new FormData();
  fd.append('action', 'update_berat');
  fd.append('id',     _beratId);
  fd.append('berat',  val);
  fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
  try {
    const res  = await fetch('req_management.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      const el = document.getElementById('berat-display-' + _beratId);
      if (el) {
        if (data.berat !== null && parseFloat(data.berat) > 0) {
          el.className   = 'berat-badge';
          el.textContent = parseFloat(data.berat).toFixed(2) + ' kg';
          el.style       = '';
        } else {
          el.className   = 'berat-na';
          el.textContent = 'belum diisi';
          el.style       = '';
        }
      }
      closeModal('modalBerat');
    }
  } catch (e) { alert('Gagal menyimpan berat. Silakan coba lagi.'); }
}
document.getElementById('beratInput').addEventListener('keydown', e => {
  if (e.key === 'Enter') saveBerat();
});

/* ── Inline edit item (AJAX) ── */
let _itemId = null;
function calculateModalTotal() {
  const akt = parseFloat(document.getElementById('itemAktInput').value) || 0;
  const price = parseFloat(document.getElementById('itemPriceInput').value) || 0;
  const total = akt * price;
  document.getElementById('itemTotalInput').value = 'Rp ' + total.toLocaleString('id-ID');
}
function openItemEdit(itemId, katNama, estKg, aktKg, catatan, price) {
  _itemId = itemId;
  document.getElementById('modalItemTitle').textContent = '✏️ Edit: ' + katNama;
  document.getElementById('itemEstInput').value    = (estKg !== null) ? estKg : '';
  document.getElementById('itemAktInput').value    = (aktKg !== null) ? aktKg : '';
  document.getElementById('itemPriceInput').value  = (price !== null && price !== undefined) ? price : '';
  document.getElementById('itemCatatanInput').value = catatan || '';
  calculateModalTotal();
  openModal('modalItem');
  setTimeout(() => document.getElementById('itemAktInput').focus(), 120);
}
async function saveItem() {
  if (!_itemId) return;
  const fd = new FormData();
  fd.append('action',      'update_item');
  fd.append('item_id',     _itemId);
  fd.append('estimasi_kg', document.getElementById('itemEstInput').value.trim());
  fd.append('aktual_kg',   document.getElementById('itemAktInput').value.trim());
  fd.append('price_per_kg', document.getElementById('itemPriceInput').value.trim());
  fd.append('catatan',     document.getElementById('itemCatatanInput').value.trim());
  fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
  try {
    const res  = await fetch('req_management.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      closeModal('modalItem');
      location.reload();
      return;
      // Update tampilan inline tanpa reload
      const estVal = document.getElementById('itemEstInput').value.trim();
      const aktVal = document.getElementById('itemAktInput').value.trim();
      const catVal = document.getElementById('itemCatatanInput').value.trim();

      const estEl = document.getElementById('est-' + _itemId);
      if (estEl) {
        if (estVal !== '' && !isNaN(parseFloat(estVal))) {
          estEl.className   = 'kg-val kg-est';
          estEl.textContent = parseFloat(estVal).toFixed(2);
        } else {
          estEl.className   = 'kg-none';
          estEl.textContent = '—';
        }
      }
      const aktEl = document.getElementById('akt-' + _itemId);
      if (aktEl) {
        if (aktVal !== '' && !isNaN(parseFloat(aktVal))) {
          aktEl.className   = 'kg-val kg-akt';
          aktEl.textContent = parseFloat(aktVal).toFixed(2);
        } else {
          aktEl.className   = 'kg-none';
          aktEl.textContent = '—';
        }
      }
      const catEl = document.getElementById('cat-' + _itemId);
      if (catEl) {
        catEl.textContent = catVal || '—';
        catEl.className   = 'item-cat-note' + (catVal ? ' has-note' : '');
        catEl.title       = catVal;
      }
      
      // Update Legend Totals dynamically
      let sumEst = 0;
      document.querySelectorAll('#itemsTable tbody tr').forEach(row => {
        const estValSpan = row.querySelector('.kg-est');
        if (estValSpan) {
          sumEst += parseFloat(estValSpan.textContent) || 0;
        }
      });
      const legendEst = document.getElementById('legendTotalEst');
      if (legendEst) legendEst.textContent = sumEst.toFixed(2);

      let sumAkt = 0;
      document.querySelectorAll('#itemsTable tbody tr').forEach(row => {
        const aktValSpan = row.querySelector('.kg-akt');
        if (aktValSpan) {
          sumAkt += parseFloat(aktValSpan.textContent) || 0;
        }
      });
      const legendAkt = document.getElementById('legendTotalAkt');
      if (legendAkt) legendAkt.textContent = sumAkt.toFixed(2);

      closeModal('modalItem');
    }
  } catch (e) { alert('Gagal menyimpan data item. Silakan coba lagi.'); }
}


</script>

<?php // Nominatim Autocomplete — gratis, tanpa API Key ?>
<script>
let _debounceTimer = null;

function onAlamatInput() {
  clearTimeout(_debounceTimer);
  clearGeoFields();
  const val = document.getElementById('geoAlamatInput').value.trim();
  if (val.length < 4) { hideSuggestions(); return; }
  _debounceTimer = setTimeout(() => {
    fetch('https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(val + ', Manado, Sulawesi Utara') + '&countrycodes=id&limit=5&addressdetails=1&viewbox=124.7,1.35,124.95,1.65&bounded=1')
      .then(r => r.json())
      .then(preds => {
        if (!preds || !preds.length) { hideSuggestions(); return; }
        renderSuggestions(preds);
      })
      .catch(() => hideSuggestions());
  }, 400);
}

function renderSuggestions(preds) {
  const box = document.getElementById('geoSuggestions');
  box.innerHTML = '';
  preds.forEach(p => {
    const div = document.createElement('div');
    div.style.cssText = 'padding:10px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid #f0f0f0;display:flex;align-items:flex-start;gap:8px;transition:background .1s';
    const mainText = p.display_name.split(',')[0];
    const subText = p.display_name.split(',').slice(1,3).join(',');
    div.innerHTML = `<span style="margin-top:1px;flex-shrink:0">📍</span>
      <div>
        <div style="font-weight:700;color:#1e293b">${mainText}</div>
        <div style="font-size:11px;color:#94a3b8">${subText}</div>
      </div>`;
    div.addEventListener('mouseenter', () => div.style.background = '#e3f2fd');
    div.addEventListener('mouseleave', () => div.style.background = '');
    div.addEventListener('mousedown', e => { e.preventDefault(); selectPlace(p); });
    box.appendChild(div);
  });
  box.style.display = 'block';
}

function hideSuggestions() {
  document.getElementById('geoSuggestions').style.display = 'none';
}

function selectPlace(place) {
  hideSuggestions();
  const clean = place.display_name
    .replace(/, Kota Manado, Sulawesi Utara, Indonesia/g, '')
    .replace(/, Indonesia/g, '');
  document.getElementById('geoAlamatInput').value = clean;

  const lat = parseFloat(place.lat);
  const lng = parseFloat(place.lon);
  document.getElementById('geoLat').value = lat;
  document.getElementById('geoLng').value = lng;
  document.getElementById('geoPlid').value = place.osm_id || '';
  document.getElementById('geoFaddr').value = place.display_name || '';

  const statusEl = document.getElementById('geoStatus');
  statusEl.innerHTML = `<span style="color:#16a34a">✓ GPS: ${lat.toFixed(6)}, ${lng.toFixed(6)}</span>
    <a href="https://www.google.com/maps?q=${lat},${lng}" target="_blank"
       style="color:#1976d2;font-size:10px">Lihat →</a>`;

  // Auto-isi kecamatan jika kosong
  if (place.address) {
    const kecSelect = document.querySelector('select[name="kecamatan"]');
    const kecMap = {
      'wenang':'wenang','malalayang':'malalayang','tikala':'tikala',
      'paal dua':'paal_dua','bunaken':'bunaken','singkil':'singkil',
      'mapanget':'mapanget','wanea':'wanea','sario':'sario',
      'tuminting':'tuminting','paal empat':'paal_empat',
      'bunaken kepulauan':'bunaken_kepulauan'
    };
    const addrLow = place.display_name.toLowerCase();
    if (kecSelect && !kecSelect.value) {
      for (const [kw,val] of Object.entries(kecMap)) {
        if (addrLow.includes(kw)) { kecSelect.value = val; break; }
      }
    }
    const kelInput = document.querySelector('input[name="kelurahan"]');
    if (kelInput && !kelInput.value && place.address.suburb) {
      kelInput.value = place.address.suburb;
    }
  }
}

function clearGeoFields() {
  document.getElementById('geoLat').value = '';
  document.getElementById('geoLng').value = '';
  document.getElementById('geoPlid').value = '';
  document.getElementById('geoFaddr').value = '';
  const statusEl = document.getElementById('geoStatus');
  if (statusEl) statusEl.innerHTML = '<span style="color:#94a3b8">GPS: belum ada koordinat</span>';
}

document.addEventListener('click', e => {
  const inp  = document.getElementById('geoAlamatInput');
  const sugg = document.getElementById('geoSuggestions');
  if (inp && sugg && !inp.contains(e.target) && !sugg.contains(e.target)) {
    hideSuggestions();
  }
});

function toggleAdminPrice() {
  const svcEl = document.getElementById('adminServiceType');
  if (!svcEl) return;
  const svc = svcEl.value;
  const priceInput = document.getElementById('adminPrice');
  const priceGroup = document.getElementById('adminPriceGroup');
  if (svc === 'Free') {
    if (priceInput) priceInput.value = '0';
    if (priceGroup) priceGroup.style.display = 'none';
  } else {
    if (priceGroup) priceGroup.style.display = 'block';
  }
}
document.addEventListener('DOMContentLoaded', toggleAdminPrice);
toggleAdminPrice();
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
