<?php
// ============================================================
//  admin/weighing_records.php — Admin Panel: Rekaman Hasil Timbang
//  Manado Recycle Hub
//  Displays weighing logs for both Pickups & Cleanups with CSV Export
// ============================================================
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/auth.php';
requireRole('admin');
$page_id    = 'weighing_records';
$page_title = 'Rekam Angkut';
$db         = getDB();
$csrfToken  = csrfToken();


// ── AJAX DETAIL HANDLER ────────────────────────────────────────
if (isset($_GET['ajax_detail'])) {
    header('Content-Type: application/json');
    $record_id = (int)($_GET['ajax_detail'] ?? 0);
    $stmtDetail = $db->prepare("
        SELECT wr.*, 
               o.nama AS officer_nama,
               COALESCE(pr.request_code, cr.request_code) AS request_code,
               COALESCE(pr.nama_pemohon, cr.nama_pemohon) AS nama_pemohon,
               COALESCE(pr.nomor_wa, cr.nomor_wa) AS nomor_wa,
               COALESCE(pr.alamat_jemput, cr.alamat_jemput) AS alamat_jemput,
               COALESCE(pr.kecamatan, cr.kecamatan) AS kecamatan,
               COALESCE(pr.kelurahan, cr.kelurahan) AS kelurahan,
               pr.price_per_kg,
               pr.berat_kg AS berat_raw,
               COALESCE(pr.status, cr.status) AS status
        FROM weighing_records wr
        LEFT JOIN officers o ON o.id = wr.officer_id
        LEFT JOIN pickup_requests pr ON pr.id = wr.pickup_request_id
        LEFT JOIN cleanup_requests cr ON cr.id = wr.cleanup_request_id
        WHERE wr.id = ?
    ");
    $stmtDetail->execute([$record_id]);
    $rec = $stmtDetail->fetch(PDO::FETCH_ASSOC);
    if ($rec) {
        $items = [];
        if ($rec['pickup_request_id']) {
            $stmtItems = $db->prepare("
                SELECT pri.id AS item_id, wc.name AS category_name, wc.ikon_emoji, pri.estimasi_kg, pri.aktual_kg AS berat_kg, pri.catatan
                FROM pickup_request_items pri
                JOIN waste_categories wc ON wc.id = pri.category_id
                WHERE pri.pickup_id = ?
            ");
            $stmtItems->execute([$rec['pickup_request_id']]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($rec['cleanup_request_id']) {
            $stmtItems = $db->prepare("
                SELECT ci.id AS item_id, wc.name AS category_name, wc.ikon_emoji, NULL AS estimasi_kg, ci.berat_kg, ci.catatan
                FROM cleanup_items ci
                JOIN waste_categories wc ON wc.id = ci.category_id
                WHERE ci.cleanup_id = ?
            ");
            $stmtItems->execute([$rec['cleanup_request_id']]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        }
        $rec['items'] = $items;
        echo json_encode(['ok' => true, 'data' => $rec]);
    } else {
        echo json_encode(['ok' => false, 'message' => 'Data tidak ditemukan.']);
    }
    exit;
}

// ── UPDATE ITEM HANDLER (AJAX) ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_item') {
    requireCsrfToken();
    $item_id    = (int)($_POST['item_id']    ?? 0);
    $is_cleanup = (int)($_POST['is_cleanup']  ?? 0);
    $estimasi   = trim($_POST['estimasi_kg'] ?? '');
    $aktual     = trim($_POST['aktual_kg']   ?? '');
    $price      = trim($_POST['price_per_kg'] ?? '');
    $cat        = trim($_POST['catatan']      ?? '');
    
    $est_val    = ($estimasi !== '') ? (float)str_replace(',', '.', $estimasi) : null;
    $akt_val    = ($aktual   !== '') ? (float)str_replace(',', '.', $aktual)   : null;
    $price_val  = ($price    !== '') ? (float)str_replace(',', '.', $price)    : null;

    if ($is_cleanup) {
        $db->prepare("UPDATE cleanup_items SET berat_kg=?, catatan=? WHERE id=?")
           ->execute([$akt_val, ($cat !== '' ? $cat : null), $item_id]);
        
        $cleanup_id = (int)$db->query("SELECT cleanup_id FROM cleanup_items WHERE id = $item_id")->fetchColumn();
        if ($cleanup_id) {
            $total_akt = $db->query("SELECT SUM(berat_kg) FROM cleanup_items WHERE cleanup_id = $cleanup_id")->fetchColumn();
            if ($total_akt !== null) {
                // For cleanup, update the total weight on weighing record
                recordCleanupWeighing($db, $cleanup_id);
            }
        }
    } else {
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
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// ── DELETE RECORD HANDLER ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_record') {
    $record_id = (int)($_POST['id'] ?? 0);
    if ($record_id > 0) {
        $stmtRecord = $db->prepare("SELECT * FROM weighing_records WHERE id = ?");
        $stmtRecord->execute([$record_id]);
        $record = $stmtRecord->fetch(PDO::FETCH_ASSOC);
        
        if ($record) {
            try {
                $db->prepare("DELETE FROM weighing_records WHERE id = ?")->execute([$record_id]);
                flash('success', 'Rekaman timbang berhasil dihapus secara permanen.');
            } catch (Exception $e) {
                flash('danger', 'Gagal menghapus rekaman: ' . $e->getMessage());
            }
        }
    }
    header('Location: weighing_records.php');
    exit;
}

// ── GET FILTERS ──────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$start_date = trim($_GET['start_date'] ?? '');
$end_date = trim($_GET['end_date'] ?? '');

// Build query
$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(wr.nama_entitas LIKE ? OR wr.jenis_sampah LIKE ? OR pr.request_code LIKE ? OR cr.request_code LIKE ? OR pr.nama_pemohon LIKE ? OR pr.nomor_wa LIKE ? OR cr.nama_pemohon LIKE ? OR cr.nomor_wa LIKE ?)";
    $q = "%$search%";
    $params[] = $q;
    $params[] = $q;
    $params[] = $q;
    $params[] = $q;
    $params[] = $q;
    $params[] = $q;
    $params[] = $q;
    $params[] = $q;
}
if ($start_date !== '') {
    $where[] = "wr.tanggal_timbang >= ?";
    $params[] = $start_date;
}
if ($end_date !== '') {
    $where[] = "wr.tanggal_timbang <= ?";
    $params[] = $end_date;
}

$where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

$query_str = "
    SELECT wr.*, 
           o.nama AS officer_nama,
           o.officer_code,
           COALESCE(pr.request_code, cr.request_code) AS request_code,
           COALESCE(pr.kecamatan, cr.kecamatan) AS kecamatan,
           COALESCE(pr.kelurahan, cr.kelurahan) AS kelurahan,
           pr.nama_pemohon AS pickup_warga,
           pr.nomor_wa AS pickup_wa,
           cr.nama_pemohon AS cleanup_warga,
           cr.nomor_wa AS cleanup_wa,
           CASE 
               WHEN wr.pickup_request_id IS NOT NULL THEN 'Pickup Sampah'
               WHEN wr.cleanup_request_id IS NOT NULL THEN 'Clean Up Service'
               ELSE 'Manual / Lainnya'
           END AS tipe_layanan,
           -- New columns for Excel/PDF consistency
           pr.place_name,
           pr.place_type,
           pr.partner_name,
           pr.pickup_type,
           pr.service_type,
           pr.berat_kg AS berat_raw,
           COALESCE(pr.price_per_kg, 0) AS price_per_kg,
           COALESCE(pr.catatan, cr.catatan) AS catatan,
           COALESCE(pr.latitude, cr.latitude) AS latitude,
           COALESCE(pr.longitude, cr.longitude) AS longitude,
           COALESCE(pr.tanggal_jemput, cr.tanggal_tugas) AS tanggal_tugas
    FROM weighing_records wr
    LEFT JOIN officers o ON o.id = wr.officer_id
    LEFT JOIN pickup_requests pr ON pr.id = wr.pickup_request_id
    LEFT JOIN cleanup_requests cr ON cr.id = wr.cleanup_request_id
    $where_sql
    ORDER BY wr.tanggal_timbang DESC, wr.id DESC
";

// Helper function to format decimal lat/lng to degrees minutes seconds (DMS)
if (!function_exists('decToDms')) {
    function decToDms($lat, $lng) {
        if (empty($lat) || empty($lng)) return '-';
        $lat = (float)$lat;
        $lng = (float)$lng;
        
        $lat_dir = ($lat >= 0) ? 'N' : 'S';
        $lat = abs($lat);
        $lat_deg = floor($lat);
        $lat_min = floor(($lat - $lat_deg) * 60);
        $lat_sec = round((($lat - $lat_deg) * 60 - $lat_min) * 60, 1);
        
        $lng_dir = ($lng >= 0) ? 'E' : 'W';
        $lng = abs($lng);
        $lng_deg = floor($lng);
        $lng_min = floor(($lng - $lng_deg) * 60);
        $lng_sec = round((($lng - $lng_deg) * 60 - $lng_min) * 60, 1);
        
        return sprintf("%d°%d'%.1f\"%s %d°%d'%.1f\"%s", 
            $lat_deg, $lat_min, $lat_sec, $lat_dir,
            $lng_deg, $lng_min, $lng_sec, $lng_dir
        );
    }
}

// ── EXPORT HANDLER ────────────────────────────────────────────
if (isset($_GET['export'])) {
    $stmt = $db->prepare($query_str);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $export_type = $_GET['export'];

    if ($export_type === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename=rekaman_timbang_' . date('Y-m-d_H-i') . '.xls');
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head><meta charset="utf-8">';
        echo '<!--[if gte mso 9]><xml>';
        echo '<x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
        echo '<x:Name>Rekaman Timbang</x:Name>';
        echo '<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>';
        echo '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook>';
        echo '</xml><![endif]--><style>';
        echo 'table, th, td { font-family: Calibri, Arial, sans-serif; font-size: 11pt; }';
        echo 'table { border-collapse: collapse; }';
        echo 'th { background-color: #ffffff; color: #000000; font-weight: bold; border: 1px solid #000000; padding: 6px; text-align: left; vertical-align: top; white-space: normal; }';
        echo 'td { border: 1px solid #000000; padding: 6px; text-align: left; vertical-align: top; }';
        echo '.number { mso-number-format:"General"; text-align: right; }';
        echo '.text { mso-number-format:"\@"; }';
        echo '</style></head><body>';
        echo '<h3 style="font-family: Calibri, Arial, sans-serif; font-size: 16pt; font-weight: bold; margin: 0 0 10px 0; color: #000000;">REKAMAN HASIL TIMBANG - MANADO RECYCLE HUB</h3>';
        if ($start_date || $end_date) {
            echo '<p style="font-family: Calibri, Arial, sans-serif; font-size: 11pt; margin: 0 0 15px 0; color: #475569;">Periode: ' . ($start_date ?: 'Awal') . ' s/d ' . ($end_date ?: 'Akhir') . '</p>';
        }
        echo '<table>';
        echo '<thead><tr>';
        echo '<th>Timestamp</th>';
        echo '<th>Date</th>';
        echo '<th>Sub-district</th>';
        echo '<th>Place Name</th>';
        echo '<th>Place Type</th>';
        echo '<th>Partner Name</th>';
        echo '<th>ID</th>';
        echo '<th>Phone Number</th>';
        echo '<th>Staff ID</th>';
        echo '<th>Type</th>';
        echo '<th>Service Type</th>';
        echo '<th>Weight (kg)</th>';
        echo '<th>Price per kg</th>';
        echo '<th>Total Harga</th>';
        echo '<th>Notes</th>';
        echo '<th>Recycled Material</th>';
        echo '<th>Geo Location</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        foreach ($records as $r) {
            $timestamp = date('m/d/Y H.i', strtotime($r['created_at']));
            $date = $r['tanggal_tugas'] ? date('n/j/Y', strtotime($r['tanggal_tugas'])) : '-';
            $sub_district = htmlspecialchars($r['kecamatan'] ?? '-');
            $place_name = htmlspecialchars($r['place_name'] ?? '-');
            $place_type = htmlspecialchars($r['place_type'] ?? '-');
            $partner_name = htmlspecialchars($r['partner_name'] ?? '-');
            $id_code = htmlspecialchars($r['request_code'] ?? '-');
            $phone = htmlspecialchars($r['pickup_wa'] ?: $r['cleanup_wa'] ?: '-');
            $staff_id = htmlspecialchars($r['officer_code'] ?? '-');
            $type = htmlspecialchars($r['pickup_type'] ?? '-');
            $service_type = htmlspecialchars($r['service_type'] ?: ($r['cleanup_request_id'] ? 'Paid' : '-'));
            $w_str = ($r['berat_raw'] !== null && $r['berat_raw'] !== '') ? $r['berat_raw'] : (string)(float)$r['berat_kg'];
            $weight = $w_str;
            $price = number_format($r['price_per_kg'], 0, '', '');
            $notes = htmlspecialchars($r['catatan'] ?? '');
            $recycled_material = htmlspecialchars($r['jenis_sampah'] ?? '-');
            $geo = decToDms($r['latitude'], $r['longitude']);

            $is_pickup = !empty($r['pickup_request_id']);
            $total_harga_val = $is_pickup ? (float)$r['berat_kg'] * (float)$r['price_per_kg'] : 0;

            echo '<tr>';
            echo '<td>' . $timestamp . '</td>';
            echo '<td>' . $date . '</td>';
            echo '<td>' . $sub_district . '</td>';
            echo '<td>' . $place_name . '</td>';
            echo '<td>' . $place_type . '</td>';
            echo '<td>' . $partner_name . '</td>';
            echo '<td class="text">' . $id_code . '</td>';
            echo '<td class="text">' . $phone . '</td>';
            echo '<td class="text">' . $staff_id . '</td>';
            echo '<td>' . $type . '</td>';
            echo '<td>' . $service_type . '</td>';
            echo '<td class="text">' . $weight . '</td>';
            echo '<td class="text">' . $price . '</td>';
            echo '<td class="number">' . number_format($total_harga_val, 0, '', '') . '</td>';
            echo '<td>' . $notes . '</td>';
            echo '<td>' . $recycled_material . '</td>';
            echo '<td>' . $geo . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    } elseif ($export_type === 'pdf') {
        $total_weight = 0;
        $total_payout = 0;
        foreach ($records as $r) {
            $total_weight += (float)$r['berat_kg'];
            if (!empty($r['pickup_request_id'])) {
                $total_payout += (float)$r['berat_kg'] * (float)$r['price_per_kg'];
            }
        }
        ?>
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8">
            <title>Laporan Hasil Timbang — <?= SITE_NAME ?></title>
            <style>
                @page { size: landscape; margin: 8mm; }
                body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; margin: 10px; line-height: 1.3; font-size: 8px; }
                .header-container { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #1c6434; padding-bottom: 10px; margin-bottom: 15px; }
                .logo-area h1 { font-size: 18px; color: #1c6434; margin: 0 0 3px 0; font-weight: 800; letter-spacing: 0.5px; }
                .logo-area p { margin: 0; font-size: 10px; color: #666; }
                .report-title { text-align: right; }
                .report-title h2 { font-size: 16px; color: #2d3748; margin: 0 0 3px 0; font-weight: 700; }
                .report-title p { margin: 0; font-size: 9px; color: #718096; }
                
                .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; }
                .meta-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; }
                .meta-box h3 { margin: 0 0 8px 0; font-size: 11px; color: #4a5568; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #cbd5e0; padding-bottom: 3px; }
                .meta-row { display: flex; justify-content: space-between; font-size: 9px; margin-bottom: 4px; }
                .meta-row:last-child { margin-bottom: 0; }
                .meta-label { color: #718096; font-weight: 500; }
                .meta-value { color: #2d3748; font-weight: 700; }
                
                table { width: 100%; border-collapse: collapse; margin-bottom: 25px; font-size: 8px; }
                th { background-color: #ffffff; color: #000000; font-weight: 700; border: 1px solid #000000; padding: 6px 4px; text-align: left; text-transform: uppercase; font-size: 7.5px; letter-spacing: 0.2px; }
                td { border: 1px solid #000000; padding: 5px 4px; text-align: left; color: #000000; word-break: break-all; }
                .text-right { text-align: right; }
                .font-bold { font-weight: 700; }
                
                .signature-section { display: flex; justify-content: flex-end; margin-top: 30px; font-size: 9px; page-break-inside: avoid; }
                .signature-box { text-align: center; width: 180px; }
                .signature-line { border-bottom: 1px solid #718096; height: 50px; margin-bottom: 8px; }
                
                @media print {
                    body { margin: 10px; }
                    .no-print { display: none; }
                    .page-break { page-break-before: always; }
                }
            </style>
        </head>
        <body>
            <div class="no-print" style="margin-bottom: 15px; background: #fff3cd; color: #856404; padding: 10px 15px; border-radius: 6px; border: 1px solid #ffeeba; display: flex; justify-content: space-between; align-items: center; font-size: 11px; font-weight: 600;">
                <span>📄 Gunakan menu browser Anda untuk mencetak atau menyimpan dokumen ini sebagai file PDF.</span>
                <button onclick="window.print()" style="background: #1c6434; color: #fff; border: none; padding: 5px 12px; border-radius: 4px; font-weight: 700; cursor: pointer;">🖨️ Cetak / Simpan PDF</button>
            </div>
            
            <div class="header-container">
                <div class="logo-area">
                    <h1>MANADO RECYCLE HUB</h1>
                    <p>Jasa Jemput Sampah Daur Ulang & Clean Up Service</p>
                    <p>Kota Manado, Sulawesi Utara, Indonesia</p>
                </div>
                <div class="report-title">
                    <h2>LAPORAN REKAMAN TIMBANG</h2>
                    <p>Dicetak pada: <?= date('d M Y H:i') ?> WITA</p>
                </div>
            </div>

            <div class="meta-grid">
                <div class="meta-box">
                    <h3>Periode & Pencarian</h3>
                    <div class="meta-row">
                        <span class="meta-label">Tanggal Mulai:</span>
                        <span class="meta-value"><?= $start_date ?: 'Semua Waktu' ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Tanggal Akhir:</span>
                        <span class="meta-value"><?= $end_date ?: 'Semua Waktu' ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Kata Kunci:</span>
                        <span class="meta-value"><?= $search ? '"' . htmlspecialchars($search) . '"' : '-' ?></span>
                    </div>
                </div>
                <div class="meta-box">
                    <h3>Ringkasan Data</h3>
                    <div class="meta-row">
                        <span class="meta-label">Total Transaksi:</span>
                        <span class="meta-value"><?= count($records) ?> item</span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Total Berat:</span>
                        <span class="meta-value" style="color: #c05621; font-size: 11px;"><?= number_format($total_weight, 2, ',', '.') ?> kg</span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Total Dibayar:</span>
                        <span class="meta-value" style="color: #0284c7; font-size: 11px;">Rp <?= number_format($total_payout, 0, ',', '.') ?></span>
                    </div>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th width="8%">Timestamp</th>
                        <th width="6%">Date</th>
                        <th width="8%">Sub-district</th>
                        <th width="9%">Place Name</th>
                        <th width="5%">Place Type</th>
                        <th width="8%">Partner Name</th>
                        <th width="6%">ID</th>
                        <th width="7%">Phone Number</th>
                        <th width="4%">Staff ID</th>
                        <th width="3%">Type</th>
                        <th width="5%">Service Type</th>
                        <th width="4%">Weight</th>
                        <th width="4%">Price</th>
                        <th width="5%">Total</th>
                        <th width="10%">Notes</th>
                        <th width="8%">Recycled Material</th>
                        <th width="10%">Geo Location</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="17" style="text-align:center; padding: 20px;">Tidak ada data rekaman timbang.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $r): ?>
                            <?php 
                            $timestamp = date('m/d/Y H.i', strtotime($r['created_at']));
                            $date = $r['tanggal_tugas'] ? date('n/j/Y', strtotime($r['tanggal_tugas'])) : '-';
                            $phone = htmlspecialchars($r['pickup_wa'] ?: $r['cleanup_wa'] ?: '-');
                            $service_type = htmlspecialchars($r['service_type'] ?: ($r['cleanup_request_id'] ? 'Paid' : '-'));
                            $geo = decToDms($r['latitude'], $r['longitude']);

                            $is_pickup = !empty($r['pickup_request_id']);
                            $w_str = ($r['berat_raw'] !== null && $r['berat_raw'] !== '') ? $r['berat_raw'] : (string)(float)$r['berat_kg'];
                            $total_harga_val = $is_pickup ? (float)$w_str * (float)$r['price_per_kg'] : 0;
                            $total_harga = $is_pickup ? htmlspecialchars($w_str) . ' kg x Rp ' . number_format((float)$r['price_per_kg'], 0, ',', '.') . ' = Rp ' . number_format($total_harga_val, 0, ',', '.') : '—';
                            ?>
                            <tr>
                                <td><?= $timestamp ?></td>
                                <td><?= $date ?></td>
                                <td><?= htmlspecialchars($r['kecamatan'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['place_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['place_type'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['partner_name'] ?? '-') ?></td>
                                <td class="font-bold"><?= htmlspecialchars($r['request_code'] ?: '-') ?></td>
                                <td><?= $phone ?></td>
                                <td><?= htmlspecialchars($r['officer_code'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['pickup_type'] ?? '-') ?></td>
                                <td><?= $service_type ?></td>
                                <td class="font-bold text-right" style="color: #b45309;"><?= htmlspecialchars($w_str) ?> kg</td>
                                <td class="text-right"><?= number_format($r['price_per_kg'], 0, ',', '.') ?></td>
                                <td class="font-bold text-right" style="color: #0284c7;"><?= $total_harga ?></td>
                                <td><?= htmlspecialchars($r['catatan'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['jenis_sampah']) ?></td>
                                <td><?= $geo ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="signature-section">
                <div class="signature-box">
                    <p>Manado, <?= date('d M Y') ?></p>
                    <p style="font-weight: 700; margin-top: 5px;">Administrator MRH,</p>
                    <div class="signature-line"></div>
                    <p style="font-weight: 700;"><?= htmlspecialchars($_SESSION['user_nama'] ?? 'Super Admin') ?></p>
                </div>
            </div>

            <script>
                window.onload = function() {
                    setTimeout(function() {
                        window.print();
                    }, 500);
                }
            </script>
        </body>
        </html>
        <?php
        exit;
    }
}

// Fetch data for view
$stmt = $db->prepare($query_str);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_weight = 0.0;
$total_payout = 0.0;
foreach ($records as $r) {
    $total_weight += (float)$r['berat_kg'];
    if (!empty($r['pickup_request_id'])) {
        $total_payout += (float)$r['berat_kg'] * (float)$r['price_per_kg'];
    }
}

require_once __DIR__ . '/layout/header.php';
?>

<style>
.weighing-table {
    font-size: 11px;
    border-collapse: collapse;
    width: 100%;
}
.weighing-table th {
    background-color: #f8fafc;
    color: #475569;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 9.5px;
    letter-spacing: 0.5px;
    padding: 10px 8px;
    border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
    text-align: left;
}
.weighing-table td {
    padding: 8px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    vertical-align: middle;
}
.weighing-table tr:hover {
    background-color: #f8fafc;
}
.weigh-timestamp {
    color: #64748b;
    font-family: monospace;
    white-space: nowrap;
}
.weigh-date {
    white-space: nowrap;
}
.weigh-sub-district {
    font-weight: 600;
    color: #0f172a;
}
.weigh-place-name {
    font-weight: 600;
    color: #1e293b;
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.weigh-id {
    font-weight: 800;
    color: var(--green-700);
}
.weigh-phone {
    font-family: monospace;
    color: #475569;
}
.weigh-staff {
    background: #e2e8f0;
    color: #334155;
    padding: 2px 6px;
    border-radius: 4px;
    font-weight: 700;
    font-size: 10px;
    display: inline-block;
}
.weigh-type-badge {
    padding: 2px 6px;
    border-radius: 9999px;
    font-weight: 700;
    font-size: 9px;
    text-transform: uppercase;
}
.weigh-type-badge.pickup {
    background: #dcfce7;
    color: #15803d;
}
.weigh-type-badge.cleanup {
    background: #faf5ff;
    color: #6b21a8;
}
.weigh-service-badge {
    padding: 2px 6px;
    border-radius: 9999px;
    font-weight: 700;
    font-size: 9px;
    text-transform: uppercase;
}
.weigh-service-badge.free {
    background: #e0f2fe;
    color: #0369a1;
}
.weigh-service-badge.paid {
    background: #fee2e2;
    color: #b91c1c;
}
.weigh-weight {
    font-size: 12px;
    font-weight: 800;
    color: #b45309;
    white-space: nowrap;
}
.weigh-price {
    font-weight: 600;
    color: #059669;
    white-space: nowrap;
}
.weigh-notes {
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #64748b;
}
.weigh-material {
    font-weight: 600;
    color: #475569;
}
.weigh-geo {
    font-size: 10px;
    font-family: monospace;
    color: #64748b;
    white-space: nowrap;
}

.weigh-filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
    margin-bottom: 20px;
    background: #fff;
    padding: 16px;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
}
.weigh-filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.weigh-filter-group label {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.weigh-filter-input {
    padding: 8px 12px;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    font-size: 13px;
    outline: none;
    min-width: 180px;
    font-family: inherit;
    transition: 0.15s;
}
.weigh-filter-input:focus {
    border-color: var(--green-600);
}
.btn-weigh {
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 700;
    border-radius: 8px;
    cursor: pointer;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: 0.2s;
    height: 38px;
}
.btn-weigh-primary {
    background: var(--green-700);
    color: #fff;
}
.btn-weigh-primary:hover {
    background: var(--green-600);
}
.btn-weigh-secondary {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #cbd5e1;
}
.btn-weigh-secondary:hover {
    background: #e2e8f0;
}
.weigh-summary-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: linear-gradient(135deg, var(--green-700), var(--green-600));
    color: #fff;
    padding: 20px 24px;
    border-radius: var(--radius);
    margin-bottom: 20px;
    box-shadow: var(--shadow);
}
.weigh-summary-title {
    font-size: 14px;
    font-weight: 700;
    opacity: 0.9;
}
.weigh-summary-value {
    font-size: 28px;
    font-weight: 900;
}
.weigh-summary-value span {
    font-size: 16px;
    font-weight: 600;
    margin-left: 4px;
}
.btn-weigh-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 12px;
    font-size: 11px;
    font-weight: 700;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
    white-space: nowrap;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #334155;
    height: 28px;
    line-height: 1;
}
.btn-weigh-action span {
    font-size: 12px;
    line-height: 1;
}
.btn-weigh-action.preview {
    border-color: #cbd5e1;
    color: #475569;
}
.btn-weigh-action.preview:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
    color: #0f172a;
}
.btn-weigh-action.delete {
    background: #fff5f5;
    border-color: #fee2e2;
    color: #e11d48;
}

/* Custom Confirm Dialog Style */
.custom-confirm-overlay {
    position: fixed;
    top: 0; left: 0; width: 100vw; height: 100vh;
    background: rgba(15, 23, 42, 0.4);
    backdrop-filter: blur(4px);
    display: flex; align-items: center; justify-content: center;
    z-index: 9999;
    animation: fadeInConfirm 0.2s ease-out;
}
.custom-confirm-box {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
    padding: 24px;
    max-width: 440px;
    width: 90%;
    text-align: center;
    border-top: 5px solid #ef4444; /* Red accent */
    animation: scaleInConfirm 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.custom-confirm-title {
    font-size: 16px;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 12px;
}
.custom-confirm-message {
    font-size: 13px;
    font-weight: 500;
    color: #475569;
    line-height: 1.6;
    margin-bottom: 24px;
    background: #fef2f2;
    padding: 12px;
    border-radius: 8px;
    border: 1px solid #fee2e2;
    text-align: left;
}
.custom-confirm-buttons {
    display: flex;
    gap: 12px;
    justify-content: center;
}
.btn-confirm-action {
    padding: 10px 24px;
    font-size: 13px;
    font-weight: 700;
    border-radius: 8px;
    cursor: pointer;
    border: none;
    transition: all 0.15s ease;
}
.btn-confirm-delete {
    background: #ef4444;
    color: #ffffff;
}
.btn-confirm-delete:hover {
    background: #dc2626;
}
.btn-confirm-cancel {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #cbd5e1;
}
.btn-confirm-cancel:hover {
    background: #e2e8f0;
}

@keyframes fadeInConfirm {
    from { opacity: 0; }
    to { opacity: 1; }
}
@keyframes scaleInConfirm {
    from { transform: scale(0.95); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}
</style>

<div class="page-header">
    <h1>⚖️ Rekam Angkut</h1>
</div>

<!-- Summary Cards -->
<div class="summary-cards-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 20px;">
    <div class="weigh-summary-box" style="margin-bottom: 0;">
        <div>
            <div class="weigh-summary-title">Total Berat Sampah Terkumpul (Filtered)</div>
            <div style="font-size: 11px; opacity: 0.75; margin-top: 4px;">Berdasarkan filter pencarian saat ini</div>
        </div>
        <div class="weigh-summary-value">
            <?= number_format($total_weight, 2, ',', '.') ?><span>kg</span>
        </div>
    </div>
    <div class="weigh-summary-box" style="margin-bottom: 0; background: linear-gradient(135deg, #0284c7, #0369a1);">
        <div>
            <div class="weigh-summary-title">Total Harga Dibayarkan ke Warga/Mitra (Filtered)</div>
            <div style="font-size: 11px; opacity: 0.75; margin-top: 4px;">Total uang yang dibayarkan untuk sampah daur ulang</div>
        </div>
        <div class="weigh-summary-value">
            <span>Rp</span><?= number_format($total_payout, 0, ',', '.') ?>
        </div>
    </div>
</div>

<!-- Filters Form -->
<form method="GET" action="weighing_records.php">
    <div class="weigh-filter-row">
        <div class="weigh-filter-group">
            <label>Cari Nama / Request Code</label>
            <input type="text" name="search" class="weigh-filter-input" placeholder="Cari..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="weigh-filter-group">
            <label>Tanggal Mulai</label>
            <input type="date" name="start_date" class="weigh-filter-input" value="<?= htmlspecialchars($start_date) ?>">
        </div>
        <div class="weigh-filter-group">
            <label>Tanggal Akhir</label>
            <input type="date" name="end_date" class="weigh-filter-input" value="<?= htmlspecialchars($end_date) ?>">
        </div>
        <div style="display:flex; gap:8px;">
            <button type="submit" class="btn-weigh btn-weigh-primary">🔍 Filter</button>
            <?php if ($search !== '' || $start_date !== '' || $end_date !== ''): ?>
                <a href="weighing_records.php" class="btn-weigh btn-weigh-secondary">Reset</a>
            <?php endif; ?>
            <a href="weighing_records.php?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="btn-weigh btn-weigh-secondary" title="Export Excel (Formatted)">
                📊 Excel
            </a>
            <a href="weighing_records.php?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn-weigh btn-weigh-secondary" target="_blank" title="Export PDF / Cetak Laporan">
                📄 PDF
            </a>
        </div>
    </div>
</form>

<!-- Table -->
<div class="card">
    <div class="table-wrap">
        <table class="weighing-table">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Date</th>
                    <th>Sub-district</th>
                    <th>Place Name</th>
                    <th>Place Type</th>
                    <th>Partner Name</th>
                    <th>ID</th>
                    <th>Phone Number</th>
                    <th>Staff ID</th>
                    <th>Type</th>
                    <th>Service Type</th>
                    <th>Weight (kg)</th>
                    <th>Price per kg</th>
                    <th>Total Harga</th>
                    <th>Notes</th>
                    <th>Recycled Material</th>
                    <th>Geo Location</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="18" style="text-align:center; padding: 40px; color:#64748b;">
                            Tidak ada rekaman timbang yang ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $r): ?>
                        <?php 
                        $timestamp = date('m/d/Y H.i', strtotime($r['created_at']));
                        $date = $r['tanggal_tugas'] ? date('n/j/Y', strtotime($r['tanggal_tugas'])) : '-';
                        $sub_district = htmlspecialchars($r['kecamatan'] ?? '-');
                        $place_name = htmlspecialchars($r['place_name'] ?? '-');
                        $place_type = htmlspecialchars($r['place_type'] ?? '-');
                        $partner_name = htmlspecialchars($r['partner_name'] ?? '-');
                        $id_code = htmlspecialchars($r['request_code'] ?? '-');
                        $phone = htmlspecialchars($r['pickup_wa'] ?: $r['cleanup_wa'] ?: '-');
                        $staff_id = htmlspecialchars($r['officer_code'] ?? '-');
                        $type = htmlspecialchars($r['pickup_type'] ?? '-');
                        $service_type = htmlspecialchars($r['service_type'] ?: ($r['cleanup_request_id'] ? 'Paid' : '-'));
                        $w_str = ($r['berat_raw'] !== null && $r['berat_raw'] !== '') ? $r['berat_raw'] : (string)(float)$r['berat_kg'];
                        $weight = htmlspecialchars($w_str) . ' kg';
                        $price = 'Rp ' . number_format($r['price_per_kg'], 0, ',', '.');
                        $notes = htmlspecialchars($r['catatan'] ?? '-');
                        $recycled_material = htmlspecialchars($r['jenis_sampah'] ?? '-');
                        $geo_decimal = (!empty($r['latitude']) && !empty($r['longitude'])) ? number_format((float)$r['latitude'], 4, '.', '') . ', ' . number_format((float)$r['longitude'], 4, '.', '') : '-';

                        $is_pickup = !empty($r['pickup_request_id']);
                        $total_harga_val = $is_pickup ? (float)$w_str * (float)$r['price_per_kg'] : 0;
                        $total_harga = $is_pickup ? htmlspecialchars($w_str) . ' kg x Rp ' . number_format((float)$r['price_per_kg'], 0, ',', '.') . ' = Rp ' . number_format($total_harga_val, 0, ',', '.') : '—';
                        ?>
                        <tr>
                            <td class="weigh-timestamp"><?= $timestamp ?></td>
                            <td class="weigh-date"><?= $date ?></td>
                            <td class="weigh-sub-district"><?= $sub_district ?></td>
                            <td class="weigh-place-name" title="<?= $place_name ?>"><?= $place_name ?></td>
                            <td><?= $place_type ?></td>
                            <td><?= $partner_name ?></td>
                            <td class="weigh-id"><?= $id_code ?></td>
                            <td class="weigh-phone"><?= $phone ?></td>
                            <td><span class="weigh-staff"><?= $staff_id ?></span></td>
                            <td>
                                <span class="weigh-type-badge <?= strtolower($type) === 'pickup' || $r['pickup_request_id'] ? 'pickup' : 'cleanup' ?>">
                                    <?= $type ?>
                                </span>
                            </td>
                            <td>
                                <span class="weigh-service-badge <?= strtolower($service_type) === 'free' ? 'free' : 'paid' ?>">
                                    <?= $service_type ?>
                                </span>
                            </td>
                            <td class="weigh-weight"><?= $weight ?></td>
                            <td class="weigh-price"><?= $price ?></td>
                            <td class="weigh-price" style="font-weight: 700; color: #0284c7;"><?= $total_harga ?></td>
                            <td class="weigh-notes" title="<?= $notes ?>"><?= $notes ?></td>
                            <td class="weigh-material"><?= $recycled_material ?></td>
                            <td class="weigh-geo"><?= $geo_decimal ?></td>
                            <td style="white-space: nowrap; text-align: center;">
                                <div style="display: inline-flex; gap: 6px; align-items: center; justify-content: center;">
                                    <button class="btn-weigh-action preview" onclick="previewRecord(<?= $r['id'] ?>)">
                                        <span>👁️</span> Detail
                                    </button>
                                    <button class="btn-weigh-action delete" onclick="deleteRecord(<?= $r['id'] ?>, '<?= htmlspecialchars($r['request_code'] ?: 'Manual') ?>')">
                                        <span>🗑️</span> Hapus
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Card Perhitungan Jarak Koordinat (Haversine Formula) -->
<style>
.haversine-grid {
    display: flex;
    flex-direction: column;
    gap: 20px;
    margin-top: 24px;
}
@media (min-width: 992px) {
    .haversine-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
    }
}
.haversine-card {
    background: #fff;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 24px;
    display: flex;
    flex-direction: column;
}
.haversine-card-title {
    font-size: 15px;
    font-weight: 800;
    color: #1e293b;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.haversine-card-desc {
    font-size: 12px;
    color: #64748b;
    margin-top: 0;
    margin-bottom: 16px;
}
@media (max-width: 768px) {
    .grid-2 {
        grid-template-columns: 1fr !important;
    }
    .preview-row-flex {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 4px !important;
    }
    .preview-row-flex > span {
        text-align: left !important;
        margin-left: 0 !important;
        min-width: auto !important;
    }
}
</style>

<?php
if (!function_exists('haversineDistanceLocal')) {
    function haversineDistanceLocal(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadiusKm * $c;
    }
}

// ── GET POINTS FOR DAUR ULANG ──
$points_daur_ulang = [
    ['name' => 'Depot', 'lat' => 1.476362, 'lng' => 124.832498]
];
try {
    $stmtGeoDaurUlang = $db->query("
        SELECT 
            COALESCE(NULLIF(pr.place_name, ''), NULLIF(pr.alamat_jemput, ''), 'Titik') AS place_name,
            pr.latitude AS lat,
            pr.longitude AS lng
        FROM weighing_records wr
        JOIN pickup_requests pr ON pr.id = wr.pickup_request_id
        WHERE pr.latitude IS NOT NULL AND pr.latitude != 0 AND pr.longitude IS NOT NULL AND pr.longitude != 0
        ORDER BY wr.created_at ASC
    ");
    
    $index = 1;
    while ($row = $stmtGeoDaurUlang->fetch(PDO::FETCH_ASSOC)) {
        $pName = trim($row['place_name']);
        if (empty($pName) || $pName === '-') {
            $pName = 'Titik ' . $index;
        } else {
            if (strlen($pName) > 40) {
                $pName = substr($pName, 0, 37) . '...';
            }
            $pName = 'Titik ' . $index . ' (' . htmlspecialchars($pName) . ')';
        }
        $points_daur_ulang[] = [
            'name' => $pName,
            'lat'  => (float)$row['lat'],
            'lng'  => (float)$row['lng']
        ];
        $index++;
    }
} catch (Exception $e) {}

// ── GET POINTS FOR CLEAN UP ──
$points_cleanup = [
    ['name' => 'Depot', 'lat' => 1.476362, 'lng' => 124.832498]
];
try {
    $stmtGeoCleanup = $db->query("
        SELECT 
            COALESCE(NULLIF(cr.alamat_jemput, ''), 'Titik') AS place_name,
            cr.latitude AS lat,
            cr.longitude AS lng
        FROM weighing_records wr
        JOIN cleanup_requests cr ON cr.id = wr.cleanup_request_id
        WHERE cr.latitude IS NOT NULL AND cr.latitude != 0 AND cr.longitude IS NOT NULL AND cr.longitude != 0
        ORDER BY wr.created_at ASC
    ");
    
    $index = 1;
    while ($row = $stmtGeoCleanup->fetch(PDO::FETCH_ASSOC)) {
        $pName = trim($row['place_name']);
        if (empty($pName) || $pName === '-') {
            $pName = 'Titik ' . $index;
        } else {
            if (strlen($pName) > 40) {
                $pName = substr($pName, 0, 37) . '...';
            }
            $pName = 'Titik ' . $index . ' (' . htmlspecialchars($pName) . ')';
        }
        $points_cleanup[] = [
            'name' => $pName,
            'lat'  => (float)$row['lat'],
            'lng'  => (float)$row['lng']
        ];
        $index++;
    }
} catch (Exception $e) {}
?>

<div class="haversine-grid">
    <!-- Tabel Layanan Daur Ulang -->
    <div class="haversine-card">
        <div class="haversine-card-title">
            <span>♻️</span> Perhitungan Jarak Layanan Daur Ulang
        </div>
        <p class="haversine-card-desc">
            Perhitungan jarak garis lurus antar titik koordinat penjemputan Daur Ulang dari Depot menggunakan rumus Haversine.
        </p>
        <div class="table-wrap">
            <table class="weighing-table" style="margin-bottom: 0;">
                <thead>
                    <tr>
                        <th style="width: 25%;">Titik</th>
                        <th style="width: 35%;">Koordinat (Lat, Lng)</th>
                        <th style="width: 20%; text-align: right;">Jarak (km)</th>
                        <th style="width: 20%; text-align: right;">Kumulatif (km)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (count($points_daur_ulang) <= 1) {
                        ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: #94a3b8; padding: 20px 0;">Tidak ada data koordinat penjemputan daur ulang</td>
                        </tr>
                        <?php
                        $total_dist_du = 0.0;
                    } else {
                        $total_dist_du = 0.0;
                        for ($i = 0; $i < count($points_daur_ulang); $i++) {
                            $p = $points_daur_ulang[$i];
                            if ($i === 0) {
                                $dist_str = '-';
                                $cum_str = '-';
                            } else {
                                $prev = $points_daur_ulang[$i - 1];
                                $dist = haversineDistanceLocal($prev['lat'], $prev['lng'], $p['lat'], $p['lng']);
                                $total_dist_du += $dist;
                                $dist_str = number_format($dist, 4, ',', '.') . ' km';
                                $cum_str = number_format($total_dist_du, 4, ',', '.') . ' km';
                            }
                            ?>
                            <tr>
                                <td style="font-weight: 700; color: #1e293b;"><?= htmlspecialchars($p['name']) ?></td>
                                <td style="font-family: monospace; color: #475569;"><?= $p['lat'] . ', ' . $p['lng'] ?></td>
                                <td style="text-align: right; font-weight: 600; color: #334155;"><?= $dist_str ?></td>
                                <td style="text-align: right; font-weight: 700; color: #0f766e;"><?= $cum_str ?></td>
                            </tr>
                            <?php
                        }
                    }
                    ?>
                    <tr style="font-weight: bold; background-color: #f8fafc; border-top: 2px solid #e2e8f0;">
                        <td colspan="2" style="font-weight: 800; color: #0f172a;">Total Jarak Daur Ulang</td>
                        <td style="text-align: right; font-weight: 800; color: #b45309;"><?= number_format($total_dist_du, 4, ',', '.') ?> km</td>
                        <td style="text-align: right; font-weight: 800; color: #b45309;"><?= number_format($total_dist_du, 4, ',', '.') ?> km</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tabel Clean Up Service -->
    <div class="haversine-card">
        <div class="haversine-card-title">
            <span>🧹</span> Perhitungan Jarak Clean Up Service
        </div>
        <p class="haversine-card-desc">
            Perhitungan jarak garis lurus antar titik koordinat tugas Clean Up dari Depot menggunakan rumus Haversine.
        </p>
        <div class="table-wrap">
            <table class="weighing-table" style="margin-bottom: 0;">
                <thead>
                    <tr>
                        <th style="width: 25%;">Titik</th>
                        <th style="width: 35%;">Koordinat (Lat, Lng)</th>
                        <th style="width: 20%; text-align: right;">Jarak (km)</th>
                        <th style="width: 20%; text-align: right;">Kumulatif (km)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (count($points_cleanup) <= 1) {
                        ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: #94a3b8; padding: 20px 0;">Tidak ada data koordinat layanan clean up</td>
                        </tr>
                        <?php
                        $total_dist_cu = 0.0;
                    } else {
                        $total_dist_cu = 0.0;
                        for ($i = 0; $i < count($points_cleanup); $i++) {
                            $p = $points_cleanup[$i];
                            if ($i === 0) {
                                $dist_str = '-';
                                $cum_str = '-';
                            } else {
                                $prev = $points_cleanup[$i - 1];
                                $dist = haversineDistanceLocal($prev['lat'], $prev['lng'], $p['lat'], $p['lng']);
                                $total_dist_cu += $dist;
                                $dist_str = number_format($dist, 4, ',', '.') . ' km';
                                $cum_str = number_format($total_dist_cu, 4, ',', '.') . ' km';
                            }
                            ?>
                            <tr>
                                <td style="font-weight: 700; color: #1e293b;"><?= htmlspecialchars($p['name']) ?></td>
                                <td style="font-family: monospace; color: #475569;"><?= $p['lat'] . ', ' . $p['lng'] ?></td>
                                <td style="text-align: right; font-weight: 600; color: #334155;"><?= $dist_str ?></td>
                                <td style="text-align: right; font-weight: 700; color: #0f766e;"><?= $cum_str ?></td>
                            </tr>
                            <?php
                        }
                    }
                    ?>
                    <tr style="font-weight: bold; background-color: #f8fafc; border-top: 2px solid #e2e8f0;">
                        <td colspan="2" style="font-weight: 800; color: #0f172a;">Total Jarak Clean Up</td>
                        <td style="text-align: right; font-weight: 800; color: #b45309;"><?= number_format($total_dist_cu, 4, ',', '.') ?> km</td>
                        <td style="text-align: right; font-weight: 800; color: #b45309;"><?= number_format($total_dist_cu, 4, ',', '.') ?> km</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- Modal Detail Rekaman Timbang -->
<div class="modal-overlay" id="modalDetailRecord" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:1000; opacity:0; visibility:hidden; transition: 0.2s ease-in-out;">
    <div class="modal" style="background:#fff; border-radius:12px; width:95%; max-width:1100px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); transform: translateY(-20px); transition: 0.2s ease-in-out; display:flex; flex-direction:column; max-height:90vh; overflow:hidden;">
        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding:18px 24px 14px;">
            <h3 style="margin:0; font-size:16px; font-weight:800; color:#1e293b;" id="detailTitle">📋 Detail Rekaman Timbang</h3>
            <button onclick="closeDetailModal()" style="background:none; border:none; font-size:18px; cursor:pointer; color:#64748b;">✕</button>
        </div>
        <div class="modal-body" id="detailContent" style="display:flex; flex-direction:column; gap:12px; flex:1; overflow-y:auto; padding:20px 24px; font-size:13px; color:#334155;">
            <div style="text-align:center; padding:20px; color:#64748b;">Memuat...</div>
        </div>
        <div class="modal-footer" style="display:flex; justify-content:flex-end; border-top:1px solid #e2e8f0; padding:14px 24px; background:#fafafa; border-radius:0 0 12px 12px;">
            <button class="btn btn-outline" onclick="closeDetailModal()">Tutup</button>
        </div>
    </div>
</div>

<!-- Form Helper for POST Delete -->
<form method="POST" id="formDeleteRecord" style="display:none;">
    <input type="hidden" name="action" value="delete_record">
    <input type="hidden" name="id" id="deleteRecordId">
</form>

<script>
function openDetailModal() {
    const overlay = document.getElementById('modalDetailRecord');
    overlay.style.opacity = '1';
    overlay.style.visibility = 'visible';
    overlay.querySelector('.modal').style.transform = 'translateY(0)';
}

function closeDetailModal() {
    const overlay = document.getElementById('modalDetailRecord');
    overlay.style.opacity = '0';
    overlay.style.visibility = 'hidden';
    overlay.querySelector('.modal').style.transform = 'translateY(-20px)';
}

async function previewRecord(id) {
    openDetailModal();
    const content = document.getElementById('detailContent');
    const title = document.getElementById('detailTitle');
    content.innerHTML = '<div style="text-align:center; padding:20px; color:#64748b;">⏳ Memuat detail...</div>';
    
    try {
        const r = await fetch('weighing_records.php?ajax_detail=' + id);
        const d = await r.json();
        
        if (d.ok && d.data) {
            const data = d.data;
            title.textContent = '📋 Detail Rekaman: ' + (data.request_code || 'Manual');
            
            let itemsHtml = '';
            if (data.items && data.items.length > 0) {
                const isCleanup = data.cleanup_request_id ? 1 : 0;
                const pricePerKg = parseFloat(data.price_per_kg || 0);
                itemsHtml = `
                    <div style="margin-top:10px;">
                        <strong style="color:#475569;">Daftar Sampah/Item Terkait:</strong>
                        <div class="table-wrap">
                        <table style="width:100%; font-size:12px; border-collapse:collapse; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-top:6px;">
                            <thead>
                                <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                                    <th style="padding:8px 12px; text-align:left; font-weight:700; white-space:nowrap; line-height:1.2;">Kategori</th>
                                    <th style="padding:8px 12px; text-align:center; font-weight:700; white-space:nowrap; line-height:1.2;">Estimasi (kg)</th>
                                    <th style="padding:8px 12px; text-align:center; font-weight:700; white-space:nowrap; line-height:1.2;">Aktual (kg)</th>
                                    <th style="padding:8px 12px; text-align:center; font-weight:700; white-space:nowrap; line-height:1.2;">Harga/kg</th>
                                    <th style="padding:8px 12px; text-align:center; font-weight:700; white-space:nowrap; line-height:1.2;">Total</th>
                                    <th style="padding:8px 12px; text-align:left; font-weight:700; white-space:nowrap; line-height:1.2;">Catatan</th>
                                    <th style="padding:8px 12px; text-align:center; font-weight:700; white-space:nowrap; line-height:1.2;">Edit</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.items.map(it => {
                                    const est = it.estimasi_kg !== null && it.estimasi_kg !== undefined ? parseFloat(it.estimasi_kg).toFixed(2) + ' kg' : '—';
                                    const akt = it.berat_kg !== null && it.berat_kg !== undefined ? parseFloat(it.berat_kg).toFixed(2) + ' kg' : '—';
                                    const priceStr = isCleanup ? '—' : 'Rp ' + pricePerKg.toLocaleString('id-ID');
                                    const totalVal = (it.berat_kg !== null && !isCleanup) ? (parseFloat(it.berat_kg) * pricePerKg) : 0;
                                    const totalStr = isCleanup ? '—' : 'Rp ' + totalVal.toLocaleString('id-ID');
                                    const cat = it.catatan || '—';
                                    const emoji = it.ikon_emoji || '♻️';
                                    const escCat = escapeHtml(cat).replace(/'/g, "\\'").replace(/\r?\n/g, "\\n");
                                     const escKatNama = escapeHtml(it.category_name).replace(/'/g, "\\'");
                                    return `
                                    <tr style="border-bottom:1px solid #e2e8f0;">
                                        <td style="padding:8px 12px; color:#475569;">
                                            <span>${emoji}</span> ${escapeHtml(it.category_name)}
                                        </td>
                                        <td style="padding:8px 12px; text-align:center; color:#475569;">${est}</td>
                                        <td style="padding:8px 12px; text-align:center; font-weight:700; color:#1e293b;">${akt}</td>
                                        <td style="padding:8px 12px; text-align:center; color:#475569;">${priceStr}</td>
                                        <td style="padding:8px 12px; text-align:center; font-weight:700; color:#1e293b;">${totalStr}</td>
                                        <td style="padding:8px 12px; color:#475569;">${escapeHtml(cat)}</td>
                                        <td style="padding:8px 12px; text-align:center;">
                                            <button class="btn btn-primary" style="padding:2px 8px; font-size:10px; background:#0f766e; color:white; border:none; border-radius:4px; cursor:pointer;"
                                                onclick="openItemEdit(${it.item_id}, '${escKatNama}', ${it.estimasi_kg !== null ? it.estimasi_kg : 'null'}, ${it.berat_kg !== null ? it.berat_kg : 'null'}, '${escCat}', ${pricePerKg}, ${isCleanup})">
                                                ✏️ Edit
                                            </button>
                                        </td>
                                    </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                        </div>
                    </div>
                `;
                /*
                    <div style="margin-top:10px;">
                        <strong style="color:#475569;">Daftar Sampah/Item Terkait:</strong>
                        <table style="width:100%; font-size:12px; border-collapse:collapse; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-top:6px;">
                            <thead>
                                <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                                    <th style="padding:8px 12px; text-align:left; font-weight:700;">Kategori</th>
                                    <th style="padding:8px 12px; text-align:right; font-weight:700;">Berat Aktual (kg)</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.items.map(it => `
                                    <tr style="border-bottom:1px solid #e2e8f0;">
                                        <td style="padding:8px 12px; color:#475569;">${escapeHtml(it.category_name)}</td>
                                        <td style="padding:8px 12px; text-align:right; font-weight:700; color:#1e293b;">${parseFloat(it.berat_kg || 0).toFixed(2)} kg</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `;*/
            }
            
            let price = parseFloat(data.price_per_kg || 0);
            let totalEst = 0;
            let totalAkt = 0;
            if (data.items && data.items.length > 0) {
                data.items.forEach(it => {
                    totalEst += parseFloat(it.estimasi_kg || 0);
                    totalAkt += parseFloat(it.berat_kg || 0);
                });
            } else {
                totalEst = parseFloat(data.berat_kg || 0);
                totalAkt = parseFloat(data.berat_kg || 0);
            }
            let isCleanup = data.cleanup_request_id ? 1 : 0;
            let totalEstPrice = totalEst * price;
            let totalAktPrice = totalAkt * price;
            
            let totalAktStr = '';
            if (data.items && data.items.length > 0) {
                totalAktStr = totalAkt.toString();
            } else {
                totalAktStr = (data.berat_raw !== null && data.berat_raw !== undefined && data.berat_raw !== '') ? data.berat_raw : totalAkt.toString();
            }

            content.innerHTML = `
                <div class="grid-2" style="margin-bottom:16px;">
                    
                    <!-- Kolom Kiri: Informasi Pemohon & Lokasi -->
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px; display:flex; flex-direction:column; gap:4px;">
                        <div style="font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:6px; margin-bottom:10px; letter-spacing:0.5px;">👤 Informasi Pemohon</div>
                        
                        <div class="preview-row-flex" style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f1f5f9; font-size:13px;">
                            <span style="font-weight:700; color:#64748b; font-size:11px; text-transform:uppercase; min-width:110px;">Kode Request</span>
                            <span style="font-weight:900; color:#2e7d32; font-size:13px; text-align:right;">${data.request_code || '-'}</span>
                        </div>
                        <div class="preview-row-flex" style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f1f5f9; font-size:13px;">
                            <span style="font-weight:700; color:#64748b; font-size:11px; text-transform:uppercase; min-width:110px;">Nama Entitas</span>
                            <span style="font-weight:600; color:#1e293b; text-align:right;">${escapeHtml(data.nama_entitas || '-')}</span>
                        </div>
                        <div class="preview-row-flex" style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f1f5f9; font-size:13px;">
                            <span style="font-weight:700; color:#64748b; font-size:11px; text-transform:uppercase; min-width:110px;">Nama Pemohon</span>
                            <span style="font-weight:600; color:#1e293b; text-align:right;">${escapeHtml(data.nama_pemohon || '-')}</span>
                        </div>
                        <div class="preview-row-flex" style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f1f5f9; font-size:13px;">
                            <span style="font-weight:700; color:#64748b; font-size:11px; text-transform:uppercase; min-width:110px;">Nomor WA</span>
                            <span style="font-weight:600; color:#1e293b; text-align:right;">
                                <a href="https://wa.me/${(data.nomor_wa || '').replace(/[^0-9]/g, '')}" target="_blank" style="color:#1976d2; text-decoration:none;">
                                    ${escapeHtml(data.nomor_wa || '-')} ↗
                                </a>
                            </span>
                        </div>
                        <div class="preview-row-flex" style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f1f5f9; font-size:13px;">
                            <span style="font-weight:700; color:#64748b; font-size:11px; text-transform:uppercase; min-width:110px;">Kecamatan</span>
                            <span style="font-weight:600; color:#1e293b; text-align:right;">${escapeHtml(data.kecamatan || '-')}</span>
                        </div>
                        <div class="preview-row-flex" style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f1f5f9; font-size:13px;">
                            <span style="font-weight:700; color:#64748b; font-size:11px; text-transform:uppercase; min-width:110px;">Kelurahan</span>
                            <span style="font-weight:600; color:#1e293b; text-align:right;">${escapeHtml(data.kelurahan || '-')}</span>
                        </div>
                        <div class="preview-row-flex" style="display:flex; justify-content:space-between; padding:6px 0; font-size:13px;">
                            <span style="font-weight:700; color:#64748b; font-size:11px; text-transform:uppercase; min-width:110px;">Alamat Lengkap</span>
                            <span style="font-weight:600; color:#1e293b; text-align:right; flex:1; margin-left:12px; word-break:break-word;">${escapeHtml(data.alamat_jemput || '-')}</span>
                        </div>
                    </div>

                    <!-- Kolom Kanan: Status & Rincian Berat/Biaya -->
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px; display:flex; flex-direction:column; gap:4px;">
                        <div style="font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:6px; margin-bottom:10px; letter-spacing:0.5px;">⚙️ Layanan & Hasil Timbang</div>
                        
                        <div class="preview-row-flex" style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f1f5f9; font-size:13px;">
                            <span style="font-weight:700; color:#64748b; font-size:11px; text-transform:uppercase; min-width:110px;">Tipe Layanan</span>
                            <span style="font-weight:700; color:#1e293b; text-align:right;">${data.pickup_request_id ? '♻️ Pickup Daur Ulang' : '🧹 Clean Up Service'}</span>
                        </div>
                        <div class="preview-row-flex" style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f1f5f9; font-size:13px;">
                            <span style="font-weight:700; color:#64748b; font-size:11px; text-transform:uppercase; min-width:110px;">Status</span>
                            <span style="font-weight:700; color:#16a34a; text-align:right;">${escapeHtml(data.status ? data.status.toUpperCase() : '-')}</span>
                        </div>
                        <div class="preview-row-flex" style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f1f5f9; font-size:13px;">
                            <span style="font-weight:700; color:#64748b; font-size:11px; text-transform:uppercase; min-width:110px;">Petugas Lapangan</span>
                            <span style="font-weight:700; color:#1e293b; text-align:right;">${escapeHtml(data.officer_nama || '-')}</span>
                        </div>
                        <div class="preview-row-flex" style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f1f5f9; font-size:13px;">
                            <span style="font-weight:700; color:#64748b; font-size:11px; text-transform:uppercase; min-width:110px;">Berat Total</span>
                            <span style="font-weight:900; color:#b45309; font-size:13px; text-align:right;">${escapeHtml(totalAktStr)} kg</span>
                        </div>
                        ${!isCleanup ? `
                        <div class="preview-row-flex" style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f1f5f9; font-size:13px;">
                            <span style="font-weight:700; color:#64748b; font-size:11px; text-transform:uppercase; min-width:110px;">Harga per Kg</span>
                            <span style="font-weight:700; color:#1e293b; text-align:right;">Rp ${price.toLocaleString('id-ID')}</span>
                        </div>
                        <div class="preview-row-flex" style="display:flex; justify-content:space-between; padding:6px 0; font-size:13px;">
                            <span style="font-weight:700; color:#64748b; font-size:11px; text-transform:uppercase; min-width:110px;">Hasil Payout</span>
                            <span style="font-weight:900; color:#16a34a; font-size:13px; text-align:right;">${escapeHtml(totalAktStr)} kg x Rp ${price.toLocaleString('id-ID')} = Rp ${totalAktPrice.toLocaleString('id-ID')}</span>
                        </div>
                        ` : ''}
                    </div>

                </div>
                
                ${itemsHtml}
            `;
        } else {
            content.innerHTML = '<div style="text-align:center; padding:20px; color:#ef4444;">❌ Gagal memuat data.</div>';
        }
    } catch(e) {
        content.innerHTML = '<div style="text-align:center; padding:20px; color:#ef4444;">❌ Error: ' + e.message + '</div>';
    }
}

function deleteRecord(id, code) {
    const existing = document.getElementById('customConfirmOverlay');
    if (existing) existing.remove();

    const overlay = document.createElement('div');
    overlay.id = 'customConfirmOverlay';
    overlay.className = 'custom-confirm-overlay';

    const box = document.createElement('div');
    box.className = 'custom-confirm-box';

    const title = document.createElement('div');
    title.className = 'custom-confirm-title';
    title.textContent = '⚠️ Hapus Rekaman Permanen';

    const message = document.createElement('div');
    message.className = 'custom-confirm-message';
    message.innerHTML = 'Apakah Anda yakin ingin menghapus rekaman timbang untuk <strong>' + escapeHtml(code) + '</strong>?<br><br>' +
                        '<span style="color:#b91c1c; font-weight:700;">Tindakan ini akan menghapus rekaman timbang secara permanen. Status request dan data berat aktual tidak akan dikembalikan ke "dijadwalkan" atau di-reset.</span>';

    const btnContainer = document.createElement('div');
    btnContainer.className = 'custom-confirm-buttons';

    const btnCancel = document.createElement('button');
    btnCancel.className = 'btn-confirm-action btn-confirm-cancel';
    btnCancel.textContent = 'Batal';
    btnCancel.onclick = () => overlay.remove();

    const btnDelete = document.createElement('button');
    btnDelete.className = 'btn-confirm-action btn-confirm-delete';
    btnDelete.textContent = 'Ya, Hapus';
    btnDelete.onclick = () => {
        document.getElementById('deleteRecordId').value = id;
        document.getElementById('formDeleteRecord').submit();
    };

    btnContainer.appendChild(btnCancel);
    btnContainer.appendChild(btnDelete);

    box.appendChild(title);
    box.appendChild(message);
    box.appendChild(btnContainer);
    overlay.appendChild(box);
    document.body.appendChild(overlay);
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}
</script>

<?php
?>
<!-- Modal Edit Item -->
<div class="modal-overlay" id="modalItem" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:9999; opacity:0; visibility:hidden; transition: 0.2s ease-in-out;">
  <div class="modal" style="max-width:420px">
    <div class="modal-header">
      <h3 id="modalItemTitle">✏️ Edit Data Item Sampah</h3>
      <button class="modal-close" onclick="closeModal('modalItem')">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-row" style="margin-bottom:12px; display:flex; gap:12px;">
        <div class="form-group" style="margin-bottom:0; flex:1; display:flex; flex-direction:column; gap:4px;">
          <label class="form-label" style="font-weight:600; font-size:12px; color:#475569;">Estimasi (kg)</label>
          <input class="form-input" id="itemEstInput" type="number" step="0.01" min="0"
                 placeholder="Dari user" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px;">
        </div>
        <div class="form-group" style="margin-bottom:0; flex:1; display:flex; flex-direction:column; gap:4px;">
          <label class="form-label" style="font-weight:600; font-size:12px; color:#475569;">Aktual (kg)</label>
          <input class="form-input" id="itemAktInput" type="number" step="0.01" min="0"
                 placeholder="Setelah ditimbang" oninput="calculateModalTotal()" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px;">
        </div>
      </div>
      <div class="form-row" style="margin-bottom:12px; display:flex; gap:12px;" id="itemPriceRow">
        <div class="form-group" style="margin-bottom:0; flex:1; display:flex; flex-direction:column; gap:4px;">
          <label class="form-label" style="font-weight:600; font-size:12px; color:#475569;">Harga per Kilogram (Rp)</label>
          <input class="form-input" id="itemPriceInput" type="number" step="1" min="0"
                 placeholder="0" oninput="calculateModalTotal()" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px;">
        </div>
        <div class="form-group" style="margin-bottom:0; flex:1; display:flex; flex-direction:column; gap:4px;">
          <label class="form-label" style="font-weight:600; font-size:12px; color:#475569;">Total (Rp)</label>
          <input class="form-input" id="itemTotalInput" type="text" readonly
                 placeholder="0" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; background:#e2e8f0; cursor:not-allowed;">
        </div>
      </div>
      <div class="form-group" style="display:flex; flex-direction:column; gap:4px;">
        <label class="form-label" style="font-weight:600; font-size:12px; color:#475569;">Catatan Item</label>
        <textarea class="form-input" id="itemCatatanInput" rows="2"
                  placeholder="Kondisi, keterangan khusus, dll..." style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-family:inherit;"></textarea>
      </div>
    </div>
    <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px;">
      <button class="btn btn-outline" onclick="closeModal('modalItem')" style="padding:8px 16px; border:1px solid #cbd5e1; border-radius:6px; background:white; cursor:pointer;">Batal</button>
      <button class="btn btn-primary" onclick="saveItem()" style="padding:8px 16px; border:none; border-radius:6px; background:#0f766e; color:white; cursor:pointer;">💾 Simpan</button>
    </div>
  </div>
</div>

<script>
let _itemId = null;
let _isCleanup = 0;

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.opacity = '0';
        modal.style.visibility = 'hidden';
        modal.querySelector('.modal').style.transform = 'translateY(-20px)';
    }
}

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.opacity = '1';
        modal.style.visibility = 'visible';
        modal.querySelector('.modal').style.transform = 'translateY(0)';
    }
}

function calculateModalTotal() {
  const akt = parseFloat(document.getElementById('itemAktInput').value) || 0;
  const price = parseFloat(document.getElementById('itemPriceInput').value) || 0;
  const total = akt * price;
  document.getElementById('itemTotalInput').value = 'Rp ' + total.toLocaleString('id-ID');
}

function openItemEdit(itemId, katNama, estKg, aktKg, catatan, price, isCleanup) {
  _itemId = itemId;
  _isCleanup = isCleanup;
  document.getElementById('modalItemTitle').textContent = '✏️ Edit: ' + katNama;
  document.getElementById('itemEstInput').value    = (estKg !== null && estKg !== undefined) ? estKg : '';
  document.getElementById('itemAktInput').value    = (aktKg !== null && aktKg !== undefined) ? aktKg : '';
  document.getElementById('itemPriceInput').value  = (price !== null && price !== undefined) ? price : '';
  document.getElementById('itemCatatanInput').value = catatan || '';
  
  if (isCleanup) {
      document.getElementById('itemPriceRow').style.display = 'none';
      document.getElementById('itemEstInput').disabled = true;
      document.getElementById('itemEstInput').style.background = '#e2e8f0';
  } else {
      document.getElementById('itemPriceRow').style.display = 'flex';
      document.getElementById('itemEstInput').disabled = false;
      document.getElementById('itemEstInput').style.background = '';
  }
  
  calculateModalTotal();
  openModal('modalItem');
  setTimeout(() => document.getElementById('itemAktInput').focus(), 120);
}

async function saveItem() {
  if (!_itemId) return;
  const fd = new FormData();
  fd.append('action',      'update_item');
  fd.append('item_id',     _itemId);
  fd.append('is_cleanup',   _isCleanup);
  fd.append('estimasi_kg', document.getElementById('itemEstInput').value.trim());
  fd.append('aktual_kg',   document.getElementById('itemAktInput').value.trim());
  fd.append('price_per_kg', document.getElementById('itemPriceInput').value.trim());
  fd.append('catatan',     document.getElementById('itemCatatanInput').value.trim());
  fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
  try {
    const res  = await fetch('weighing_records.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      closeModal('modalItem');
      location.reload();
    } else {
      alert('Gagal menyimpan data item: ' + (data.message || 'Error'));
    }
  } catch (e) { alert('Gagal menyimpan data item. Silakan coba lagi.'); }
}
</script>

<?php
require_once __DIR__ . '/layout/footer.php';
?>
