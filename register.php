<?php
// ============================================================
//  register.php — Registrasi Admin & Officer/Petugas
//  Manado Recycle Hub
//  Sinkron penuh dengan: users + officers (hub.sql)
// ============================================================
require_once __DIR__ . '/include/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Kalau sudah login, redirect sesuai role
if (!empty($_SESSION['user_id'])) {
    $r = $_SESSION['role_name'] ?? '';
    header('Location: ' . ($r === 'admin' ? 'admin/dashboard.php' : ($r === 'officer' ? 'officer/officer_console.php' : 'index.php')));
    exit;
}

$db      = getDB();
$errors  = [];
$success = false;
$old     = [];

// ── Auto-migrasi aman: tambah kolom yang belum ada ───────────
$migrateCols = [
    'users' => [
        'nomor_wa'      => "ALTER TABLE `users` ADD COLUMN `nomor_wa`      VARCHAR(20)  DEFAULT NULL",
        'alamat'        => "ALTER TABLE `users` ADD COLUMN `alamat`         TEXT         DEFAULT NULL",
        'kota'          => "ALTER TABLE `users` ADD COLUMN `kota`           VARCHAR(100) DEFAULT 'Manado'",
        'kecamatan'     => "ALTER TABLE `users` ADD COLUMN `kecamatan`      VARCHAR(100) DEFAULT NULL",
        'kelurahan'     => "ALTER TABLE `users` ADD COLUMN `kelurahan`      VARCHAR(100) DEFAULT NULL",
        'foto_profil'   => "ALTER TABLE `users` ADD COLUMN `foto_profil`    VARCHAR(255) DEFAULT NULL",
        'is_active'     => "ALTER TABLE `users` ADD COLUMN `is_active`      TINYINT(1)   NOT NULL DEFAULT 1",
        'email_verified'=> "ALTER TABLE `users` ADD COLUMN `email_verified` TINYINT(1)   NOT NULL DEFAULT 0",
        'last_login_at' => "ALTER TABLE `users` ADD COLUMN `last_login_at`  TIMESTAMP    NULL DEFAULT NULL",
        'updated_at'    => "ALTER TABLE `users` ADD COLUMN `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ],
    'officers' => [
        'nomor_wa'          => "ALTER TABLE `officers` ADD COLUMN `nomor_wa`          VARCHAR(20)  DEFAULT NULL",
        'nip'               => "ALTER TABLE `officers` ADD COLUMN `nip`               VARCHAR(50)  DEFAULT NULL",
        'zona_tugas'        => "ALTER TABLE `officers` ADD COLUMN `zona_tugas`        VARCHAR(150) DEFAULT NULL",
        'kendaraan'         => "ALTER TABLE `officers` ADD COLUMN `kendaraan`         VARCHAR(100) DEFAULT NULL",
        'status'            => "ALTER TABLE `officers` ADD COLUMN `status`            ENUM('aktif','cuti','nonaktif') NOT NULL DEFAULT 'aktif'",
        'tanggal_bergabung' => "ALTER TABLE `officers` ADD COLUMN `tanggal_bergabung` DATE         DEFAULT NULL",
        'catatan'           => "ALTER TABLE `officers` ADD COLUMN `catatan`           TEXT         DEFAULT NULL",
        'updated_at'        => "ALTER TABLE `officers` ADD COLUMN `updated_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ],
];
foreach ($migrateCols as $tbl => $cols) {
    $existing = $db->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cols as $col => $sql) {
        if (!in_array($col, $existing)) {
            try { $db->exec($sql); }
            catch (PDOException $e) { error_log("[MRH Migrate $tbl.$col] " . $e->getMessage()); }
        }
    }
}

// ── Ambil roles admin & officer dari DB ───────────────────────
$allowedRoles = $db->query("SELECT id, name, description FROM roles WHERE name IN ('admin','officer') ORDER BY name")->fetchAll();
$roleMap      = [];
foreach ($allowedRoles as $rl) $roleMap[$rl['id']] = $rl;

// ── Kecamatan zona tugas officer ─────────────────────────────
$kecamatans = ['Wenang','Malalayang','Tikala','Paal Dua','Bunaken','Singkil','Mapanget','Wanea','Sario','Tuminting'];

// ── Generate officer_code unik ────────────────────────────────
function nextOfficerCode(PDO $db): string {
    $last = $db->query("SELECT officer_code FROM officers ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($last && preg_match('/(\d+)$/', $last, $m)) {
        $num = (int)$m[1] + 1;
    } else {
        $num = 1;
    }
    return 'OFF' . str_pad($num, 4, '0', STR_PAD_LEFT);
}

// ── Cek kolom yang benar-benar ada di tabel officers ─────────
$officerCols = $db->query("SHOW COLUMNS FROM officers")->fetchAll(PDO::FETCH_COLUMN);

// ── POST handler ──────────────────────────────────────────────
// Opsi jenis kendaraan
$jenisKendaraanOpts = ['Motor','Mobil Pick-up','Truk Kecil','Truk Sedang','Gerobak Motor'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old           = $_POST;
    $role_id       = (int)($_POST['role_id']        ?? 0);
    $nama          = trim($_POST['nama']            ?? '');
    $email         = trim($_POST['email']           ?? '');
    $pass          = $_POST['password']             ?? '';
    $passConf      = $_POST['password_conf']        ?? '';
    $nomor_wa      = preg_replace('/\D/', '', trim($_POST['nomor_wa'] ?? ''));
    $jenis_kend    = trim($_POST['jenis_kendaraan'] ?? '');
    $nomor_plat    = strtoupper(trim($_POST['nomor_plat'] ?? ''));
    $nip           = preg_replace('/\D/', '', trim($_POST['nip'] ?? '')); // angka saja

    // ── Validasi ──────────────────────────────────────────────
    if (!isset($roleMap[$role_id]))
        $errors[] = 'Role tidak valid. Pilih Admin atau Officer.';
    if (strlen($nama) < 3)
        $errors[] = 'Nama minimal 3 karakter.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'Format email tidak valid.';
    if (strlen($pass) < 8)
        $errors[] = 'Password minimal 8 karakter.';
    if ($pass !== $passConf)
        $errors[] = 'Konfirmasi password tidak cocok.';
    if (empty($nomor_wa))
        $errors[] = 'Nomor WhatsApp wajib diisi (angka saja, contoh: 6281234567890).';
    if (!empty($roleMap[$role_id]) && $roleMap[$role_id]['name'] === 'officer') {
        if (empty($jenis_kend)) $errors[] = 'Jenis kendaraan wajib dipilih untuk Officer.';
        if (empty($nomor_plat)) $errors[] = 'Nomor plat kendaraan wajib diisi untuk Officer.';
    }

    // Cek email unik
    if (empty($errors)) {
        $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ((int)$chk->fetchColumn() > 0)
            $errors[] = 'Email ' . htmlspecialchars($email) . ' sudah terdaftar.';
    }

    // ── Simpan ke DB ──────────────────────────────────────────
    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $hash = password_hash($pass, PASSWORD_BCRYPT);

            // Bangun kolom INSERT secara dinamis sesuai kolom yang ada
            $userCols   = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $insertCols = ['role_id','nama','email','password_hash'];
            $insertVals = [$role_id, $nama, $email, $hash];

            if (in_array('nomor_wa', $userCols))       { $insertCols[] = 'nomor_wa';       $insertVals[] = $nomor_wa; }
            if (in_array('is_active', $userCols))      { $insertCols[] = 'is_active';       $insertVals[] = 1; }
            if (in_array('email_verified', $userCols)) { $insertCols[] = 'email_verified';  $insertVals[] = 1; }

            $colList  = implode(', ', array_map(fn($c) => "`$c`", $insertCols));
            $phList   = implode(', ', array_fill(0, count($insertVals), '?'));
            $db->prepare("INSERT INTO users ($colList, created_at) VALUES ($phList, NOW())")
               ->execute($insertVals);

            $uid = (int)$db->lastInsertId();

            // Officer → tambah ke tabel officers
            if ($roleMap[$role_id]['name'] === 'officer') {
                $code = nextOfficerCode($db);

                $officerCols = $db->query("SHOW COLUMNS FROM officers")->fetchAll(PDO::FETCH_COLUMN);

                $oCols = ['user_id','officer_code','nama'];
                $oVals = [$uid, $code, $nama];

                if (in_array('nomor_wa',   $officerCols)) { $oCols[] = 'nomor_wa';   $oVals[] = $nomor_wa; }
                if (in_array('status',     $officerCols)) { $oCols[] = 'status';      $oVals[] = 'aktif'; }

                if (in_array('kendaraan',  $officerCols)) {
                    $kendVal = trim($jenis_kend . ($nomor_plat ? ' — ' . $nomor_plat : ''));
                    $oCols[] = 'kendaraan'; $oVals[] = $kendVal;
                }
                if (in_array('nip', $officerCols) && $nip !== '') {
                    $oCols[] = 'nip'; $oVals[] = $nip;
                }

                $oColList = implode(', ', array_map(fn($c) => "`$c`", $oCols));
                $oPhList  = implode(', ', array_fill(0, count($oVals), '?'));
                $db->prepare("INSERT INTO officers ($oColList, created_at) VALUES ($oPhList, NOW())")
                   ->execute($oVals);
            }

            $db->commit();
            $success = true;
            $old     = [];

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errors[] = 'Gagal menyimpan: ' . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrasi Akun — <?= SITE_NAME ?></title>
    <link rel="icon" type="image/png" href="<?= baseUrl('Title.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Comfortaa:wght@400;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --g:#1c6434; --gl:#22c55e; }
        *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Inter',sans-serif; background:#f8fafc; color:#1e293b; display:flex; min-height:100vh; }

        /* ── Split Layout ── */
        .split { display:flex; width:100%; }
        .left {
            flex:1; min-width:0;
            background: linear-gradient(145deg, rgba(28,100,52,.92), rgba(34,197,94,.75)),
                        url('https://images.unsplash.com/photo-1532996122724-e3c354a0b15b?auto=format&fit=crop&w=1600&q=80') center/cover;
            display:flex; flex-direction:column; justify-content:center; align-items:center;
            padding:48px 40px; color:#fff; position:relative;
        }
        .left::after { content:''; position:absolute; inset:0; background:rgba(0,0,0,.18); z-index:1; }
        .brand { z-index:2; text-align:center; max-width:440px; }
        .brand-logo { width:76px; height:76px; background:#fff; border-radius:18px; display:flex; align-items:center; justify-content:center; font-size:36px; font-weight:800; color:var(--g); margin:0 auto 22px; box-shadow:0 10px 28px rgba(0,0,0,.22); }
        .brand h1 { font-family:'Comfortaa',cursive; font-size:32px; font-weight:700; margin-bottom:14px; line-height:1.2; }
        .brand p  { font-size:15px; opacity:.9; line-height:1.65; }
        .feature-list { margin-top:32px; text-align:left; display:flex; flex-direction:column; gap:12px; }
        .feature-item { display:flex; align-items:center; gap:10px; font-size:14px; opacity:.92; }
        .fi-icon { font-size:18px; }

        /* ── Right panel ── */
        .right { width:100%; max-width:560px; background:#fff; display:flex; flex-direction:column; justify-content:center; padding:48px 52px; overflow-y:auto; box-shadow:-8px 0 28px rgba(0,0,0,.06); }

        .reg-header { margin-bottom:28px; }
        .reg-header h2 { font-size:26px; font-weight:800; color:#0f172a; margin-bottom:6px; }
        .reg-header p  { color:#64748b; font-size:13.5px; }

        /* ── Role selector ── */
        .role-tabs { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:24px; }
        .role-tab { border:2px solid #e2e8f0; border-radius:12px; padding:14px 16px; cursor:pointer; transition:all .2s; background:#f8fafc; display:flex; align-items:center; gap:10px; }
        .role-tab:hover { border-color:#86efac; background:#f0fdf4; }
        .role-tab.selected { border-color:var(--g); background:#f0fdf4; }
        .role-tab input[type=radio] { display:none; }
        .rt-icon { font-size:24px; }
        .rt-info .rt-title { font-size:14px; font-weight:700; color:#1e293b; }
        .rt-info .rt-sub   { font-size:11px; color:#64748b; margin-top:2px; }

        /* ── Form ── */
        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .form-group { margin-bottom:16px; }
        .form-label { display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:7px; text-transform:uppercase; letter-spacing:.04em; }
        .form-input, .form-select { width:100%; padding:13px 15px; background:#f8fafc; border:2px solid #e2e8f0; border-radius:11px; font-size:14px; font-family:'Inter',sans-serif; outline:none; transition:all .2s; color:#1e293b; }
        .form-input:focus, .form-select:focus { border-color:var(--gl); background:#fff; box-shadow:0 0 0 4px rgba(34,197,94,.1); }
        .form-input.error, .form-select.error { border-color:#f87171; }

        /* ── Zona chips ── */
        .zona-grid { display:flex; flex-wrap:wrap; gap:7px; margin-top:4px; }
        .zona-chip { position:relative; }
        .zona-chip input { position:absolute; opacity:0; width:0; height:0; }
        .zona-chip label { display:inline-block; padding:5px 13px; border:1.5px solid #e2e8f0; border-radius:20px; font-size:12px; font-weight:600; cursor:pointer; transition:all .15s; background:#f8fafc; color:#475569; }
        .zona-chip input:checked + label { border-color:var(--g); background:#f0fdf4; color:var(--g); }
        .zona-chip label:hover { border-color:#86efac; background:#f0fdf4; }

        /* ── Officer extra fields ── */
        #officerFields { display:none; }
        #officerFields.show { display:block; }

        /* ── Password strength ── */
        .pass-strength { height:4px; border-radius:2px; background:#e2e8f0; margin-top:7px; overflow:hidden; }
        .pass-fill { height:100%; border-radius:2px; width:0; transition:width .3s, background .3s; }

        /* ── Centered Flash Notification Overlay Style ── */
        .flash-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            animation: alert-fade-in 0.25s ease-out;
        }
        .flash {
            background: #ffffff !important;
            border-radius: 16px !important;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04) !important;
            padding: 24px 32px !important;
            max-width: 400px;
            width: 90%;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 16px;
            border: none !important;
            animation: alert-scale-in 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
            font-family: inherit;
        }
        .flash-icon {
            font-size: 48px;
            line-height: 1;
        }
        .flash-msg {
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.5;
        }
        .flash-close-btn {
            background: #1c6434;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 8px 24px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
            transition: background 0.15s;
        }
        .flash-close-btn:hover {
            background: #166534;
        }
        
        @keyframes alert-fade-in {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes alert-scale-in {
            from { transform: scale(0.9) translateY(10px); }
            to { transform: scale(1) translateY(0); }
        }

        /* ── Submit ── */
        .btn-submit { width:100%; padding:14px; background:var(--g); color:#fff; border:none; border-radius:12px; font-size:15px; font-weight:700; cursor:pointer; transition:all .2s; margin-top:8px; font-family:'Inter',sans-serif; }
        .btn-submit:hover { background:#144f29; transform:translateY(-2px); box-shadow:0 8px 18px rgba(28,100,52,.22); }
        .btn-submit:active { transform:none; }

        .back-link { display:inline-flex; align-items:center; gap:6px; color:#64748b; font-size:13px; font-weight:600; text-decoration:none; margin-top:24px; transition:color .2s; }
        .back-link:hover { color:var(--g); }

        .divider { text-align:center; color:#cbd5e1; font-size:12px; margin:20px 0; display:flex; align-items:center; gap:10px; }
        .divider::before, .divider::after { content:''; flex:1; height:1px; background:#e2e8f0; }

        @media (max-width:860px) {
            .left { display:none; }
            .right { max-width:100%; align-items:center; padding:40px 24px; }
            form { width:100%; max-width:440px; }
            .form-row { grid-template-columns: 1fr; }
        }

        /* Password Show/Hide Styles */
        .password-toggle-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #64748b;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            outline: none;
            transition: color 0.2s;
            z-index: 5;
        }
        .password-toggle-btn:hover {
            color: var(--g);
        }
        .eye-icon {
            width: 20px;
            height: 20px;
            pointer-events: none;
        }
        .password-toggle-btn .eye-slash {
            display: none;
        }
        .password-toggle-btn.show-password .eye-open {
            display: none;
        }
        .password-toggle-btn.show-password .eye-slash {
            display: block;
        }
    </style>
</head>
<body>

<div class="split">
    <!-- ── LEFT SIDE ── -->
    <div class="left">
        <div class="brand">
            <div class="brand-logo">M</div>
            <h1><?= SITE_NAME ?></h1>
            <p>Platform pengelolaan sampah daur ulang terintegrasi untuk kota Manado.</p>
            <div class="feature-list">
                <div class="feature-item"><span class="fi-icon">🛡️</span> Role-based access control</div>
                <div class="feature-item"><span class="fi-icon">🗺️</span> Rute penjemputan otomatis (algoritma)</div>
                <div class="feature-item"><span class="fi-icon">📊</span> Dashboard real-time</div>
                <div class="feature-item"><span class="fi-icon">📱</span> Mobile-friendly untuk petugas lapangan</div>
            </div>
        </div>
    </div>

    <!-- ── RIGHT SIDE ── -->
    <div class="right">
        <?php if ($success): ?>
        <!-- SUCCESS STATE -->
        <div style="text-align:center;padding:20px 0">
            <div style="font-size:64px;margin-bottom:16px">✅</div>
            <h2 style="font-size:24px;font-weight:800;color:#0f172a;margin-bottom:8px">Akun Berhasil Dibuat!</h2>
            <p style="color:#64748b;margin-bottom:28px">Silakan login dengan email dan password yang sudah didaftarkan.</p>
            <a href="login.php" style="display:inline-flex;align-items:center;gap:8px;background:var(--g);color:#fff;padding:13px 28px;border-radius:12px;font-weight:700;font-size:15px;text-decoration:none;transition:background .2s">
                🔐 Login
            </a>
            <br>
            <a href="register.php" class="back-link" style="margin-top:16px;display:inline-flex">+ Daftarkan Akun Lain</a>
        </div>

        <?php else: ?>
        <!-- FORM -->
        <div class="reg-header">
            <h2>Daftar Akun Baru</h2>
            <p>Registrasi untuk <strong>Admin</strong> atau <strong>Petugas Lapangan</strong></p>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="flash-overlay" id="flashOverlay">
            <div class="flash flash-danger" style="max-width: 440px;">
                <div class="flash-icon">⚠️</div>
                <div class="flash-msg" style="text-align: left; width: 100%;">
                    <div style="font-weight: 800; margin-bottom: 8px; text-align: center;">Mohon perbaiki kesalahan berikut:</div>
                    <ul style="margin-left: 20px; font-size: 13px; font-weight: 600; color: #4b5563;"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
                </div>
                <button type="button" class="flash-close-btn" onclick="document.getElementById('flashOverlay').style.display='none'">Tutup</button>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" action="" id="regForm">

            <!-- ROLE SELECTOR -->
            <div class="form-group">
                <label class="form-label">Pilih Role *</label>
                <div class="role-tabs" id="roleTabs">
                    <?php foreach ($allowedRoles as $rl): ?>
                    <?php $isOfficer = ($rl['name'] === 'officer'); ?>
                    <label class="role-tab <?= (int)($old['role_id'] ?? 0) === (int)$rl['id'] ? 'selected' : '' ?>"
                           for="role_<?= $rl['id'] ?>">
                        <input type="radio" name="role_id" id="role_<?= $rl['id'] ?>"
                               value="<?= $rl['id'] ?>"
                               <?= (int)($old['role_id'] ?? 0) === (int)$rl['id'] ? 'checked' : '' ?>
                               onchange="onRoleChange(<?= $rl['id'] ?>, <?= $isOfficer ? 'true' : 'false' ?>)">
                        <span class="rt-icon"><?= $isOfficer ? '👷' : '🛡️' ?></span>
                        <div class="rt-info">
                            <div class="rt-title"><?= $isOfficer ? 'Officer / Petugas' : 'Admin' ?></div>
                            <div class="rt-sub"><?= htmlspecialchars($rl['description'] ?? ($isOfficer ? 'Petugas lapangan' : 'Pengelola sistem')) ?></div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- NAMA & EMAIL -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="nama">Nama Lengkap *</label>
                    <input class="form-input <?= in_array('Nama minimal 3 karakter.', $errors) ? 'error' : '' ?>"
                           type="text" id="nama" name="nama" placeholder="Nama lengkap"
                           value="<?= htmlspecialchars($old['nama'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="nomor_wa">Nomor WhatsApp *</label>
                    <input class="form-input <?= in_array('Nomor WhatsApp wajib diisi.', $errors) ? 'error' : '' ?>"
                           type="tel" id="nomor_wa" name="nomor_wa" placeholder="628xxxxxxxxx"
                           value="<?= htmlspecialchars($old['nomor_wa'] ?? '') ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="email">Alamat Email *</label>
                <input class="form-input <?= in_array('Format email tidak valid.', $errors) || in_array('Email sudah terdaftar.', $errors) ? 'error' : '' ?>"
                       type="email" id="email" name="email" placeholder="email@domain.com"
                       value="<?= htmlspecialchars($old['email'] ?? '') ?>" required>
            </div>

            <!-- PASSWORD -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="password">Password *</label>
                    <div style="position: relative;">
                        <input class="form-input <?= in_array('Password minimal 8 karakter.', $errors) ? 'error' : '' ?>"
                               type="password" id="password" name="password"
                               placeholder="Min. 8 karakter" required
                               oninput="updateStrength(this.value)" style="padding-right: 46px;">
                        <button type="button" id="togglePassword" class="password-toggle-btn" onclick="togglePasswordVisibility('password', 'togglePassword')">
                            <!-- Eye Open SVG -->
                            <svg class="eye-icon eye-open" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <!-- Eye Slash SVG -->
                            <svg class="eye-icon eye-slash" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                            </svg>
                        </button>
                    </div>
                    <div class="pass-strength"><div class="pass-fill" id="passFill"></div></div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="password_conf">Konfirmasi Password *</label>
                    <div style="position: relative;">
                        <input class="form-input <?= in_array('Konfirmasi password tidak cocok.', $errors) ? 'error' : '' ?>"
                               type="password" id="password_conf" name="password_conf"
                               placeholder="Ulangi password" required style="padding-right: 46px;">
                        <button type="button" id="togglePasswordConf" class="password-toggle-btn" onclick="togglePasswordVisibility('password_conf', 'togglePasswordConf')">
                            <!-- Eye Open SVG -->
                            <svg class="eye-icon eye-open" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <!-- Eye Slash SVG -->
                            <svg class="eye-icon eye-slash" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- OFFICER EXTRA FIELDS -->
            <div id="officerFields" class="<?= (isset($old['role_id']) && ($roleMap[(int)$old['role_id']]['name'] ?? '') === 'officer') ? 'show' : '' ?>">
                <div class="divider">Detail Petugas Lapangan</div>


                <!-- NIP -->
                <div class="form-group" style="margin-top:4px">
                    <label class="form-label" for="nip">NIP <span style="font-weight:400;color:#94a3b8;text-transform:none">(Nomor Induk Pegawai, opsional)</span></label>
                    <input class="form-input" type="text" id="nip" name="nip"
                           placeholder="Kosongkan jika tidak ada"
                           value="<?= htmlspecialchars($old['nip'] ?? '') ?>"
                           inputmode="numeric" pattern="\d*">
                    <div style="font-size:10px;color:#94a3b8;margin-top:5px">Hanya angka, maksimal 50 digit.</div>
                </div>

                <!-- Kendaraan -->

                <div class="form-row" style="margin-top:4px">
                    <div class="form-group">
                        <label class="form-label" for="jenis_kendaraan">Jenis Kendaraan *</label>
                        <select class="form-select <?= (!empty($errors) && empty($old['jenis_kendaraan'])) ? 'error' : '' ?>"
                                id="jenis_kendaraan" name="jenis_kendaraan">
                            <option value="">— Pilih Jenis —</option>
                            <?php foreach ($jenisKendaraanOpts as $jk): ?>
                            <option value="<?= $jk ?>" <?= ($old['jenis_kendaraan'] ?? '') === $jk ? 'selected' : '' ?>>
                                <?= $jk ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="nomor_plat">Nomor Plat Kendaraan *</label>
                        <input class="form-input <?= (!empty($errors) && empty($old['nomor_plat'])) ? 'error' : '' ?>"
                               type="text" id="nomor_plat" name="nomor_plat"
                               placeholder="Contoh: DB 1234 XY"
                               value="<?= htmlspecialchars($old['nomor_plat'] ?? '') ?>"
                               style="text-transform:uppercase">
                        <div style="font-size:10px;color:#94a3b8;margin-top:5px">
                            Disimpan sebagai: Jenis — Plat (contoh: Motor — DB1234XY)
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-submit" id="btnSubmit">
                ✅ Buat Akun
            </button>
        </form>

        <div class="divider">sudah punya akun?</div>
        <a href="login.php" style="display:block;text-align:center;padding:13px;border:2px solid #e2e8f0;border-radius:12px;font-weight:700;font-size:14px;color:#475569;text-decoration:none;transition:all .2s"
           onmouseover="this.style.borderColor='#86efac';this.style.color='#1c6434'"
           onmouseout="this.style.borderColor='#e2e8f0';this.style.color='#475569'">
            🔐 Login
        </a>
        <?php endif; ?>
    </div>
</div>

<script>
function togglePasswordVisibility(inputId, btnId) {
    const input = document.getElementById(inputId);
    const btn = document.getElementById(btnId);
    if (input.type === 'password') {
        input.type = 'text';
        btn.classList.add('show-password');
    } else {
        input.type = 'password';
        btn.classList.remove('show-password');
    }
}

// ── Role selector ─────────────────────────────────────────────
<?php
$officerRoleIds = array_values(array_filter($allowedRoles, fn($r) => $r['name'] === 'officer'));
$officerRoleId  = $officerRoleIds[0]['id'] ?? 0;
?>
const OFFICER_ROLE_ID = <?= (int)$officerRoleId ?>;

function onRoleChange(roleId, isOfficer) {
    // Visual tab selection
    document.querySelectorAll('.role-tab').forEach(t => t.classList.remove('selected'));
    const lbl = document.querySelector(`label[for="role_${roleId}"]`);
    if (lbl) lbl.classList.add('selected');

    // Show/hide officer fields
    const of = document.getElementById('officerFields');
    if (isOfficer) { of.classList.add('show'); }
    else           { of.classList.remove('show'); }
}

// Auto-select first role if none chosen
window.addEventListener('DOMContentLoaded', () => {
    const checked = document.querySelector('input[name="role_id"]:checked');
    if (!checked) {
        const first = document.querySelector('input[name="role_id"]');
        if (first) { first.checked = true; first.dispatchEvent(new Event('change')); }
    }
});



// ── Password strength ──────────────────────────────────────────
function updateStrength(val) {
    const fill = document.getElementById('passFill');
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const colors = ['#ef4444','#f97316','#eab308','#22c55e'];
    fill.style.width  = (score * 25) + '%';
    fill.style.background = colors[score - 1] || '#e2e8f0';
}

// ── Confirm password match ──────────────────────────────────────
document.getElementById('regForm').addEventListener('submit', function(e) {
    const p  = document.getElementById('password').value;
    const pc = document.getElementById('password_conf').value;
    if (p !== pc) {
        e.preventDefault();
        document.getElementById('password_conf').classList.add('error');
        document.getElementById('password_conf').focus();
        alert('Konfirmasi password tidak cocok!');
    }

});
</script>
</body>
</html>
