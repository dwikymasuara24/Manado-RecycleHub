<?php
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/auth.php';
requireRole('admin');
$page_id    = 'idea_management';
$page_title = 'Kotak Ide';
$db         = getDB();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'update_status') {
        $id            = (int)($_POST['id'] ?? 0);
        $status        = trim($_POST['status'] ?? 'baru');
        $catatan_admin = trim($_POST['catatan_admin'] ?? '');
        $admin_id      = $_SESSION['user_id'] ?? 1;

        if ($id) {
            $db->prepare("UPDATE idea_box 
                          SET status=?, catatan_admin=?, admin_id=?, reviewed_at=COALESCE(reviewed_at, NOW()), updated_at=NOW() 
                          WHERE id=?")
               ->execute([$status, $catatan_admin, $admin_id, $id]);
            flash('success', 'Status ide berhasil diperbarui!');
        }
        header('Location: idea_management.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM idea_box WHERE id=?")->execute([$id]);
        flash('success', 'Ide berhasil dihapus dari kotak.');
        header('Location: idea_management.php');
        exit;
    }
}

// Search and filter query parameters
$search = trim($_GET['q'] ?? '');
$filter_status = trim($_GET['status'] ?? '');

$where = '1=1';
$params = [];

if ($search) {
    $where .= " AND (nama_pengirim LIKE ? OR deskripsi_ide LIKE ? OR nomor_wa LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filter_status) {
    $where .= " AND status = ?";
    $params[] = $filter_status;
}

$stmt = $db->prepare("SELECT ib.*, a.nama AS admin_name FROM idea_box ib LEFT JOIN users a ON ib.admin_id = a.id WHERE $where ORDER BY ib.created_at DESC");
$stmt->execute($params);
$ideas = $stmt->fetchAll();

// Edit / Detail modal prefill
$detailData = null;
if (!empty($_GET['detail'])) {
    $did = (int)$_GET['detail'];
    $detailData = $db->query("SELECT ib.*, a.nama AS admin_name FROM idea_box ib LEFT JOIN users a ON ib.admin_id = a.id WHERE ib.id=$did")->fetch();
}

require_once __DIR__ . '/layout/header.php';
?>

<div class="page-header">
  <h1>Kotak Ide Masyarakat</h1>
  <p>Tinjau saran, aspirasi, dan usulan ide kreatif daur ulang dari masyarakat</p>
</div>

<div class="card">
  <div class="toolbar">
    <div class="toolbar-left">
      <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input class="search-input" name="q" type="text" placeholder="Cari ide..." value="<?= htmlspecialchars($search) ?>">
        
        <select class="filter-select" name="status" onchange="this.form.submit()">
          <option value="">-- Semua Status --</option>
          <option value="baru" <?= $filter_status==='baru'?'selected':'' ?>>Baru</option>
          <option value="ditinjau" <?= $filter_status==='ditinjau'?'selected':'' ?>>Ditinjau</option>
          <option value="disetujui" <?= $filter_status==='disetujui'?'selected':'' ?>>Disetujui</option>
          <option value="ditolak" <?= $filter_status==='ditolak'?'selected':'' ?>>Ditolak</option>
          <option value="direalisasi" <?= $filter_status==='direalisasi'?'selected':'' ?>>Direalisasi</option>
        </select>

        <?php if ($search || $filter_status): ?>
          <a href="idea_management.php" class="btn btn-outline">Reset</a>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Pengirim</th>
          <th>Kontak (WA)</th>
          <th>Isi Ide</th>
          <th>Status</th>
          <th>Admin Reviewer</th>
          <th>Tanggal Kirim</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($ideas)): ?>
          <tr>
            <td colspan="8" style="text-align:center;padding:24px;color:#888;">Belum ada ide yang dikirimkan oleh masyarakat.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($ideas as $i => $idm): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><strong><?= htmlspecialchars($idm['nama_pengirim']) ?></strong></td>
            <td>
              <?php if (!empty($idm['nomor_wa'])): ?>
                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $idm['nomor_wa']) ?>" target="_blank" style="color:var(--green-700);font-weight:700">
                  💬 <?= htmlspecialchars($idm['nomor_wa']) ?>
                </a>
              <?php else: ?>
                <span style="color:#aaa">-</span>
              <?php endif; ?>
            </td>
            <td style="max-width:320px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
              <?= htmlspecialchars($idm['deskripsi_ide']) ?>
            </td>
            <td>
              <?php if ($idm['status'] === 'baru'): ?>
                <span class="badge badge-blue">Baru</span>
              <?php elseif ($idm['status'] === 'ditinjau'): ?>
                <span class="badge badge-orange">Ditinjau</span>
              <?php elseif ($idm['status'] === 'disetujui'): ?>
                <span class="badge badge-green">Disetujui</span>
              <?php elseif ($idm['status'] === 'ditolak'): ?>
                <span class="badge badge-red">Ditolak</span>
              <?php else: ?>
                <span class="badge badge-purple">Direalisasi</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($idm['admin_name'] ?? '-') ?></td>
            <td>
              <div style="font-weight:600"><?= date('d/m/Y', strtotime($idm['created_at'])) ?></div>
              <div style="font-size:10px;color:#aaa"><?= date('H:i', strtotime($idm['created_at'])) ?> WITA</div>
            </td>
            <td>
              <div style="display:flex;gap:4px">
                <a class="btn btn-outline btn-icon" href="idea_management.php?detail=<?= $idm['id'] ?>" title="Tinjau">👁️</a>
                <button class="btn btn-danger btn-icon" title="Hapus" onclick="confirmDelete(<?= $idm['id'] ?>, '<?= htmlspecialchars(addslashes($idm['nama_pengirim'])) ?>')">🗑️</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- MODAL TINJAU DETAIL -->
