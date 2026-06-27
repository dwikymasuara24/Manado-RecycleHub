<?php
require_once __DIR__ . '/../include/config.php';
$page_id    = 'laporan_bulanan';
$page_title = 'Laporan Bulanan';
$db         = getDB();

$monthOffset = (int)($_GET['offset'] ?? 0);
$baseDate    = new DateTime('first day of this month');
$baseDate->modify("{$monthOffset} months");
$year  = (int)$baseDate->format('Y');
$month = (int)$baseDate->format('m');

// Izinkan override langsung lewat parameter GET 'month' dan 'year'
if (isset($_GET['month']) && isset($_GET['year'])) {
    $month = (int)$_GET['month'];
    $year  = (int)$_GET['year'];
    
    // Hitung kembali offset agar tombol Bulan Lalu / Bulan Depan tetap sinkron
    $d1 = new DateTime('first day of this month');
    $d2 = new DateTime("$year-$month-01");
    $diff = $d1->diff($d2);
    $monthOffset = ($diff->y * 12) + $diff->m;
    if ($d2 < $d1) {
        $monthOffset = -$monthOffset;
    }
}
$bulanId = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$monthName = $bulanId[$month] . ' ' . $year;

$kecamatans = ['Wenang','Malalayang','Tikala','Paal Dua','Bunaken','Singkil','Mapanget','Wanea','Sario','Tuminting'];
$fKec = $_GET['kecamatan'] ?? '';
if ($fKec && !in_array($fKec, $kecamatans)) {
    $fKec = '';
}

$type = $_GET['type'] ?? 'pickup';
if ($type !== 'cleanup') $type = 'pickup';

// Semua request bulan ini
if ($type === 'pickup') {
    $sql = "
        SELECT pr.id, pr.request_code, pr.nama_pemohon, pr.partner_name, pr.kecamatan, pr.nomor_wa,
               pr.place_name, pr.place_type, pr.pickup_type, pr.service_type, 
               COALESCE(pr.price_per_kg, 0) AS price_per_kg, pr.catatan, pr.latitude, pr.longitude, pr.tanggal_jemput AS tanggal_tugas,
               (SELECT GROUP_CONCAT(wc.name SEPARATOR ', ') FROM pickup_request_items pri JOIN waste_categories wc ON wc.id=pri.category_id WHERE pri.pickup_id=pr.id) AS jenis_sampah,
               pr.berat_total_kg, pr.status, pr.created_at, 'pickup' as req_type,
               0 as biaya_aktual, 0 as biaya_estimasi,
               o.officer_code
        FROM pickup_requests pr
        LEFT JOIN officers o ON o.id = pr.officer_id
        WHERE YEAR(pr.created_at)=? AND MONTH(pr.created_at)=?
    ";
    $params = [$year, $month];
    if ($fKec) {
        $sql .= " AND pr.kecamatan = ?";
        $params[] = $fKec;
    }
    $sql .= " ORDER BY pr.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
} else {
    $sql = "
        SELECT cr.id, cr.request_code, cr.nama_pemohon, NULL as partner_name, cr.kecamatan, cr.nomor_wa,
               NULL as place_name, NULL as place_type, 'C' as pickup_type, 'Paid' as service_type, 
               0 AS price_per_kg, cr.catatan, cr.latitude, cr.longitude, cr.tanggal_tugas,
               (SELECT GROUP_CONCAT(wc.name SEPARATOR ', ') FROM cleanup_items ci JOIN waste_categories wc ON wc.id=ci.category_id WHERE ci.cleanup_id=cr.id) AS jenis_sampah,
               (SELECT SUM(berat_kg) FROM cleanup_items WHERE cleanup_id=cr.id) AS berat_total_kg,
               cr.status, cr.created_at, 'cleanup' as req_type, cr.biaya_aktual, cr.biaya_estimasi,
               o.officer_code
        FROM cleanup_requests cr
        LEFT JOIN officers o ON o.id = cr.officer_id
        WHERE YEAR(cr.created_at)=? AND MONTH(cr.created_at)=?
    ";
    $params = [$year, $month];
    if ($fKec) {
        $sql .= " AND cr.kecamatan = ?";
        $params[] = $fKec;
    }
    $sql .= " ORDER BY cr.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
}
$rows = $stmt->fetchAll();

