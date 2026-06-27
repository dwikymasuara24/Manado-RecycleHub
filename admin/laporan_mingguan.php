<?php
require_once __DIR__ . '/../include/config.php';
$page_id    = 'laporan_mingguan';
$page_title = 'Laporan Mingguan';
$db         = getDB();

// Tentukan minggu yang dipilih
$weekOffset = (int)($_GET['offset'] ?? 0);

$kecamatans = ['Wenang','Malalayang','Tikala','Paal Dua','Bunaken','Singkil','Mapanget','Wanea','Sario','Tuminting'];
$fKec = $_GET['kecamatan'] ?? '';
if ($fKec && !in_array($fKec, $kecamatans)) {
    $fKec = '';
}

$bulanId = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

// Check if direct month/year overrides are passed
if (isset($_GET['month']) && isset($_GET['year'])) {
    $month = (int)$_GET['month'];
    $year  = (int)$_GET['year'];
    
    $currentMonth = (int)date('m');
    $currentYear  = (int)date('Y');
    
    if ($month === $currentMonth && $year === $currentYear) {
        $baseDate = new DateTime();
    } else {
        $baseDate = new DateTime("$year-$month-01");
    }
    
    // Find the Monday of that week
    $monday = (clone $baseDate)->modify('monday this week');
    
    // Calculate the week offset relative to today's Monday
    $todayMonday = (new DateTime())->modify('monday this week');
    $diffDays = (int)$todayMonday->diff($monday)->format('%r%a');
    $weekOffset = (int)round($diffDays / 7);
} else {
    $monday = (new DateTime())->modify('monday this week')->modify("{$weekOffset} weeks");
    $month  = (int)$monday->format('m');
    $year   = (int)$monday->format('Y');
}

$sunday     = (clone $monday)->modify('+6 days');
$startDate  = $monday->format('Y-m-d');
$endDate    = $sunday->format('Y-m-d');

$type = $_GET['type'] ?? 'pickup';
if ($type !== 'cleanup') $type = 'pickup';

// Semua request minggu ini
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
        WHERE DATE(pr.created_at) BETWEEN ? AND ?
    ";
    $params = [$startDate, $endDate];
    if ($fKec) {
        $sql .= " AND pr.kecamatan = ?";
        $params[] = $fKec;
    }
    $sql .= " ORDER BY pr.created_at ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
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
        WHERE DATE(cr.created_at) BETWEEN ? AND ?
    ";
    $params = [$startDate, $endDate];
    if ($fKec) {
        $sql .= " AND cr.kecamatan = ?";
        $params[] = $fKec;
    }
    $sql .= " ORDER BY cr.created_at ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
}
$rows = $stmt->fetchAll();

// ── EXPORT HANDLER LAPORAN MINGGUAN ───────────────────────────
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    $layanan = $type === 'cleanup' ? 'Clean Up Service' : 'Daur Ulang';
    $filename = 'laporan_mingguan_' . $type . '_' . $startDate . '_to_' . $endDate;

    if ($export_type === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename . '.xls');
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head><meta charset="utf-8">';
        echo '<!--[if gte mso 9]><xml>';
        echo '<x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
        echo '<x:Name>Laporan Mingguan</x:Name>';
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
        echo '<h3 style="font-family: Calibri, Arial, sans-serif; font-size: 16pt; font-weight: bold; margin: 0 0 10px 0; color: #000000;">LAPORAN MINGGUAN ' . strtoupper($layanan) . '</h3>';
        echo '<p style="font-family: Calibri, Arial, sans-serif; font-size: 11pt; margin: 0 0 15px 0; color: #475569;">Periode: ' . date('d M Y', strtotime($startDate)) . ' s/d ' . date('d M Y', strtotime($endDate)) . '</p>';
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
            <title>Laporan Mingguan <?= htmlspecialchars($layanan) ?></title>
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
                    <h2>LAPORAN MINGGUAN <?= htmlspecialchars(strtoupper($layanan)) ?></h2>
                    <p>Dicetak pada: <?= date('d M Y H:i') ?> WITA</p>
                </div>
            </div>

            <div class="meta-grid">
                <div class="meta-box">
                    <h3>Periode Laporan</h3>
                    <div class="meta-row">
                        <span class="meta-label">Mulai Tanggal:</span>
                        <span class="meta-value"><?= date('d F Y', strtotime($startDate)) ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Sampai Tanggal:</span>
                        <span class="meta-value"><?= date('d F Y', strtotime($endDate)) ?></span>
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
                            <td colspan="18" style="text-align:center; padding: 20px;">Tidak ada transaksi pada minggu ini.</td>
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

// Statistik
$total    = count($rows);
$selesai  = count(array_filter($rows, fn($r) => $r['status']==='selesai'));
$menunggu = count(array_filter($rows, fn($r) => $r['status']==='menunggu'));
$berat    = array_sum(array_column($rows,'berat_total_kg'));

