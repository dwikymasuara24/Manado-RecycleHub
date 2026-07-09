<?php
require_once __DIR__ . '/../include/config.php';
$page_id    = 'kategori_sampah';
$page_title = 'Kategori Sampah';
$db         = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $kode  = trim($_POST['kode'] ?? '');
        $nama  = trim($_POST['name'] ?? '');
        $ikon  = trim($_POST['ikon_emoji'] ?? '♻️');
        $desc  = trim($_POST['deskripsi'] ?? '');
        $aktif = isset($_POST['is_active']) ? 1 : 0;
        if (!$kode || !$nama) { flash('danger','Kode dan nama wajib!'); header('Location: kategori_sampah.php'); exit; }
        if ($id) {
            $db->prepare("UPDATE waste_categories SET kode=?,name=?,ikon_emoji=?,deskripsi=?,is_active=? WHERE id=?")->execute([$kode,$nama,$ikon,$desc,$aktif,$id]);
            flash('success','Kategori diperbarui!');
        } else {
            $db->prepare("INSERT INTO waste_categories (kode,name,ikon_emoji,deskripsi,is_active) VALUES (?,?,?,?,?)")->execute([$kode,$nama,$ikon,$desc,$aktif]);
            flash('success','Kategori baru ditambahkan!');
        }
        header('Location: kategori_sampah.php'); exit;
    }

    if ($action === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $val = (int)($_POST['is_active'] ?? 0);
        $db->prepare("UPDATE waste_categories SET is_active=? WHERE id=?")->execute([$val,$id]);
        header('Content-Type: application/json');
        echo json_encode(['success'=>true,'is_active'=>$val]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try { $db->prepare("DELETE FROM waste_categories WHERE id=?")->execute([$id]); flash('success','Kategori dihapus.'); }
        catch (Exception $e) { flash('danger','Tidak bisa dihapus — masih digunakan oleh data request.'); }
        header('Location: kategori_sampah.php'); exit;
    }
}

