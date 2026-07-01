<?php
// ============================================================
//  admin/pivot_pembayaran.php — Admin Panel: Pivot Table Pembayaran Mitra
//  Manado Recycle Hub
//  Merekap total berat sampah dan pembayaran otomatis ke mitra/warga
// ============================================================
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/auth.php';
requireRole('admin');

$page_id    = 'pivot_pembayaran';
$page_title = 'Pivot Table Pembayaran';
$db         = getDB();

// ── GET FILTERS & PERIODS ────────────────────────────────────
$period_type = $_GET['period_type'] ?? 'monthly';
if (!in_array($period_type, ['weekly', 'monthly', 'yearly', 'custom'])) {
    $period_type = 'monthly';
}

$year       = (int)($_GET['year'] ?? date('Y'));
$month      = (int)($_GET['month'] ?? date('n'));
$week       = (int)($_GET['week'] ?? date('W'));
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-t');
$search     = trim($_GET['search'] ?? '');

// Calculate date range based on period_type
if ($period_type === 'weekly') {
    $dto = new DateTime();
    $dto->setISODate($year, $week);
    $startDate = $dto->format('Y-m-d');
    $dto->modify('+6 days');
    $endDate = $dto->format('Y-m-d');
} elseif ($period_type === 'monthly') {
    $startDate = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
    $endDate   = date('Y-m-t', strtotime($startDate));
} elseif ($period_type === 'yearly') {
    $startDate = "$year-01-01";
    $endDate   = "$year-12-31";
} else { // custom
    $startDate = $start_date;
    $endDate   = $end_date;
}

// Format period label for UI / Export headers
$bulanId = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
if ($period_type === 'weekly') {
    $periodLabel = "Minggu Ke-$week Tahun $year (" . date('d M Y', strtotime($startDate)) . " - " . date('d M Y', strtotime($endDate)) . ")";
} elseif ($period_type === 'monthly') {
    $periodLabel = $bulanId[$month] . " " . $year;
} elseif ($period_type === 'yearly') {
    $periodLabel = "Tahun " . $year;
} else {
    $periodLabel = date('d M Y', strtotime($startDate)) . " s/d " . date('d M Y', strtotime($endDate));
}

// ── GET DATA FROM DATABASE ────────────────────────────────────
$where = ["pr.status = 'selesai'", "DATE(pr.created_at) BETWEEN ? AND ?"];
$params = [$startDate, $endDate];

