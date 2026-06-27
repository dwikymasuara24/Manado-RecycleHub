<?php
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/auth.php';
requireRole('admin');
$page_id    = 'blog_management';
$page_title = 'Manajemen Blog';
$db         = getDB();
$csrfToken  = csrfToken();

// Handle POST actions (Save, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCsrfToken();
    $action = $_POST['action'];

    if ($action === 'save') {
        $id            = (int)($_POST['id'] ?? 0);
        $judul         = trim($_POST['judul'] ?? '');
        $konten        = sanitizeRichText(trim($_POST['konten'] ?? ''));
        $gambar_url    = trim($_POST['gambar_url'] ?? '');

        // Handle file upload if present
        if (isset($_FILES['gambar_file']) && $_FILES['gambar_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['gambar_file']['tmp_name'];
            $file_name = $_FILES['gambar_file']['name'];
            
            // Extract extension
            $ext = pathinfo($file_name, PATHINFO_EXTENSION);
            // Clean file name
            $clean_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file_name, PATHINFO_FILENAME));
            $new_filename = 'blog_' . time() . '_' . $clean_name . '.' . $ext;
            
            $upload_dir = __DIR__ . '/../uploads/blog/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            if (move_uploaded_file($file_tmp, $upload_dir . $new_filename)) {
                $gambar_url = 'uploads/blog/' . $new_filename;
            }
        }

        $gambar_alt    = trim($_POST['gambar_alt'] ?? '');
        $sumber_gambar = trim($_POST['sumber_gambar'] ?? '');
        $tags          = trim($_POST['tags'] ?? '');
        $platform_asal = trim($_POST['platform_asal'] ?? 'website');
        $status        = trim($_POST['status'] ?? 'draft');
        $author_id     = $_SESSION['user_id'] ?? 1;

        if (!$judul) {
            flash('danger', 'Judul wajib diisi!');
            header('Location: blog_management.php');
            exit;
        }

        // Generate slug
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($judul));
        $slug = trim($slug, '-');

        // Check if slug is unique
        $checkStmt = $db->prepare("SELECT id FROM blog_posts WHERE slug = ? AND id != ?");
        $checkStmt->execute([$slug, $id]);
        if ($checkStmt->fetch()) {
            $slug .= '-' . time();
        }

        if ($id) {
            $sql = "UPDATE blog_posts 
                    SET judul=?, slug=?, konten=?, gambar_url=?, gambar_alt=?, sumber_gambar=?, tags=?, platform_asal=?, status=?, updated_at=NOW()";
            if ($status === 'published') {
                $sql .= ", published_at=COALESCE(published_at, NOW())";
            }
            $sql .= " WHERE id=?";
            $db->prepare($sql)->execute([$judul, $slug, $konten, $gambar_url, $gambar_alt, $sumber_gambar, $tags, $platform_asal, $status, $id]);
            flash('success', 'Artikel blog berhasil diperbarui!');
        } else {
            $published_at = ($status === 'published') ? date('Y-m-d H:i:s') : null;
            $db->prepare("INSERT INTO blog_posts (author_id, judul, slug, konten, gambar_url, gambar_alt, sumber_gambar, tags, platform_asal, status, published_at, created_at, updated_at) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
               ->execute([$author_id, $judul, $slug, $konten, $gambar_url, $gambar_alt, $sumber_gambar, $tags, $platform_asal, $status, $published_at]);
            flash('success', 'Artikel blog baru berhasil ditambahkan!');
        }
        header('Location: blog_management.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM blog_posts WHERE id=?")->execute([$id]);
        flash('success', 'Artikel blog berhasil dihapus.');
        header('Location: blog_management.php');
        exit;
    }
}