$search  = trim($_GET['q'] ?? '');
$where   = '1=1'; $params = [];
if ($search) { $where .= " AND (kode LIKE ? OR name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$stmt = $db->prepare("SELECT wc.*, (SELECT SUM(pri.estimasi_kg) FROM pickup_request_items pri WHERE pri.category_id=wc.id) as total_kg FROM waste_categories wc WHERE $where ORDER BY wc.id ASC");
$stmt->execute($params);
$kategori = $stmt->fetchAll();

$editData = null;
if (!empty($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $editData = $db->query("SELECT * FROM waste_categories WHERE id=$eid")->fetch();
}

require_once __DIR__ . '/layout/header.php';
?>

<div class="page-header">
  <h1>Kategori Sampah</h1>
  <p>Kelola jenis sampah yang diterima layanan Manado Recycle Hub</p>
</div>

<div class="card">
  <div class="toolbar">
    <div class="toolbar-left">
      <form method="GET" style="display:flex;gap:8px;align-items:center">
        <input class="search-input" name="q" type="text" placeholder="Cari kategori..." value="<?= htmlspecialchars($search) ?>">
        <?php if ($search): ?><a href="kategori_sampah.php" class="btn btn-outline">Reset</a><?php endif; ?>
      </form>
    </div>
    <div class="toolbar-right">
      <button class="btn btn-primary" onclick="openModal('modalKategori')">+ Tambah Kategori</button>
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>#</th><th>Ikon</th><th>Kode</th><th>Nama Kategori</th><th>Deskripsi</th><th>Total Estimasi (kg)</th><th>Aksi</th><th>Aktif/Nonaktif</th></tr>
      </thead>
      <tbody>
        <?php foreach ($kategori as $i => $k): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td style="font-size:20px"><?= htmlspecialchars($k['ikon_emoji'] ?? '♻️') ?></td>
          <td><code style="background:#f5f5f5;padding:2px 6px;border-radius:4px;font-size:11px"><?= htmlspecialchars($k['kode']) ?></code></td>
          <td><strong><?= htmlspecialchars($k['name'] ?? '-') ?></strong></td>
          <td style="font-size:12px;color:#666;max-width:180px"><?= htmlspecialchars($k['deskripsi'] ?? '-') ?></td>
          <td>
            <strong><?= $k['total_kg'] ? number_format($k['total_kg'],2) : '0' ?></strong>
            <span style="font-size:11px;color:#999"> kg</span>
          </td>
          <td>
            <div style="display:flex;gap:4px">
              <a class="btn btn-outline btn-icon" href="kategori_sampah.php?edit=<?= $k['id'] ?>" title="Edit">✏️</a>
              <button class="btn btn-danger btn-icon" title="Hapus" onclick="delKat(<?= $k['id'] ?>, '<?= htmlspecialchars($k['name'] ?? '') ?>')">🗑️</button>
            </div>
          </td>
          <td>
            <div class="toggle-wrap">
              <label class="toggle">
                <input type="checkbox" <?= $k['is_active'] ? 'checked' : '' ?>
                  onchange="toggleKat(<?= $k['id'] ?>, this.checked, this)">
                <span class="toggle-slider"></span>
              </label>
              <span class="toggle-label" id="tlabel_<?= $k['id'] ?>"><?= $k['is_active'] ? 'Aktif' : 'Nonaktif' ?></span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- MODAL TAMBAH/EDIT -->
<div class="modal-overlay" id="modalKategori" <?= $editData ? 'style="display:flex"' : '' ?>>
  <div class="modal">
    <div class="modal-header">
      <h3><?= $editData ? 'Edit Kategori: '.htmlspecialchars($editData['name'] ?? '') : 'Tambah Kategori Sampah' ?></h3>
      <a href="kategori_sampah.php" class="modal-close">✕</a>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= $editData['id'] ?? '' ?>">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Kode *</label><input class="form-input" name="kode" required value="<?= htmlspecialchars($editData['kode'] ?? '') ?>" placeholder="kertas_hvs"></div>
          <div class="form-group"><label class="form-label">Ikon Emoji</label><input class="form-input" name="ikon_emoji" value="<?= htmlspecialchars($editData['ikon_emoji'] ?? '') ?>" placeholder="📄"></div>
        </div>
        <div class="form-group"><label class="form-label">Nama Kategori *</label><input class="form-input" name="name" required value="<?= htmlspecialchars($editData['name'] ?? '') ?>" placeholder="Nama kategori"></div>
        <div class="form-group"><label class="form-label">Deskripsi</label><textarea class="form-input" name="deskripsi" rows="2"><?= htmlspecialchars($editData['deskripsi'] ?? '') ?></textarea></div>
        <div class="form-group">
          <label class="toggle-wrap" style="cursor:pointer">
            <label class="toggle"><input type="checkbox" name="is_active" value="1" <?= ($editData['is_active'] ?? 1) ? 'checked' : '' ?>><span class="toggle-slider"></span></label>
            <span class="toggle-label" style="font-size:13px;font-weight:600">Aktif</span>
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <a href="kategori_sampah.php" class="btn btn-outline">Batal</a>
        <button type="submit" class="btn btn-primary">Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL DELETE -->
<div class="modal-overlay" id="modalDelete">
  <div class="modal" style="max-width:400px">
    <div class="modal-header"><h3>Konfirmasi Hapus</h3><button class="modal-close" onclick="closeModal('modalDelete')">✕</button></div>
    <div class="modal-body"><p style="font-size:14px;color:#444" id="deleteMsg"></p></div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modalDelete')">Batal</button>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="deleteId">
        <button type="submit" class="btn btn-danger">Ya, Hapus</button>
      </form>
    </div>
  </div>
</div>

<script>
function toggleKat(id, checked, el) {
  fetch('kategori_sampah.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'action=toggle&id='+id+'&is_active='+(checked?1:0)
  }).then(r=>r.json()).then(d=>{
    if (d.success) {
      document.getElementById('tlabel_'+id).textContent = d.is_active ? 'Aktif' : 'Nonaktif';
      showToast('success', 'Kategori '+(d.is_active?'diaktifkan':'dinonaktifkan')+'!');
    }
  }).catch(()=>{ el.checked = !checked; showToast('danger','Gagal mengubah status.'); });
}

function delKat(id, nama) {
  document.getElementById('deleteMsg').textContent = 'Hapus kategori "'+nama+'"? Pastikan tidak ada data sampah yang menggunakan kategori ini.';
  document.getElementById('deleteId').value = id;
  openModal('modalDelete');
}
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
