<?php
require_once __DIR__ . '/../include/config.php';
$page_id    = 'laporan_harian';
$page_title = 'Laporan Harian';
$db         = getDB();

$kecamatans = ['Wenang','Malalayang','Tikala','Paal Dua','Bunaken','Singkil','Mapanget','Wanea','Sario','Tuminting'];
$fKec = $_GET['kecamatan'] ?? '';
if ($fKec && !in_array($fKec, $kecamatans)) {
    $fKec = '';
}

$bulanId = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

$tgl = $_GET['tgl'] ?? date('Y-m-d');
$tglDateTime = new DateTime($tgl);
$month = (int)$tglDateTime->format('m');
$year  = (int)$tglDateTime->format('Y');
$day   = (int)$tglDateTime->format('d');

if (isset($_GET['day']) && isset($_GET['month']) && isset($_GET['year'])) {
    $day   = (int)$_GET['day'];
    $month = (int)$_GET['month'];
    $year  = (int)$_GET['year'];
    
    // Calculate how many days are in the selected month
    $maxDays = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $day = min($day, $maxDays);
    $tgl = sprintf('%04d-%02d-%02d', $year, $month, $day);
}

$type = $_GET['type'] ?? 'pickup';
if ($type !== 'cleanup') $type = 'pickup';

// Data hari ini
if ($type === 'pickup') {
    $sql = "
        SELECT pr.id, pr.request_code, pr.nama_pemohon, pr.partner_name, pr.kecamatan, pr.nomor_wa,
               pr.place_name, pr.place_type, pr.pickup_type, pr.service_type, 
               COALESCE(pr.price_per_kg, 0) AS price_per_kg, pr.catatan, pr.latitude, pr.longitude, pr.tanggal_jemput AS tanggal_tugas,
               (SELECT GROUP_CONCAT(wc.name SEPARATOR ', ') FROM pickup_request_items pri JOIN waste_categories wc ON wc.id=pri.category_id WHERE pri.pickup_id=pr.id) AS jenis_sampah,
               pr.berat_total_kg, pr.status, pr.created_at, 'pickup' as req_type,
               o.officer_code
        FROM pickup_requests pr
        LEFT JOIN officers o ON o.id = pr.officer_id
        WHERE DATE(pr.created_at)=?
    ";
    $params = [$tgl];
    if ($fKec) {
        $sql .= " AND pr.kecamatan = ?";
        $params[] = $fKec;
    }
    $sql .= " ORDER BY pr.created_at DESC";
    $requests = $db->prepare($sql);
    $requests->execute($params);
} else {
    $sql = "
        SELECT cr.id, cr.request_code, cr.nama_pemohon, NULL as partner_name, cr.kecamatan, cr.nomor_wa,
               NULL as place_name, NULL as place_type, 'C' as pickup_type, 'Paid' as service_type, 
               0 AS price_per_kg, cr.catatan, cr.latitude, cr.longitude, cr.tanggal_tugas,
               (SELECT GROUP_CONCAT(wc.name SEPARATOR ', ') FROM cleanup_items ci JOIN waste_categories wc ON wc.id=ci.category_id WHERE ci.cleanup_id=cr.id) AS jenis_sampah,
               (SELECT SUM(berat_kg) FROM cleanup_items WHERE cleanup_id=cr.id) AS berat_total_kg,
               cr.status, cr.created_at, 'cleanup' as req_type,
               o.officer_code
        FROM cleanup_requests cr
        LEFT JOIN officers o ON o.id = cr.officer_id
        WHERE DATE(cr.created_at)=?
    ";
    $params = [$tgl];
    if ($fKec) {
        $sql .= " AND cr.kecamatan = ?";
        $params[] = $fKec;
    }
    $sql .= " ORDER BY cr.created_at DESC";
    $requests = $db->prepare($sql);
    $requests->execute($params);
}
$rows = $requests->fetchAll();

