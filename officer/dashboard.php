<?php
// ============================================================
//  officer/dashboard.php — Officer Panel: Dashboard & Visualisasi
//  Manado Recycle Hub
// ============================================================
require_once __DIR__ . '/../include/config.php';
$page_id    = 'dashboard';
$page_title = 'Dashboard Statistik';
$db         = getDB();

require_once __DIR__ . '/layout/header.php';

// ── Chart 1: Tren Tugas Selesai 30 Hari Terakhir ─────────────
$trendRows = $db->prepare("
    SELECT tgl, SUM(cnt) AS cnt 
    FROM (
        SELECT DATE(COALESCE(completed_at, updated_at)) AS tgl, COUNT(*) AS cnt 
        FROM pickup_requests 
        WHERE officer_id = ? AND status = 'selesai' AND COALESCE(completed_at, updated_at) >= CURDATE() - INTERVAL 29 DAY 
        GROUP BY DATE(COALESCE(completed_at, updated_at))
        UNION ALL 
        SELECT DATE(COALESCE(completed_at, updated_at)) AS tgl, COUNT(*) AS cnt 
        FROM cleanup_requests 
        WHERE officer_id = ? AND status = 'selesai' AND COALESCE(completed_at, updated_at) >= CURDATE() - INTERVAL 29 DAY 
        GROUP BY DATE(COALESCE(completed_at, updated_at))
    ) t 
    GROUP BY tgl 
    ORDER BY tgl ASC
");
$trendRows->execute([$officerId, $officerId]);
$trendData = $trendRows->fetchAll(PDO::FETCH_ASSOC);

$trendMap = [];
foreach ($trendData as $t) {
    $trendMap[$t['tgl']] = $t['cnt'];
}

$trendLabels = [];
$trendValues = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trendLabels[] = date('d/m', strtotime($d));
    $trendValues[] = $trendMap[$d] ?? 0;
}

