<?php
$page_id    = 'riwayat';
$page_title = 'Riwayat Tugas';
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/layout/header.php'; // auth + $officerId + $officer + $st

$db = getDB();

// Filters & search
$search = trim($_GET['q'] ?? '');
$filter_type = trim($_GET['type'] ?? 'semua');
$filter_status = trim($_GET['status'] ?? 'semua');

$params = [];

// Build dynamic union queries with positional placeholders
$pickup_sql = "SELECT 
    'daur_ulang' AS type,
    pr.id,
    pr.request_code,
    pr.nama_pemohon,
    pr.kecamatan,
    pr.alamat_jemput AS alamat,
    pr.status,
    pr.berat_total_kg AS info_tambahan,
    pr.catatan,
    pr.catatan_officer,
    COALESCE(pr.completed_at, pr.updated_at) AS tgl_selesai
FROM pickup_requests pr
WHERE pr.officer_id = ? AND pr.status IN ('selesai', 'dibatalkan')";

$cleanup_sql = "SELECT 
    'cleanup' AS type,
    cr.id,
    cr.request_code,
    cr.nama_pemohon,
    cr.kecamatan,
    cr.alamat_jemput AS alamat,
    cr.status,
    cr.biaya_aktual AS info_tambahan,
    cr.catatan,
    cr.catatan_officer,
    COALESCE(cr.completed_at, cr.updated_at) AS tgl_selesai
FROM cleanup_requests cr
WHERE cr.officer_id = ? AND cr.status IN ('selesai', 'dibatalkan')";

// Add filters to subqueries if set
if ($filter_status === 'selesai') {
    $pickup_sql .= " AND pr.status = 'selesai'";
    $cleanup_sql .= " AND cr.status = 'selesai'";
} elseif ($filter_status === 'dibatalkan') {
    $pickup_sql .= " AND pr.status = 'dibatalkan'";
    $cleanup_sql .= " AND cr.status = 'dibatalkan'";
}

$pickup_search = "";
$cleanup_search = "";
if ($search) {
    $pickup_search = " AND (pr.request_code LIKE ? OR pr.nama_pemohon LIKE ? OR pr.alamat_jemput LIKE ?)";
    $cleanup_search = " AND (cr.request_code LIKE ? OR cr.nama_pemohon LIKE ? OR cr.alamat_jemput LIKE ?)";
}