<div class="modal-overlay" id="modalDetail" <?= $detailData ? 'style="display:flex"' : '' ?>>
  <div class="modal" style="max-width:600px">
    <div class="modal-header">
      <h3>Tinjau Ide Masuk</h3>
      <a href="idea_management.php" class="modal-close">✕</a>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="id" value="<?= $detailData['id'] ?? '' ?>">
      <div class="modal-body">
        
        <div style="background:#f8fafc;padding:16px;border-radius:8px;border:1.5px dashed var(--green-200);margin-bottom:16px">
          <div style="display:flex;justify-content:space-between;margin-bottom:8px">
            <span style="font-size:11px;color:#666">Pengirim:</span>
            <span style="font-size:12px;font-weight:700"><?= htmlspecialchars($detailData['nama_pengirim'] ?? '') ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;margin-bottom:8px">
            <span style="font-size:11px;color:#666">Nomor Kontak:</span>
            <span style="font-size:12px;font-weight:700;color:var(--green-700)"><?= htmlspecialchars($detailData['nomor_wa'] ?? '-') ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;margin-bottom:8px">
            <span style="font-size:11px;color:#666">Dikirim pada:</span>
            <span style="font-size:12px;font-weight:700"><?= !empty($detailData['created_at']) ? date('d M Y H:i', strtotime($detailData['created_at'])) . ' WITA' : '-' ?></span>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" style="color:#777">Isi Ide Kreatif</label>
          <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:7px;padding:12px;font-size:13px;line-height:1.5;color:#333;white-space:pre-wrap"><?= htmlspecialchars($detailData['deskripsi_ide'] ?? '') ?></div>
        </div>

        <div class="form-row" style="margin-top:16px">
          <div class="form-group">
            <label class="form-label">Ubah Status Ide</label>
            <select class="form-input" name="status">
              <option value="baru" <?= ($detailData['status'] ?? '') === 'baru' ? 'selected' : '' ?>>Baru</option>
              <option value="ditinjau" <?= ($detailData['status'] ?? '') === 'ditinjau' ? 'selected' : '' ?>>Ditinjau</option>
              <option value="disetujui" <?= ($detailData['status'] ?? '') === 'disetujui' ? 'selected' : '' ?>>Disetujui (Tampilkan/Proses)</option>
              <option value="ditolak" <?= ($detailData['status'] ?? '') === 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
              <option value="direalisasi" <?= ($detailData['status'] ?? '') === 'direalisasi' ? 'selected' : '' ?>>Direalisasi (Sudah Diwujudkan)</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" style="font-size:11px">Reviewer Terakhir</label>
            <input class="form-input" disabled value="<?= htmlspecialchars($detailData['admin_name'] ?? 'Belum ada') ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Catatan Admin (Untuk arsip internal)</label>
          <textarea class="form-input" name="catatan_admin" rows="3" placeholder="Masukkan catatan peninjauan, keputusan, atau follow-up di sini..."><?= htmlspecialchars($detailData['catatan_admin'] ?? '') ?></textarea>
        </div>

      </div>
      <div class="modal-footer">
        <a href="idea_management.php" class="btn btn-outline">Batal</a>
        <button type="submit" class="btn btn-primary">💾 Perbarui Ide</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL DELETE -->
<div class="modal-overlay" id="modalDelete">
  <div class="modal" style="max-width:400px">
    <div class="modal-header">
      <h3>Konfirmasi Hapus</h3>
      <button class="modal-close" onclick="closeModal('modalDelete')">✕</button>
    </div>
    <div class="modal-body">
      <p style="font-size:14px;color:#444" id="deleteMsg"></p>
    </div>
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
function confirmDelete(id, nama) {
  document.getElementById('deleteMsg').textContent = 'Hapus usulan ide dari "' + nama + '"? Tindakan ini tidak dapat dibatalkan.';
  document.getElementById('deleteId').value = id;
  openModal('modalDelete');
}
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
