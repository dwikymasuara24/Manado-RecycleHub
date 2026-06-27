<?php
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/auth.php';
requireRole('admin');
$page_id    = 'diy_management';
$page_title = 'Manajemen DIY';
$db         = getDB();

// Handle POST actions (Save, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'save') {
        $id              = (int)($_POST['id'] ?? 0);
        $judul           = trim($_POST['judul'] ?? '');
        $deskripsi       = trim($_POST['deskripsi'] ?? '');
        $ikon_emoji      = trim($_POST['ikon_emoji'] ?? '♻️');
        $level_kesulitan = trim($_POST['level_kesulitan'] ?? 'mudah');
        $bahan_baku      = trim($_POST['bahan_baku'] ?? '');
        $gambar_url      = trim($_POST['gambar_url'] ?? '');
        $status          = trim($_POST['status'] ?? 'draft');
        $author_id       = $_SESSION['user_id'] ?? 1;

        // Ensure upload directory exists
        $upload_dir = __DIR__ . '/../uploads/diy/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        // Handle Ikon Foto upload
        if (isset($_FILES['ikon_foto']) && $_FILES['ikon_foto']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['ikon_foto']['tmp_name'];
            $file_name = $_FILES['ikon_foto']['name'];
            
            $ext = pathinfo($file_name, PATHINFO_EXTENSION);
            $clean_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file_name, PATHINFO_FILENAME));
            $new_filename = 'icon_' . time() . '_' . substr($clean_name, 0, 30) . '.' . $ext;
            
            if (move_uploaded_file($file_tmp, $upload_dir . $new_filename)) {
                $ikon_emoji = 'uploads/diy/' . $new_filename;
            }
        }

        // Handle Gambar URL upload
        if (isset($_FILES['gambar_file']) && $_FILES['gambar_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['gambar_file']['tmp_name'];
            $file_name = $_FILES['gambar_file']['name'];
            
            $ext = pathinfo($file_name, PATHINFO_EXTENSION);
            $clean_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file_name, PATHINFO_FILENAME));
            $new_filename = 'diy_' . time() . '_' . $clean_name . '.' . $ext;
            
            if (move_uploaded_file($file_tmp, $upload_dir . $new_filename)) {
                $gambar_url = 'uploads/diy/' . $new_filename;
            }
        }

        if (!$judul) {
            flash('danger', 'Judul DIY wajib diisi!');
            header('Location: diy_management.php');
            exit;
        }

        // Generate slug
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($judul));
        $slug = trim($slug, '-');

        // Check uniqueness of slug
        $checkStmt = $db->prepare("SELECT id FROM diy_projects WHERE slug = ? AND id != ?");
        $checkStmt->execute([$slug, $id]);
        if ($checkStmt->fetch()) {
            $slug .= '-' . time();
        }

        try {
            $db->beginTransaction();

            if ($id) {
                $db->prepare("UPDATE diy_projects 
                              SET judul=?, slug=?, deskripsi=?, ikon_emoji=?, level_kesulitan=?, bahan_baku=?, gambar_url=?, status=?, updated_at=NOW() 
                              WHERE id=?")
                   ->execute([$judul, $slug, $deskripsi, $ikon_emoji, $level_kesulitan, $bahan_baku, $gambar_url, $status, $id]);
                $project_id = $id;
            } else {
                $db->prepare("INSERT INTO diy_projects (author_id, judul, slug, deskripsi, ikon_emoji, level_kesulitan, bahan_baku, gambar_url, status, created_at, updated_at) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
                   ->execute([$author_id, $judul, $slug, $deskripsi, $ikon_emoji, $level_kesulitan, $bahan_baku, $gambar_url, $status]);
                $project_id = $db->lastInsertId();
            }

            // Save steps
            // 1. Delete existing steps
            $db->prepare("DELETE FROM diy_steps WHERE project_id = ?")->execute([$project_id]);

            // 2. Insert new steps
            $step_titles = $_POST['step_title'] ?? [];
            $step_descs  = $_POST['step_desc'] ?? [];
            $urutan = 1;

            for ($idx = 0; $idx < count($step_titles); $idx++) {
                $st = trim($step_titles[$idx] ?? '');
                $sd = trim($step_descs[$idx] ?? '');

                if ($st !== '' || $sd !== '') {
                    $db->prepare("INSERT INTO diy_steps (project_id, urutan, judul_langkah, deskripsi) VALUES (?, ?, ?, ?)")
                       ->execute([$project_id, $urutan, $st ?: "Langkah $urutan", $sd]);
                    $urutan++;
                }
            }

            $db->commit();
            flash('success', $id ? 'Proyek DIY berhasil diperbarui!' : 'Proyek DIY baru berhasil ditambahkan!');
        } catch (Exception $e) {
            $db->rollBack();
            flash('danger', 'Gagal menyimpan proyek DIY: ' . $e->getMessage());
        }

        header('Location: diy_management.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $db->beginTransaction();
            $db->prepare("DELETE FROM diy_steps WHERE project_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM diy_projects WHERE id = ?")->execute([$id]);
            $db->commit();
            flash('success', 'Proyek DIY berhasil dihapus.');
        } catch (Exception $e) {
            $db->rollBack();
            flash('danger', 'Gagal menghapus proyek DIY: ' . $e->getMessage());
        }
        header('Location: diy_management.php');
        exit;
    }
}

