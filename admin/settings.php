<?php
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/auth.php';
requireRole('admin');
$page_id    = 'settings';
$page_title = 'Pengaturan';
$db         = getDB();
$csrfToken  = csrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCsrfToken();
    $action = $_POST['action'];

    // Dapatkan user ID yang valid atau NULL untuk mencegah error foreign key constraint
    $current_user_id = $_SESSION['user_id'] ?? null;
    if ($current_user_id) {
        $chkUser = $db->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
        $chkUser->execute([$current_user_id]);
        if ((int)$chkUser->fetchColumn() === 0) {
            $current_user_id = null;
        }
    }
    if (!$current_user_id) {
        $current_user_id = $db->query("SELECT id FROM users LIMIT 1")->fetchColumn() ?: null;
    }

    if ($action === 'save_site') {
        $keys = ['site_name','whatsapp_number','instagram_url','google_maps_api_key'];
        foreach ($keys as $k) {
            $val = trim($_POST[$k] ?? '');
            $db->prepare("INSERT INTO site_settings (setting_key,setting_value,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=?, updated_by=?")->execute([$k,$val,$current_user_id,$val,$current_user_id]);
        }
        flash('success','Pengaturan situs disimpan!');
    }

    if ($action === 'save_tarif') {
        $keys = ['cleanup_tarif_per_jam','max_pickup_per_day','pickup_auto_confirm'];
        foreach ($keys as $k) {
            $val = trim($_POST[$k] ?? '0');
            $db->prepare("INSERT INTO site_settings (setting_key,setting_value,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=?, updated_by=?")->execute([$k,$val,$current_user_id,$val,$current_user_id]);
        }
        flash('success','Pengaturan tarif disimpan!');
    }

    if ($action === 'save_algo') {
        $keys = ['metode_jadwal','metode_rute','hari_jadwal','pickup_auto_confirm'];
        foreach ($keys as $k) {
            $val = trim($_POST[$k] ?? '');
            $db->prepare("INSERT INTO site_settings (setting_key,setting_value,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=?, updated_by=?")->execute([$k,$val,$current_user_id,$val,$current_user_id]);
        }
        flash('success','Pengaturan algoritma disimpan!');
    }

    if ($action === 'save_admin') {
        $nama  = trim($_POST['nama'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $pw    = trim($_POST['password'] ?? '');
        if ($nama && $email) {
            $current_admin_id = $_SESSION['user_id'] ?? 1;
            
            // Cek apakah email sudah digunakan oleh user lain
            $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
            $chk->execute([$email, $current_admin_id]);
            if ((int)$chk->fetchColumn() > 0) {
                flash('danger', 'Email ' . htmlspecialchars($email) . ' sudah digunakan oleh akun lain!');
            } else {
                try {
                    $db->prepare("UPDATE users SET nama=?,email=? WHERE id=?")->execute([$nama,$email,$current_admin_id]);
                    if ($pw) {
                        $hash = password_hash($pw, PASSWORD_BCRYPT);
                        $db->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash,$current_admin_id]);
                    }
                    // Update session values
                    $_SESSION['user_nama'] = $nama;
                    $_SESSION['user_email'] = $email;
                    
                    logActivity($db,$current_admin_id,'update_admin_settings','users',$current_admin_id);
                    flash('success','Akun admin diperbarui!');
                } catch (PDOException $e) {
                    flash('danger', 'Gagal memperbarui akun: ' . $e->getMessage());
                }
            }
        } else {
            flash('danger','Nama dan email tidak boleh kosong!');
        }
    }

    header('Location: settings.php'); exit;
}

// Load settings
$settings = $db->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$current_admin_id = $_SESSION['user_id'] ?? 1;
$admin_stmt = $db->prepare("SELECT nama, email FROM users WHERE id = ? LIMIT 1");
$admin_stmt->execute([$current_admin_id]);
$admin = $admin_stmt->fetch();

require_once __DIR__ . '/layout/header.php';
?>

<div class="page-header">
  <h1>Pengaturan Sistem</h1>
  <p>Konfigurasi global <?= SITE_NAME ?></p>
</div>