// ── Chart 2: Distribusi Jenis Tugas (Daur Ulang vs Clean Up) ─
$typeRows = $db->prepare("
    SELECT type, COUNT(*) as cnt FROM (
        SELECT 'Daur Ulang' as type FROM pickup_requests WHERE officer_id = ? AND status = 'selesai'
        UNION ALL
        SELECT 'Clean Up' as type FROM cleanup_requests WHERE officer_id = ? AND status = 'selesai'
    ) t GROUP BY type
");
$typeRows->execute([$officerId, $officerId]);
$typeData = $typeRows->fetchAll(PDO::FETCH_ASSOC);

$typeLabels = array_column($typeData, 'type');
$typeCounts = array_column($typeData, 'cnt');

if (empty($typeLabels)) {
    $typeLabels = ['Daur Ulang', 'Clean Up'];
    $typeCounts = [0, 0];
}

// ── Chart 3: Kategori Sampah Terkumpul (kg) ──────────────────
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

$wasteLabels = array_map(fn($w) => $w['ikon_emoji'].' '.$w['name'], $wasteData);
$wasteKg = array_column($wasteData, 'total_kg');

// ── Chart 4: Perbandingan Tugas Selesai Mingguan (12 Minggu) 
$weeklyRows = $db->prepare("
    SELECT yw, MIN(tgl) AS tgl, SUM(cnt) AS cnt 
    FROM (
        SELECT YEARWEEK(COALESCE(completed_at, updated_at), 1) AS yw, DATE(COALESCE(completed_at, updated_at)) AS tgl, COUNT(*) AS cnt 
        FROM pickup_requests 
        WHERE officer_id = ? AND status = 'selesai' AND COALESCE(completed_at, updated_at) >= CURDATE() - INTERVAL 12 WEEK 
        GROUP BY yw, tgl
        UNION ALL
        SELECT YEARWEEK(COALESCE(completed_at, updated_at), 1) AS yw, DATE(COALESCE(completed_at, updated_at)) AS tgl, COUNT(*) AS cnt 
        FROM cleanup_requests 
        WHERE officer_id = ? AND status = 'selesai' AND COALESCE(completed_at, updated_at) >= CURDATE() - INTERVAL 12 WEEK 
        GROUP BY yw, tgl
    ) t 
    GROUP BY yw 
    ORDER BY yw ASC
");
$weeklyRows->execute([$officerId, $officerId]);
$weeklyData = $weeklyRows->fetchAll(PDO::FETCH_ASSOC);

$weeklyLabels = array_map(fn($w) => 'Mg '.date('d/m', strtotime($w['tgl'])), $weeklyData);
$weeklyValues = array_column($weeklyData, 'cnt');
?>

<!-- ══ PAGE HEADER ══ -->
<div class="page-header">
    <h1>📊 Dashboard Statistik</h1>
    <p>Visualisasi performa kerja, tren tugas selesai, dan komparasi berat sampah daur ulang yang berhasil dikumpulkan.</p>
</div>

<!-- ══ STATS MINI CARD ══ -->
<div class="stats-row" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 20px;">
  <div class="stat-mini" style="border-top-color:var(--green)">
    <div class="val" style="color:var(--green)"><?= (int)$st['total_selesai'] ?></div>
    <div class="lbl">Tugas Selesai</div>
  </div>
  <div class="stat-mini" style="border-top-color:var(--amber)">
    <div class="val" style="color:var(--amber)"><?= (int)$st['selesai_hari_ini'] ?></div>
    <div class="lbl">Selesai Hari Ini</div>
  </div>
  <div class="stat-mini" style="border-top-color:var(--blue)">
    <div class="val" style="color:var(--blue)"><?= (int)$st['aktif'] ?></div>
    <div class="lbl">Tugas Aktif</div>
  </div>
  <div class="stat-mini" style="border-top-color:var(--orange)">
    <div class="val" style="color:var(--orange)"><?= number_format($st['total_berat'], 1, ',', '.') ?> <span style="font-size:12px;">kg</span></div>
    <div class="lbl">Total Sampah</div>
  </div>
</div>

<!-- ══ VISUALISASI GRID 1 ══ -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:16px; margin-bottom:16px;">
  <!-- Trend Chart -->
  <div class="card" style="padding:18px;">
    <div class="card-title" style="margin-bottom:14px;"><span class="ct-icon">📈</span> Tren Tugas Selesai Anda (30 Hari)</div>
    <div style="height:220px; position:relative;">
      <canvas id="chartTrend"></canvas>
    </div>
  </div>

  <!-- Jenis Tugas Chart -->
  <div class="card" style="padding:18px;">
    <div class="card-title" style="margin-bottom:14px;"><span class="ct-icon">🍩</span> Distribusi Jenis Tugas</div>
    <div style="height:220px; position:relative; display:flex; align-items:center; justify-content:center;">
      <div style="height:180px; width:180px; position:relative;">
        <canvas id="chartType"></canvas>
      </div>
    </div>
  </div>
</div>

<!-- ══ VISUALISASI GRID 2 ══ -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:16px; margin-bottom:16px;">
  <!-- Waste Chart -->
  <div class="card" style="padding:18px;">
    <div class="card-title" style="margin-bottom:14px;"><span class="ct-icon">♻️</span> Kategori Sampah Terkumpul (kg)</div>
    <div style="height:220px; position:relative;">
      <canvas id="chartWaste"></canvas>
    </div>
  </div>

  <!-- Weekly Chart -->
  <div class="card" style="padding:18px;">
    <div class="card-title" style="margin-bottom:14px;"><span class="ct-icon">📊</span> Performa Mingguan (12 Minggu)</div>
    <div style="height:220px; position:relative;">
      <canvas id="chartWeekly"></canvas>
    </div>
  </div>
</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
// Chart 1: Trend
new Chart(document.getElementById('chartTrend'), {
    type: 'line',
    data: {
        labels: <?= json_encode($trendLabels) ?>,
        datasets: [{
            label: 'Tugas Selesai',
            data: <?= json_encode($trendValues) ?>,
            borderColor: '#22c55e',
            backgroundColor: 'rgba(34, 197, 94, 0.1)',
            fill: true,
            tension: 0.3,
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { 
                beginAtZero: true, 
                ticks: { 
                    stepSize: 1,
                    font: { family: 'Inter, system-ui, sans-serif' }
                } 
            },
            x: {
                ticks: { font: { family: 'Inter, system-ui, sans-serif' } }
            }
        }
    }
});

// Chart 2: Type
new Chart(document.getElementById('chartType'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($typeLabels) ?>,
        datasets: [{
            data: <?= json_encode($typeCounts) ?>,
            backgroundColor: ['#22c55e', '#f97316'],
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
                    boxWidth: 12, 
                    font: { family: 'Inter, system-ui, sans-serif' } 
                } 
            }
        }
    }
});

// Chart 3: Waste
new Chart(document.getElementById('chartWaste'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($wasteLabels) ?>,
        datasets: [{
            label: 'Berat (kg)',
            data: <?= json_encode($wasteKg) ?>,
            backgroundColor: '#3b82f6',
            borderRadius: 6
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
                ticks: { font: { family: 'Inter, system-ui, sans-serif' } }
            },
            y: {
                ticks: { font: { family: 'Inter, system-ui, sans-serif' } }
            }
        }
    }
});

// Chart 4: Weekly
new Chart(document.getElementById('chartWeekly'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($weeklyLabels) ?>,
        datasets: [{
            label: 'Tugas Selesai',
            data: <?= json_encode($weeklyValues) ?>,
            backgroundColor: '#8b5cf6',
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { 
                beginAtZero: true, 
                ticks: { 
                    stepSize: 1,
                    font: { family: 'Inter, system-ui, sans-serif' }
                } 
            },
            x: {
                ticks: { font: { family: 'Inter, system-ui, sans-serif' } }
            }
        }
    }
});
</script>

<?php
require_once __DIR__ . '/layout/footer.php';
?>