// ── EXPORT HANDLER LAPORAN HARIAN ─────────────────────────────
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    $layanan = $type === 'cleanup' ? 'Clean Up Service' : 'Daur Ulang';
    $filename = 'laporan_harian_' . $type . '_' . $tgl;

    if ($export_type === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename . '.xls');
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head><meta charset="utf-8">';
        echo '<!--[if gte mso 9]><xml>';
        echo '<x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
        echo '<x:Name>Laporan Harian</x:Name>';
        echo '<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>';
        echo '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook>';
        echo '</xml><![endif]--><style>';
        echo 'table, th, td { font-family: Calibri, Arial, sans-serif; font-size: 11pt; }';
        echo 'table { border-collapse: collapse; }';
        echo 'th { background-color: #ffffff; color: #000000; font-weight: bold; border: 1px solid #000000; padding: 6px; text-align: left; vertical-align: top; white-space: normal; }';
        echo 'td { border: 1px solid #000000; padding: 6px; text-align: left; vertical-align: top; }';
        echo '.number { mso-number-format:"\#\,\#\#0\.00"; text-align: right; }';
        echo '.text { mso-number-format:"\@"; }';
        echo '</style></head><body>';
        echo '<h3 style="font-family: Calibri, Arial, sans-serif; font-size: 16pt; font-weight: bold; margin: 0 0 10px 0; color: #000000;">LAPORAN HARIAN ' . strtoupper($layanan) . '</h3>';
        echo '<p style="font-family: Calibri, Arial, sans-serif; font-size: 11pt; margin: 0 0 15px 0; color: #475569;">Tanggal: ' . date('d F Y', strtotime($tgl)) . '</p>';
        echo '<table>';
        echo '<thead><tr>';
        echo '<th>Timestamp</th>';
        echo '<th>Date</th>';
        echo '<th>Sub-district</th>';
        echo '<th>Place Name</th>';
        echo '<th>Place Type</th>';
        echo '<th>Partner Name</th>';
        echo '<th>ID/Kode Request</th>';
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
        echo '<th>Status</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        foreach ($rows as $r) {
            $timestamp = date('m/d/Y H.i', strtotime($r['created_at']));
            $date = $r['tanggal_tugas'] ? date('n/j/Y', strtotime($r['tanggal_tugas'])) : '-';
            $sub_district = $r['kecamatan'] ?? '-';
            $place_name = $r['place_name'] ?? '-';
            $place_type = $r['place_type'] ?? '-';
            $partner_name = $r['partner_name'] ?: $r['nama_pemohon'];
            $id_code = $r['request_code'] ?? '-';
            $phone = $r['nomor_wa'] ?? '-';
            $staff_id = $r['officer_code'] ?? '-';
            $type_val = $r['pickup_type'] ?? ($r['req_type'] === 'cleanup' ? 'C' : '-');
            $service_type = $r['service_type'] ?: ($r['req_type'] === 'cleanup' ? 'Paid' : 'Free');
            $weight = $r['berat_total_kg'] ?: '0';
            $price = $r['price_per_kg'] ?? '0';
            $notes = $r['catatan'] ?? '-';
            $recycled_material = $r['jenis_sampah'] ?? '-';
            $geo = decToDms($r['latitude'], $r['longitude']);

            $is_pickup = $r['req_type'] === 'pickup';
            $total_harga_val = $is_pickup ? (float)$weight * (float)$price : 0;

            echo '<tr>';
            echo '<td class="text">' . htmlspecialchars($timestamp) . '</td>';
            echo '<td class="text">' . htmlspecialchars($date) . '</td>';
            echo '<td>' . htmlspecialchars($sub_district) . '</td>';
            echo '<td>' . htmlspecialchars($place_name) . '</td>';
            echo '<td>' . htmlspecialchars($place_type) . '</td>';
            echo '<td>' . htmlspecialchars($partner_name) . '</td>';
            echo '<td class="text">' . htmlspecialchars($id_code) . '</td>';
            echo '<td class="text">' . htmlspecialchars($phone) . '</td>';
            echo '<td class="text">' . htmlspecialchars($staff_id) . '</td>';
            echo '<td class="text">' . htmlspecialchars($type_val) . '</td>';
            echo '<td class="text">' . htmlspecialchars($service_type) . '</td>';
            echo '<td class="number">' . $weight . '</td>';
            echo '<td class="number">' . $price . '</td>';
            echo '<td class="number">' . number_format($total_harga_val, 0, '', '') . '</td>';
            echo '<td>' . htmlspecialchars($notes) . '</td>';
            echo '<td>' . htmlspecialchars($recycled_material) . '</td>';
            echo '<td class="text">' . htmlspecialchars($geo) . '</td>';
            echo '<td>' . htmlspecialchars($r['status']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    } elseif ($export_type === 'pdf') {
        $total_weight = array_sum(array_column($rows, 'berat_total_kg'));
        $total_payout = 0;
        foreach ($rows as $r) {
            if ($r['req_type'] === 'pickup') {
                $total_payout += (float)$r['berat_total_kg'] * (float)$r['price_per_kg'];
            }
        }
        ?>
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8">
            <title>Laporan Harian <?= htmlspecialchars($layanan) ?> — <?= date('d M Y', strtotime($tgl)) ?></title>
            <style>
                @page { size: landscape; margin: 20px; }
                body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; margin: 20px; line-height: 1.4; font-size: 10px; }
                .header-container { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #1c6434; padding-bottom: 10px; margin-bottom: 15px; }
                .logo-area h1 { font-size: 20px; color: #1c6434; margin: 0 0 3px 0; font-weight: 800; letter-spacing: 0.5px; }
                .logo-area p { margin: 0; font-size: 10px; color: #666; }
                .report-title { text-align: right; }
                .report-title h2 { font-size: 16px; color: #2d3748; margin: 0 0 3px 0; font-weight: 700; text-transform: uppercase; }
                .report-title p { margin: 0; font-size: 9px; color: #718096; }
                
                .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; }
                .meta-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; }
                .meta-box h3 { margin: 0 0 5px 0; font-size: 11px; color: #4a5568; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #cbd5e0; padding-bottom: 3px; }
                .meta-row { display: flex; justify-content: space-between; font-size: 10px; margin-bottom: 4px; }
                .meta-row:last-child { margin-bottom: 0; }
                .meta-label { color: #718096; font-weight: 500; }
                .meta-value { color: #2d3748; font-weight: 700; }
                
                table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 9px; }
                th { background-color: #ffffff; color: #000000; font-weight: 700; border: 1px solid #000000; padding: 6px 4px; text-align: left; text-transform: uppercase; font-size: 8.5px; letter-spacing: 0.3px; }
                td { border: 1px solid #000000; padding: 6px 4px; text-align: left; color: #000000; }
                .text-right { text-align: right; }
                .font-bold { font-weight: 700; }
                
                .signature-section { display: flex; justify-content: flex-end; margin-top: 30px; font-size: 10px; page-break-inside: avoid; }
                .signature-box { text-align: center; width: 180px; }
                .signature-line { border-bottom: 1px solid #718096; height: 50px; margin-bottom: 8px; }
                
                @media print {
                    body { margin: 10px; }
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="no-print" style="margin-bottom: 20px; background: #fff3cd; color: #856404; padding: 12px 20px; border-radius: 6px; border: 1px solid #ffeeba; display: flex; justify-content: space-between; align-items: center; font-size: 13px; font-weight: 600;">
                <span>📄 Gunakan menu browser Anda untuk mencetak atau menyimpan dokumen ini sebagai file PDF.</span>
                <button onclick="window.print()" style="background: #1c6434; color: #fff; border: none; padding: 6px 14px; border-radius: 4px; font-weight: 700; cursor: pointer;">🖨️ Cetak / Simpan PDF</button>
            </div>
            
            <div class="header-container">
                <div class="logo-area">
                    <h1>MANADO RECYCLE HUB</h1>
                    <p>Jasa Jemput Sampah Daur Ulang & Clean Up Service</p>
                    <p>Kota Manado, Sulawesi Utara, Indonesia</p>
                </div>
                <div class="report-title">
                    <h2>LAPORAN HARIAN <?= htmlspecialchars(strtoupper($layanan)) ?></h2>
                    <p>Dicetak pada: <?= date('d M Y H:i') ?> WITA</p>
                </div>
            </div>

            <div class="meta-grid">
                <div class="meta-box">
                    <h3>Periode Laporan</h3>
                    <div class="meta-row">
                        <span class="meta-label">Tanggal:</span>
                        <span class="meta-value"><?= date('d F Y', strtotime($tgl)) ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Hari:</span>
                        <span class="meta-value"><?= date('l', strtotime($tgl)) ?></span>
                    </div>
                </div>
                <div class="meta-box">
                    <h3>Ringkasan Transaksi</h3>
                    <div class="meta-row">
                        <span class="meta-label">Total Transaksi:</span>
                        <span class="meta-value"><?= count($rows) ?> request</span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Total Berat Estimasi:</span>
                        <span class="meta-value" style="color: #c05621; font-size: 14px;"><?= number_format($total_weight, 2, ',', '.') ?> kg</span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Total Dibayar ke Mitra/Warga:</span>
                        <span class="meta-value" style="color: #0284c7; font-size: 14px;">Rp <?= number_format($total_payout, 0, ',', '.') ?></span>
                    </div>
                </div>
            </div>

            <table>
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
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="18" style="text-align:center; padding: 20px;">Tidak ada transaksi pada tanggal ini.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php 
                            $timestamp = date('m/d/Y H.i', strtotime($r['created_at']));
                            $date = $r['tanggal_tugas'] ? date('n/j/Y', strtotime($r['tanggal_tugas'])) : '-';
                            $sub_district = htmlspecialchars($r['kecamatan'] ?? '-');
                            $place_name = htmlspecialchars($r['place_name'] ?? '-');
                            $place_type = htmlspecialchars($r['place_type'] ?? '-');
                            $partner_name = htmlspecialchars($r['partner_name'] ?: $r['nama_pemohon']);
                            $id_code = htmlspecialchars($r['request_code'] ?? '-');
                            $phone = htmlspecialchars($r['nomor_wa'] ?? '-');
                            $staff_id = htmlspecialchars($r['officer_code'] ?? '-');
                            $type_val = htmlspecialchars($r['pickup_type'] ?? ($r['req_type'] === 'cleanup' ? 'C' : '-'));
                            $service_type = htmlspecialchars($r['service_type'] ?: ($r['req_type'] === 'cleanup' ? 'Paid' : 'Free'));
                            $weight = $r['berat_total_kg'] ? number_format($r['berat_total_kg'], 2, ',', '.') . ' kg' : '-';
                            $price = 'Rp ' . number_format($r['price_per_kg'] ?? 0, 0, ',', '.');
                            $notes = htmlspecialchars($r['catatan'] ?? '-');
                            $recycled_material = htmlspecialchars($r['jenis_sampah'] ?? '-');
                            $geo = decToDms($r['latitude'], $r['longitude']);

                            $is_pickup = $r['req_type'] === 'pickup';
                            $total_harga_val = $is_pickup ? (float)$r['berat_total_kg'] * (float)$r['price_per_kg'] : 0;
                            $total_harga = $is_pickup ? 'Rp ' . number_format($total_harga_val, 0, ',', '.') : '—';
                            ?>
                            <tr>
                                <td><?= $timestamp ?></td>
                                <td><?= $date ?></td>
                                <td><?= $sub_district ?></td>
                                <td><?= $place_name ?></td>
                                <td><?= $place_type ?></td>
                                <td><?= $partner_name ?></td>
                                <td class="font-bold" style="color: var(--green-700);"><?= $id_code ?></td>
                                <td><?= $phone ?></td>
                                <td><?= $staff_id ?></td>
                                <td><?= $type_val ?></td>
                                <td><?= $service_type ?></td>
                                <td class="font-bold text-right"><?= $weight ?></td>
                                <td class="text-right"><?= $price ?></td>
                                <td class="font-bold text-right" style="color: #0284c7;"><?= $total_harga ?></td>
                                <td><?= $notes ?></td>
                                <td><?= $recycled_material ?></td>
                                <td style="font-size: 8px; white-space: nowrap;"><?= $geo ?></td>
                                <td><?= htmlspecialchars($r['status']) ?></td>
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

// Statistik hari ini
$stats = [
    'total'     => count($rows),
    'menunggu'  => count(array_filter($rows, fn($r) => $r['status']==='menunggu')),
    'selesai'   => count(array_filter($rows, fn($r) => $r['status']==='selesai')),
    'berat'     => array_sum(array_column($rows,'berat_total_kg')),
];

// Per kecamatan hari ini
if ($type === 'pickup') {
    $sqlK = "
        SELECT kecamatan, COUNT(*) as cnt FROM pickup_requests 
        WHERE DATE(created_at)=? AND kecamatan IS NOT NULL
    ";
    $paramsK = [$tgl];
    if ($fKec) {
        $sqlK .= " AND kecamatan = ?";
        $paramsK[] = $fKec;
    }
    $sqlK .= " GROUP BY kecamatan ORDER BY cnt DESC";
    $kecToday = $db->prepare($sqlK);
    $kecToday->execute($paramsK);
} else {
    $sqlK = "
        SELECT kecamatan, COUNT(*) as cnt FROM cleanup_requests 
        WHERE DATE(created_at)=? AND kecamatan IS NOT NULL
    ";
    $paramsK = [$tgl];
    if ($fKec) {
        $sqlK .= " AND kecamatan = ?";
        $paramsK[] = $fKec;
    }
    $sqlK .= " GROUP BY kecamatan ORDER BY cnt DESC";
    $kecToday = $db->prepare($sqlK);
    $kecToday->execute($paramsK);
}
$kecRows = $kecToday->fetchAll();

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
</style>

<div class="page-header">
  <h1>Laporan Harian</h1>
  <p>Data <?= $type === 'cleanup' ? 'Clean Up Service' : 'Penjemputan Sampah Daur Ulang' ?> per hari — tersinkron dari database</p>
</div>

<!-- Navigasi Laporan -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <span class="btn btn-sm" style="background:#f0fdf4;color:#16a34a;border:1.5px solid #bbf7d0;font-weight:700;cursor:default">📅 Harian</span>
  <a href="laporan_mingguan.php?type=<?= $type ?>" class="btn btn-outline btn-sm">📆 Mingguan</a>
  <a href="laporan_bulanan.php?type=<?= $type ?>"  class="btn btn-outline btn-sm">📊 Bulanan</a>
  <a href="analisis_data.php"    class="btn btn-outline btn-sm">🔬 Analisis Data</a>
  <a href="dashboard.php"        class="btn btn-outline btn-sm" style="margin-left:auto">← Dashboard</a>
</div>

<!-- Tab Filter Layanan -->
<div class="tabs-container" style="display:flex;gap:12px;margin-bottom:20px;border-bottom:2px solid #f1f5f9;padding-bottom:1px">
  <a href="?tgl=<?= urlencode($tgl) ?>&type=pickup&kecamatan=<?= urlencode($fKec) ?>&month=<?= $month ?>&year=<?= $year ?>" class="tab-link" style="padding:10px 16px;text-decoration:none;font-weight:700;font-size:14px;color:<?= $type === 'pickup' ? 'var(--green-700)' : '#64748b' ?>;border-bottom:3px solid <?= $type === 'pickup' ? 'var(--green-700)' : 'transparent' ?>;margin-bottom:-2px">🚛 Daur Ulang (Pickup)</a>
  <a href="?tgl=<?= urlencode($tgl) ?>&type=cleanup&kecamatan=<?= urlencode($fKec) ?>&month=<?= $month ?>&year=<?= $year ?>" class="tab-link" style="padding:10px 16px;text-decoration:none;font-weight:700;font-size:14px;color:<?= $type === 'cleanup' ? 'var(--green-700)' : '#64748b' ?>;border-bottom:3px solid <?= $type === 'cleanup' ? 'var(--green-700)' : 'transparent' ?>;margin-bottom:-2px">🧹 Clean Up Service</a>
</div>

<!-- Filter Tanggal -->
<div class="card mb-24" style="padding:14px 20px">
  <form method="GET" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;width:100%">
    <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
    
    <label class="form-label" style="margin-bottom:0;white-space:nowrap">Pilih Tanggal:</label>
    
    <div style="display:flex;align-items:center;gap:8px;margin-left:12px;">
      <select name="day" class="filter-select" style="padding:6px 10px; font-size:12.5px; border-radius:6px; border:1px solid #cbd5e1; font-weight:600; cursor:pointer;">
        <?php for ($d = 1; $d <= 31; $d++): ?>
          <option value="<?= $d ?>" <?= $day === $d ? 'selected' : '' ?>><?= $d ?></option>
        <?php endfor; ?>
      </select>

      <select name="month" class="filter-select" style="padding:6px 10px; font-size:12.5px; border-radius:6px; border:1px solid #cbd5e1; font-weight:600; cursor:pointer;">
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= $bulanId[$m] ?></option>
        <?php endfor; ?>
      </select>
      
      <select name="year" class="filter-select" style="padding:6px 10px; font-size:12.5px; border-radius:6px; border:1px solid #cbd5e1; font-weight:600; cursor:pointer;">
        <?php 
        $currentYear = (int)date('Y');
        for ($y = $currentYear - 3; $y <= $currentYear + 1; $y++): 
        ?>
          <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>

      <select name="kecamatan" class="filter-select" style="padding:6px 10px; font-size:12.5px; border-radius:6px; border:1px solid #cbd5e1; font-weight:600; cursor:pointer;">
        <option value="">Semua Kecamatan</option>
        <?php foreach ($kecamatans as $k): ?>
          <option value="<?= $k ?>" <?= $fKec === $k ? 'selected' : '' ?>><?= $k ?></option>
        <?php endforeach; ?>
      </select>

      <button type="submit" class="btn btn-primary" style="padding:6px 12px; font-size:12.5px;">Filter</button>
      <a href="laporan_harian.php?type=<?= $type ?>&kecamatan=<?= urlencode($fKec) ?>" class="btn btn-outline" style="padding:6px 12px; font-size:12.5px;">Hari Ini</a>
    </div>

    <span style="font-size:13px;color:#888;margin-left:8px;"><?= date('l, d F Y', strtotime($tgl)) ?></span>
    
    <div style="margin-left:auto; display:flex; gap:8px;">
      <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="btn btn-sm" style="background:#10b981; color:white; font-weight:700; border-radius:6px; box-shadow:0 2px 4px rgba(16,185,129,0.2);" title="Export Excel (Formatted)">📊 Excel</a>
      <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" target="_blank" class="btn btn-sm" style="background:#ef4444; color:white; font-weight:700; border-radius:6px; box-shadow:0 2px 4px rgba(239,68,68,0.2);" title="Export PDF / Cetak Laporan">📄 PDF</a>
    </div>
  </form>
</div>

<!-- Stats -->
<div class="stats-grid mb-24">
  <div class="stat-card green">
    <div class="stat-label">Total Request</div>
    <div class="stat-value"><?= $stats['total'] ?></div>
    <div class="stat-sub"><?= date('d M Y', strtotime($tgl)) ?></div>
  </div>
  <div class="stat-card amber">
    <div class="stat-label">Menunggu</div>
    <div class="stat-value"><?= $stats['menunggu'] ?></div>
    <div class="stat-sub">belum diproses</div>
  </div>
  <div class="stat-card blue">
    <div class="stat-label">Selesai</div>
    <div class="stat-value"><?= $stats['selesai'] ?></div>
    <div class="stat-sub">penjemputan berhasil</div>
  </div>
  <div class="stat-card red">
    <div class="stat-label">Total Berat</div>
    <div class="stat-value"><?= number_format($stats['berat'],1) ?> kg</div>
    <div class="stat-sub">estimasi</div>
  </div>
</div>

<!-- Tabel lengkap -->
<div class="card mb-24">
  <div class="card-title"><div class="ct-icon">📋</div> Detail Request — <?= date('d M Y', strtotime($tgl)) ?></div>
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
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($rows): ?>
        <?php foreach ($rows as $r): ?>
        <?php 
        $timestamp = date('m/d/Y H.i', strtotime($r['created_at']));
        $date = $r['tanggal_tugas'] ? date('n/j/Y', strtotime($r['tanggal_tugas'])) : '-';
        $sub_district = htmlspecialchars($r['kecamatan'] ?? '-');
        $place_name = htmlspecialchars($r['place_name'] ?? '-');
        $place_type = htmlspecialchars($r['place_type'] ?? '-');
        $partner_name = htmlspecialchars($r['partner_name'] ?: $r['nama_pemohon']);
        $id_code = htmlspecialchars($r['request_code'] ?? '-');
        $phone = htmlspecialchars($r['nomor_wa'] ?? '-');
        $staff_id = htmlspecialchars($r['officer_code'] ?? '-');
        $type_val = htmlspecialchars($r['pickup_type'] ?? ($r['req_type'] === 'cleanup' ? 'C' : '-'));
        $service_type = htmlspecialchars($r['service_type'] ?: ($r['req_type'] === 'cleanup' ? 'Paid' : 'Free'));
        $weight = $r['berat_total_kg'] ? number_format($r['berat_total_kg'], 2, ',', '.') . ' kg' : '-';
        $price = 'Rp ' . number_format($r['price_per_kg'] ?? 0, 0, ',', '.');
        $notes = htmlspecialchars($r['catatan'] ?? '-');
        $recycled_material = htmlspecialchars($r['jenis_sampah'] ?? '-');
        $geo = decToDms($r['latitude'], $r['longitude']);

        $is_pickup = $r['req_type'] === 'pickup';
        $total_harga_val = $is_pickup ? (float)$r['berat_total_kg'] * (float)($r['price_per_kg'] ?? 0) : 0;
        $total_harga = $is_pickup ? 'Rp ' . number_format($total_harga_val, 0, ',', '.') : '—';
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
              <span class="weigh-type-badge <?= strtolower($type_val) === 'pickup' || $r['req_type'] === 'pickup' ? 'pickup' : 'cleanup' ?>">
                  <?= $type_val ?>
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
          <td class="weigh-geo"><?= $geo ?></td>
          <td><?= statusBadge($r['status']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php else: ?>
        <tr><td colspan="18" style="text-align:center;color:#aaa;padding:30px">Tidak ada request pada tanggal ini.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Per Kecamatan -->
<?php if ($kecRows): ?>
<div class="card mb-24">
  <div class="card-title"><div class="ct-icon">📊</div> Distribusi per Kecamatan — <?= date('d M Y', strtotime($tgl)) ?></div>
  <div class="bar-chart">
    <?php
    $maxK = max(array_column($kecRows,'cnt'));
    foreach ($kecRows as $k):
      $h = round(($k['cnt']/$maxK)*120);
    ?>
    <div class="bar-item">
      <span class="bar-val"><?= $k['cnt'] ?></span>
      <div class="bar" style="height:<?= $h ?>px"></div>
      <span><?= htmlspecialchars($k['kecamatan']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Navigasi hari -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px">
  <?php
    $prev = date('Y-m-d', strtotime($tgl.' -1 day'));
    $next = date('Y-m-d', strtotime($tgl.' +1 day'));
  ?>
  <a href="laporan_harian.php?tgl=<?= $prev ?>&type=<?= $type ?>&kecamatan=<?= urlencode($fKec) ?>" class="btn btn-outline">◀ <?= date('d M', strtotime($prev)) ?></a>
  <span style="font-size:12px;color:#888"><?= date('d F Y', strtotime($tgl)) ?></span>
  <a href="laporan_harian.php?tgl=<?= $next ?>&type=<?= $type ?>&kecamatan=<?= urlencode($fKec) ?>" class="btn btn-outline"><?= date('d M', strtotime($next)) ?> ▶</a>
</div>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
