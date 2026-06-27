<?php
$page_id    = 'profil';
$page_title = 'Profil Saya';
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/layout/header.php';
// $officer, $st, $officerId sudah tersedia dari header.php

// Query waste categories collected by this officer
$wasteData = [];
try {
    $wasteRows = $db->prepare("
        SELECT wc.name, wc.ikon_emoji, SUM(t.total_kg) as total_kg FROM (
            SELECT pri.category_id, SUM(COALESCE(pri.aktual_kg, pri.estimasi_kg)) as total_kg 
            FROM pickup_request_items pri 
            JOIN pickup_requests pr ON pr.id=pri.pickup_id 
            WHERE pr.officer_id = ? AND pr.status='selesai' 
            GROUP BY pri.category_id
            UNION ALL
            SELECT ci.category_id, SUM(ci.berat_kg) as total_kg 
            FROM cleanup_items ci 
            JOIN cleanup_requests cr ON cr.id=ci.cleanup_id 
            WHERE cr.officer_id = ? AND cr.status='selesai' 
            GROUP BY ci.category_id
        ) t 
        JOIN waste_categories wc ON wc.id=t.category_id 
        GROUP BY wc.id, wc.name, wc.ikon_emoji 
        ORDER BY total_kg DESC 
        LIMIT 5
    ");
    $wasteRows->execute([$officerId, $officerId]);
    $wasteData = $wasteRows->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$wasteLabels = array_map(fn($w) => $w['ikon_emoji'].' '.$w['name'], $wasteData);
$wasteKg = array_column($wasteData, 'total_kg');
?>

<!-- Kartu profil -->
<div class="card">
  <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px">
    <div style="width:64px;height:64px;border-radius:50%;background:var(--green);color:#fff;display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:700;flex-shrink:0">
      <?= strtoupper(substr($officer['nama'],0,1)) ?>
    </div>
    <div>
      <div style="font-size:18px;font-weight:700"><?= htmlspecialchars($officer['nama']) ?></div>
      <div style="font-size:12px;color:#888;margin-top:2px"><?= htmlspecialchars($officer['officer_code']) ?></div>
      <span class="badge badge-green" style="margin-top:6px">Aktif</span>
    </div>
  </div>
  <div class="info-row"><span class="lbl">🚗 Kendaraan</span><span class="val"><?= htmlspecialchars($officer['kendaraan']??'-') ?></span></div>
  <div class="info-row"><span class="lbl">🪪 NIP</span><span class="val"><?= htmlspecialchars($officer['nip']??'-') ?></span></div>
  <div class="info-row"><span class="lbl">📧 Email</span><span class="val"><?= htmlspecialchars($officer['email']??'-') ?></span></div>
  <div class="info-row"><span class="lbl">📱 WhatsApp</span><span class="val"><?= htmlspecialchars($officer['nomor_wa']??$officer['user_wa']??'-') ?></span></div>
</div>

<!-- Statistik -->
<div class="card">
  <div class="card-title"><div class="ct-icon">📊</div> Statistik Saya</div>
  
  <?php if ($st['total_all'] > 0): ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px;">
      <!-- Chart 1: Status Tugas -->
      <div style="text-align: center;">
        <div style="font-size: 11px; font-weight: 700; color: #555; margin-bottom: 12px; font-family: var(--ui); text-transform: uppercase; letter-spacing: 0.05em;">DISTRIBUSI STATUS TUGAS</div>
        <div style="height: 180px; display: flex; align-items: center; justify-content: center;">
          <div style="height: 160px; width: 160px; position: relative;">
            <canvas id="chartProfilStatus"></canvas>
          </div>
        </div>
      </div>
      
      <!-- Chart 2: Berat Sampah -->
      <div>
        <div style="font-size: 11px; font-weight: 700; color: #555; margin-bottom: 12px; font-family: var(--ui); text-align: center; text-transform: uppercase; letter-spacing: 0.05em;">SAMPAH TERKUMPUL (KG)</div>
        <div style="height: 180px; position: relative;">
          <?php if (empty($wasteData)): ?>
            <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #888; font-size: 12px; font-family: var(--ui);">
              Belum ada data sampah terkumpul
            </div>
          <?php else: ?>
            <canvas id="chartProfilWaste"></canvas>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div style="text-align: center; padding: 30px; color: #888; font-size: 13px; font-family: var(--ui);">
      Belum ada riwayat tugas atau statistik aktivitas.
    </div>
  <?php endif; ?>
</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
<?php if ($st['total_all'] > 0): ?>
  // Chart Status
  new Chart(document.getElementById('chartProfilStatus'), {
      type: 'doughnut',
      data: {
          labels: ['Selesai', 'Aktif', 'Batal'],
          datasets: [{
              data: [
                  <?= (int)$st['total_selesai'] ?>,
                  <?= (int)$st['aktif'] ?>,
                  <?= max(0, (int)$st['total_all'] - (int)$st['total_selesai'] - (int)$st['aktif']) ?>
              ],
              backgroundColor: ['#22c55e', '#f97316', '#ef4444'],
              borderWidth: 1
          }]
      },
      options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
              legend: {
                  position: 'bottom',
                  labels: {
                      boxWidth: 10,
                      font: { family: 'Inter, system-ui, sans-serif', size: 10 }
                  }
              }
          }
      }
  });

  <?php if (!empty($wasteData)): ?>
  // Chart Waste
  new Chart(document.getElementById('chartProfilWaste'), {
      type: 'bar',
      data: {
          labels: <?= json_encode($wasteLabels) ?>,
          datasets: [{
              data: <?= json_encode($wasteKg) ?>,
              backgroundColor: '#3b82f6',
              borderRadius: 4
          }]
      },
      options: {
          indexAxis: 'y',
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
              x: { 
                  beginAtZero: true,
                  ticks: { font: { family: 'Inter, system-ui, sans-serif', size: 10 } }
              },
              y: {
                  ticks: { font: { family: 'Inter, system-ui, sans-serif', size: 10 } }
              }
          }
      }
  });
  <?php endif; ?>
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