// Assemble final query and compile parameters sequentially
if ($filter_type === 'daur_ulang') {
    $final_sql = $pickup_sql . $pickup_search . " ORDER BY tgl_selesai DESC";
    $params[] = $officerId;
    if ($search) {
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
} elseif ($filter_type === 'cleanup') {
    $final_sql = $cleanup_sql . $cleanup_search . " ORDER BY tgl_selesai DESC";
    $params[] = $officerId;
    if ($search) {
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
} else {
    $final_sql = "(" . $pickup_sql . $pickup_search . ") UNION ALL (" . $cleanup_sql . $cleanup_search . ") ORDER BY tgl_selesai DESC";
    // For pickup part
    $params[] = $officerId;
    if ($search) {
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    // For cleanup part
    $params[] = $officerId;
    if ($search) {
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
}

$stmt = $db->prepare($final_sql);
$stmt->execute($params);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Let's count some quick stats for the officer history using positional placeholders
$stat_sql = "
    SELECT 
        SUM(CASE WHEN t.status='selesai' THEN 1 ELSE 0 END) as selesai_count,
        SUM(CASE WHEN t.status='dibatalkan' THEN 1 ELSE 0 END) as batal_count,
        SUM(CASE WHEN t.type='daur_ulang' THEN 1 ELSE 0 END) as pickup_count,
        SUM(CASE WHEN t.type='cleanup' THEN 1 ELSE 0 END) as cleanup_count
    FROM (
        SELECT 'daur_ulang' as type, status FROM pickup_requests WHERE officer_id = ? AND status IN ('selesai', 'dibatalkan')
        UNION ALL
        SELECT 'cleanup' as type, status FROM cleanup_requests WHERE officer_id = ? AND status IN ('selesai', 'dibatalkan')
    ) t
";
$stmt_stat = $db->prepare($stat_sql);
$stmt_stat->execute([$officerId, $officerId]);
$history_stats = $stmt_stat->fetch(PDO::FETCH_ASSOC) ?: ['selesai_count'=>0, 'batal_count'=>0, 'pickup_count'=>0, 'cleanup_count'=>0];

$sbgMap  = ['selesai'=>'#dcfce7','dibatalkan'=>'#fee2e2'];
$stxtMap = ['selesai'=>'#166534','dibatalkan'=>'#991b1b'];
$slblMap = ['selesai'=>'Selesai','dibatalkan'=>'Dibatalkan'];
?>

<!-- Stat mini -->
<div class="stats-row">
  <div class="stat-mini" style="border-top-color:var(--green-mid)"><div class="val" style="color:var(--green-mid)"><?= (int)$history_stats['selesai_count'] ?></div><div class="lbl">Tugas Selesai</div></div>
  <div class="stat-mini" style="border-top-color:var(--red)"><div class="val" style="color:var(--red)"><?= (int)$history_stats['batal_count'] ?></div><div class="lbl">Tugas Batal</div></div>
  <div class="stat-mini" style="border-top-color:var(--blue)"><div class="val" style="color:var(--blue)"><?= (int)$history_stats['pickup_count'] ?></div><div class="lbl">Daur Ulang</div></div>
  <div class="stat-mini" style="border-top-color:var(--orange)"><div class="val" style="color:var(--orange)"><?= (int)$history_stats['cleanup_count'] ?></div><div class="lbl">Clean Up</div></div>
</div>

<div class="card" style="padding: 16px;">
  <!-- Filter & Search Bar -->
  <form method="GET" style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center;width:100%">
    <div style="flex:1;min-width:200px">
      <input class="form-input" name="q" type="text" placeholder="Cari Kode atau Nama Pemohon..." value="<?= htmlspecialchars($search) ?>" style="margin-bottom:0">
    </div>
    
    <div>
      <select class="form-input" name="type" onchange="this.form.submit()" style="margin-bottom:0;min-width:140px">
        <option value="semua" <?= $filter_type==='semua'?'selected':'' ?>>-- Semua Jenis --</option>
        <option value="daur_ulang" <?= $filter_type==='daur_ulang'?'selected':'' ?>>Daur Ulang</option>
        <option value="cleanup" <?= $filter_type==='cleanup'?'selected':'' ?>>Clean Up Service</option>
      </select>
    </div>

    <div>
      <select class="form-input" name="status" onchange="this.form.submit()" style="margin-bottom:0;min-width:140px">
        <option value="semua" <?= $filter_status==='semua'?'selected':'' ?>>-- Semua Status --</option>
        <option value="selesai" <?= $filter_status==='selesai'?'selected':'' ?>>Selesai</option>
        <option value="dibatalkan" <?= $filter_status==='dibatalkan'?'selected':'' ?>>Dibatalkan</option>
      </select>
    </div>

    <div style="display:flex;gap:6px">
      <button type="submit" class="btn btn-green btn-sm" style="height:38px">🔍 Cari</button>
      <?php if ($search || $filter_type !== 'semua' || $filter_status !== 'semua'): ?>
        <a href="riwayat.php" class="btn btn-outline btn-sm" style="height:38px;line-height:22px">Reset</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Jenis</th>
          <th>Kode</th>
          <th>Nama Pemohon</th>
          <th>Alamat &amp; Kec.</th>
          <th>Status</th>
          <th>Tanggal Selesai/Batal</th>
          <th>Detail</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($history)): ?>
          <tr>
            <td colspan="7" style="text-align:center;padding:24px;color:#aaa;">Tidak ada riwayat tugas yang ditemukan.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($history as $h): 
            $sbg  = $sbgMap[$h['status']] ?? '#f5f5f5';
            $stxt = $stxtMap[$h['status']] ?? '#333';
            $slbl = $slblMap[$h['status']] ?? $h['status'];
          ?>
          <tr>
            <td>
              <?php if ($h['type'] === 'daur_ulang'): ?>
                <span class="badge badge-green" style="background:#e0f2fe;color:#0369a1">♻️ Daur Ulang</span>
              <?php else: ?>
                <span class="badge badge-orange" style="background:#ffedd5;color:#c2410c">🧹 Clean Up</span>
              <?php endif; ?>
            </td>
            <td><strong><?= htmlspecialchars($h['request_code']) ?></strong></td>
            <td>
              <strong><?= htmlspecialchars($h['nama_pemohon']) ?></strong>
            </td>
            <td style="max-width:200px;font-size:11px;color:#555">
              <?= htmlspecialchars($h['alamat']) ?>, Kec. <?= htmlspecialchars($h['kecamatan']) ?>
            </td>
            <td>
              <span class="task-badge" style="background:<?= $sbg ?>;color:<?= $stxt ?>;padding:2px 7px;font-size:10px"><?= $slbl ?></span>
            </td>
            <td>
              <div style="font-weight:600"><?= date('d M Y', strtotime($h['tgl_selesai'])) ?></div>
              <div style="font-size:9.5px;color:#aaa"><?= date('H:i', strtotime($h['tgl_selesai'])) ?> WITA</div>
            </td>
            <td style="display:flex;gap:4px">
              <button class="btn btn-outline btn-sm" style="padding:4px 8px" onclick="viewHistoryDetail(<?= $h['id'] ?>, '<?= $h['type'] ?>')">👁️</button>
              <?php if ($h['status'] === 'selesai'): ?>
              <button class="btn btn-danger btn-sm" style="padding:4px 8px;background:#fff5f5;color:#ef4444;border:1px solid #fee2e2" onclick="deleteHistory(<?= $h['id'] ?>, '<?= $h['type'] ?>', '<?= htmlspecialchars($h['request_code']) ?>')">🗑️</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ══ MODAL SHEET: DETAIL RIWAYAT ══ -->
<div class="modal-backdrop" id="historyDetailModal">
  <div class="modal-sheet" style="max-width:600px;margin:auto;border-radius:20px 20px 20px 20px;">
    <div class="modal-handle"></div>
    <div class="modal-title" style="display:flex;justify-content:space-between;align-items:center">
      <span id="hModalCode">Detail Tugas</span>
      <button class="btn btn-outline btn-sm" onclick="closeHistoryDetailModal()" style="border:none;font-size:16px">✕</button>
    </div>
    
    <div id="hModalContent" style="display:flex;flex-direction:column;gap:12px;margin-bottom:18px">
      <!-- Loaded dynamically via AJAX -->
      <div style="text-align:center;padding:20px;color:#888;">Memuat detail...</div>
    </div>
    
    <div style="display:flex;gap:8px;margin-top:8px" id="hModalButtons">
      <button class="btn btn-outline btn-full" onclick="closeHistoryDetailModal()">Tutup</button>
    </div>
  </div>
</div>

<script>
function openHistoryDetailModal() {
  document.getElementById('historyDetailModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeHistoryDetailModal() {
  document.getElementById('historyDetailModal').classList.remove('open');
  document.body.style.overflow = '';
}

// Close when clicking outside sheet
document.getElementById('historyDetailModal').addEventListener('click', e => {
  if (e.target === document.getElementById('historyDetailModal')) closeHistoryDetailModal();
});

async function viewHistoryDetail(id, type) {
  openHistoryDetailModal();
  const content = document.getElementById('hModalContent');
  const codeLabel = document.getElementById('hModalCode');
  
  content.innerHTML = '<div style="text-align:center;padding:20px;color:#888;">Memuat detail...</div>';
  codeLabel.textContent = 'Memuat...';
  document.getElementById('hModalButtons').innerHTML = `
    <button class="btn btn-outline btn-full" onclick="closeHistoryDetailModal()">Tutup</button>
  `;

  try {
    const fd = new FormData();
    fd.append('ajax', 'get_details');
    fd.append('id', id);
    fd.append('type', type);
    
    const r = await fetch('api.php?oid=' + OFFICER_ID, { method: 'POST', body: fd });
    const d = await r.json();
    
    if (d.ok && d.data) {
      const data = d.data;
      codeLabel.textContent = data.request_code + ' — ' + (type === 'daur_ulang' ? 'Daur Ulang' : 'Clean Up');
      
      if (data.status === 'selesai') {
        document.getElementById('hModalButtons').innerHTML = `
          <button class="btn btn-outline" style="flex:1" onclick="closeHistoryDetailModal()">Tutup</button>
          <button class="btn btn-danger" style="flex:1; background:#ef4444; color:#fff;" onclick="deleteHistory(${data.id}, '${type}', '${data.request_code}')">🗑️ Hapus & Revert</button>
        `;
      } else {
        document.getElementById('hModalButtons').innerHTML = `
          <button class="btn btn-outline btn-full" onclick="closeHistoryDetailModal()">Tutup</button>
        `;
      }
      
      let itemsHtml = '';
      if (data.items && data.items.length > 0) {
        itemsHtml = `
          <div style="margin-top:10px">
            <div class="form-label" style="font-size:10px;color:#666">Daftar Sampah/Item Terkait:</div>
            <table style="width:100%;font-size:11px;border:1px solid #f0f0f0;border-radius:6px;overflow:hidden">
              <thead style="background:#f9fafb">
                <tr>
                  <th style="padding:6px 10px">Kategori</th>
                  <th style="padding:6px 10px;text-align:right">${type === 'daur_ulang' ? 'Estimasi (kg)' : 'Berat Aktual (kg)'}</th>
                </tr>
              </thead>
              <tbody>
                ${data.items.map(it => `
                  <tr>
                    <td style="padding:6px 10px">${escapeHtml(it.category_name)}</td>
                    <td style="padding:6px 10px;text-align:right;font-weight:700">${parseFloat(it.estimasi_kg || it.berat_kg || 0).toFixed(1)} kg</td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
          </div>
        `;
      }

      let fotoHtml = '';
      if (type === 'cleanup') {
        fotoHtml = `
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:8px">
            <div>
              <div class="form-label" style="font-size:9px;color:#888">Foto Sebelum:</div>
              ${data.foto_sebelum ? `<img src="../uploads/cleanup/${data.foto_sebelum}" style="width:100%;height:120px;object-fit:cover;border-radius:8px;border:1px solid #ddd">` : `<div style="height:120px;background:#f3f4f6;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#aaa;font-size:11px">Tidak ada foto</div>`}
            </div>
            <div>
              <div class="form-label" style="font-size:9px;color:#888">Foto Sesudah:</div>
              ${data.foto_sesudah ? `<img src="../uploads/cleanup/${data.foto_sesudah}" style="width:100%;height:120px;object-fit:cover;border-radius:8px;border:1px solid #ddd">` : `<div style="height:120px;background:#f3f4f6;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#aaa;font-size:11px">Tidak ada foto</div>`}
            </div>
          </div>
        `;
      }

      content.innerHTML = `
        <div class="info-row" style="margin-bottom:0;border-bottom:1px solid #eee;border-radius:8px 8px 0 0">
          <span class="lbl">Nama Pemohon:</span>
          <span class="val">${escapeHtml(data.nama_pemohon)}</span>
        </div>
        <div class="info-row" style="margin-bottom:0;border-bottom:1px solid #eee;border-radius:0">
          <span class="lbl">Nomor WA:</span>
          <span class="val" style="color:var(--green-mid)">${escapeHtml(data.nomor_wa || '-')}</span>
        </div>
        <div class="info-row" style="margin-bottom:0;border-bottom:1px solid #eee;border-radius:0">
          <span class="lbl">Alamat:</span>
          <span class="val" style="text-align:right;max-width:200px;font-size:12px">${escapeHtml(data.alamat_jemput || data.alamat || '-')}</span>
        </div>
        <div class="info-row" style="margin-bottom:0;border-bottom:1px solid #eee;border-radius:0">
          <span class="lbl">Kecamatan:</span>
          <span class="val">${escapeHtml(data.kecamatan || '-')}</span>
        </div>
        <div class="info-row" style="margin-bottom:0;border-bottom:1px solid #eee;border-radius:0">
          <span class="lbl">Status:</span>
          <span class="val" style="text-transform:capitalize;font-weight:700">${escapeHtml(data.status)}</span>
        </div>
        
        ${type === 'daur_ulang' ? `
          <div class="info-row" style="margin-bottom:0;border-bottom:1px solid #eee;border-radius:0">
            <span class="lbl">Total Berat Aktual:</span>
            <span class="val" style="color:var(--blue)">${data.berat_total_kg ? parseFloat(data.berat_total_kg).toFixed(1) + ' kg' : '-'}</span>
          </div>
        ` : `
          <div class="info-row" style="margin-bottom:0;border-bottom:1px solid #eee;border-radius:0">
            <span class="lbl">Biaya Aktual:</span>
            <span class="val" style="color:var(--orange)">Rp ${data.biaya_aktual ? parseFloat(data.biaya_aktual).toLocaleString('id-ID') : '-'}</span>
          </div>
        `}

        <div class="info-row" style="margin-bottom:10px;border-radius:0 0 8px 8px">
          <span class="lbl">Diselesaikan pada:</span>
          <span class="val">${data.completed_at ? formatDateString(data.completed_at) : formatDateString(data.updated_at)}</span>
        </div>

        ${data.catatan ? `
          <div style="background:#fffde7;padding:10px;border-radius:8px;border-left:4px solid #f59e0b;font-size:12px;margin-top:4px">
            <strong>Catatan Warga:</strong><br>
            ${escapeHtml(data.catatan)}
          </div>
        ` : ''}

        ${data.catatan_officer ? `
          <div style="background:#f1f5f9;padding:10px;border-radius:8px;border-left:4px solid #64748b;font-size:12px;margin-top:4px">
            <strong>Catatan Petugas (Anda):</strong><br>
            ${escapeHtml(data.catatan_officer)}
          </div>
        ` : ''}

        ${itemsHtml}
        ${fotoHtml}
      `;
    } else {
      content.innerHTML = '<div style="text-align:center;padding:20px;color:red;">Gagal memuat detail tugas.</div>';
    }
  } catch (e) {
    content.innerHTML = '<div style="text-align:center;padding:20px;color:red;">Error: ' + e.message + '</div>';
  }
}

async function deleteHistory(id, type, code) {
  if (!confirm(`Hapus rekaman selesai untuk ${code}?\nStatus tugas akan kembali ke "dijadwalkan" dan berat aktual di-reset.`)) {
    return;
  }
  
  try {
    const fd = new FormData();
    fd.append('ajax', 'delete_riwayat');
    fd.append('id', id);
    fd.append('type', type);
    
    const r = await fetch('api.php?oid=' + OFFICER_ID, { method: 'POST', body: fd });
    const d = await r.json();
    
    if (d.ok) {
      alert('Tugas berhasil di-revert dan disinkronkan.');
      closeHistoryDetailModal();
      location.reload();
    } else {
      alert('Gagal menghapus: ' + (d.error || 'Unknown error'));
    }
  } catch (e) {
    alert('Koneksi error: ' + e.message);
  }
}

function escapeHtml(str) {
  if (!str) return '';
  return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function formatDateString(dateStr) {
  if (!dateStr) return '-';
  const d = new Date(dateStr);
  if (isNaN(d.getTime())) return dateStr;
  const day = String(d.getDate()).padStart(2, '0');
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
  const month = months[d.getMonth()];
  const year = d.getFullYear();
  const hours = String(d.getHours()).padStart(2, '0');
  const minutes = String(d.getMinutes()).padStart(2, '0');
  return `${day} ${month} ${year} ${hours}:${minutes} WITA`;
}
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