// Search and Filter query parameters
$search = trim($_GET['q'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$filter_platform = trim($_GET['platform'] ?? '');

$where = '1=1';
$params = [];

if ($search) {
    $where .= " AND (judul LIKE ? OR konten LIKE ? OR tags LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filter_status) {
    $where .= " AND status = ?";
    $params[] = $filter_status;
}
if ($filter_platform) {
    $where .= " AND platform_asal = ?";
    $params[] = $filter_platform;
}

$stmt = $db->prepare("SELECT bp.*, u.nama AS author_name FROM blog_posts bp LEFT JOIN users u ON bp.author_id = u.id WHERE $where ORDER BY bp.created_at DESC");
$stmt->execute($params);
$posts = $stmt->fetchAll();

// Edit prefill logic
$editData = null;
if (!empty($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $editData = $db->query("SELECT * FROM blog_posts WHERE id=$eid")->fetch();
}

require_once __DIR__ . '/layout/header.php';
?>

<div class="page-header">
  <h1>Manajemen Blog & Media Sosial</h1>
  <p>Kelola artikel edukasi, berita, dan postingan media sosial untuk warga</p>
</div>

<div class="card">
  <div class="toolbar">
    <div class="toolbar-left">
      <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input class="search-input" name="q" type="text" placeholder="Cari artikel..." value="<?= htmlspecialchars($search) ?>">
        
        <select class="filter-select" name="status" onchange="this.form.submit()">
          <option value="">-- Semua Status --</option>
          <option value="draft" <?= $filter_status==='draft'?'selected':'' ?>>Draft</option>
          <option value="published" <?= $filter_status==='published'?'selected':'' ?>>Published</option>
          <option value="archived" <?= $filter_status==='archived'?'selected':'' ?>>Archived</option>
        </select>

        <select class="filter-select" name="platform" onchange="this.form.submit()">
          <option value="">-- Semua Platform --</option>
          <option value="website" <?= $filter_platform==='website'?'selected':'' ?>>Website</option>
          <option value="instagram" <?= $filter_platform==='instagram'?'selected':'' ?>>Instagram</option>
          <option value="facebook" <?= $filter_platform==='facebook'?'selected':'' ?>>Facebook</option>
          <option value="twitter" <?= $filter_platform==='twitter'?'selected':'' ?>>Twitter</option>
          <option value="youtube" <?= $filter_platform==='youtube'?'selected':'' ?>>YouTube</option>
          <option value="lainnya" <?= $filter_platform==='lainnya'?'selected':'' ?>>Lainnya</option>
        </select>

        <?php if ($search || $filter_status || $filter_platform): ?>
          <a href="blog_management.php" class="btn btn-outline">Reset</a>
        <?php endif; ?>
      </form>
    </div>
    <div class="toolbar-right">
      <button class="btn btn-primary" onclick="openModal('modalBlog')">+ Buat Postingan Baru</button>
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Gambar</th>
          <th>Judul</th>
          <th>Platform</th>
          <th>Status</th>
          <th>Author</th>
          <th>Tanggal Dibuat</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($posts)): ?>
          <tr>
            <td colspan="8" style="text-align:center;padding:24px;color:#888;">Belum ada postingan blog. Klik "Buat Postingan Baru" untuk menambahkan.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($posts as $i => $p): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td>
              <?php if (!empty($p['gambar_url'])): ?>
                <img src="../<?= htmlspecialchars($p['gambar_url']) ?>" alt="Thumbnail" style="width:50px;height:50px;object-fit:cover;border-radius:4px;border:1px solid #e0e0e0" onerror="this.src='../logo_square.png'; this.onerror=null;">
              <?php else: ?>
                <div style="width:50px;height:50px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;font-size:16px;border-radius:4px;color:#bbb">🖼️</div>
              <?php endif; ?>
            </td>
            <td style="max-width:250px">
              <strong><?= htmlspecialchars($p['judul']) ?></strong>
              <div style="font-size:11px;color:#888;margin-top:2px">Slug: <code><?= htmlspecialchars($p['slug']) ?></code></div>
              <?php if (!empty($p['tags'])): ?>
                <div style="margin-top:4px">
                  <?php foreach (explode(',', $p['tags']) as $tag): ?>
                    <span style="font-size:9px;background:#e2e8f0;color:#4a5568;padding:1px 5px;border-radius:3px;margin-right:2px">#<?= htmlspecialchars(trim($tag)) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge badge-gray"><?= htmlspecialchars(ucfirst($p['platform_asal'])) ?></span>
            </td>
            <td>
              <?php if ($p['status'] === 'published'): ?>
                <span class="badge badge-green">Published</span>
              <?php elseif ($p['status'] === 'draft'): ?>
                <span class="badge badge-amber">Draft</span>
              <?php else: ?>
                <span class="badge badge-red">Archived</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($p['author_name'] ?? 'Admin') ?></td>
            <td>
              <div style="font-weight:600"><?= date('d/m/Y', strtotime($p['created_at'])) ?></div>
              <div style="font-size:10px;color:#aaa"><?= date('H:i', strtotime($p['created_at'])) ?> WITA</div>
            </td>
            <td>
              <div style="display:flex;gap:4px">
                <a class="btn btn-outline btn-icon" href="blog_management.php?edit=<?= $p['id'] ?>" title="Edit">✏️</a>
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
<div class="modal-overlay" id="modalBlog" <?= $editData ? 'style="display:flex"' : '' ?>>
  <div class="modal" style="max-width:720px">
    <div class="modal-header">
      <h3><?= $editData ? 'Edit Postingan Blog' : 'Buat Postingan Baru' ?></h3>
      <a href="blog_management.php" class="modal-close">✕</a>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= $editData['id'] ?? '' ?>">
      <?= csrfInput() ?>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Judul Artikel / Postingan *</label>
          <input class="form-input" name="judul" required value="<?= htmlspecialchars($editData['judul'] ?? '') ?>" placeholder="Masukkan judul postingan...">
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Platform Asal</label>
            <select class="form-input" name="platform_asal">
              <option value="website" <?= ($editData['platform_asal'] ?? '') === 'website'?'selected':'' ?>>Website</option>
              <option value="instagram" <?= ($editData['platform_asal'] ?? '') === 'instagram'?'selected':'' ?>>Instagram</option>
              <option value="facebook" <?= ($editData['platform_asal'] ?? '') === 'facebook'?'selected':'' ?>>Facebook</option>
              <option value="twitter" <?= ($editData['platform_asal'] ?? '') === 'twitter'?'selected':'' ?>>Twitter</option>
              <option value="youtube" <?= ($editData['platform_asal'] ?? '') === 'youtube'?'selected':'' ?>>YouTube</option>
              <option value="lainnya" <?= ($editData['platform_asal'] ?? '') === 'lainnya'?'selected':'' ?>>Lainnya</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Status Postingan</label>
            <select class="form-input" name="status">
              <option value="draft" <?= ($editData['status'] ?? '') === 'draft'?'selected':'' ?>>Draft</option>
              <option value="published" <?= ($editData['status'] ?? 'published') === 'published'?'selected':'' ?>>Published</option>
              <option value="archived" <?= ($editData['status'] ?? '') === 'archived'?'selected':'' ?>>Archived</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Upload Gambar / Foto Artikel (Bebas Ekstensi)</label>
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
          <input class="form-input" name="gambar_url" value="<?= htmlspecialchars($editData['gambar_url'] ?? '') ?>" placeholder="e.g. images/global-recycling-day.png">
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Alt Teks Gambar</label>
            <input class="form-input" name="gambar_alt" value="<?= htmlspecialchars($editData['gambar_alt'] ?? '') ?>" placeholder="e.g. Ilustrasi daur ulang plastik">
          </div>
          <div class="form-group">
            <label class="form-label">Sumber Gambar</label>
            <input class="form-input" name="sumber_gambar" value="<?= htmlspecialchars($editData['sumber_gambar'] ?? '') ?>" placeholder="e.g. pexels.com, Sarah Chai">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Tags / Kategori Hashtag (Pisahkan dengan koma)</label>
          <input class="form-input" name="tags" value="<?= htmlspecialchars($editData['tags'] ?? '') ?>" placeholder="e.g. recycle, daurulang, ramahlingkungan">
        </div>

        <div class="form-group">
          <label class="form-label">Konten Artikel (Mendukung tag HTML seperti &lt;p&gt;, &lt;a&gt;, &lt;b&gt;, &lt;br&gt;) *</label>
          <textarea class="form-input" name="konten" required rows="6" placeholder="Masukkan konten artikel..."><?= htmlspecialchars($editData['konten'] ?? '') ?></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <a href="blog_management.php" class="btn btn-outline">Batal</a>
        <button type="submit" class="btn btn-primary">💾 Simpan Postingan</button>
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
        <?= csrfInput() ?>
        <button type="submit" class="btn btn-danger">Ya, Hapus</button>
      </form>
    </div>
  </div>
</div>

<script>
function confirmDelete(id, judul) {
  document.getElementById('deleteMsg').textContent = 'Hapus postingan blog "' + judul + '"? Tindakan ini tidak dapat dibatalkan.';
  document.getElementById('deleteId').value = id;
  openModal('modalDelete');
}
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