// ── EXPORT HANDLER LAPORAN BULANAN ────────────────────────────
if (isset($_GET['export'])) {
    $bulanId = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $export_type = $_GET['export'];
    $layanan = $type === 'cleanup' ? 'Clean Up Service' : 'Daur Ulang';
    $filename = 'laporan_bulanan_' . $type . '_' . $year . '_' . $month;

    if ($export_type === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename . '.xls');
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head><meta charset="utf-8">';
        echo '<!--[if gte mso 9]><xml>';
        echo '<x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
        echo '<x:Name>Laporan Bulanan</x:Name>';
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
        echo '<h3 style="font-family: Calibri, Arial, sans-serif; font-size: 16pt; font-weight: bold; margin: 0 0 10px 0; color: #000000;">LAPORAN BULANAN ' . strtoupper($layanan) . '</h3>';
        echo '<p style="font-family: Calibri, Arial, sans-serif; font-size: 11pt; margin: 0 0 15px 0; color: #475569;">Bulan: ' . $bulanId[$month] . ' ' . $year . '</p>';
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
            <title>Laporan Laporan Bulanan <?= htmlspecialchars($layanan) ?></title>
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
                    <h2>LAPORAN BULANAN <?= htmlspecialchars(strtoupper($layanan)) ?></h2>
                    <p>Dicetak pada: <?= date('d M Y H:i') ?> WITA</p>
                </div>
            </div>

            <div class="meta-grid">
                <div class="meta-box">
                    <h3>Periode Laporan</h3>
                    <div class="meta-row">
                        <span class="meta-label">Bulan:</span>
                        <span class="meta-value"><?= $bulanId[$month] ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Tahun:</span>
                        <span class="meta-value"><?= $year ?></span>
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
                            <td colspan="18" style="text-align:center; padding: 20px;">Tidak ada transaksi pada bulan ini.</td>
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

// Statistik bulanan
$total    = count($rows);
$selesai  = count(array_filter($rows, fn($r) => $r['status']==='selesai'));
$batal    = count(array_filter($rows, fn($r) => $r['status']==='dibatalkan'));
$berat    = array_sum(array_column($rows,'berat_total_kg'));
$cleanupCount = count(array_filter($rows, fn($r) => $r['req_type']==='cleanup'));
$pendapatan   = array_sum(array_map(function($r) {
    if ($r['req_type'] === 'cleanup') {
        return $r['status'] === 'selesai' ? (float)$r['biaya_aktual'] : (float)$r['biaya_estimasi'];
    }
    return 0;
}, $rows));

// Per minggu dalam bulan
if ($type === 'pickup') {
    $sqlW = "
        SELECT WEEK(created_at,1) as wk, MIN(DATE(created_at)) as tgl_awal, COUNT(*) as cnt 
        FROM pickup_requests WHERE YEAR(created_at)=? AND MONTH(created_at)=?
    ";
    $paramsW = [$year, $month];
    if ($fKec) {
        $sqlW .= " AND kecamatan = ?";
        $paramsW[] = $fKec;
    }
    $sqlW .= " GROUP BY WEEK(created_at,1) ORDER BY wk ASC";
    $perMinggu = $db->prepare($sqlW);
    $perMinggu->execute($paramsW);
} else {
    $sqlW = "
        SELECT WEEK(created_at,1) as wk, MIN(DATE(created_at)) as tgl_awal, COUNT(*) as cnt 
        FROM cleanup_requests WHERE YEAR(created_at)=? AND MONTH(created_at)=?
    ";
    $paramsW = [$year, $month];
    if ($fKec) {
        $sqlW .= " AND kecamatan = ?";
        $paramsW[] = $fKec;
    }
    $sqlW .= " GROUP BY WEEK(created_at,1) ORDER BY wk ASC";
    $perMinggu = $db->prepare($sqlW);
    $perMinggu->execute($paramsW);
}
$weekData = $perMinggu->fetchAll();

// Per kecamatan bulan ini
if ($type === 'pickup') {
    $sqlK = "
        SELECT kecamatan, COUNT(*) as cnt, SUM(COALESCE(berat_total_kg,0)) as total_kg 
        FROM pickup_requests WHERE YEAR(created_at)=? AND MONTH(created_at)=? AND kecamatan IS NOT NULL
    ";
    $paramsK = [$year, $month];
    if ($fKec) {
        $sqlK .= " AND kecamatan = ?";
        $paramsK[] = $fKec;
    }
    $sqlK .= " GROUP BY kecamatan ORDER BY cnt DESC";
    $kecStmt = $db->prepare($sqlK);
    $kecStmt->execute($paramsK);
} else {
    $sqlK = "
        SELECT cr.kecamatan, COUNT(*) as cnt, SUM(COALESCE(ci.berat,0)) as total_kg 
        FROM cleanup_requests cr 
        LEFT JOIN (SELECT cleanup_id, SUM(berat_kg) as berat FROM cleanup_items GROUP BY cleanup_id) ci ON ci.cleanup_id=cr.id 
        WHERE YEAR(cr.created_at)=? AND MONTH(cr.created_at)=? AND cr.kecamatan IS NOT NULL
    ";
    $paramsK = [$year, $month];
    if ($fKec) {
        $sqlK .= " AND cr.kecamatan = ?";
        $paramsK[] = $fKec;
    }
    $sqlK .= " GROUP BY cr.kecamatan ORDER BY cnt DESC";
    $kecStmt = $db->prepare($sqlK);
    $kecStmt->execute($paramsK);
}
$kecRows = $kecStmt->fetchAll();

// Jenis sampah bulan ini
if ($type === 'pickup') {
    $sqlWa = "
        SELECT wc.name, wc.ikon_emoji, SUM(COALESCE(pri.estimasi_kg, 0)) as total_kg
        FROM pickup_request_items pri
        JOIN pickup_requests pr ON pr.id=pri.pickup_id
        JOIN waste_categories wc ON wc.id=pri.category_id
        WHERE YEAR(pr.created_at)=? AND MONTH(pr.created_at)=?
    ";
    $paramsWa = [$year, $month];
    if ($fKec) {
        $sqlWa .= " AND pr.kecamatan = ?";
        $paramsWa[] = $fKec;
    }
    $sqlWa .= " GROUP BY wc.id, wc.name, wc.ikon_emoji
        ORDER BY total_kg DESC LIMIT 6";
    $wasteStmt = $db->prepare($sqlWa);
    $wasteStmt->execute($paramsWa);
} else {
    $sqlWa = "
        SELECT wc.name, wc.ikon_emoji, SUM(COALESCE(ci.berat_kg, 0)) as total_kg
        FROM cleanup_items ci
        JOIN cleanup_requests cr ON cr.id=ci.cleanup_id
        JOIN waste_categories wc ON wc.id=ci.category_id
        WHERE YEAR(cr.created_at)=? AND MONTH(cr.created_at)=?
    ";
    $paramsWa = [$year, $month];
    if ($fKec) {
        $sqlWa .= " AND cr.kecamatan = ?";
        $paramsWa[] = $fKec;
    }
    $sqlWa .= " GROUP BY wc.id, wc.name, wc.ikon_emoji
        ORDER BY total_kg DESC LIMIT 6";
    $wasteStmt = $db->prepare($sqlWa);
    $wasteStmt->execute($paramsWa);
}
$wasteRows = $wasteStmt->fetchAll();

// Bulan Indonesia
$bulanId = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

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
  <h1>Laporan Bulanan</h1>
  <p>Rekap dan analisis <?= $type === 'cleanup' ? 'Clean Up Service' : 'Penjemputan Sampah Daur Ulang' ?> per bulan — <strong><?= $bulanId[$month] ?> <?= $year ?></strong><?= $fKec ? " di <strong>Kecamatan " . htmlspecialchars($fKec) . "</strong>" : "" ?></p>
</div>

<!-- Navigasi Laporan -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <a href="laporan_harian.php?type=<?= $type ?>"   class="btn btn-outline btn-sm">📅 Harian</a>
  <a href="laporan_mingguan.php?type=<?= $type ?>" class="btn btn-outline btn-sm">📆 Mingguan</a>
  <span class="btn btn-sm" style="background:#f0fdf4;color:#16a34a;border:1.5px solid #bbf7d0;font-weight:700;cursor:default">📊 Bulanan</span>
  <a href="analisis_data.php"    class="btn btn-outline btn-sm">🔬 Analisis Data</a>
  <a href="dashboard.php"        class="btn btn-outline btn-sm" style="margin-left:auto">← Dashboard</a>
</div>

<!-- Tab Filter Layanan -->
<div class="tabs-container" style="display:flex;gap:12px;margin-bottom:20px;border-bottom:2px solid #f1f5f9;padding-bottom:1px">
  <a href="?offset=<?= $monthOffset ?>&type=pickup<?= $fKec ? '&kecamatan=' . urlencode($fKec) : '' ?>" class="tab-link" style="padding:10px 16px;text-decoration:none;font-weight:700;font-size:14px;color:<?= $type === 'pickup' ? 'var(--green-700)' : '#64748b' ?>;border-bottom:3px solid <?= $type === 'pickup' ? 'var(--green-700)' : 'transparent' ?>;margin-bottom:-2px">🚛 Daur Ulang (Pickup)</a>
  <a href="?offset=<?= $monthOffset ?>&type=cleanup<?= $fKec ? '&kecamatan=' . urlencode($fKec) : '' ?>" class="tab-link" style="padding:10px 16px;text-decoration:none;font-weight:700;font-size:14px;color:<?= $type === 'cleanup' ? 'var(--green-700)' : '#64748b' ?>;border-bottom:3px solid <?= $type === 'cleanup' ? 'var(--green-700)' : 'transparent' ?>;margin-bottom:-2px">🧹 Clean Up Service</a>
</div>

<!-- Navigasi Bulan -->
<div class="card mb-24" style="padding:14px 20px">
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <a href="laporan_bulanan.php?offset=<?= $monthOffset-1 ?>&type=<?= $type ?><?= $fKec ? '&kecamatan=' . urlencode($fKec) : '' ?>" class="btn btn-outline btn-sm">◀ Bulan Lalu</a>
    
    <!-- Dropdown Selector untuk Akses Langsung -->
    <form method="GET" style="display:inline-flex;align-items:center;gap:6px;">
      <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
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
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <?php if ($fKec): ?>
        <a href="laporan_bulanan.php?month=<?= $month ?>&year=<?= $year ?>&type=<?= $type ?>" class="btn btn-outline btn-sm" title="Hapus Filter Kecamatan">✕ Reset</a>
      <?php endif; ?>
    </form>

    <a href="laporan_bulanan.php?offset=<?= $monthOffset+1 ?>&type=<?= $type ?><?= $fKec ? '&kecamatan=' . urlencode($fKec) : '' ?>" class="btn btn-outline btn-sm">Bulan Depan ▶</a>
    
    <?php if ($monthOffset !== 0): ?>
    <a href="laporan_bulanan.php?type=<?= $type ?><?= $fKec ? '&kecamatan=' . urlencode($fKec) : '' ?>" class="btn btn-outline btn-sm">Bulan Ini</a>
    <?php endif; ?>
    
    <div style="margin-left:auto; display:flex; gap:8px;">
      <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="btn btn-sm" style="background:#10b981; color:white; font-weight:700; border-radius:6px; box-shadow:0 2px 4px rgba(16,185,129,0.2);" title="Export Excel (Formatted)">📊 Excel</a>
      <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" target="_blank" class="btn btn-sm" style="background:#ef4444; color:white; font-weight:700; border-radius:6px; box-shadow:0 2px 4px rgba(239,68,68,0.2);" title="Export PDF / Cetak Laporan">📄 PDF</a>
    </div>
  </div>
</div>

<!-- Stats Bulanan -->
<div class="stats-grid mb-24">
  <div class="stat-card green">
    <div class="stat-label">Total Request</div>
    <div class="stat-value"><?= $total ?></div>
    <div class="stat-sub"><?= $bulanId[$month] ?> <?= $year ?></div>
  </div>
  <div class="stat-card blue">
    <div class="stat-label">Selesai</div>
    <div class="stat-value"><?= $selesai ?></div>
    <div class="stat-sub"><?= $total>0 ? round($selesai/$total*100) : 0 ?>% dari total</div>
  </div>
  <div class="stat-card amber">
    <div class="stat-label">Total Berat</div>
    <div class="stat-value"><?= number_format($berat,1) ?> kg</div>
    <div class="stat-sub">estimasi terkumpul</div>
  </div>
  <div class="stat-card red">
    <?php if ($type === 'cleanup'): ?>
      <div class="stat-label">Pendapatan Clean Up</div>
      <div class="stat-value">Rp <?= number_format($pendapatan/1000) ?>K</div>
      <div class="stat-sub"><?= $cleanupCount ?> sesi @Rp50.000/jam</div>
    <?php else: ?>
      <div class="stat-label">Dibatalkan</div>
      <div class="stat-value"><?= $batal ?></div>
      <div class="stat-sub">request dibatalkan</div>
    <?php endif; ?>
  </div>
</div>

<!-- Tabel lengkap -->
<div class="card mb-24">
  <div class="card-title"><div class="ct-icon">📋</div> Semua Request — <?= $bulanId[$month] ?> <?= $year ?> <span style="font-size:12px;font-weight:400;color:#888">(<?= $total ?> request)</span></div>
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
        <tr><td colspan="18" style="text-align:center;color:#aaa;padding:30px">Tidak ada request pada bulan ini.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid-2 mb-24">
  <!-- Tren per Minggu -->
  <div class="card">
    <div class="card-title"><div class="ct-icon">📈</div> Tren Request per Minggu</div>
    <?php if ($weekData): ?>
    <div class="bar-chart">
      <?php
      $maxW = max(array_column($weekData,'cnt') ?: [1]);
      foreach ($weekData as $idx => $w):
        $h = round(($w['cnt']/$maxW)*120);
      ?>
      <div class="bar-item">
        <span class="bar-val"><?= $w['cnt'] ?></span>
        <div class="bar" style="height:<?= max($h,4) ?>px"></div>
        <span>Mg <?= $idx+1 ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p style="color:#aaa;text-align:center;padding:30px;font-size:13px">Tidak ada data</p>
    <?php endif; ?>
  </div>

  <!-- Distribusi Kecamatan -->
  <div class="card">
    <div class="card-title"><div class="ct-icon">🏆</div> Distribusi per Kecamatan</div>
    <?php if ($kecRows): ?>
    <?php
    $maxKec = max(array_column($kecRows,'cnt') ?: [1]);
    foreach ($kecRows as $i => $k):
      $pct = round(($k['cnt']/$maxKec)*100);
      $rankCls = $i===0?'rank-1':($i===1?'rank-2':($i===2?'rank-3':'rank-n'));
    ?>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
      <span class="priority-rank <?= $rankCls ?>"><?= $i+1 ?></span>
      <span style="flex:1;font-size:12px;font-weight:600"><?= htmlspecialchars($k['kecamatan']) ?></span>
      <div style="width:90px;background:#f0f0f0;border-radius:4px;height:6px;overflow:hidden">
        <div style="height:100%;background:var(--green-600);border-radius:4px;width:<?= $pct ?>%"></div>
      </div>
      <span style="font-size:12px;font-weight:700;min-width:22px;text-align:right"><?= $k['cnt'] ?></span>
      <span style="font-size:10px;color:#aaa;min-width:36px"><?= number_format((float)$k['total_kg'],0) ?> kg</span>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <p style="color:#aaa;text-align:center;padding:20px;font-size:13px">Tidak ada data</p>
    <?php endif; ?>
  </div>
</div>

<!-- Jenis Sampah -->
<?php if ($wasteRows): ?>
<div class="card mb-24">
  <div class="card-title"><div class="ct-icon">🗑️</div> Jenis Sampah Terkumpul — <?= $bulanId[$month] ?> <?= $year ?></div>
  <?php
  $maxWaste = max(array_column($wasteRows,'total_kg') ?: [1]);
  foreach ($wasteRows as $w):
    $pct = round(($w['total_kg']/$maxWaste)*100);
  ?>
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
    <span style="min-width:150px;font-size:12px"><?= htmlspecialchars($w['ikon_emoji'].' '.$w['name']) ?></span>
    <div style="flex:1;background:#f0f0f0;border-radius:4px;height:10px;overflow:hidden">
      <div style="height:100%;background:var(--green-600);border-radius:4px;width:<?= $pct ?>%"></div>
    </div>
    <span style="font-size:13px;font-weight:700;min-width:60px;text-align:right"><?= number_format((float)$w['total_kg'],1) ?> kg</span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