// Search and filters
$search = trim($_GET['q'] ?? '');
$filter_difficulty = trim($_GET['difficulty'] ?? '');
$filter_status = trim($_GET['status'] ?? '');

$where = '1=1';
$params = [];

if ($search) {
    $where .= " AND (judul LIKE ? OR deskripsi LIKE ? OR bahan_baku LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filter_difficulty) {
    $where .= " AND level_kesulitan = ?";
    $params[] = $filter_difficulty;
}
if ($filter_status) {
    $where .= " AND status = ?";
    $params[] = $filter_status;
}

$stmt = $db->prepare("SELECT dp.*, u.nama AS author_name, (SELECT COUNT(*) FROM diy_steps ds WHERE ds.project_id = dp.id) AS total_steps 
                      FROM diy_projects dp 
                      LEFT JOIN users u ON dp.author_id = u.id 
                      WHERE $where ORDER BY dp.created_at DESC");
$stmt->execute($params);
$projects = $stmt->fetchAll();

// Edit prefill logic
$editData = null;
$editSteps = [];
if (!empty($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $editData = $db->query("SELECT * FROM diy_projects WHERE id=$eid")->fetch();
    if ($editData) {
        $editSteps = $db->query("SELECT * FROM diy_steps WHERE project_id=$eid ORDER BY urutan ASC")->fetchAll();
    }
}

require_once __DIR__ . '/layout/header.php';
?>

<div class="page-header">
  <h1>Manajemen DIY Daur Ulang</h1>
  <p>Kelola ide kreatif produk daur ulang beserta panduan langkah pembuatannya</p>
</div>

<div class="card">
  <div class="toolbar">
    <div class="toolbar-left">
      <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input class="search-input" name="q" type="text" placeholder="Cari proyek DIY..." value="<?= htmlspecialchars($search) ?>">
        
        <select class="filter-select" name="difficulty" onchange="this.form.submit()">
          <option value="">-- Semua Kesulitan --</option>
          <option value="mudah" <?= $filter_difficulty==='mudah'?'selected':'' ?>>Mudah</option>
          <option value="menengah" <?= $filter_difficulty==='menengah'?'selected':'' ?>>Menengah</option>
          <option value="sulit" <?= $filter_difficulty==='sulit'?'selected':'' ?>>Sulit</option>
        </select>

        <select class="filter-select" name="status" onchange="this.form.submit()">
          <option value="">-- Semua Status --</option>
          <option value="draft" <?= $filter_status==='draft'?'selected':'' ?>>Draft</option>
          <option value="published" <?= $filter_status==='published'?'selected':'' ?>>Published</option>
          <option value="archived" <?= $filter_status==='archived'?'selected':'' ?>>Archived</option>
        </select>

        <?php if ($search || $filter_difficulty || $filter_status): ?>
          <a href="diy_management.php" class="btn btn-outline">Reset</a>
        <?php endif; ?>
      </form>
    </div>
    <div class="toolbar-right">
      <button class="btn btn-primary" onclick="openModal('modalDiy')">+ Tambah Proyek DIY</button>
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Ikon</th>
          <th>Judul Proyek</th>
          <th>Kesulitan</th>
          <th>Bahan Baku Utama</th>
          <th>Status</th>
          <th>Langkah</th>
          <th>Dibuat Oleh</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($projects)): ?>
          <tr>
            <td colspan="9" style="text-align:center;padding:24px;color:#888;">Belum ada proyek DIY. Klik "Tambah Proyek DIY" untuk menambahkan.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($projects as $i => $p): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td>
              <?php if (!empty($p['ikon_emoji']) && (strpos($p['ikon_emoji'], '/') !== false || strpos($p['ikon_emoji'], '.') !== false)): ?>
                <img src="../<?= htmlspecialchars($p['ikon_emoji']) ?>" alt="Icon" style="width:32px; height:32px; object-fit:cover; border-radius:4px; border:1px solid #e0e0e0" onerror="this.src='../logo_square.png'; this.onerror=null;">
              <?php else: ?>
                <span style="font-size:24px"><?= htmlspecialchars($p['ikon_emoji'] ?: '♻️') ?></span>
              <?php endif; ?>
            </td>
            <td>
              <strong><?= htmlspecialchars($p['judul']) ?></strong>
              <div style="font-size:11px;color:#888;margin-top:2px">Slug: <code><?= htmlspecialchars($p['slug']) ?></code></div>
            </td>
            <td>
              <?php if ($p['level_kesulitan'] === 'mudah'): ?>
                <span class="badge badge-green">⭐ Mudah</span>
              <?php elseif ($p['level_kesulitan'] === 'menengah'): ?>
                <span class="badge badge-amber">⭐⭐ Menengah</span>
              <?php else: ?>
                <span class="badge badge-red">⭐⭐⭐ Sulit</span>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;color:#555;max-width:180px"><?= htmlspecialchars($p['bahan_baku'] ?: '-') ?></td>
            <td>
              <?php if ($p['status'] === 'published'): ?>
                <span class="badge badge-green">Published</span>
              <?php elseif ($p['status'] === 'draft'): ?>
                <span class="badge badge-amber">Draft</span>
              <?php else: ?>
                <span class="badge badge-red">Archived</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge badge-blue"><?= $p['total_steps'] ?> Langkah</span>
            </td>
            <td><?= htmlspecialchars($p['author_name'] ?? 'Admin') ?></td>
            <td>
              <div style="display:flex;gap:4px">
                <a class="btn btn-outline btn-icon" href="diy_management.php?edit=<?= $p['id'] ?>" title="Edit">✏️</a>
                <button class="btn btn-danger btn-icon" title="Hapus" onclick="confirmDelete(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['judul'])) ?>')">🗑️</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- MODAL TAMBAH/EDIT -->
<div class="modal-overlay" id="modalDiy" <?= $editData ? 'style="display:flex"' : '' ?>>
  <div class="modal" style="max-width:760px">
    <div class="modal-header">
      <h3><?= $editData ? 'Edit Proyek DIY' : 'Tambah Proyek DIY Baru' ?></h3>
      <a href="diy_management.php" class="modal-close">✕</a>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= $editData['id'] ?? '' ?>">
      <div class="modal-body">
        
        <div class="form-row">
          <div class="form-group" style="grid-column: span 2">
            <label class="form-label">Judul Proyek *</label>
            <input class="form-input" name="judul" required value="<?= htmlspecialchars($editData['judul'] ?? '') ?>" placeholder="e.g. Vas Bunga dari Botol Plastik">
          </div>
          <div class="form-group">
            <label class="form-label">Ikon Emoji</label>
            <input class="form-input" name="ikon_emoji" value="<?= htmlspecialchars($editData['ikon_emoji'] ?? '♻️') ?>" placeholder="e.g. 🏺, 📦, 💡">
          </div>
          <div class="form-group">
            <label class="form-label">Ikon Foto (Upload - Bebas Ekstensi)</label>
            <input type="file" class="form-input" name="ikon_foto" style="padding: 8px;">
            <?php if (!empty($editData['ikon_emoji']) && (strpos($editData['ikon_emoji'], '/') !== false || strpos($editData['ikon_emoji'], '.') !== false)): ?>
              <div style="margin-top:6px; display:flex; align-items:center; gap:8px;">
                <img src="../<?= htmlspecialchars($editData['ikon_emoji']) ?>" alt="Icon Preview" style="width:30px; height:30px; object-fit:cover; border-radius:4px; border:1px solid #ddd" onerror="this.src='../logo_square.png'; this.onerror=null;">
                <span style="font-size:11px; color:#666;">Ikon foto aktif</span>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Level Kesulitan</label>
            <select class="form-input" name="level_kesulitan">
              <option value="mudah" <?= ($editData['level_kesulitan'] ?? '') === 'mudah' ? 'selected' : '' ?>>Mudah</option>
              <option value="menengah" <?= ($editData['level_kesulitan'] ?? '') === 'menengah' ? 'selected' : '' ?>>Menengah</option>
              <option value="sulit" <?= ($editData['level_kesulitan'] ?? '') === 'sulit' ? 'selected' : '' ?>>Sulit</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <select class="form-input" name="status">
              <option value="draft" <?= ($editData['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option>
              <option value="published" <?= ($editData['status'] ?? 'published') === 'published' ? 'selected' : '' ?>>Published</option>
              <option value="archived" <?= ($editData['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archived</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Bahan Baku Utama</label>
          <input class="form-input" name="bahan_baku" value="<?= htmlspecialchars($editData['bahan_baku'] ?? '') ?>" placeholder="e.g. Botol PET, cat, dekorasi, pita">
        </div>

        <div class="form-group">
          <label class="form-label">Upload Gambar / Foto Proyek (Bebas Ekstensi)</label>
          <input type="file" class="form-input" name="gambar_file" style="padding: 8px;">
          <?php if (!empty($editData['gambar_url'])): ?>
            <div style="margin-top:8px; display:flex; align-items:center; gap:12px; background:#f8fafc; padding:8px 12px; border-radius:6px; border:1px solid #e2e8f0;">
              <img src="../<?= htmlspecialchars($editData['gambar_url']) ?>" alt="Preview" style="width:60px; height:60px; object-fit:cover; border-radius:4px; border:1px solid #cbd5e1" onerror="this.src='../logo_square.png'; this.onerror=null;">
              <div>
                <span style="font-size:12px; color:#475569; font-weight:600; display:block;">Gambar Saat Ini:</span>
                <code style="font-size:11px; color:#64748b; word-break:break-all;"><?= htmlspecialchars($editData['gambar_url']) ?></code>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <div class="form-group">
          <label class="form-label">Atau Gunakan Gambar URL Manual (Path Relatif)</label>
          <input class="form-input" name="gambar_url" value="<?= htmlspecialchars($editData['gambar_url'] ?? '') ?>" placeholder="e.g. images/diy-vas.png">
        </div>

        <div class="form-group">
          <label class="form-label">Deskripsi Proyek</label>
          <textarea class="form-input" name="deskripsi" rows="2" placeholder="Jelaskan secara singkat kegunaan proyek DIY ini..."><?= htmlspecialchars($editData['deskripsi'] ?? '') ?></textarea>
        </div>

        <!-- LANGKAH-LANGKAH (STEPS) WIZARD FORM -->
        <div style="margin-top:20px;padding-top:16px;border-top:2.5px solid var(--green-200)">
          <h4 style="font-weight:700;color:var(--green-700);margin-bottom:12px;font-size:14px">Langkah-Langkah Pembuatan</h4>
          
          <div style="display:flex;flex-direction:column;gap:12px">
            <?php for ($s = 1; $s <= 6; $s++): 
              $s_title = $editSteps[$s-1]['judul_langkah'] ?? '';
              $s_desc  = $editSteps[$s-1]['deskripsi'] ?? '';
            ?>
              <div style="background:#f9fafb;border:1px solid #e2e8f0;border-radius:8px;padding:12px">
                <div style="font-weight:700;font-size:12px;color:var(--green-700);margin-bottom:8px">LANGKAH <?= $s ?></div>
                <div class="form-row">
                  <div class="form-group" style="margin-bottom:0">
                    <label class="form-label" style="font-size:10px">Judul Langkah</label>
                    <input class="form-input" style="padding:6px 10px;font-size:12px" name="step_title[]" value="<?= htmlspecialchars($s_title) ?>" placeholder="e.g. Persiapan Bahan">
                  </div>
                  <div class="form-group" style="margin-bottom:0">
                    <label class="form-label" style="font-size:10px">Penjelasan Langkah</label>
                    <input class="form-input" style="padding:6px 10px;font-size:12px" name="step_desc[]" value="<?= htmlspecialchars($s_desc) ?>" placeholder="e.g. Cuci bersih botol dan siapkan alat cat...">
                  </div>
                </div>
              </div>
            <?php endfor; ?>
          </div>
        </div>

      </div>
      <div class="modal-footer">
        <a href="diy_management.php" class="btn btn-outline">Batal</a>
        <button type="submit" class="btn btn-primary">💾 Simpan Proyek</button>
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
function confirmDelete(id, judul) {
  document.getElementById('deleteMsg').textContent = 'Hapus proyek DIY "' + judul + '" beserta semua langkah pembuatannya?';
  document.getElementById('deleteId').value = id;
  openModal('modalDelete');
}
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