// Per hari dalam minggu
$perHari = [];
$days = ['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'];
for ($i=0;$i<7;$i++) {
    $d = (clone $monday)->modify("+$i days")->format('Y-m-d');
    $cnt = count(array_filter($rows, fn($r) => date('Y-m-d',strtotime($r['created_at'])) === $d));
    $perHari[] = ['hari'=>$days[$i],'tgl'=>$d,'cnt'=>$cnt];
}

// Per kecamatan minggu ini
if ($type === 'pickup') {
    $sqlK = "
        SELECT kecamatan, COUNT(*) as cnt FROM pickup_requests 
        WHERE DATE(created_at) BETWEEN ? AND ? AND kecamatan IS NOT NULL 
    ";
    $paramsK = [$startDate, $endDate];
    if ($fKec) {
        $sqlK .= " AND kecamatan = ?";
        $paramsK[] = $fKec;
    }
    $sqlK .= " GROUP BY kecamatan ORDER BY cnt DESC";
    $kecStmt = $db->prepare($sqlK);
    $kecStmt->execute($paramsK);
} else {
    $sqlK = "
        SELECT kecamatan, COUNT(*) as cnt FROM cleanup_requests 
        WHERE DATE(created_at) BETWEEN ? AND ? AND kecamatan IS NOT NULL 
    ";
    $paramsK = [$startDate, $endDate];
    if ($fKec) {
        $sqlK .= " AND kecamatan = ?";
        $paramsK[] = $fKec;
    }
    $sqlK .= " GROUP BY kecamatan ORDER BY cnt DESC";
    $kecStmt = $db->prepare($sqlK);
    $kecStmt->execute($paramsK);
}
$kecRows = $kecStmt->fetchAll();

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
  <h1>Laporan Mingguan</h1>
  <p>Rekap <?= $type === 'cleanup' ? 'Clean Up Service' : 'Penjemputan Sampah Daur Ulang' ?> per minggu — menggunakan penjadwalan Priority Rule (Sabtu)</p>
</div>

<!-- Navigasi Laporan -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <a href="laporan_harian.php?type=<?= $type ?>"   class="btn btn-outline btn-sm">📅 Harian</a>
  <span class="btn btn-sm" style="background:#f0fdf4;color:#16a34a;border:1.5px solid #bbf7d0;font-weight:700;cursor:default">📆 Mingguan</span>
  <a href="laporan_bulanan.php?type=<?= $type ?>"  class="btn btn-outline btn-sm">📊 Bulanan</a>
  <a href="analisis_data.php"    class="btn btn-outline btn-sm">🔬 Analisis Data</a>
  <a href="dashboard.php"        class="btn btn-outline btn-sm" style="margin-left:auto">← Dashboard</a>
</div>

<!-- Tab Filter Layanan -->
<div class="tabs-container" style="display:flex;gap:12px;margin-bottom:20px;border-bottom:2px solid #f1f5f9;padding-bottom:1px">
  <a href="?offset=<?= $weekOffset ?>&type=pickup&kecamatan=<?= urlencode($fKec) ?>" class="tab-link" style="padding:10px 16px;text-decoration:none;font-weight:700;font-size:14px;color:<?= $type === 'pickup' ? 'var(--green-700)' : '#64748b' ?>;border-bottom:3px solid <?= $type === 'pickup' ? 'var(--green-700)' : 'transparent' ?>;margin-bottom:-2px">🚛 Daur Ulang (Pickup)</a>
  <a href="?offset=<?= $weekOffset ?>&type=cleanup&kecamatan=<?= urlencode($fKec) ?>" class="tab-link" style="padding:10px 16px;text-decoration:none;font-weight:700;font-size:14px;color:<?= $type === 'cleanup' ? 'var(--green-700)' : '#64748b' ?>;border-bottom:3px solid <?= $type === 'cleanup' ? 'var(--green-700)' : 'transparent' ?>;margin-bottom:-2px">🧹 Clean Up Service</a>
</div>

