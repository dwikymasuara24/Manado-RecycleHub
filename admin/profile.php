<?php
// profile.php
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/auth.php';
requireRole('admin');
$page_id    = 'profile';
$page_title = 'Profil Admin';
$db         = getDB();
$csrfToken  = csrfToken();

$current_admin_id = $_SESSION['user_id'] ?? 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $nama  = trim($_POST['nama'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $wa    = trim($_POST['nomor_wa'] ?? '');
    $pw    = trim($_POST['password'] ?? '');
    if ($nama && $email) {
        // Cek apakah email sudah digunakan oleh user lain
        $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
        $chk->execute([$email, $current_admin_id]);
        if ((int)$chk->fetchColumn() > 0) {
            flash('danger', 'Email ' . htmlspecialchars($email) . ' sudah digunakan oleh akun lain!');
        } else {
            try {
                $db->prepare("UPDATE users SET nama=?,email=?,nomor_wa=? WHERE id=?")->execute([$nama,$email,$wa,$current_admin_id]);
                if ($pw) {
                    $db->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($pw,PASSWORD_BCRYPT),$current_admin_id]);
                }
                // Update session values
                $_SESSION['user_nama'] = $nama;
                $_SESSION['user_email'] = $email;
                
                flash('success','Profil berhasil diperbarui!');
            } catch (PDOException $e) {
                flash('danger', 'Gagal memperbarui profil: ' . $e->getMessage());
            }
        }
    } else {
        flash('danger','Nama dan email wajib diisi!');
    }
    header('Location: profile.php'); exit;
}

$admin_stmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$admin_stmt->execute([$current_admin_id]);
$admin = $admin_stmt->fetch();

$logCountStmt = $db->prepare("SELECT COUNT(*) FROM activity_logs WHERE user_id=?");
$logCountStmt->execute([$current_admin_id]);
$logCount = (int)$logCountStmt->fetchColumn();

require_once __DIR__ . '/layout/header.php';
?>

<div class="page-header">
  <h1>Profil Admin</h1>
  <p>Informasi dan pengaturan akun administrator</p>
</div>

<div class="grid-2">
  <div class="card">
    <div style="display:flex;align-items:center;gap:20px;margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid #f0f0f0">
      <div style="width:72px;height:72px;border-radius:50%;background:var(--green-700);display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;color:#fff;flex-shrink:0">
        <?= strtoupper(substr($admin['nama']??'A',0,1)) ?>
      </div>
      <div>
        <div style="font-weight:700;font-size:17px"><?= htmlspecialchars($admin['nama']??'Admin') ?></div>
        <div style="font-size:13px;color:#888;margin-top:2px"><?= htmlspecialchars($admin['email']??'') ?></div>
        <span class="badge badge-green" style="margin-top:6px">Super Admin</span>
      </div>
    </div>

    <form method="POST">
      <?= csrfInput() ?>
      <div class="form-group"><label class="form-label">Nama Lengkap</label><input class="form-input" name="nama" value="<?= htmlspecialchars($admin['nama']??'') ?>"></div>
      <div class="form-group"><label class="form-label">Email</label><input class="form-input" name="email" type="email" value="<?= htmlspecialchars($admin['email']??'') ?>"></div>
      <div class="form-group"><label class="form-label">Nomor WhatsApp</label><input class="form-input" name="nomor_wa" value="<?= htmlspecialchars($admin['nomor_wa']??'') ?>"></div>
      <div class="form-group"><label class="form-label">Password Baru</label><input class="form-input" name="password" type="password" placeholder="Kosongkan jika tidak diubah"></div>
      <button type="submit" class="btn btn-primary">💾 Simpan Perubahan</button>
    </form>
  </div>

  <div class="card">
    <div class="card-title"><div class="ct-icon">📊</div> Statistik Akun</div>
    <div style="display:flex;flex-direction:column;gap:14px">
      <div style="display:flex;justify-content:space-between;padding:12px;background:#f9fafb;border-radius:var(--radius)">
        <span style="font-size:13px;color:#555">Bergabung Sejak</span>
        <span style="font-size:13px;font-weight:700"><?= fmtDate($admin['created_at']??'','d M Y') ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;padding:12px;background:#f9fafb;border-radius:var(--radius)">
        <span style="font-size:13px;color:#555">Total Aksi Log</span>
        <span style="font-size:13px;font-weight:700;color:var(--green-700)"><?= $logCount ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;padding:12px;background:#f9fafb;border-radius:var(--radius)">
        <span style="font-size:13px;color:#555">Role</span>
        <span class="badge badge-purple">Super Admin</span>
      </div>
      <div style="display:flex;justify-content:space-between;padding:12px;background:#f9fafb;border-radius:var(--radius)">
        <span style="font-size:13px;color:#555">Status Akun</span>
        <span class="badge badge-green">Aktif & Terverifikasi</span>
      </div>
    </div>
    <div style="margin-top:20px;padding-top:16px;border-top:1px solid #f0f0f0">
      <a href="logout.php" class="btn btn-danger" style="width:100%;justify-content:center">🚪 Keluar dari Sistem</a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
