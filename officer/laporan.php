<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/auth.php';
requireRole('officer');

$db = getDB();

// Resolve officer_id
$officerId = (int)($_SESSION['officer_id'] ?? 0);
if (!$officerId) {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid) {
        $r = $db->prepare("SELECT id FROM officers WHERE user_id=? LIMIT 1");
        $r->execute([$uid]);
        $officerId = (int)$r->fetchColumn();
        if ($officerId) $_SESSION['officer_id'] = $officerId;
    }
}

// Fetch officer details
$officer = null;
if ($officerId) {
    try {
        $s = $db->prepare("SELECT o.*, u.email, u.nomor_wa AS user_wa FROM officers o LEFT JOIN users u ON u.id=o.user_id WHERE o.id=?");
        $s->execute([$officerId]);
        $officer = $s->fetch();
    } catch(Exception $e){}
}
if (!$officer) {
    $officer = ['id'=>$officerId,'nama'=>'Petugas','officer_code'=>'OFC-0001','kendaraan'=>'-','status'=>'aktif','email'=>'','user_wa'=>''];
}

// Period & type filters
$tgl_mulai = $_GET['tgl_mulai'] ?? date('Y-m-01');
$tgl_selesai = $_GET['tgl_selesai'] ?? date('Y-m-d');
$type = $_GET['type'] ?? 'all';
if (!in_array($type, ['all', 'pickup', 'cleanup'])) {
    $type = 'all';
}

$rows = [];

