<?php
$page_id    = 'tugas';
$page_title = 'Tugas Hari Ini';
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/layout/header.php'; // auth + $officerId + $officer + $st

// ── Data: tugas hari ini ──────────────────────────────────────
$today = date('Y-m-d');
$stmt  = $db->prepare("
    SELECT pr.*,
    (SELECT GROUP_CONCAT(wc.name SEPARATOR ', ')
     FROM pickup_request_items pri JOIN waste_categories wc ON wc.id=pri.category_id
     WHERE pri.pickup_id=pr.id) AS jenis_sampah
    FROM pickup_requests pr
    WHERE pr.officer_id=? AND pr.status NOT IN ('selesai','dibatalkan')
    AND (pr.tanggal_jemput<=? OR pr.tanggal_jemput IS NULL)
    ORDER BY pr.tanggal_jemput ASC, pr.created_at ASC
");
$stmt->execute([$officerId, $today]);
$tasks = $stmt->fetchAll();

$sbgMap  = ['menunggu'=>'#fef3c7','dikonfirmasi'=>'#dbeafe','dijadwalkan'=>'#ede9fe','dalam_perjalanan'=>'#fef08a','sedang_diproses'=>'#ffedd5','selesai'=>'#dcfce7','dibatalkan'=>'#fee2e2'];
$stxtMap = ['menunggu'=>'#92400e','dikonfirmasi'=>'#1e40af','dijadwalkan'=>'#5b21b6','dalam_perjalanan'=>'#854d0e','sedang_diproses'=>'#9a3412','selesai'=>'#166534','dibatalkan'=>'#991b1b'];
$slblMap = ['menunggu'=>'Menunggu','dikonfirmasi'=>'Dikonfirmasi','dijadwalkan'=>'Dijadwalkan','dalam_perjalanan'=>'Dalam Perjalanan','sedang_diproses'=>'Sedang Diproses','selesai'=>'Selesai','dibatalkan'=>'Dibatalkan'];
?>

<!-- Stat mini -->
<div class="stats-row">
  <div class="stat-mini"><div class="val"><?= count($tasks) ?></div><div class="lbl">Tugas Hari Ini</div></div>
  <div class="stat-mini" style="border-top-color:#22c55e"><div class="val" style="color:#22c55e"><?= $st['selesai_hari_ini'] ?></div><div class="lbl">Selesai Hari Ini</div></div>
  <div class="stat-mini" style="border-top-color:var(--orange)"><div class="val" style="color:var(--orange)"><?= $st['aktif'] ?></div><div class="lbl">Total Aktif</div></div>
  <div class="stat-mini" style="border-top-color:var(--blue)"><div class="val" style="color:var(--blue);font-size:18px"><?= number_format((float)$st['total_berat'],1) ?> kg</div><div class="lbl">Total Berat</div></div>
</div>

<?php if($tasks): ?>
<?php foreach($tasks as $i => $t):
  $sbg = $sbgMap[$t['status']] ?? '#f5f5f5';
  $stxt= $stxtMap[$t['status']] ?? '#333';
  $slbl= $slblMap[$t['status']] ?? $t['status'];
?>
<div class="task-card status-<?= $t['status'] ?>">
  <div class="task-header">
    <div class="task-seq" style="<?= $t['status']==='sedang_diproses'?'background:var(--orange)':($t['status']==='dalam_perjalanan'?'background:#eab308':'') ?>"><?= $i+1 ?></div>
    <div class="task-info">
      <div class="task-code"><?= htmlspecialchars($t['request_code']) ?></div>
      <div class="task-name">
        <?= htmlspecialchars($t['nama_pemohon']) ?>
        <?php if($t['partner_name']): ?> <span style="font-size:12px;color:#1c6434">| <?= htmlspecialchars($t['partner_name']) ?></span><?php endif; ?>
      </div>
      <div style="font-size:11px;color:#666;margin-bottom:6px">
        <?php if($t['place_name']): ?><span title="Place Name">🏢 <?= htmlspecialchars($t['place_name']) ?></span><?php endif; ?>
        <?php if($t['place_type']): ?><span title="Place Type"> (<?= htmlspecialchars($t['place_type']) ?>)</span><?php endif; ?>
        <?php if($t['pickup_type']): ?> • <span title="Pickup Type (B/P/R)">Type: <?= htmlspecialchars($t['pickup_type']) ?></span><?php endif; ?>
        <?php if($t['service_type']): ?> • <span title="Service Type">💰 <?= htmlspecialchars($t['service_type']) ?></span><?php endif; ?>
      </div>
      <div class="task-addr">📍 <?= htmlspecialchars($t['alamat_jemput']??'-') ?><?= $t['kelurahan']?', '.htmlspecialchars($t['kelurahan']):'' ?>, Kec. <?= htmlspecialchars($t['kecamatan']??'-') ?></div>
      <div class="task-meta">
        <span class="task-badge" style="background:<?= $sbg ?>;color:<?= $stxt ?>"><?= $slbl ?></span>
        <?php if($t['jenis_sampah']): ?><span class="task-badge" style="background:#f0fdf4;color:#1c6434">♻️ <?= htmlspecialchars(mb_substr($t['jenis_sampah'],0,30)) ?></span><?php endif; ?>
        <?php if($t['berat_total_kg']): ?><span class="task-badge" style="background:#f0f9ff;color:#0369a1">⚖️ <?= $t['berat_total_kg'] ?> kg</span><?php endif; ?>
      </div>
      <?php if($t['catatan']): ?>
      <div style="margin-top:7px;font-size:11px;color:#888;background:#fffde7;padding:6px 10px;border-radius:6px;border-left:3px solid #ffc107">
        💬 <?= htmlspecialchars($t['catatan']) ?>
      </div>
      <?php endif; ?>
      <?php if(!empty($t['catatan_officer'])): ?>
      <div style="margin-top:7px;font-size:11px;color:#475569;background:#eef2f6;padding:6px 10px;border-radius:6px;border-left:3px solid #64748b">
        👷 <strong>Catatan Petugas/Tugas:</strong> <?= htmlspecialchars($t['catatan_officer']) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="task-actions">
    <?php
    $waClean = preg_replace('/[^0-9]/', '', $t['nomor_wa'] ?? '');
    if (str_starts_with($waClean, '0')) {
        $waClean = '62' . substr($waClean, 1);
    } elseif (str_starts_with($waClean, '8')) {
        $waClean = '62' . $waClean;
    }
    
    $waMsg = "Halo Kak【" . ($t['nama_pemohon'] ?? '') . "】,\n" .
             "Saya " . ($officer['nama'] ?? 'Petugas') . " dari Manado Recycle Hub, Mau konfirmasi penjemputan sampah dengan kode request【" . ($t['request_code'] ?? '') . "】ke alamat:\n" .
             "【" . ($t['alamat_jemput'] ?? '') . ($t['kelurahan'] ? ', ' . $t['kelurahan'] : '') . ($t['kecamatan'] ? ', Kec. ' . $t['kecamatan'] : '') . "】\n\n" .
             "Sampah yang dipilih: 【" . ($t['jenis_sampah'] ?? '-') . "】\n" .
             "Estimasi berat: 【" . ($t['berat_kg'] ?? '0') . " kg】\n\n" .
             "Mohon konfirmasinya ya, apakah benar penjemputan ini milik Kakak?\n\n" .
             "Terima kasih";
    ?>
    <a href="tel:+<?= $waClean ?>" class="btn btn-outline btn-sm">📞 Telepon</a>
    <a href="https://wa.me/<?= $waClean ?>?text=<?= urlencode($waMsg) ?>" target="_blank" class="btn btn-outline btn-sm" style="color:#25d366">💬 WhatsApp</a>
    <?php if(floatval($t['latitude']) != 0 && floatval($t['longitude']) != 0): ?>
    <a href="https://www.google.com/maps/dir/?api=1&destination=<?= $t['latitude'] ?>,<?= $t['longitude'] ?>" target="_blank" class="btn btn-blue btn-sm">🧭 Navigasi</a>
    <?php else: ?>
    <a href="https://www.google.com/maps/search/<?= urlencode(($t['alamat_jemput']??'').' '.($t['kecamatan']??'').' Manado') ?>" target="_blank" class="btn btn-blue btn-sm">🔍 Cari di Maps</a>
    <?php endif; ?>
    <button class="btn btn-green btn-sm" onclick='openUpdateModal(<?= htmlspecialchars(json_encode($t),ENT_QUOTES) ?>)'>✏️ Update Status</button>
    <button class="btn btn-sm" style="background:#fff3f3;color:#dc2626;border:1px solid #fecaca" onclick='openKendalaModal(<?= $t['id'] ?>)'>🚨 Kendala</button>
  </div>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="card" style="text-align:center;padding:40px 24px;border:none;box-shadow:0 10px 30px rgba(34,197,94,0.08);background:#fff;border-radius:16px;margin:20px 0;border-top:4px solid #22c55e;">
  <div style="font-size:54px;margin-bottom:16px;">✨</div>
  <h3 style="font-size:20px;font-weight:800;color:#1e293b;margin-bottom:10px;">Terima Kasih!</h3>
  <p style="font-size:13.5px;color:#64748b;line-height:1.6;margin-bottom:20px;max-width:340px;margin-left:auto;margin-right:auto;">
    Terima kasih telah menyelesaikan tugas dan pekerjaan Anda hari ini. Semua penjemputan sampah daur ulang telah diproses dengan luar biasa!
  </p>
  <div style="font-size:11px;color:#16a34a;font-weight:700;text-transform:uppercase;letter-spacing:1px;background:#f0fdf4;padding:6px 16px;border-radius:20px;display:inline-block;border:1px solid #bbf7d0">
    📅 <?= date('d M Y') ?>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