<!-- Navigasi Minggu -->
<div class="card mb-24" style="padding:14px 20px">
  <form method="GET" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;width:100%">
    <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
    <input type="hidden" name="offset" value="<?= $weekOffset ?>">
    
    <div style="display:flex;align-items:center;gap:8px;">
      <a href="laporan_mingguan.php?offset=<?= $weekOffset-1 ?>&type=<?= $type ?>&kecamatan=<?= urlencode($fKec) ?>" class="btn btn-outline">◀ Minggu Lalu</a>
      <span style="font-weight:700;font-size:14px;white-space:nowrap">
        Minggu: <?= $monday->format('d M') ?> — <?= $sunday->format('d M Y') ?>
      </span>
      <a href="laporan_mingguan.php?offset=<?= $weekOffset+1 ?>&type=<?= $type ?>&kecamatan=<?= urlencode($fKec) ?>" class="btn btn-outline">Minggu Depan ▶</a>
      <?php if ($weekOffset !== 0): ?>
      <a href="laporan_mingguan.php?type=<?= $type ?>&kecamatan=<?= urlencode($fKec) ?>" class="btn btn-outline">Minggu Ini</a>
      <?php endif; ?>
    </div>

    <div style="display:flex;align-items:center;gap:8px;margin-left:12px;">
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

      <button type="submit" class="btn btn-primary" style="padding: 6px 12px; font-size:12.5px;">Filter</button>
    </div>

    <div style="margin-left:auto; display:flex; gap:8px;">
      <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="btn btn-sm" style="background:#10b981; color:white; font-weight:700; border-radius:6px; box-shadow:0 2px 4px rgba(16,185,129,0.2);" title="Export Excel (Formatted)">📊 Excel</a>
      <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" target="_blank" class="btn btn-sm" style="background:#ef4444; color:white; font-weight:700; border-radius:6px; box-shadow:0 2px 4px rgba(239,68,68,0.2);" title="Export PDF / Cetak Laporan">📄 PDF</a>
    </div>
  </form>
</div>

<!-- Stats Minggu -->
<div class="stats-grid mb-24">
  <div class="stat-card green">
    <div class="stat-label">Total Request</div>
    <div class="stat-value"><?= $total ?></div>
    <div class="stat-sub">minggu ini</div>
  </div>
  <div class="stat-card blue">
    <div class="stat-label">Selesai</div>
    <div class="stat-value"><?= $selesai ?></div>
    <div class="stat-sub">penjemputan berhasil</div>
  </div>
  <div class="stat-card amber">
    <div class="stat-label">Menunggu</div>
    <div class="stat-value"><?= $menunggu ?></div>
    <div class="stat-sub">perlu tindakan</div>
  </div>
  <div class="stat-card red">
    <div class="stat-label">Total Berat</div>
    <div class="stat-value"><?= number_format($berat,1) ?> kg</div>
    <div class="stat-sub">estimasi terkumpul</div>
  </div>
</div>

<!-- Tabel lengkap -->
<div class="card mb-24">
  <div class="card-title"><div class="ct-icon">📋</div> Detail Semua Request — <?= $monday->format('d M') ?> s.d. <?= $sunday->format('d M Y') ?></div>
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
        <tr><td colspan="18" style="text-align:center;color:#aaa;padding:30px">Tidak ada request pada minggu ini.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid-2 mb-24">
  <!-- Per Hari -->
  <div class="card">
    <div class="card-title"><div class="ct-icon">📊</div> Request per Hari</div>
    <div class="bar-chart">
      <?php
      $maxH = max(array_column($perHari,'cnt') ?: [1]);
      foreach ($perHari as $ph):
        $h = $maxH > 0 ? round(($ph['cnt']/$maxH)*120) : 0;
        $isSat = $ph['hari'] === 'Sabtu';
      ?>
      <div class="bar-item">
        <span class="bar-val"><?= $ph['cnt'] ?></span>
        <div class="bar" style="height:<?= max($h,4) ?>px;background:<?= $isSat?'#e65100':'var(--green-600)' ?>"></div>
        <span><?= $ph['hari'] ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:8px;font-size:11px;color:#888">
      <span style="display:inline-block;width:10px;height:10px;background:#e65100;border-radius:2px;margin-right:4px"></span>Sabtu = hari penjemputan utama
    </div>
  </div>

  <!-- Per Kecamatan -->
  <div class="card">
    <div class="card-title"><div class="ct-icon">🏆</div> Prioritas Kecamatan Minggu Ini</div>
    <?php if ($kecRows): ?>
    <?php
    $maxK = max(array_column($kecRows,'cnt') ?: [1]);
    foreach ($kecRows as $i => $k):
      $pct = round(($k['cnt']/$maxK)*100);
      $rankCls = $i===0?'rank-1':($i===1?'rank-2':($i===2?'rank-3':'rank-n'));
    ?>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
      <span class="priority-rank <?= $rankCls ?>"><?= $i+1 ?></span>
      <span style="flex:1;font-size:13px;font-weight:600"><?= htmlspecialchars($k['kecamatan']) ?></span>
      <div style="width:100px;background:#f0f0f0;border-radius:4px;height:6px;overflow:hidden">
        <div style="height:100%;background:var(--green-600);border-radius:4px;width:<?= $pct ?>%"></div>
      </div>
      <span style="font-size:12px;font-weight:700;min-width:20px"><?= $k['cnt'] ?></span>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <p style="color:#aaa;text-align:center;padding:20px 0;font-size:13px">Tidak ada data minggu ini</p>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