<!-- Quick links admin -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap">
  <a href="dashboard.php"        class="btn btn-outline btn-sm">🖥️ Dashboard</a>
  <a href="officer_management.php" class="btn btn-outline btn-sm">👷 Kelola Petugas</a>
  <a href="../officer/officer_console.php"  class="btn btn-outline btn-sm">📱 Officer Console</a>
  <a href="rute_jadwal.php"      class="btn btn-outline btn-sm">🗺️ Rute & Jadwal</a>
</div>

<div class="grid-2">
  <!-- Info Situs -->
  <div class="card">
    <div class="card-title"><div class="ct-icon">🌐</div> Info Situs</div>
    <form method="POST">
      <input type="hidden" name="action" value="save_site">
      <?= csrfInput() ?>
      <div class="form-group"><label class="form-label">Nama Situs</label><input class="form-input" name="site_name" value="<?= htmlspecialchars($settings['site_name'] ?? 'Manado Recycle Hub') ?>"></div>
      <div class="form-group"><label class="form-label">Nomor WhatsApp</label><input class="form-input" name="whatsapp_number" value="<?= htmlspecialchars($settings['whatsapp_number'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label">Instagram URL</label><input class="form-input" name="instagram_url" value="<?= htmlspecialchars($settings['instagram_url'] ?? '') ?>"></div>
      <div class="form-group">
        <label class="form-label">
          Google Maps API Key (Opsional)
          <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener"
             style="font-size:10px;color:var(--green-700);font-weight:600;margin-left:6px">Dapatkan Key →</a>
        </label>
        <input class="form-input" name="google_maps_api_key"
               type="password"
               value="<?= htmlspecialchars($settings['google_maps_api_key'] ?? '') ?>"
               placeholder="AIza...">
        <div style="font-size:11px;color:#888;margin-top:4px">
          Sistem menggunakan Leaflet (OpenStreetMap) secara default yang 100% gratis dan tidak membutuhkan API Key. Input di atas hanya opsional jika Anda ingin mengaktifkan layanan Google Maps tertentu di masa mendatang.
          <span style="color:#16a34a;font-weight:600;display:block;margin-top:2px">✓ OpenStreetMap aktif sebagai map utama (Tanpa API Key).</span>
        </div>
      </div>
      <button type="submit" class="btn btn-primary">💾 Simpan</button>
    </form>
  </div>

  <!-- Tarif Layanan -->
  <div class="card">
    <div class="card-title"><div class="ct-icon">💰</div> Tarif Layanan</div>
    <form method="POST">
      <input type="hidden" name="action" value="save_tarif">
      <?= csrfInput() ?>
      <div class="form-group">
        <label class="form-label">Penjemputan Reguler (Warga)</label>
        <input class="form-input" value="GRATIS" readonly style="background:#f5f5f5;color:#666">
      </div>
      <div class="form-group">
        <label class="form-label">Clean Up Service (Rp/jam)</label>
        <input class="form-input" type="number" name="cleanup_tarif_per_jam" value="<?= $settings['cleanup_tarif_per_jam'] ?? 50000 ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Maks. Request/Hari/Petugas</label>
        <input class="form-input" type="number" name="max_pickup_per_day" value="<?= $settings['max_pickup_per_day'] ?? 20 ?>">
      </div>
      <button type="submit" class="btn btn-primary">💾 Simpan</button>
    </form>
  </div>

  <!-- Pengaturan Algoritma -->
  <div class="card">
    <div class="card-title"><div class="ct-icon">🤖</div> Pengaturan Algoritma</div>
    <form method="POST">
      <input type="hidden" name="action" value="save_algo">
      <?= csrfInput() ?>
      <div class="form-group">
        <label class="form-label">Metode Penjadwalan</label>
        <select class="form-input" name="metode_jadwal">
          <option value="priority_rule" <?= ($settings['metode_jadwal']??'priority_rule')==='priority_rule'?'selected':'' ?>>Priority Rule (Request Terbanyak)</option>
          <option value="round_robin" <?= ($settings['metode_jadwal']??'')==='round_robin'?'selected':'' ?>>Round Robin Kecamatan</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Metode Penentuan Rute</label>
        <select class="form-input" name="metode_rute">
          <option value="nearest_neighbor" <?= ($settings['metode_rute']??'nearest_neighbor')==='nearest_neighbor'?'selected':'' ?>>Nearest Neighbor</option>
          <option value="greedy" <?= ($settings['metode_rute']??'')==='greedy'?'selected':'' ?>>Greedy Distance</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Hari Penjadwalan Otomatis</label>
        <select class="form-input" name="hari_jadwal">
          <option value="sabtu" <?= ($settings['hari_jadwal']??'sabtu')==='sabtu'?'selected':'' ?>>Sabtu</option>
          <option value="jumat" <?= ($settings['hari_jadwal']??'')==='jumat'?'selected':'' ?>>Jumat</option>
          <option value="harian" <?= ($settings['hari_jadwal']??'')==='harian'?'selected':'' ?>>Setiap Hari</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Konfirmasi Request Otomatis</label>
        <div class="toggle-wrap" style="margin-top:6px">
          <label class="toggle">
            <input type="checkbox" name="pickup_auto_confirm" value="1" id="autoConfirm" <?= ($settings['pickup_auto_confirm']??'0')==='1'?'checked':'' ?>>
            <span class="toggle-slider"></span>
          </label>
          <span class="toggle-label" id="autoConfirmLabel"><?= ($settings['pickup_auto_confirm']??'0')==='1'?'Aktif':'Nonaktif' ?></span>
        </div>
      </div>
      <button type="submit" class="btn btn-primary">💾 Simpan</button>
    </form>
  </div>

  <!-- Akun Admin -->
  <div class="card">
    <div class="card-title"><div class="ct-icon">🔐</div> Akun Admin</div>
    <form method="POST">
      <input type="hidden" name="action" value="save_admin">
      <?= csrfInput() ?>
      <div class="form-group"><label class="form-label">Nama Admin</label><input class="form-input" name="nama" value="<?= htmlspecialchars($admin['nama'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label">Email</label><input class="form-input" name="email" type="email" value="<?= htmlspecialchars($admin['email'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label">Password Baru</label><input class="form-input" name="password" type="password" placeholder="Kosongkan jika tidak diubah"></div>
      <div class="form-group"><label class="form-label">Konfirmasi Password</label><input class="form-input" type="password" placeholder="Ulangi password baru" id="confPw"></div>
      <button type="submit" class="btn btn-primary" id="saveAdminBtn">💾 Simpan</button>
    </form>
  </div>
