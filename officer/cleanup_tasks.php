<?php
$page_id    = 'cleanup';
$page_title = 'Tugas Clean Up';
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/layout/header.php'; // auth + $officerId + $officer + $st

$db = getDB();

// ── Auto-migrasi: pastikan kolom baru ada di tabel cleanup_requests ────────
try {
    $existingCols = array_map('strtolower', $db->query("SHOW COLUMNS FROM cleanup_requests")->fetchAll(PDO::FETCH_COLUMN));
    
    if (!in_array('jam_kerja_aktual', $existingCols)) {
        $db->exec("ALTER TABLE cleanup_requests ADD COLUMN jam_kerja_aktual DECIMAL(10,2) NULL AFTER estimasi_jam_kerja");
    }
    if (!in_array('catatan_officer', $existingCols)) {
        $db->exec("ALTER TABLE cleanup_requests ADD COLUMN catatan_officer TEXT NULL AFTER catatan");
    }
} catch (Exception $e) {
    error_log('[MRH Cleanup Tasks Migration Error] ' . $e->getMessage());
}

// ── Kategori Clean Up (Shared) ──────────────────────────────────
$cleanup_types = [
    'acara'  => ['label' => 'Bersih-bersih Acara', 'icon' => '🎉'],
    'rumah'  => ['label' => 'Pembersihan Rumah',   'icon' => '🏠'],
    'kantor' => ['label' => 'Pembersihan Kantor',  'icon' => '🏢'],
];

// ── Handle Action (Update Status / Input Weight) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $rid    = (int)($_POST['id'] ?? 0);

    if ($action === 'update_status') {
        $status = $_POST['status'];
        $db->prepare("UPDATE cleanup_requests SET status=?, updated_at=NOW() WHERE id=? AND officer_id=?")
           ->execute([$status, $rid, $officerId]);
        triggerWhatsAppOnStatusChange($db, $rid, $status, 'cleanup');
        
        // Kirim notifikasi ke Admin
        try {
            $req_code = $db->query("SELECT request_code FROM cleanup_requests WHERE id = $rid")->fetchColumn();
            $officer_name = $db->query("SELECT u.nama FROM users u JOIN officers o ON o.user_id = u.id WHERE o.id = $officerId")->fetchColumn();
            $status_labels = [
                'dalam_perjalanan' => 'sedang menuju lokasi',
                'sedang_cleanup'   => 'sedang membersihkan lokasi',
                'selesai'          => 'menyelesaikan tugas',
                'dibatalkan'       => 'membatalkan tugas'
            ];
            $lbl = $status_labels[$status] ?? "mengubah status ke $status";
            createNotification($db, 'admin', 'Update Petugas (Clean Up)', "Petugas $officer_name $lbl untuk request $req_code.", 'sistem', $rid, 'cleanup_requests');
        } catch(Exception $ex) {}

        flash('success', "Status berhasil diperbarui menjadi: " . str_replace('_', ' ', ucfirst($status)));
    }

    if ($action === 'input_weights') {
        $berat = floatval($_POST['berat_sampah'] ?? 0);
        $jenis = clean($_POST['jenis_sampah'] ?? 'Campuran');
        $hasil = clean($_POST['hasil_pemilahan'] ?? '');
        $jam_aktual = floatval($_POST['jam_kerja_aktual'] ?? 0);
        $biaya = $jam_aktual > 0 ? $jam_aktual * 50000 : floatval($_POST['biaya_aktual'] ?? 0);
        $cat_officer = trim($_POST['catatan_officer'] ?? '');
        
        $foto_sebelum = null; $foto_sesudah = null;
        $upl_dir = __DIR__ . '/../uploads/cleanup/';
        if(!is_dir($upl_dir)) mkdir($upl_dir, 0777, true);
        
        if(!empty($_FILES['foto_sebelum']['name'])){
            $ext = pathinfo($_FILES['foto_sebelum']['name'], PATHINFO_EXTENSION);
            $foto_sebelum = 'sebelum_'.$rid.'_'.time().'.'.$ext;
            move_uploaded_file($_FILES['foto_sebelum']['tmp_name'], $upl_dir.$foto_sebelum);
        }
        if(!empty($_FILES['foto_sesudah']['name'])){
            $ext = pathinfo($_FILES['foto_sesudah']['name'], PATHINFO_EXTENSION);
            $foto_sesudah = 'sesudah_'.$rid.'_'.time().'.'.$ext;
            move_uploaded_file($_FILES['foto_sesudah']['tmp_name'], $upl_dir.$foto_sesudah);
        }

        // UPDATE request
        $db->prepare("UPDATE cleanup_requests SET jam_kerja_aktual=?, biaya_aktual=?, foto_sebelum=IFNULL(?, foto_sebelum), foto_sesudah=IFNULL(?, foto_sesudah), catatan_officer=?, status='selesai', completed_at=NOW() WHERE id=?")
           ->execute([$jam_aktual, $biaya, $foto_sebelum, $foto_sesudah, $cat_officer, $rid]);
        triggerWhatsAppOnStatusChange($db, $rid, 'selesai', 'cleanup');

        // Kirim notifikasi ke Admin
        try {
            $req_code = $db->query("SELECT request_code FROM cleanup_requests WHERE id = $rid")->fetchColumn();
            $officer_name = $db->query("SELECT u.nama FROM users u JOIN officers o ON o.user_id = u.id WHERE o.id = $officerId")->fetchColumn();
            createNotification($db, 'admin', 'Update Petugas (Clean Up)', "Petugas $officer_name menyelesaikan tugas untuk request $req_code.", 'sistem', $rid, 'cleanup_requests');
        } catch(Exception $ex) {}
        
        // Simpan item
        $catatan = "Jenis: $jenis | Hasil: $hasil";
        $db->prepare("INSERT INTO cleanup_items (cleanup_id, category_id, berat_kg, catatan) VALUES (?, 3, ?, ?)")
           ->execute([$rid, $berat, $catatan]);
        
        recordCleanupWeighing($db, $rid);
           
        flash('success', "Data penyelesaian tugas dan foto berhasil disimpan.");
    }
    echo "<script>window.location.href='cleanup_tasks.php';</script>";
    exit;
}

