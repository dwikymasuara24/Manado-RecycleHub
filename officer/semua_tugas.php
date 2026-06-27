<?php
$page_id    = 'semua_tugas';
$page_title = 'Semua Tugas';
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/layout/header.php';

// ── Data: semua tugas aktif officer ──────────────────────────
$filter  = $_GET['status'] ?? 'aktif';
$where   = $filter === 'semua' ? "pr.officer_id=?" : "pr.officer_id=? AND pr.status NOT IN ('selesai','dibatalkan')";
$params  = [$officerId];

$stmt = $db->prepare("
    SELECT pr.id,pr.request_code,pr.nama_pemohon,pr.kecamatan,pr.alamat_jemput,
           pr.status,pr.tanggal_jemput,pr.jam_jemput,pr.latitude,pr.longitude,pr.nomor_wa,
           pr.partner_name, pr.place_name, pr.pickup_type, pr.service_type,
           pr.catatan, pr.catatan_officer
    FROM pickup_requests pr
    WHERE $where
    ORDER BY pr.tanggal_jemput ASC
");
$stmt->execute($params);
$allTasks = $stmt->fetchAll();

$sbgMap  = ['menunggu'=>'#fef3c7','dikonfirmasi'=>'#dbeafe','dijadwalkan'=>'#ede9fe','dalam_perjalanan'=>'#fef08a','sedang_diproses'=>'#ffedd5','selesai'=>'#dcfce7','dibatalkan'=>'#fee2e2'];
$stxtMap = ['menunggu'=>'#92400e','dikonfirmasi'=>'#1e40af','dijadwalkan'=>'#5b21b6','dalam_perjalanan'=>'#854d0e','sedang_diproses'=>'#9a3412','selesai'=>'#166534','dibatalkan'=>'#991b1b'];
$slblMap = ['menunggu'=>'Menunggu','dikonfirmasi'=>'Dikonfirmasi','dijadwalkan'=>'Dijadwalkan','dalam_perjalanan'=>'Dalam Perjalanan','sedang_diproses'=>'Sedang Diproses','selesai'=>'Selesai','dibatalkan'=>'Dibatalkan'];
?>

<!-- Filter bar -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <a href="semua_tugas.php?status=aktif"  class="btn btn-sm <?= $filter!=='semua'?'btn-green':'btn-outline' ?>">🔄 Aktif</a>
  <a href="semua_tugas.php?status=semua"  class="btn btn-sm <?= $filter==='semua'?'btn-green':'btn-outline' ?>">📋 Semua</a>
  <span style="margin-left:auto;font-size:12px;color:#888;align-self:center;font-family:var(--ui)"><?= count($allTasks) ?> data</span>
</div>

<?php if($allTasks): ?>
<div class="card" style="padding:0">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Request</th><th>Nama</th><th>Kecamatan</th>
          <th>Status</th><th>Jadwal</th><th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($allTasks as $t):
          $sbg = $sbgMap[$t['status']] ?? '#f5f5f5';
          $stxt= $stxtMap[$t['status']] ?? '#333';
          $slbl= $slblMap[$t['status']] ?? $t['status'];
        ?>
        <tr>
          <td><span style="font-size:11px;font-weight:700;color:var(--green)"><?= htmlspecialchars($t['request_code']) ?></span></td>
          <td style="font-size:12px">
            <?= htmlspecialchars($t['nama_pemohon']) ?>
            <?php if($t['partner_name']): ?><br><span style="color:#1c6434;font-size:10px;font-weight:600"><?= htmlspecialchars($t['partner_name']) ?></span><?php endif; ?>
            <?php if($t['service_type']==='Paid'): ?><br><span style="background:#fef9c3;color:#854d0e;padding:1px 4px;border-radius:4px;font-size:9px">💰 Paid</span><?php endif; ?>
          </td>
          <td style="font-size:12px"><?= htmlspecialchars($t['kecamatan']??'-') ?></td>
          <td><span class="task-badge" style="background:<?= $sbg ?>;color:<?= $stxt ?>"><?= $slbl ?></span></td>
          <td style="font-size:11px;color:#888">
            <?= $t['tanggal_jemput'] ? date('d M Y', strtotime($t['tanggal_jemput'])) : '-' ?>
          </td>
          <td style="display:flex;gap:4px;padding:8px 10px;flex-wrap:wrap">
            <?php if(floatval($t['latitude']) != 0 && floatval($t['longitude']) != 0): ?>
            <a href="https://www.google.com/maps/dir/?api=1&destination=<?= $t['latitude'] ?>,<?= $t['longitude'] ?>" target="_blank" class="btn btn-blue btn-sm" style="padding:4px 8px" title="Navigasi Peta">🧭</a>
            <?php else: ?>
            <a href="https://www.google.com/maps/search/<?= urlencode(($t['alamat_jemput']??'').' '.($t['kecamatan']??'').' Manado') ?>" target="_blank" class="btn btn-blue btn-sm" style="padding:4px 8px;background:#f59e0b;border-color:#d97706" title="Cari Alamat">🔍</a>
            <?php endif; ?>
            <?php if(!in_array($t['status'],['selesai','dibatalkan'])): ?>
            <button class="btn btn-green btn-sm" style="padding:4px 8px" onclick='openUpdateModal(<?= htmlspecialchars(json_encode($t),ENT_QUOTES) ?>)'>✏️</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="empty">
  <div class="empty-icon">📋</div>
  <div class="empty-text">Belum ada tugas yang di-assign</div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