</div>

<!-- Info DB -->
<div class="card" style="margin-top:16px">
  <div class="card-title"><div class="ct-icon">🗄️</div> Informasi Database</div>
  <div class="grid-3">
    <?php
    $tbls = [
      ['pickup_requests','Total Request'],
      ['officers','Total Petugas'],
      ['users','Total Pengguna'],
      ['waste_categories','Kategori Sampah'],
      ['survey_responses','Kuesioner'],
      ['activity_logs','Log Aktivitas'],
    ];
    foreach ($tbls as [$tbl,$lbl]):
      $cnt = (int)$db->query("SELECT COUNT(*) FROM $tbl")->fetchColumn();
    ?>
    <div style="background:#f9fafb;border-radius:var(--radius);padding:12px;border:1px solid #ebebeb">
      <div style="font-size:11px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px"><?= $lbl ?></div>
      <div style="font-size:22px;font-weight:700;color:var(--green-700)"><?= $cnt ?></div>
      <div style="font-size:10px;color:#aaa;margin-top:2px"><?= $tbl ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
document.getElementById('autoConfirm').addEventListener('change', function() {
  document.getElementById('autoConfirmLabel').textContent = this.checked ? 'Aktif' : 'Nonaktif';
});
document.getElementById('saveAdminBtn').addEventListener('click', function(e) {
  const pw1 = document.querySelector('input[name="password"]').value;
  const pw2 = document.getElementById('confPw').value;
  if (pw1 && pw1 !== pw2) { e.preventDefault(); showToast('danger','Password tidak cocok!'); }
});
</script>