// ── Data: tugas clean up ──────────────────────────────────────
$stmt = $db->prepare("
    SELECT cr.*
    FROM cleanup_requests cr
    WHERE cr.officer_id=? AND cr.status NOT IN ('selesai','dibatalkan')
    ORDER BY cr.tanggal_tugas ASC, cr.created_at ASC
");
$stmt->execute([$officerId]);
$tasks = $stmt->fetchAll();

$sbgMap  = ['menunggu'=>'#fef3c7','dikonfirmasi'=>'#dbeafe','dijadwalkan'=>'#ede9fe','dalam_perjalanan'=>'#fef08a','sedang_diproses'=>'#ffedd5','sedang_cleanup'=>'#ffedd5','selesai'=>'#dcfce7','dibatalkan'=>'#fee2e2'];
$stxtMap = ['menunggu'=>'#92400e','dikonfirmasi'=>'#1e40af','dijadwalkan'=>'#5b21b6','dalam_perjalanan'=>'#854d0e','sedang_diproses'=>'#9a3412','sedang_cleanup'=>'#9a3412','selesai'=>'#166534','dibatalkan'=>'#991b1b'];
$slblMap = ['menunggu'=>'Menunggu','dikonfirmasi'=>'Dikonfirmasi','dijadwalkan'=>'Dijadwalkan','dalam_perjalanan'=>'Dalam Perjalanan','sedang_diproses'=>'Sedang Diproses','sedang_cleanup'=>'Sedang Clean Up','selesai'=>'Selesai','dibatalkan'=>'Dibatalkan'];
?>

<div class="stats-row">
  <div class="stat-mini"><div class="val"><?= count($tasks) ?></div><div class="lbl">Tugas Clean Up</div></div>
  <div class="stat-mini" style="border-top-color:var(--blue)"><div class="val" style="color:var(--blue)">📅 <?= date('d M') ?></div><div class="lbl">Jadwal Tugas</div></div>
</div>

<?php if($tasks): ?>
<?php foreach($tasks as $i => $t):
  $sbg = $sbgMap[$t['status']] ?? '#f5f5f5';
  $stxt= $stxtMap[$t['status']] ?? '#333';
  $slbl= $slblMap[$t['status']] ?? $t['status'];
?>
<div class="task-card status-<?= $t['status'] ?>" style="border-left: 4px solid var(--gd)">
  <div class="task-header">
    <div class="task-seq" style="background:var(--gd)"><?= $i+1 ?></div>
    <div class="task-info">
      <div class="task-code"><?= htmlspecialchars($t['request_code']) ?></div>
      <div class="task-name"><?= htmlspecialchars($t['nama_pemohon']) ?></div>
      <div style="font-size:11px;color:#666;margin-bottom:6px">
        <?= $cleanup_types[$t['service_type']]['icon'] ?? '🧹' ?> <strong>Layanan:</strong> <?= $cleanup_types[$t['service_type']]['label'] ?? ucfirst($t['service_type']) ?> 
      </div>
      <div class="task-addr">📍 <?= htmlspecialchars($t['alamat_jemput']??'-') ?>, Kec. <?= htmlspecialchars($t['kecamatan']??'-') ?></div>
      <div class="task-meta">
        <span class="task-badge" style="background:<?= $sbg ?>;color:<?= $stxt ?>"><?= $slbl ?></span>
        <span class="task-badge" style="background:#fff7ed;color:#9a3412">💰 Rp<?= number_format((float)($t['biaya_estimasi'] ?? 0), 0, ',', '.') ?></span>
      </div>
      <?php if($t['catatan']): ?>
      <div style="margin-top:7px;font-size:11px;color:#888;background:#f8fafc;padding:6px 10px;border-radius:6px;border-left:3px solid #cbd5e1">
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
             "Saya " . ($officer['nama'] ?? 'Petugas') . " dari Manado Recycle Hub, Mau konfirmasi layanan Clean Up dengan kode request【" . ($t['request_code'] ?? '') . "】ke alamat:\n" .
             "【" . ($t['alamat_jemput'] ?? '') . ($t['kecamatan'] ? ', Kec. ' . $t['kecamatan'] : '') . "】\n\n" .
             "Layanan: 【" . ($cleanup_types[$t['service_type']]['label'] ?? ucfirst($t['service_type'])) . "】\n" .
             "Estimasi biaya: 【Rp" . number_format((float)($t['biaya_estimasi'] ?? 0), 0, ',', '.') . "】\n\n" .
             "Mohon konfirmasinya ya, apakah benar request ini milik Kakak?\n\n" .
             "Terima kasih";
    ?>
    <a href="https://wa.me/<?= $waClean ?>?text=<?= urlencode($waMsg) ?>" target="_blank" class="btn btn-outline btn-sm" style="color:#25d366">💬 WhatsApp</a>
    
    <?php if($t['status'] === 'dijadwalkan'): ?>
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" value="<?= $t['id'] ?>">
            <input type="hidden" name="status" value="dalam_perjalanan">
            <button type="submit" class="btn btn-blue btn-sm">🛵 Menuju Lokasi</button>
        </form>
    <?php elseif($t['status'] === 'dalam_perjalanan'): ?>
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" value="<?= $t['id'] ?>">
            <input type="hidden" name="status" value="sedang_cleanup">
            <button type="submit" class="btn btn-blue btn-sm">🚀 Mulai Clean Up</button>
        </form>
    <?php elseif(in_array($t['status'], ['sedang_diproses', 'sedang_cleanup'])): ?>
        <button class="btn btn-green btn-sm" onclick='openFinishModal(<?= htmlspecialchars(json_encode($t),ENT_QUOTES) ?>)'>🏁 Selesai & Timbang</button>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="empty">
  <div class="empty-icon">🏖️</div>
  <div class="empty-text">Tidak ada tugas Clean Up aktif</div>
</div>
<?php endif; ?>

<!-- Modal Selesai & Timbang -->
<style>
.modal-overlay-cl { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); z-index:1000; align-items:center; justify-content:center; padding:16px; backdrop-filter:blur(4px); }
.modal-cl { background:#fff; width:100%; max-width:440px; border-radius:16px; overflow:hidden; box-shadow:0 20px 40px rgba(0,0,0,0.15); display:flex; flex-direction:column; max-height:90vh; animation:modalIn 0.2s ease; }
@keyframes modalIn { from{opacity:0; transform:scale(0.95) translateY(10px);} to{opacity:1; transform:scale(1) translateY(0);} }
.modal-header-cl { padding:18px 24px; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; background:#fff; }
.modal-header-cl h3 { margin:0; font-size:16px; font-weight:800; color:#1e293b; }
.modal-close-cl { background:none; border:none; font-size:20px; color:#94a3b8; cursor:pointer; padding:4px; border-radius:6px; transition:0.2s; line-height:1; }
.modal-close-cl:hover { color:#ef4444; background:#fee2e2; }
.modal-body-cl { padding:20px 24px; overflow-y:auto; flex:1; }
.form-grp { margin-bottom:14px; }
.form-lbl { display:block; font-size:11px; font-weight:700; color:#64748b; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.4px; }
.form-inp { width:100%; padding:10px 14px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:13px; font-family:inherit; outline:none; transition:0.2s; background:#fff; }
.form-inp:focus { border-color:#22c55e; box-shadow:0 0 0 3px rgba(34,197,94,0.1); }
.file-grp { display:flex; gap:12px; }
.file-box { flex:1; background:#f8fafc; border:1.5px dashed #cbd5e1; border-radius:8px; padding:10px; text-align:center; position:relative; overflow:hidden; }
.file-box input[type="file"] { font-size:11px; width:100%; color:#475569; }
.file-box .lbl { font-size:10px; font-weight:700; color:#64748b; margin-bottom:4px; display:block; }
.modal-footer-cl { padding:16px 24px; border-top:1px solid #f1f5f9; background:#f8fafc; display:flex; gap:12px; }
.btn-cl { flex:1; padding:12px; border-radius:8px; font-size:13px; font-weight:700; text-align:center; cursor:pointer; transition:0.2s; border:none; outline:none; }
.btn-cancel { background:#fff; border:1px solid #cbd5e1; color:#475569; }
.btn-cancel:hover { background:#f1f5f9; }
.btn-submit { background:var(--green); color:#fff; box-shadow:0 4px 12px rgba(46,125,50,0.2); }
.btn-submit:hover { background:#155229; transform:translateY(-1px); box-shadow:0 6px 16px rgba(46,125,50,0.3); }
@media (max-width: 640px) {
  .modal-overlay-cl { padding: 10px; }
  .modal-cl { max-height: 95vh; }
  .modal-header-cl { padding: 14px 16px; }
  .modal-body-cl { padding: 14px 16px; }
  .modal-footer-cl { padding: 12px 16px; flex-direction: column; gap: 8px; }
  .file-grp { flex-direction: column; }
}
</style>

<div class="modal-overlay-cl" id="modalFinish">
    <div class="modal-cl">
        <div class="modal-header-cl">
            <h3>🏁 Selesai Clean Up</h3>
            <button class="modal-close-cl" onclick="closeFinishModal()">✕</button>
        </div>
        <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;flex:1;overflow:hidden;min-height:0">
            <div class="modal-body-cl">
                <input type="hidden" name="action" value="input_weights">
                <input type="hidden" name="id" id="finishId">
                
                <div class="form-grp">
                    <label class="form-lbl">⚖️ Berat Sampah (kg)</label>
                    <input type="number" step="0.01" name="berat_sampah" class="form-inp" placeholder="Contoh: 12.5" required>
                </div>

                <div style="display:flex; gap:12px;">
                    <div class="form-grp" style="flex:1">
                        <label class="form-lbl">🗑️ Jenis Sampah</label>
                        <input type="text" name="jenis_sampah" class="form-inp" placeholder="Mis. Botol Plastik" required>
                    </div>
                    <div class="form-grp" style="flex:1">
                        <label class="form-lbl">♻️ Hasil Pemilahan</label>
                        <input type="text" name="hasil_pemilahan" class="form-inp" placeholder="Mis. PET Bersih">
                    </div>
                </div>

                <div class="form-grp">
                    <label class="form-lbl">📸 Dokumentasi (Foto)</label>
                    <div class="file-grp">
                        <div class="file-box">
                            <span class="lbl">Foto Sebelum</span>
                            <input type="file" name="foto_sebelum" accept="image/*">
                        </div>
                        <div class="file-box">
                            <span class="lbl">Foto Sesudah</span>
                            <input type="file" name="foto_sesudah" accept="image/*">
                        </div>
                    </div>
                </div>
                
                <div style="margin:20px 0; border-top:1px dashed #cbd5e1"></div>

                <div class="form-grp">
                    <label class="form-lbl">⏱️ Jam Kerja Aktual</label>
                    <input type="number" step="0.5" name="jam_kerja_aktual" id="finishJam" class="form-inp" style="background:#f0fdf4; border-color:#bbf7d0; color:#166534; font-weight:700" oninput="calcFinishBiaya()">
                </div>

                <div class="form-grp" style="margin-bottom:0">
                    <label class="form-lbl">💰 Biaya Aktual (Rp)</label>
                    <input type="number" name="biaya_aktual" id="finishBiaya" class="form-inp" readonly style="background:#f8fafc; font-weight:800; color:#b45309; border-color:#e2e8f0">
                    <div style="font-size:10px; color:#94a3b8; margin-top:6px; font-weight:600">
                        * Otomatis terhitung: Jam Kerja Aktual × Rp 50.000
                    </div>
                </div>

                <div class="form-grp" style="margin-top:14px">
                    <label class="form-lbl">📝 Catatan Tambahan Petugas</label>
                    <textarea name="catatan_officer" class="form-inp" rows="2" placeholder="Catatan hasil pengerjaan, kondisi, dll..."></textarea>
                </div>
            </div>
            <div class="modal-footer-cl">
                <button type="button" class="btn-cl btn-cancel" onclick="closeFinishModal()">Batal</button>
                <button type="submit" class="btn-cl btn-submit">Simpan & Selesai</button>
            </div>
        </form>
    </div>
</div>

<script>
function calcFinishBiaya() {
    let jam = parseFloat(document.getElementById('finishJam').value) || 0;
    document.getElementById('finishBiaya').value = jam * 50000;
}
function openFinishModal(data) {
    document.getElementById('finishId').value = data.id;
    document.getElementById('finishJam').value = data.estimasi_jam_kerja || 1;
    calcFinishBiaya();
    const catEl = document.querySelector('#modalFinish textarea[name="catatan_officer"]');
    if (catEl) catEl.value = data.catatan_officer || '';
    document.getElementById('modalFinish').style.display = 'flex';
}
function closeFinishModal() {
    document.getElementById('modalFinish').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