// Fetch Pickup Requests for this officer in period
if ($type === 'all' || $type === 'pickup') {
    $pickupStmt = $db->prepare("
        SELECT 
            'pickup' AS type,
            id,
            request_code,
            nama_pemohon,
            nomor_wa,
            alamat_jemput AS alamat,
            kecamatan,
            kelurahan,
            tanggal_jemput AS tanggal_tugas,
            berat_total_kg,
            status,
            catatan_officer,
            created_at
        FROM pickup_requests
        WHERE officer_id = ? AND (
            (tanggal_jemput BETWEEN ? AND ?) 
            OR (tanggal_jemput IS NULL AND DATE(created_at) BETWEEN ? AND ?)
        )
    ");
    $pickupStmt->execute([$officerId, $tgl_mulai, $tgl_selesai, $tgl_mulai, $tgl_selesai]);
    while ($r = $pickupStmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = $r;
    }
}

// Fetch Cleanup Requests for this officer in period
if ($type === 'all' || $type === 'cleanup') {
    $cleanupStmt = $db->prepare("
        SELECT 
            'cleanup' AS type,
            id,
            request_code,
            nama_pemohon,
            nomor_wa,
            alamat_jemput AS alamat,
            kecamatan,
            kelurahan,
            tanggal_tugas,
            (SELECT SUM(berat_kg) FROM cleanup_items WHERE cleanup_id = cleanup_requests.id) AS berat_total_kg,
            status,
            catatan_officer,
            created_at
        FROM cleanup_requests
        WHERE officer_id = ? AND (
            (tanggal_tugas BETWEEN ? AND ?)
            OR (tanggal_tugas IS NULL AND DATE(created_at) BETWEEN ? AND ?)
        )
    ");
    $cleanupStmt->execute([$officerId, $tgl_mulai, $tgl_selesai, $tgl_mulai, $tgl_selesai]);
    while ($r = $cleanupStmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = $r;
    }
}

// Sort $rows by date DESC
usort($rows, function($a, $b) {
    $dateA = $a['tanggal_tugas'] ?? date('Y-m-d', strtotime($a['created_at']));
    $dateB = $b['tanggal_tugas'] ?? date('Y-m-d', strtotime($b['created_at']));
    return strcmp($dateB, $dateA);
});

// EXPORT HANDLER (Must be placed before requiring header.php to prevent header output conflicts)
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    $filename = 'laporan_petugas_' . $officer['officer_code'] . '_' . $tgl_mulai . '_to_' . $tgl_selesai;

    if ($export_type === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename . '.xls');
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head><meta charset="utf-8">';
        echo '<!--[if gte mso 9]><xml>';
        echo '<x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
        echo '<x:Name>Laporan Kinerja Petugas</x:Name>';
        echo '<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>';
        echo '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook>';
        echo '</xml><![endif]--><style>';
        echo 'table, th, td { font-family: Calibri, Arial, sans-serif; font-size: 11pt; }';
        echo 'table { border-collapse: collapse; }';
        echo 'th { background-color: #1c6434; color: #ffffff; font-weight: bold; border: 1px solid #000000; padding: 6px; text-align: left; }';
        echo 'td { border: 1px solid #000000; padding: 6px; text-align: left; }';
        echo '.number { mso-number-format:"\#\,\#\#0\.00"; text-align: right; }';
        echo '.text { mso-number-format:"\@"; }';
        echo '</style></head><body>';
        echo '<h3 style="font-family: Calibri, Arial, sans-serif; font-size: 16pt; font-weight: bold; margin: 0 0 5px 0;">LAPORAN KINERJA PETUGAS</h3>';
        echo '<p style="margin: 0 0 5px 0;">Petugas: ' . htmlspecialchars($officer['nama']) . ' (' . htmlspecialchars($officer['officer_code']) . ')</p>';
        echo '<p style="margin: 0 0 15px 0;">Periode: ' . date('d M Y', strtotime($tgl_mulai)) . ' s/d ' . date('d M Y', strtotime($tgl_selesai)) . '</p>';
        echo '<table>';
        echo '<thead><tr>';
        echo '<th>Tanggal Tugas</th>';
        echo '<th>Kode Request</th>';
        echo '<th>Layanan</th>';
        echo '<th>Nama Pemohon</th>';
        echo '<th>Kecamatan</th>';
        echo '<th>Kelurahan</th>';
        echo '<th>Alamat</th>';
        echo '<th>No. WA</th>';
        echo '<th>Berat (kg)</th>';
        echo '<th>Status</th>';
        echo '<th>Catatan Petugas</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        foreach ($rows as $r) {
            $date = $r['tanggal_tugas'] ? date('d/m/Y', strtotime($r['tanggal_tugas'])) : date('d/m/Y', strtotime($r['created_at']));
            $layanan = $r['type'] === 'pickup' ? 'Daur Ulang (Pickup)' : 'Clean Up';
            $weight = $r['berat_total_kg'] ?: '0';
            
            echo '<tr>';
            echo '<td class="text">' . htmlspecialchars($date) . '</td>';
            echo '<td class="text">' . htmlspecialchars($r['request_code']) . '</td>';
            echo '<td>' . htmlspecialchars($layanan) . '</td>';
            echo '<td>' . htmlspecialchars($r['nama_pemohon']) . '</td>';
            echo '<td>' . htmlspecialchars($r['kecamatan'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($r['kelurahan'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($r['alamat'] ?? '-') . '</td>';
            echo '<td class="text">' . htmlspecialchars($r['nomor_wa']) . '</td>';
            echo '<td class="number">' . $weight . '</td>';
            echo '<td>' . htmlspecialchars($r['status']) . '</td>';
            echo '<td>' . htmlspecialchars($r['catatan_officer'] ?? '-') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    } elseif ($export_type === 'pdf') {
        $total_selesai = count(array_filter($rows, fn($r) => $r['status']==='selesai'));
        $total_berat = array_sum(array_map(fn($r) => $r['status']==='selesai' ? (float)$r['berat_total_kg'] : 0, $rows));
        ?>
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8">
            <title>Laporan Petugas - <?= htmlspecialchars($officer['nama']) ?> (<?= htmlspecialchars($officer['officer_code']) ?>)</title>
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
                th { background-color: #1c6434; color: #ffffff; font-weight: 700; border: 1px solid #000000; padding: 6px 4px; text-align: left; text-transform: uppercase; font-size: 8.5px; }
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
                    <h2>LAPORAN KINERJA PETUGAS</h2>
                    <p>Dicetak pada: <?= date('d M Y H:i') ?> WITA</p>
                </div>
            </div>

            <div class="meta-grid">
                <div class="meta-box">
                    <h3>Profil Petugas</h3>
                    <div class="meta-row"><span class="meta-label">Nama Petugas:</span><span class="meta-value"><?= htmlspecialchars($officer['nama']) ?></span></div>
                    <div class="meta-row"><span class="meta-label">Kode Petugas:</span><span class="meta-value"><?= htmlspecialchars($officer['officer_code']) ?></span></div>
                    <div class="meta-row"><span class="meta-label">Kendaraan:</span><span class="meta-value"><?= htmlspecialchars($officer['kendaraan'] ?? '-') ?></span></div>
                </div>
                <div class="meta-box">
                    <h3>Ringkasan Kinerja</h3>
                    <div class="meta-row"><span class="meta-label">Periode:</span><span class="meta-value"><?= date('d M Y', strtotime($tgl_mulai)) ?> - <?= date('d M Y', strtotime($tgl_selesai)) ?></span></div>
                    <div class="meta-row"><span class="meta-label">Total Tugas / Selesai:</span><span class="meta-value"><?= count($rows) ?> / <?= $total_selesai ?></span></div>
                    <div class="meta-row"><span class="meta-label">Total Sampah Terkumpul:</span><span class="meta-value" style="color: #c05621; font-size: 14px;"><?= number_format($total_berat, 1, ',', '.') ?> kg</span></div>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Kode Request</th>
                        <th>Layanan</th>
                        <th>Nama Pemohon</th>
                        <th>Kecamatan</th>
                        <th>Kelurahan</th>
                        <th>Alamat</th>
                        <th>No. WA</th>
                        <th class="text-right">Berat (kg)</th>
                        <th>Status</th>
                        <th>Catatan Petugas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="11" style="text-align:center; padding: 20px;">Tidak ada transaksi selama periode ini.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php 
                            $date = $r['tanggal_tugas'] ? date('d/m/Y', strtotime($r['tanggal_tugas'])) : date('d/m/Y', strtotime($r['created_at']));
                            $layanan = $r['type'] === 'pickup' ? 'Daur Ulang' : 'Clean Up';
                            $weight = $r['berat_total_kg'] ? number_format($r['berat_total_kg'], 1, ',', '.') . ' kg' : '-';
                            ?>
                            <tr>
                                <td><?= $date ?></td>
                                <td class="font-bold"><?= htmlspecialchars($r['request_code']) ?></td>
                                <td><?= $layanan ?></td>
                                <td><?= htmlspecialchars($r['nama_pemohon']) ?></td>
                                <td><?= htmlspecialchars($r['kecamatan'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['kelurahan'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['alamat'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['nomor_wa']) ?></td>
                                <td class="font-bold text-right"><?= $weight ?></td>
                                <td><?= htmlspecialchars($r['status']) ?></td>
                                <td><?= htmlspecialchars($r['catatan_officer'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="signature-section">
                <div class="signature-box">
                    <p>Manado, <?= date('d M Y') ?></p>
                    <p style="font-weight: 700; margin-top: 5px;">Petugas Lapangan,</p>
                    <div class="signature-line"></div>
                    <p style="font-weight: 700;"><?= htmlspecialchars($officer['nama']) ?></p>
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

// ── REQUIRE SIDEBAR HEADER ──
// Now it is safe to output HTML and import the header, as the export headers check has passed.
$page_id = 'laporan';
$page_title = 'Laporan Saya';
require_once __DIR__ . '/layout/header.php';

// Calculate Stats for current page display
$stat_total = count($rows);
$stat_selesai = count(array_filter($rows, fn($r) => $r['status'] === 'selesai'));
$stat_aktif = count(array_filter($rows, fn($r) => !in_array($r['status'], ['selesai', 'dibatalkan'])));
$stat_berat = array_sum(array_map(fn($r) => $r['status'] === 'selesai' ? (float)$r['berat_total_kg'] : 0, $rows));

$sbgMap  = ['selesai'=>'#dcfce7','dibatalkan'=>'#fee2e2'];
$stxtMap = ['selesai'=>'#166534','dibatalkan'=>'#991b1b'];
$slblMap = ['selesai'=>'Selesai','dibatalkan'=>'Dibatalkan'];
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
.weigh-date {
    white-space: nowrap;
}
.weigh-id {
    font-weight: 800;
    color: var(--green);
}
.weigh-phone {
    font-family: monospace;
    color: #475569;
}
.weigh-type-badge {
    padding: 2px 6px;
    border-radius: 9999px;
    font-weight: 700;
    font-size: 9px;
    text-transform: uppercase;
}
.weigh-type-badge.pickup {
    background: #e0f2fe;
    color: #0369a1;
}
.weigh-type-badge.cleanup {
    background: #ffedd5;
    color: #c2410c;
}
.weigh-weight {
    font-size: 12px;
    font-weight: 800;
    color: #b45309;
    white-space: nowrap;
}
</style>

<div class="page-header">
    <h1>📄 Laporan Saya</h1>
    <p>Laporan kinerja pribadi dan detail tugas yang ditugaskan kepada Anda selama periode tertentu.</p>
</div>

<!-- Stat mini -->
<div class="stats-row" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 20px;">
  <div class="stat-mini" style="border-top-color:var(--green)">
    <div class="val" style="color:var(--green)"><?= $stat_total ?></div>
    <div class="lbl">Total Tugas</div>
  </div>
  <div class="stat-mini" style="border-top-color:var(--green-mid)">
    <div class="val" style="color:var(--green-mid)"><?= $stat_selesai ?></div>
    <div class="lbl">Selesai</div>
  </div>
  <div class="stat-mini" style="border-top-color:var(--orange)">
    <div class="val" style="color:var(--orange)"><?= $stat_aktif ?></div>
    <div class="lbl">Aktif</div>
  </div>
  <div class="stat-mini" style="border-top-color:var(--blue)">
    <div class="val" style="color:var(--blue)"><?= number_format($stat_berat, 1) ?> kg</div>
    <div class="lbl">Total Sampah</div>
  </div>
</div>

<div class="card" style="padding: 16px;">
  <!-- Filter & Search Bar -->
  <form method="GET" style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;align-items:center;width:100%">
    
    <div style="display:flex; flex-direction:column; gap:4px;">
      <label class="form-label" style="margin-bottom:0">Mulai Tanggal:</label>
      <input class="form-input" name="tgl_mulai" type="date" value="<?= htmlspecialchars($tgl_mulai) ?>" style="margin-bottom:0; width:150px">
    </div>

    <div style="display:flex; flex-direction:column; gap:4px;">
      <label class="form-label" style="margin-bottom:0">Selesai Tanggal:</label>
      <input class="form-input" name="tgl_selesai" type="date" value="<?= htmlspecialchars($tgl_selesai) ?>" style="margin-bottom:0; width:150px">
    </div>
    
    <div style="display:flex; flex-direction:column; gap:4px;">
      <label class="form-label" style="margin-bottom:0">Layanan:</label>
      <select class="form-input" name="type" onchange="this.form.submit()" style="margin-bottom:0;min-width:140px">
        <option value="all" <?= $type==='all'?'selected':'' ?>>Semua Layanan</option>
        <option value="pickup" <?= $type==='pickup'?'selected':'' ?>>Daur Ulang (Pickup)</option>
        <option value="cleanup" <?= $type==='cleanup'?'selected':'' ?>>Clean Up</option>
      </select>
    </div>

    <div style="display:flex; gap:6px; margin-top:20px; align-self: flex-start;">
      <button type="submit" class="btn btn-green btn-sm" style="height:38px">🔍 Tampilkan</button>
      <a href="?tgl_mulai=<?= date('Y-m-d') ?>&tgl_selesai=<?= date('Y-m-d') ?>&type=<?= $type ?>" class="btn btn-outline btn-sm" style="height:38px;line-height:22px">Hari Ini</a>
      <a href="?tgl_mulai=<?= date('Y-m-01') ?>&tgl_selesai=<?= date('Y-m-d') ?>&type=<?= $type ?>" class="btn btn-outline btn-sm" style="height:38px;line-height:22px">Bulan Ini</a>
    </div>

    <div style="margin-left:auto; display:flex; gap:8px; margin-top:20px; align-self: flex-start;">
      <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="btn btn-sm" style="background:#10b981; color:white; font-weight:700; border-radius:6px; height:38px; display:inline-flex; align-items:center; box-shadow:0 2px 4px rgba(16,185,129,0.2);" title="Export Excel (Formatted)">📊 Excel</a>
      <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" target="_blank" class="btn btn-sm" style="background:#ef4444; color:white; font-weight:700; border-radius:6px; height:38px; display:inline-flex; align-items:center; box-shadow:0 2px 4px rgba(239,68,68,0.2);" title="Export PDF / Cetak Laporan">📄 PDF</a>
    </div>
  </form>

  <div class="table-wrap">
    <table class="weighing-table">
      <thead>
        <tr>
          <th>Tanggal</th>
          <th>Kode</th>
          <th>Layanan</th>
          <th>Nama Pemohon</th>
          <th>No. WA</th>
          <th>Alamat &amp; Kec.</th>
          <th>Status</th>
          <th style="text-align:right">Berat (kg)</th>
          <th>Catatan Petugas</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="9" style="text-align:center;padding:24px;color:#aaa;">Tidak ada tugas ditemukan untuk periode ini.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $r): 
            $date = $r['tanggal_tugas'] ? date('d M Y', strtotime($r['tanggal_tugas'])) : date('d M Y', strtotime($r['created_at']));
            $sbg  = $sbgMap[$r['status']] ?? '#f5f5f5';
            $stxt = $stxtMap[$r['status']] ?? '#333';
            $slbl = $slblMap[$r['status']] ?? $r['status'];
          ?>
          <tr>
            <td class="weigh-date"><?= $date ?></td>
            <td class="weigh-id"><strong><?= htmlspecialchars($r['request_code']) ?></strong></td>
            <td>
              <?php if ($r['type'] === 'pickup'): ?>
                <span class="weigh-type-badge pickup">♻️ Daur Ulang</span>
              <?php else: ?>
                <span class="weigh-type-badge cleanup">🧹 Clean Up</span>
              <?php endif; ?>
            </td>
            <td><strong><?= htmlspecialchars($r['nama_pemohon']) ?></strong></td>
            <td class="weigh-phone"><?= htmlspecialchars($r['nomor_wa']) ?></td>
            <td style="max-width:200px;font-size:11px;color:#555">
              <?= htmlspecialchars($r['alamat'] ?? '-') ?>, Kec. <?= htmlspecialchars($r['kecamatan'] ?? '-') ?>
            </td>
            <td>
              <span class="task-badge" style="background:<?= $sbg ?>;color:<?= $stxt ?>;padding:2px 7px;font-size:10px"><?= $slbl ?></span>
            </td>
            <td class="weigh-weight" style="text-align:right">
              <?= $r['berat_total_kg'] ? number_format($r['berat_total_kg'], 1) . ' kg' : '-' ?>
            </td>
            <td style="font-size:11px;color:#666">
              <?= htmlspecialchars($r['catatan_officer'] ?? '-') ?>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