<!-- Integrasi Officer ↔ Admin -->
<div class="card" style="margin-top:16px">
  <div class="card-title"><div class="ct-icon">🔗</div> Status Integrasi Officer ↔ Admin</div>
  <?php
    $officerAktif  = (int)$db->query("SELECT COUNT(*) FROM officers WHERE status='aktif'")->fetchColumn();
    $reqUnassigned = (int)$db->query("SELECT (SELECT COUNT(*) FROM pickup_requests WHERE officer_id IS NULL AND status NOT IN ('selesai','dibatalkan')) + (SELECT COUNT(*) FROM cleanup_requests WHERE officer_id IS NULL AND status NOT IN ('selesai','dibatalkan'))")->fetchColumn();
    $reqAssigned   = (int)$db->query("SELECT (SELECT COUNT(*) FROM pickup_requests WHERE officer_id IS NOT NULL AND status NOT IN ('selesai','dibatalkan')) + (SELECT COUNT(*) FROM cleanup_requests WHERE officer_id IS NOT NULL AND status NOT IN ('selesai','dibatalkan'))")->fetchColumn();
    $withGPS       = (int)$db->query("SELECT (SELECT COUNT(*) FROM pickup_requests WHERE latitude IS NOT NULL AND longitude IS NOT NULL) + (SELECT COUNT(*) FROM cleanup_requests WHERE latitude IS NOT NULL AND longitude IS NOT NULL)")->fetchColumn();
    $gmapsApiStatus = !empty($settings['google_maps_api_key']);
  ?>
  <div class="grid-3" style="gap:10px">
    <div style="background:<?= $officerAktif > 0 ? '#f0fdf4' : '#fff3f3' ?>;border-radius:8px;padding:12px;border:1px solid <?= $officerAktif > 0 ? '#bbf7d0' : '#fecaca' ?>">
      <div style="font-size:10px;color:#666;font-weight:700;text-transform:uppercase;margin-bottom:4px">Officer Aktif</div>
      <div style="font-size:22px;font-weight:800;color:<?= $officerAktif > 0 ? '#16a34a' : '#dc2626' ?>"><?= $officerAktif ?></div>
      <div style="font-size:10px;color:#888;margin-top:2px"><?= $officerAktif > 0 ? '✅ Siap bertugas' : '⚠️ Tidak ada petugas aktif' ?></div>
    </div>
    <div style="background:<?= $reqUnassigned == 0 ? '#f0fdf4' : '#fffbeb' ?>;border-radius:8px;padding:12px;border:1px solid <?= $reqUnassigned == 0 ? '#bbf7d0' : '#fde68a' ?>">
      <div style="font-size:10px;color:#666;font-weight:700;text-transform:uppercase;margin-bottom:4px">Request Unassigned</div>
      <div style="font-size:22px;font-weight:800;color:<?= $reqUnassigned == 0 ? '#16a34a' : '#d97706' ?>"><?= $reqUnassigned ?></div>
      <div style="font-size:10px;color:#888;margin-top:2px"><?= $reqUnassigned == 0 ? '✅ Semua ter-assign' : '⚠️ Perlu di-assign' ?></div>
    </div>
    <div style="background:#f0fdf4;border-radius:8px;padding:12px;border:1px solid #bbf7d0">
      <div style="font-size:10px;color:#666;font-weight:700;text-transform:uppercase;margin-bottom:4px">Map Engine</div>
      <div style="font-size:22px;font-weight:800;color:#16a34a">OSM</div>
      <div style="font-size:10px;color:#888;margin-top:2px">Leaflet/OpenStreetMap aktif secara default (Tanpa API Key)</div>
    </div>
    <div style="background:#f0f9ff;border-radius:8px;padding:12px;border:1px solid #bae6fd">
      <div style="font-size:10px;color:#666;font-weight:700;text-transform:uppercase;margin-bottom:4px">Request Ter-assign</div>
      <div style="font-size:22px;font-weight:800;color:#0284c7"><?= $reqAssigned ?></div>
      <div style="font-size:10px;color:#888;margin-top:2px">sedang ditangani officer</div>
    </div>
    <div style="background:#f5f3ff;border-radius:8px;padding:12px;border:1px solid #ddd6fe">
      <div style="font-size:10px;color:#666;font-weight:700;text-transform:uppercase;margin-bottom:4px">Data GPS/Koordinat</div>
      <div style="font-size:22px;font-weight:800;color:#7c3aed"><?= $withGPS ?></div>
      <div style="font-size:10px;color:#888;margin-top:2px">request punya koordinat</div>
    </div>
    <div style="background:#fafafa;border-radius:8px;padding:12px;border:1px solid #e2e8f0">
      <div style="font-size:10px;color:#666;font-weight:700;text-transform:uppercase;margin-bottom:4px">Officer Console</div>
      <div style="font-size:22px;font-weight:800;color:#334155">📱</div>
      <div style="font-size:10px;margin-top:4px"><a href="../officer/officer_console.php" style="color:#1c6434;font-weight:700">Buka Console →</a></div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