if ($search !== '') {
    $where[] = "(pr.request_code LIKE ? OR pr.partner_name LIKE ? OR pr.nama_pemohon LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_sql = implode(" AND ", $where);

$query_str = "
    SELECT 
        pr.request_code, 
        COALESCE(NULLIF(TRIM(pr.partner_name), ''), TRIM(pr.nama_pemohon)) AS partner_name, 
        COUNT(pr.id) AS total_kunjungan, 
        SUM(COALESCE(pr.berat_total_kg, 0)) AS total_weight, 
        COALESCE(pr.price_per_kg, 0) AS price_per_kg, 
        pr.service_type,
        SUM(CASE WHEN pr.service_type = 'Paid' THEN COALESCE(pr.berat_total_kg, 0) * COALESCE(pr.price_per_kg, 0) ELSE 0 END) AS total_bayar
    FROM pickup_requests pr
    WHERE $where_sql
    GROUP BY pr.request_code, COALESCE(NULLIF(TRIM(pr.partner_name), ''), TRIM(pr.nama_pemohon)), pr.price_per_kg, pr.service_type
    ORDER BY pr.request_code ASC
";

$stmt = $db->prepare($query_str);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$grandKunjungan = 0;
$grandBerat = 0.0;
$grandBayar = 0.0;
$uniquePartners = [];

foreach ($rows as $r) {
    $grandKunjungan += (int)$r['total_kunjungan'];
    $grandBerat += (float)$r['total_weight'];
    $grandBayar += (float)$r['total_bayar'];
    $uniquePartners[trim($r['partner_name'])] = true;
}
$totalMitraCount = count($uniquePartners);

// Helper function to format decimal lat/lng to DMS if needed (consistent with config)
if (!function_exists('decToDms')) {
    function decToDms($lat, $lng): string {
        if ($lat === null || $lng === null || $lat == 0 || $lng == 0) return '-';
        $getDMS = function($dec, $is_lat) {
            $direction = $is_lat ? ($dec >= 0 ? 'N' : 'S') : ($dec >= 0 ? 'E' : 'W');
            $dec = abs($dec);
            $degrees = floor($dec);
            $minfloat = ($dec - $degrees) * 60;
            $minutes = floor($minfloat);
            $secfloat = ($minfloat - $minutes) * 60;
            $seconds = round($secfloat, 1);
            if ($seconds == 60) { $minutes++; $seconds = 0; }
            if ($minutes == 60) { $degrees++; $minutes = 0; }
            return $degrees . '°' . $minutes . '\'' . $seconds . '"' . $direction;
        };
        return $getDMS($lat, true) . ' ' . $getDMS($lng, false);
    }
}

// ── EXPORT HANDLER ────────────────────────────────────────────
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    $filename = 'pivot_pembayaran_mitra_' . str_replace(' ', '_', strtolower($periodLabel));

    if ($export_type === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename . '.xls');
        ?>
        <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
        <head>
            <meta charset="utf-8">
            <!--[if gte mso 9]><xml>
            <x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
            <x:Name>Pivot Rekap Pembayaran</x:Name>
            <x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
            </x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook>
            </xml><![endif]-->
            <style>
                table, th, td { font-family: Calibri, Arial, sans-serif; font-size: 11pt; border-collapse: collapse; }
                th { background-color: #1c6434; color: #ffffff; font-weight: bold; border: 1px solid #000000; padding: 6px; text-align: left; }
                td { border: 1px solid #000000; padding: 6px; text-align: left; }
                .number { text-align: right; }
                .bold { font-weight: bold; }
                .title { font-size: 16pt; font-weight: bold; color: #1c6434; margin-bottom: 5px; }
                .meta { font-size: 11pt; color: #475569; margin-bottom: 15px; }
            </style>
        </head>
        <body>
            <div class="title">PIVOT REKAP PEMBAYARAN MITRA - MANADO RECYCLE HUB</div>
            <div class="meta">Periode: <?= htmlspecialchars($periodLabel) ?></div>
            <table>
                <thead>
                    <tr>
                        <th style="width: 50px;">No</th>
                        <th style="width: 150px;">ID Pengumpul</th>
                        <th style="width: 250px;">Partner Name</th>
                        <th style="width: 120px; text-align: right;">Total Kunjungan</th>
                        <th style="width: 150px; text-align: right;">Total Weight (kg)</th>
                        <th style="width: 150px; text-align: right;">Price per kg (Rp)</th>
                        <th style="width: 180px; text-align: right;">Total Bayar (Rp)</th>
                        <th style="width: 120px;">Service Type</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = 1;
                    if ($rows): 
                        foreach ($rows as $r): 
                            $price_formatted = number_format($r['price_per_kg'], 0, '', '');
                            $total_bayar_formatted = number_format($r['total_bayar'], 0, '', '');
                            $weight_val = (float)$r['total_weight'];
                    ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td><?= htmlspecialchars($r['request_code']) ?></td>
                            <td><?= htmlspecialchars($r['partner_name']) ?></td>
                            <td class="number"><?= (int)$r['total_kunjungan'] ?></td>
                            <td class="number"><?= $weight_val ?></td>
                            <td class="number"><?= $price_formatted ?></td>
                            <td class="number"><?= $total_bayar_formatted ?></td>
                            <td><?= htmlspecialchars($r['service_type']) ?></td>
                        </tr>
                    <?php 
                        endforeach; 
                    ?>
                        <tr class="bold" style="background-color: #e2e8f0;">
                            <td colspan="3">GRAND TOTAL</td>
                            <td class="number"><?= $grandKunjungan ?></td>
                            <td class="number"><?= $grandBerat ?></td>
                            <td class="number">-</td>
                            <td class="number"><?= number_format($grandBayar, 0, '', '') ?></td>
                            <td>-</td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center;">Tidak ada data pada periode terpilih.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </body>
        </html>
        <?php
        exit;
    } elseif ($export_type === 'pdf') {
        ?>
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8">
            <title>Laporan Pivot Pembayaran Mitra — <?= SITE_NAME ?></title>
            <style>
                @page { size: portrait; margin: 15mm; }
                body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; margin: 0; line-height: 1.4; font-size: 10px; }
                .header-container { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #1c6434; padding-bottom: 10px; margin-bottom: 15px; }
                .logo-area h1 { font-size: 18px; color: #1c6434; margin: 0 0 3px 0; font-weight: 800; letter-spacing: 0.5px; }
                .logo-area p { margin: 0; font-size: 9px; color: #666; }
                .report-title { text-align: right; }
                .report-title h2 { font-size: 14px; color: #2d3748; margin: 0 0 3px 0; font-weight: 700; text-transform: uppercase; }
                .report-title p { margin: 0; font-size: 8px; color: #718096; }
                
                .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; }
                .meta-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; }
                .meta-box h3 { margin: 0 0 6px 0; font-size: 10px; color: #4a5568; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #cbd5e0; padding-bottom: 3px; }
                .meta-row { display: flex; justify-content: space-between; font-size: 9px; margin-bottom: 4px; }
                .meta-row:last-child { margin-bottom: 0; }
                .meta-label { color: #718096; font-weight: 500; }
                .meta-value { color: #2d3748; font-weight: 700; }
                
                table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 9px; }
                th { background-color: #1c6434; color: #ffffff; font-weight: 700; border: 1px solid #e2e8f0; padding: 8px 6px; text-align: left; text-transform: uppercase; font-size: 8px; letter-spacing: 0.3px; }
                td { border: 1px solid #e2e8f0; padding: 8px 6px; text-align: left; color: #2d3748; }
                .text-right { text-align: right; }
                .font-bold { font-weight: 700; }
                .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 8px; font-weight: 700; text-transform: uppercase; }
                .badge-paid { background: #fee2e2; color: #991b1b; }
                .badge-free { background: #e0f2fe; color: #0369a1; }
                
                .signature-section { display: flex; justify-content: flex-end; margin-top: 30px; font-size: 9px; page-break-inside: avoid; }
                .signature-box { text-align: center; width: 180px; }
                .signature-line { border-bottom: 1px solid #718096; height: 50px; margin-bottom: 8px; }
                
                @media print {
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="no-print" style="margin-bottom: 20px; background: #fff3cd; color: #856404; padding: 12px 20px; border-radius: 6px; border: 1px solid #ffeeba; display: flex; justify-content: space-between; align-items: center; font-size: 12px; font-weight: 600;">
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
                    <h2>PIVOT REKAP PEMBAYARAN MITRA</h2>
                    <p>Dicetak pada: <?= date('d M Y H:i') ?> WITA</p>
                </div>
            </div>

            <div class="meta-grid">
                <div class="meta-box">
                    <h3>Parameter Laporan</h3>
                    <div class="meta-row">
                        <span class="meta-label">Jenis Periode:</span>
                        <span class="meta-value" style="text-transform: capitalize;"><?= htmlspecialchars($period_type) ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Periode Terpilih:</span>
                        <span class="meta-value"><?= htmlspecialchars($periodLabel) ?></span>
                    </div>
                    <?php if ($search): ?>
                        <div class="meta-row">
                            <span class="meta-label">Pencarian:</span>
                            <span class="meta-value">"<?= htmlspecialchars($search) ?>"</span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="meta-box">
                    <h3>Ringkasan Pivot</h3>
                    <div class="meta-row">
                        <span class="meta-label">Total Mitra Terlibat:</span>
                        <span class="meta-value"><?= $totalMitraCount ?> pengumpul</span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Total Berat Terkumpul:</span>
                        <span class="meta-value" style="color: #c05621; font-size: 12px;"><?= number_format($grandBerat, 2, ',', '.') ?> kg</span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Total Pembayaran (Paid):</span>
                        <span class="meta-value" style="color: #0284c7; font-size: 12px;">Rp <?= number_format($grandBayar, 0, ',', '.') ?></span>
                    </div>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width: 40px;">No</th>
                        <th style="width: 100px;">ID Pengumpul</th>
                        <th>Partner Name</th>
                        <th style="text-align: right; width: 90px;">Total Kunjungan</th>
                        <th style="text-align: right; width: 100px;">Total Weight</th>
                        <th style="text-align: right; width: 100px;">Price per kg</th>
                        <th style="text-align: right; width: 120px;">Total Bayar</th>
                        <th style="width: 80px;">Service Type</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = 1;
                    if ($rows): 
                        foreach ($rows as $r): 
                    ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td class="font-bold" style="color: #1c6434;"><?= htmlspecialchars($r['request_code']) ?></td>
                            <td><?= htmlspecialchars($r['partner_name']) ?></td>
                            <td class="text-right"><?= (int)$r['total_kunjungan'] ?>x</td>
                            <td class="font-bold text-right"><?= number_format($r['total_weight'], 2, ',', '.') ?> kg</td>
                            <td class="text-right">Rp <?= number_format($r['price_per_kg'], 0, ',', '.') ?></td>
                            <td class="font-bold text-right" style="color: #0284c7;">Rp <?= number_format($r['total_bayar'], 0, ',', '.') ?></td>
                            <td>
                                <span class="badge <?= $r['service_type'] === 'Paid' ? 'badge-paid' : 'badge-free' ?>">
                                    <?= htmlspecialchars($r['service_type']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php 
                        endforeach; 
                    ?>
                        <tr class="font-bold" style="background-color: #f8fafc; border-top: 2px solid #1c6434;">
                            <td colspan="3">TOTAL KESELURUHAN</td>
                            <td class="text-right"><?= $grandKunjungan ?>x</td>
                            <td class="text-right"><?= number_format($grandBerat, 2, ',', '.') ?> kg</td>
                            <td class="text-right">-</td>
                            <td class="text-right" style="color: #0284c7;">Rp <?= number_format($grandBayar, 0, ',', '.') ?></td>
                            <td>-</td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 20px; color: #888;">Tidak ada data pada periode terpilih.</td>
                        </tr>
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

// Generate the list of weeks for the week dropdown
$weeksList = [];
$dto = new DateTime();
$dto->setISODate($year, 1);
for ($w = 1; $w <= 53; $w++) {
    if ($dto->format('o') != $year && $w > 50) {
        break;
    }
    $start = $dto->format('d M');
    $dto->modify('+6 days');
    $end = $dto->format('d M Y');
    $weeksList[$w] = "Minggu $w ($start - $end)";
    $dto->modify('+1 day');
}

// Include layout header
require_once __DIR__ . '/layout/header.php';
?>

<style>
    /* Styling khusus Pivot Table */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)) !important;
        gap: 16px;
        margin-bottom: 24px;
    }
    .pivot-filter-card {
        background: #ffffff;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 20px;
        margin-bottom: 24px;
        border-left: 4px solid var(--green-700);
    }
    .pivot-tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 20px;
        border-bottom: 1px solid #e2e8f0;
        padding-bottom: 8px;
    }
    .pivot-tab-btn {
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        color: #64748b;
        padding: 8px 16px;
        font-size: 13px;
        font-weight: 700;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s var(--smooth-transit);
    }
    .pivot-tab-btn:hover {
        background: #f1f5f9;
        color: #1e293b;
    }
    .pivot-tab-btn.active {
        background: var(--green-700);
        color: #ffffff;
        border-color: var(--green-700);
        box-shadow: 0 4px 6px -1px rgba(28, 100, 52, 0.2);
    }
    .pivot-filter-inputs {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        align-items: flex-end;
    }
    .pivot-filter-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .pivot-filter-group label {
        font-size: 11px;
        font-weight: 700;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .pivot-input {
        padding: 8px 12px;
        border: 1.5px solid #cbd5e1;
        border-radius: 8px;
        font-size: 13px;
        outline: none;
        min-width: 140px;
        background: #f8fafc;
        font-family: inherit;
        transition: all 0.2s var(--smooth-transit);
    }
    .pivot-input:focus {
        border-color: var(--green-700);
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(28, 100, 52, 0.1);
    }
    .pivot-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    .pivot-table th {
        background-color: #f8fafc;
        color: #475569;
        font-weight: 800;
        text-transform: uppercase;
        font-size: 9.5px;
        letter-spacing: 0.5px;
        padding: 12px 10px;
        border-bottom: 2px solid #cbd5e1;
        text-align: left;
    }
    .pivot-table td {
        padding: 12px 10px;
        border-bottom: 1px solid #f1f5f9;
        color: #1e293b;
        vertical-align: middle;
    }
    .pivot-table tbody tr:hover {
        background-color: #f0fdf4;
    }
    .pivot-id-badge {
        font-weight: 800;
        color: var(--green-700);
        background: var(--green-50);
        border: 1px solid var(--green-200);
        padding: 4px 8px;
        border-radius: 6px;
        display: inline-block;
        font-family: monospace;
        font-size: 11px;
    }
    .pivot-badge-b {
        color: #1c6434;
        background: #f0fdf4;
        border-color: #bbf7d0;
    }
    .pivot-badge-s {
        color: #b45309;
        background: #fffbeb;
        border-color: #fef3c7;
    }
    .pivot-badge-c {
        color: #6b21a8;
        background: #faf5ff;
        border-color: #ede9fe;
    }
    .pivot-weight {
        font-size: 13px;
        font-weight: 800;
        color: #b45309;
    }
    .pivot-price {
        font-weight: 600;
        color: #475569;
    }
    .pivot-pay {
        font-size: 13px;
        font-weight: 800;
        color: #0284c7;
    }
    .pivot-badge-service {
        padding: 3px 8px;
        border-radius: 9999px;
        font-weight: 700;
        font-size: 9px;
        text-transform: uppercase;
        display: inline-block;
    }
    .pivot-badge-service.paid {
        background: #fee2e2;
        color: #b91c1c;
    }
    .pivot-badge-service.free {
        background: #e0f2fe;
        color: #0369a1;
    }

    /* Media query responsif untuk HP/Mobile */
    @media (max-width: 768px) {
        .pivot-filter-card {
            padding: 14px 16px;
        }
        .pivot-tabs {
            flex-wrap: wrap;
            gap: 6px;
        }
        .pivot-tab-btn {
            flex: 1 1 calc(50% - 6px);
            text-align: center;
            padding: 6px 10px;
            font-size: 12px;
        }
        .pivot-filter-inputs {
            flex-direction: column;
            align-items: stretch;
            gap: 12px;
        }
        .pivot-filter-group {
            width: 100%;
        }
        .pivot-input {
            width: 100% !important;
            min-width: unset;
        }
        .btn-group-wrapper,
        .export-group-wrapper {
            width: 100%;
            display: flex;
            gap: 8px;
            margin-left: 0 !important;
        }
        .btn-group-wrapper .btn,
        .export-group-wrapper .btn {
            flex: 1;
            width: 100% !important;
            justify-content: center;
            display: inline-flex;
            align-items: center;
        }
        .btn-group-wrapper a,
        .export-group-wrapper a {
            flex: 1;
            width: 100% !important;
            justify-content: center;
            display: inline-flex;
            align-items: center;
        }
        .stat-value {
            font-size: 22px !important;
        }
    }
</style>

<div class="page-header">
    <h1>🧩 Pivot Table Pembayaran Mitra</h1>
    <p>Rekapitulasi total berat sampah dan rincian pembayaran otomatis ke pengumpul (Recycle Bin / Collector / Sack-Karom) per periode.</p>
</div>

<!-- Navigasi Laporan -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap">
    <a href="laporan_harian.php"    class="btn btn-outline btn-sm">📅 Lap. Harian</a>
    <a href="laporan_mingguan.php"  class="btn btn-outline btn-sm">📆 Lap. Mingguan</a>
    <a href="laporan_bulanan.php"   class="btn btn-outline btn-sm">🗓️ Lap. Bulanan</a>
    <a href="analisis_data.php"     class="btn btn-outline btn-sm">📊 Analisis Data</a>
    <span class="btn btn-sm" style="background:#f0fdf4;color:#16a34a;border:1.5px solid #bbf7d0;font-weight:700;cursor:default">🧩 Pivot Rekap Bayar</span>
</div>

<!-- Period Filter Panel Card -->
<div class="pivot-filter-card">
    <div class="pivot-tabs">
        <button type="button" class="pivot-tab-btn <?= $period_type === 'monthly' ? 'active' : '' ?>" onclick="switchPeriodType('monthly')">📅 Bulanan</button>
        <button type="button" class="pivot-tab-btn <?= $period_type === 'weekly' ? 'active' : '' ?>" onclick="switchPeriodType('weekly')">📆 Mingguan</button>
        <button type="button" class="pivot-tab-btn <?= $period_type === 'yearly' ? 'active' : '' ?>" onclick="switchPeriodType('yearly')">🗓️ Tahunan</button>
        <button type="button" class="pivot-tab-btn <?= $period_type === 'custom' ? 'active' : '' ?>" onclick="switchPeriodType('custom')">⚡ Kustom</button>
    </div>

    <form method="GET" id="pivotFilterForm">
        <input type="hidden" name="period_type" id="periodTypeInput" value="<?= htmlspecialchars($period_type) ?>">
        
        <div class="pivot-filter-inputs">
            <!-- Search Input (Always Visible) -->
            <div class="pivot-filter-group" style="flex: 1; min-width: 200px;">
                <label for="searchInput">Cari Mitra / ID:</label>
                <input type="text" name="search" id="searchInput" class="pivot-input" style="width: 100%;" placeholder="Ketik Kode/Nama Mitra..." value="<?= htmlspecialchars($search) ?>">
            </div>

            <!-- Inputs for Monthly Period -->
            <div class="pivot-filter-group period-fields monthly-fields" style="display: <?= $period_type === 'monthly' ? 'flex' : 'none' ?>;">
                <label for="monthInput">Bulan:</label>
                <select name="month" id="monthInput" class="pivot-input">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= $bulanId[$m] ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <!-- Inputs for Weekly Period -->
            <div class="pivot-filter-group period-fields weekly-fields" style="display: <?= $period_type === 'weekly' ? 'flex' : 'none' ?>;">
                <label for="weekInput">Pilih Minggu:</label>
                <select name="week" id="weekInput" class="pivot-input" style="min-width: 250px;">
                    <?php foreach ($weeksList as $wNum => $wLabel): ?>
                        <option value="<?= $wNum ?>" <?= $week === $wNum ? 'selected' : '' ?>><?= htmlspecialchars($wLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Inputs for Year (Shared between Weekly, Monthly, Yearly) -->
            <div class="pivot-filter-group period-fields yearly-fields monthly-fields weekly-fields" style="display: <?= $period_type !== 'custom' ? 'flex' : 'none' ?>;">
                <label for="yearInput">Tahun:</label>
                <select name="year" id="yearInput" class="pivot-input">
                    <?php 
                    $currYear = (int)date('Y');
                    for ($y = $currYear - 3; $y <= $currYear + 1; $y++): 
                    ?>
                        <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <!-- Inputs for Custom Period -->
            <div class="pivot-filter-group period-fields custom-fields" style="display: <?= $period_type === 'custom' ? 'flex' : 'none' ?>;">
                <label for="startDateInput">Tanggal Mulai:</label>
                <input type="date" name="start_date" id="startDateInput" class="pivot-input" value="<?= htmlspecialchars($start_date) ?>">
            </div>
            <div class="pivot-filter-group period-fields custom-fields" style="display: <?= $period_type === 'custom' ? 'flex' : 'none' ?>;">
                <label for="endDateInput">Tanggal Selesai:</label>
                <input type="date" name="end_date" id="endDateInput" class="pivot-input" value="<?= htmlspecialchars($end_date) ?>">
            </div>

            <!-- Action Buttons -->
            <div class="btn-group-wrapper">
                <button type="submit" class="btn btn-primary" style="height: 38px; font-weight: 700;">🔍 Terapkan</button>
                <a href="pivot_pembayaran.php" class="btn btn-outline" style="height: 38px; font-weight: 700; align-items: center; display: inline-flex;">🔄 Reset</a>
            </div>

            <!-- Exports -->
            <div class="export-group-wrapper" style="margin-left: auto;">
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="btn btn-sm" style="background:#10b981; color:white; font-weight:700; border-radius:6px; box-shadow:0 2px 4px rgba(16,185,129,0.2); height: 38px;" title="Export Excel (Formatted)">📊 Excel</a>
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" target="_blank" class="btn btn-sm" style="background:#ef4444; color:white; font-weight:700; border-radius:6px; box-shadow:0 2px 4px rgba(239,68,68,0.2); height: 38px;" title="Export PDF / Cetak Laporan">📄 PDF</a>
            </div>
        </div>
    </form>
</div>

<!-- Stats Grid -->
<div class="stats-grid mb-24">
    <div class="stat-card green">
        <div class="stat-label">👥 Mitra Aktif</div>
        <div class="stat-value" style="font-size: 26px; font-weight: 800;"><?= $totalMitraCount ?></div>
        <div class="stat-sub">terlibat transaksi</div>
    </div>
    <div class="stat-card amber">
        <div class="stat-label">🚛 Total Kunjungan</div>
        <div class="stat-value" style="font-size: 26px; font-weight: 800;"><?= $grandKunjungan ?>x</div>
        <div class="stat-sub">penjemputan selesai</div>
    </div>
    <div class="stat-card blue">
        <div class="stat-label">⚖️ Total Berat</div>
        <div class="stat-value" style="font-size: 26px; font-weight: 800;"><?= number_format($grandBerat, 2, ',', '.') ?> <span style="font-size: 13px; font-weight: 700; color: #64748b;">kg</span></div>
        <div class="stat-sub">sampah terkumpul</div>
    </div>
    <div class="stat-card red">
        <div class="stat-label">🪙 Total Pembayaran</div>
        <div class="stat-value" style="font-size: 26px; font-weight: 800; color: #0284c7;">Rp <?= number_format($grandBayar, 0, ',', '.') ?></div>
        <div class="stat-sub">untuk Paid service</div>
    </div>
</div>

<!-- Pivot Table Card -->
<div class="card mb-24">
    <div class="card-title">
        <div class="ct-icon">📊</div> 
        <span>Pivot Table Pembayaran Mitra — <strong><?= htmlspecialchars($periodLabel) ?></strong></span>
    </div>
    
    <div class="table-wrap">
        <table class="pivot-table">
            <thead>
                <tr>
                    <th style="width: 50px;">No</th>
                    <th style="width: 150px;">ID Pengumpul</th>
                    <th>Partner Name</th>
                    <th style="text-align: right; width: 120px;">Total Kunjungan</th>
                    <th style="text-align: right; width: 150px;">Total Weight (kg)</th>
                    <th style="text-align: right; width: 150px;">Price per kg</th>
                    <th style="text-align: right; width: 180px;">Total Bayar</th>
                    <th style="width: 120px; text-align: center;">Service Type</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $no = 1;
                if ($rows):
                    foreach ($rows as $r): 
                        // Determine badge class depending on request prefix (B = Bin, S = Community/Karom, C = Collector)
                        $badgeClass = '';
                        $code = strtoupper($r['request_code']);
                        if (str_contains($code, '-B-')) {
                            $badgeClass = 'pivot-badge-b';
                        } elseif (str_contains($code, '-S-')) {
                            $badgeClass = 'pivot-badge-s';
                        } elseif (str_contains($code, '-C-')) {
                            $badgeClass = 'pivot-badge-c';
                        }
                ?>
                    <tr>
                        <td><?= $no++ ?></td>
                        <td><span class="pivot-id-badge <?= $badgeClass ?>"><?= htmlspecialchars($r['request_code']) ?></span></td>
                        <td style="font-weight: 600; color: #0f172a;"><?= htmlspecialchars($r['partner_name']) ?></td>
                        <td class="text-right" style="font-weight: 600;"><?= (int)$r['total_kunjungan'] ?>x kunjungan</td>
                        <td class="text-right pivot-weight"><?= number_format($r['total_weight'], 2, ',', '.') ?> kg</td>
                        <td class="text-right pivot-price">Rp <?= number_format($r['price_per_kg'], 0, ',', '.') ?> / kg</td>
                        <td class="text-right pivot-pay">Rp <?= number_format($r['total_bayar'], 0, ',', '.') ?></td>
                        <td style="text-align: center;">
                            <span class="pivot-badge-service <?= strtolower($r['service_type']) === 'paid' ? 'paid' : 'free' ?>">
                                <?= htmlspecialchars($r['service_type']) ?>
                            </span>
                        </td>
                    </tr>
                <?php 
                    endforeach; 
                ?>
                    <!-- Grand Total Row -->
                    <tr style="background-color: #f8fafc; font-weight: bold; border-top: 2.5px solid #cbd5e1; border-bottom: 2px solid #cbd5e1;">
                        <td colspan="3" style="font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; padding: 14px 10px;">Total Keseluruhan</td>
                        <td class="text-right" style="font-size: 13px;"><?= $grandKunjungan ?> kunjungan</td>
                        <td class="text-right pivot-weight" style="font-size: 14px;"><?= number_format($grandBerat, 2, ',', '.') ?> kg</td>
                        <td class="text-right">-</td>
                        <td class="text-right pivot-pay" style="font-size: 14px; color: #0284c7;">Rp <?= number_format($grandBayar, 0, ',', '.') ?></td>
                        <td style="text-align: center;">-</td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: #64748b; padding: 40px;">
                            <div style="font-size: 24px; margin-bottom: 10px;">📋</div>
                            <strong>Tidak ada data penjemputan daur ulang yang selesai untuk periode terpilih.</strong>
                            <p style="font-size: 11px; color: #94a3b8; margin-top: 4px;">Pastikan data penjemputan telah selesai ditimbang dan diproses oleh petugas lapangan.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    function switchPeriodType(type) {
        // Update hidden input
        document.getElementById('periodTypeInput').value = type;
        
        // Update active class on tab buttons
        document.querySelectorAll('.pivot-tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        event.target.classList.add('active');
        
        // Show/hide relevant fields
        document.querySelectorAll('.period-fields').forEach(field => {
            field.style.display = 'none';
        });
        
        // Show elements belonging to selected type class
        document.querySelectorAll('.' + type + '-fields').forEach(field => {
            field.style.display = 'flex';
        });
    }
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
