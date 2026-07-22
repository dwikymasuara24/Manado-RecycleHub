<?php
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/auth.php';
requireRole('admin');
$page_id    = 'cleanup_management';
$page_title = 'Manajemen Clean Up';
$db         = getDB();
$csrfToken  = csrfToken();

// --- AUTO-MIGRATION: Create tables if not exist ---
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `cleanup_requests` (
        `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `request_code` varchar(20) NOT NULL DEFAULT '',
        `user_id` bigint(20) UNSIGNED DEFAULT NULL,
        `service_type` varchar(50) DEFAULT NULL,
        `nama_pemohon` varchar(150) NOT NULL,
        `nomor_wa` varchar(30) NOT NULL,
        `alamat_jemput` text DEFAULT NULL,
        `kecamatan` varchar(100) DEFAULT NULL,
        `kelurahan` varchar(100) DEFAULT NULL,
        `latitude` decimal(10,8) DEFAULT NULL,
        `longitude` decimal(11,8) DEFAULT NULL,
        `area_size_sqm` decimal(10,2) DEFAULT NULL,
        `dominant_waste` varchar(255) DEFAULT NULL,
        `catatan` text DEFAULT NULL,
        `status` enum('menunggu','dikonfirmasi','dijadwalkan','dalam_perjalanan','sedang_diproses','sedang_cleanup','selesai','dibatalkan') NOT NULL DEFAULT 'menunggu',
        `officer_id` bigint(20) UNSIGNED DEFAULT NULL,
        `tanggal_tugas` date DEFAULT NULL,
        `jam_mulai` time DEFAULT NULL,
        `estimasi_jam_kerja` int(11) DEFAULT NULL,
        `biaya_estimasi` decimal(12,2) DEFAULT NULL,
        `biaya_aktual` decimal(12,2) DEFAULT NULL,
        `foto_sebelum` varchar(255) DEFAULT NULL,
        `foto_sesudah` varchar(255) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        `completed_at` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_cleanup_request_code` (`request_code`),
        KEY `idx_cleanup_user` (`user_id`),
        KEY `idx_cleanup_officer` (`officer_id`),
        KEY `idx_cleanup_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    $db->exec("CREATE TABLE IF NOT EXISTS `cleanup_items` (
        `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `cleanup_id` bigint(20) UNSIGNED NOT NULL,
        `category_id` smallint(5) UNSIGNED NOT NULL,
        `berat_kg` decimal(10,2) DEFAULT NULL,
        `catatan` varchar(255) DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_cleanup_item_req` (`cleanup_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    // Add trigger if not exists (Simplified approach: drop and create)
    $db->exec("DROP TRIGGER IF EXISTS `trg_cleanup_request_code` ");
    $db->exec("CREATE TRIGGER `trg_cleanup_request_code` BEFORE INSERT ON `cleanup_requests` FOR EACH ROW BEGIN
        DECLARE v_code VARCHAR(20);
        DECLARE v_exists INT DEFAULT 1;
        IF NEW.request_code IS NULL OR NEW.request_code = '' THEN
            WHILE v_exists > 0 DO
                SET v_code = CONCAT('CLN', LPAD(FLOOR(RAND() * 90000000) + 10000000, 8, '0'));
                SELECT COUNT(*) INTO v_exists FROM cleanup_requests WHERE request_code = v_code;
            END WHILE;
            SET NEW.request_code = v_code;
        END IF;
    END");
    // Pastikan kolom baru ditambahkan jika tabel sudah ada sebelumnya
    try {
        $existingCols = array_map('strtolower', $db->query("SHOW COLUMNS FROM `cleanup_requests`")->fetchAll(PDO::FETCH_COLUMN));
        if (!in_array('jam_kerja_aktual', $existingCols)) {
            $db->exec("ALTER TABLE `cleanup_requests` ADD COLUMN `jam_kerja_aktual` DECIMAL(10,2) NULL AFTER `estimasi_jam_kerja`");
        }
        if (!in_array('catatan_officer', $existingCols)) {
            $db->exec("ALTER TABLE `cleanup_requests` ADD COLUMN `catatan_officer` TEXT NULL AFTER `catatan`");
        }
    } catch (Exception $e) {}
} catch (Exception $e) {
    // Ignore migration errors or log them
}


// ── Kategori Clean Up (Shared) ──────────────────────────────────
$cleanup_types = [
    'acara'  => ['label' => 'Bersih-bersih Acara', 'icon' => '🎉'],
    'rumah'  => ['label' => 'Pembersihan Rumah',   'icon' => '🏠'],
    'kantor' => ['label' => 'Pembersihan Kantor',  'icon' => '🏢'],
];

// ── AJAX / POST handler ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCsrfToken();
    $action = $_POST['action'];

    // ── KONFIRMASI & SET BIAYA ──
    if ($action === 'confirm') {
        $id    = (int)($_POST['id'] ?? 0);
        $biaya = floatval($_POST['biaya_estimasi'] ?? 0);
        $jam = intval($_POST['estimasi_jam_kerja'] ?? 0);
        $code  = $db->query("SELECT request_code FROM cleanup_requests WHERE id=$id")->fetchColumn();
        
        $db->prepare("UPDATE cleanup_requests SET biaya_estimasi=?, estimasi_jam_kerja=?, status='dikonfirmasi' WHERE id=? AND status='menunggu'")
           ->execute([$biaya, $jam, $id]);
        triggerWhatsAppOnStatusChange($db, $id, 'dikonfirmasi', 'cleanup');
           
        logActivity($db, 1, "konfirmasi_cleanup $code (Biaya: $biaya)", 'cleanup_requests', $id);
        flash('success', "Layanan $code dikonfirmasi dengan biaya Rp" . number_format($biaya, 0, ',', '.'));
        header('Location: cleanup_management.php');
        exit;
    }

    // ── ASSIGN PETUGAS ──
    if ($action === 'assign_officer') {
        $rid  = (int)($_POST['request_id'] ?? 0);
        $oid  = (int)($_POST['officer_id'] ?? 0);
        $tgl  = $_POST['tanggal_tugas'] ?: null;
        $est  = (int)($_POST['estimasi_jam_kerja'] ?? 1);
        $catTugas = trim($_POST['catatan_tugas'] ?? '');
        $biaya = $est * 50000;
        
        if ($rid && $oid) {
            // Batas maksimal 30 titik per hari per petugas
            $targetTgl = $tgl;
            if ($targetTgl) {
                $stmtCount = $db->prepare("
                    SELECT 
                        (SELECT COUNT(*) FROM pickup_requests WHERE officer_id = ? AND tanggal_jemput = ? AND status NOT IN ('dibatalkan')) +
                        (SELECT COUNT(*) FROM cleanup_requests WHERE officer_id = ? AND tanggal_tugas = ? AND status NOT IN ('dibatalkan') AND id != ?)
                ");
                $stmtCount->execute([$oid, $targetTgl, $oid, $targetTgl, $rid]);
                $currentCount = (int)$stmtCount->fetchColumn();
                if ($currentCount >= 30) {
                    flash('danger', "Batas maksimal 30 titik per hari per petugas terlampaui untuk petugas tersebut pada tanggal $targetTgl.");
                    header('Location: cleanup_management.php');
                    exit;
                }
            }

            $db->prepare("UPDATE cleanup_requests SET officer_id=?, tanggal_tugas=?, jam_mulai=NULL, estimasi_jam_kerja=?, biaya_estimasi=?, catatan_officer=?, status='dijadwalkan' WHERE id=?")
               ->execute([$oid, $tgl, $est, $biaya, $catTugas, $rid]);
            triggerWhatsAppOnStatusChange($db, $rid, 'dijadwalkan', 'cleanup');
            
            $code = $db->query("SELECT request_code FROM cleanup_requests WHERE id=$rid")->fetchColumn();
            logActivity($db, 1, "assign_cleanup_officer $code", 'cleanup_requests', $rid);
            flash('success', "Petugas telah ditugaskan untuk $code.");
        }
        header('Location: cleanup_management.php');
        exit;
    }

    // ── HAPUS ──
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM cleanup_requests WHERE id=?")->execute([$id]);
        $db->prepare("DELETE FROM weighing_records WHERE cleanup_request_id=?")->execute([$id]);
        flash('success', "Data berhasil dihapus.");
        header('Location: cleanup_management.php');
        exit;
    }
    // ── EDIT ──
    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $kec = trim($_POST['kecamatan'] ?? '');
        $al  = trim($_POST['alamat_jemput'] ?? '');
        $waste = trim($_POST['dominant_waste'] ?? '');
        $cat = trim($_POST['catatan'] ?? '');
        $catOfficer = trim($_POST['catatan_officer'] ?? '');
        $st = trim($_POST['status'] ?? '');
        
        $db->prepare("UPDATE cleanup_requests SET kecamatan=?, alamat_jemput=?, dominant_waste=?, catatan=?, catatan_officer=?, status=? WHERE id=?")
           ->execute([$kec, $al, $waste, $cat, $catOfficer, $st, $id]);
        triggerWhatsAppOnStatusChange($db, $id, $st, 'cleanup');
        flash('success', "Data request berhasil diperbarui.");
        header('Location: cleanup_management.php');
        exit;
    }
}

// ── FETCH EDIT / PREVIEW DATA ─────────────────────────
$editData = null;
if (!empty($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $editData = $db->query("SELECT * FROM cleanup_requests WHERE id=$eid")->fetch();
}

$previewData = null;
$previewItems = [];
if (!empty($_GET['preview'])) {
    $pid = (int)$_GET['preview'];
    $previewData = $db->query("
        SELECT r.*, o.nama AS officer_nama, u.email, u.nomor_wa as user_wa
        FROM cleanup_requests r 
        LEFT JOIN officers o ON o.id = r.officer_id 
        LEFT JOIN users u ON u.id = r.user_id
        WHERE r.id=$pid
    ")->fetch();
    if ($previewData) {
        $previewItems = $db->query("SELECT * FROM cleanup_items WHERE cleanup_id=$pid")->fetchAll();
    }
}

// ── FILTER & SEARCH ─────────────────────────────────────────
$where   = '1=1';
$params  = [];
$search  = trim($_GET['q']        ?? '');
$fStatus = $_GET['status']        ?? '';
$fKec    = $_GET['kecamatan']     ?? '';

if ($search !== '') {
    $where   .= " AND (r.nama_pemohon LIKE ? OR r.request_code LIKE ? OR r.kecamatan LIKE ? OR r.nomor_wa LIKE ? OR o.nama LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($fStatus !== '') {
    $where   .= " AND r.status = ?";
    $params[] = $fStatus;
}
if ($fKec !== '') {
    $where   .= " AND r.kecamatan = ?";
    $params[] = $fKec;
}

// ── QUERY DATA ──────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT r.*, o.nama AS officer_nama
    FROM cleanup_requests r
    LEFT JOIN officers o ON o.id = r.officer_id
    WHERE $where
    ORDER BY r.created_at DESC
");
$stmt->execute($params);
$requests = $stmt->fetchAll();

$statuses = ['menunggu', 'dikonfirmasi', 'dijadwalkan', 'dalam_perjalanan', 'sedang_diproses', 'sedang_cleanup', 'selesai', 'dibatalkan'];
$kecamatans = $db->query("SELECT DISTINCT kecamatan FROM cleanup_requests WHERE kecamatan IS NOT NULL AND kecamatan != '' ORDER BY kecamatan")->fetchAll(PDO::FETCH_COLUMN);

$officers = $db->query("SELECT id, nama FROM officers WHERE status='aktif' ORDER BY nama")->fetchAll();

require_once __DIR__ . '/layout/header.php';
?>

<div class="page-header">
    <h1>🧹 Manajemen Clean Up</h1>
</div>

<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap">
  <a href="dashboard.php"        class="btn btn-outline btn-sm">🖥️ Dashboard</a>
  <a href="officer_management.php" class="btn btn-outline btn-sm">👷 Kelola Petugas</a>
  <a href="rute_jadwal.php?tipe=cleanup"  class="btn btn-primary btn-sm">🗺️ Peta Rute Clean Up</a>
</div>

<div class="stats-grid mb-24">
    <div class="stat-card">
        <div class="stat-label">Total Request</div>
        <div class="stat-value"><?= count($requests) ?></div>
        <div class="stat-sub">layanan clean up</div>
    </div>
    <div class="stat-card amber">
        <div class="stat-label">Menunggu</div>
        <div class="stat-value"><?= count(array_filter($requests, fn($r) => $r['status'] === 'menunggu')) ?></div>
        <div class="stat-sub">butuh konfirmasi biaya</div>
    </div>
    <div class="stat-card blue">
        <div class="stat-label">Dijadwalkan</div>
        <div class="stat-value"><?= count(array_filter($requests, fn($r) => $r['status'] === 'dijadwalkan')) ?></div>
        <div class="stat-sub">petugas ditetapkan</div>
    </div>
    <div class="stat-card green">
        <div class="stat-label">Selesai</div>
        <div class="stat-value"><?= count(array_filter($requests, fn($r) => $r['status'] === 'selesai')) ?></div>
        <div class="stat-sub">tugas telah rampung</div>
    </div>
</div>

<div class="card">

    <!-- Toolbar Pencarian & Filter -->
    <div class="toolbar" style="margin-bottom:16px">
        <div class="toolbar-left">
            <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input class="search-input" name="q" type="text"
                       placeholder="🔍 Cari nama / ID / WA / kecamatan..."
                       value="<?= htmlspecialchars($search) ?>">
                <select class="filter-select" name="status" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    <?php foreach ($statuses as $s): ?>
                    <option value="<?= $s ?>" <?= $fStatus===$s?'selected':'' ?>>
                        <?= ucfirst(str_replace('_',' ',$s)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <select class="filter-select" name="kecamatan" onchange="this.form.submit()">
                    <option value="">Semua Kecamatan</option>
                    <?php foreach ($kecamatans as $k): ?>
                    <option value="<?= htmlspecialchars($k) ?>" <?= $fKec===$k?'selected':'' ?>><?= htmlspecialchars($k) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline">Cari</button>
                <?php if ($search || $fStatus || $fKec): ?>
                    <a href="cleanup_management.php" class="btn btn-outline">✕ Reset</a>
                <?php endif; ?>
            </form>
        </div>
        <div class="toolbar-right" style="display:flex;gap:8px">
            <button class="btn btn-outline" onclick="location.reload()" title="Refresh">🔄 Refresh</button>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID / Pemohon</th>
                    <th>Lokasi / Area</th>
                    <th>Detail Layanan</th>
                    <th>Biaya</th>
                    <th>Petugas / Jadwal</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8">Belum ada permintaan Clean Up.</td></tr>
                <?php endif; foreach ($requests as $r): ?>
                <tr>
                    <td>
                        <div style="font-weight:800;color:#2e7d32"><?= $r['request_code'] ?></div>
                        <div style="font-size:11px;font-weight:600"><?= htmlspecialchars($r['nama_pemohon']) ?></div>
                        <?php 
                        $waClean = preg_replace('/[^0-9]/', '', $r['nomor_wa'] ?? '');
                        if (str_starts_with($waClean, '0')) {
                            $waClean = '62' . substr($waClean, 1);
                        } elseif (str_starts_with($waClean, '8')) {
                            $waClean = '62' . $waClean;
                        }
                        $waText = urlencode("Halo " . ($r['nama_pemohon'] ?? '') . ", saya Admin dari " . SITE_NAME . ". Kami ingin mengonfirmasi request Clean Up Anda dengan kode " . ($r['request_code'] ?? '') . ".");
                        $waLink = "https://wa.me/" . $waClean . "?text=" . $waText;
                        ?>
                        <div style="font-size:10px;color:#94a3b8;display:flex;align-items:center;gap:4px">
                            WA: <?= htmlspecialchars($r['nomor_wa']) ?> 
                            <a href="<?= $waLink ?>" target="_blank" style="color:#16a34a;font-weight:bold;text-decoration:none" title="Chat WhatsApp">💬 WA</a>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight:700"><?= htmlspecialchars($r['kecamatan']) ?></div>
                    </td>
                    <td>
                        <div style="font-size:11px;font-weight:700;color:#1c6434">
                            <?= $cleanup_types[$r['service_type']]['icon'] ?? '🧹' ?> 
                            <?= $cleanup_types[$r['service_type']]['label'] ?? ucfirst($r['service_type']) ?>
                        </div>
                        <div style="font-size:10px;color:#94a3b8">Sampah: <?= htmlspecialchars($r['dominant_waste'] ?: '-') ?></div>
                    </td>
                    <td>
                        <div style="font-weight:700;color:#b45309">
                            <?php if ($r['status'] === 'selesai' && $r['biaya_aktual']): ?>
                                <span style="color:#166534">Aktual: Rp<?= number_format($r['biaya_aktual'], 0, ',', '.') ?></span>
                                <div style="font-size:10px;color:#64748b;font-weight:500;margin-top:2px">⏱️ <?= htmlspecialchars($r['jam_kerja_aktual'] ?? 0) ?> Jam</div>
                            <?php elseif ($r['biaya_estimasi']): ?>
                                <span title="Estimasi">Rp<?= number_format($r['biaya_estimasi'], 0, ',', '.') ?></span>
                            <?php else: ?>
                                <span style="color:#cbd5e1">Belum Set</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($r['officer_id']): ?>
                            <div style="font-weight:700;color:#1e293b"><?= htmlspecialchars($r['officer_nama']) ?></div>
                            <div style="font-size:11px;color:#64748b">📅 <?= fmtDate($r['tanggal_tugas']) ?></div>
                        <?php else: ?>
                            <span style="color:#cbd5e1">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= statusBadge($r['status']) ?></td>
                    <td>
                        <div style="display:flex;gap:8px">
                            <?php if ($r['status'] === 'menunggu'): ?>
                                <button class="btn-icon" title="Konfirmasi & Set Biaya" style="background:#fff7ed;color:#9a3412;border-color:#ffedd5"
                                        onclick="openConfirmModal(<?= $r['id'] ?>, '<?= $r['request_code'] ?>', <?= $r['estimasi_jam_kerja'] ?: 1 ?>)">💰</button>
                            <?php endif; ?>
                            
                            <?php if (in_array($r['status'], ['menunggu', 'dikonfirmasi'])): ?>
                                <button class="btn-icon" title="Assign Petugas" style="background:#f0fdf4;color:#166534;border-color:#bbf7d0"
                                        onclick="openAssignModal(<?= $r['id'] ?>, '<?= $r['request_code'] ?>', <?= $r['estimasi_jam_kerja'] ?: 1 ?>, '<?= addslashes($r['catatan_officer'] ?? '') ?>')">👷</button>
                            <?php endif; ?>
                            
                            <a class="btn-icon" title="Preview" href="cleanup_management.php?preview=<?= $r['id'] ?>">👁️</a>
                            <?php if (!in_array($r['status'], ['selesai', 'dibatalkan'])): ?>
                            <a class="btn-icon" title="Edit" href="cleanup_management.php?edit=<?= $r['id'] ?>">✏️</a>
                            <?php endif; ?>
                            <button class="btn-icon btn-danger" onclick="openDeleteModal(<?= $r['id'] ?>, '<?= $r['request_code'] ?>')">🗑️</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Konfirmasi & Biaya -->
<div class="modal-overlay" id="modalConfirm">
    <div class="modal" style="max-width:400px">
        <div class="modal-header">
            <h3>💰 Konfirmasi & Set Biaya</h3>
            <button class="modal-close" onclick="closeModal('modalConfirm')">✕</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="confirm">
                <input type="hidden" name="id" id="confirmId">
                <?= csrfInput() ?>
                <div class="form-group">
                    <label class="form-label">Order ID</label>
                    <input type="text" id="confirmCode" class="form-input" readonly style="background:#f8fafc">
                </div>
                <div class="form-group" style="margin-top:12px">
                    <label class="form-label">Estimasi Jam Kerja *</label>
                    <input type="number" name="estimasi_jam_kerja" id="confirmJam" class="form-input" required placeholder="Misal: 2" oninput="calcConfirmBiaya()">
                </div>
                <div class="form-group" style="margin-top:12px">
                    <label class="form-label">Estimasi Biaya (Rp) *</label>
                    <input type="number" name="biaya_estimasi" id="confirmBiaya" class="form-input" required readonly style="background:#f8fafc">
                    <p style="font-size:10px;color:#64748b;margin-top:4px">Otomatis dihitung: Rp 50.000 / Jam Kerja.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalConfirm')">Batal</button>
                <button type="submit" class="btn btn-primary">Konfirmasi Layanan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Assign Petugas -->
<div class="modal-overlay" id="modalAssign">
    <div class="modal" style="max-width:480px">
        <div class="modal-header">
            <h3>👷 Penugasan Tim Clean Up</h3>
            <button class="modal-close" onclick="closeModal('modalAssign')">✕</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="assign_officer">
                <input type="hidden" name="request_id" id="assignId">
                <?= csrfInput() ?>
                <div class="form-group">
                    <label class="form-label">Pilih Petugas *</label>
                    <select name="officer_id" class="form-input" required>
                        <option value="">— Pilih Petugas —</option>
                        <?php foreach ($officers as $o): ?>
                        <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['nama']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-top:12px">
                    <label class="form-label">Tanggal Tugas</label>
                    <input type="date" name="tanggal_tugas" class="form-input" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group" style="margin-top:12px">
                    <label class="form-label">Estimasi Jam Kerja</label>
                    <input type="number" name="estimasi_jam_kerja" id="assignJam" class="form-input" value="2" oninput="calcAssignBiaya()">
                </div>
                <div class="form-group" style="margin-top:12px">
                    <label class="form-label">Biaya Terhitung (Rp)</label>
                    <input type="text" id="assignBiayaText" class="form-input" value="100.000" readonly style="background:#f8fafc; font-weight:bold; color:var(--gd)">
                </div>
                <div class="form-group" style="margin-top:12px">
                    <label class="form-label">Catatan Tugas</label>
                    <textarea name="catatan_tugas" class="form-input" placeholder="Instruksi khusus untuk petugas..." style="height:60px"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalAssign')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Jadwal</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Hapus -->
<div class="modal-overlay" id="modalDelete">
    <div class="modal" style="max-width:400px">
        <div class="modal-header">
            <h3>🗑️ Hapus Data</h3>
            <button class="modal-close" onclick="closeModal('modalDelete')">✕</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteId">
                <?= csrfInput() ?>
                <p>Apakah Anda yakin ingin menghapus request <strong id="deleteCode"></strong>?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalDelete')">Batal</button>
                <button type="submit" class="btn btn-danger">Ya, Hapus</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit Request -->
<div class="modal-overlay" id="modalEdit" <?= $editData ? 'style="display:flex"' : '' ?>>
    <div class="modal" style="max-width:600px">
        <div class="modal-header">
            <h3>✏️ Edit Clean Up Request</h3>
            <a href="cleanup_management.php" class="modal-close">✕</a>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="<?= $editData['id'] ?? '' ?>">
                <?= csrfInput() ?>
                
                <div class="form-group">
                    <label class="form-label">Kecamatan</label>
                    <input type="text" name="kecamatan" class="form-input" value="<?= htmlspecialchars($editData['kecamatan'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Alamat Lengkap</label>
                    <input type="text" name="alamat_jemput" class="form-input" value="<?= htmlspecialchars($editData['alamat_jemput'] ?? '') ?>">
                </div>

                <div style="display:flex;gap:12px">
                    <div class="form-group" style="flex:1">
                        <label class="form-label">Sampah Dominan</label>
                        <input type="text" name="dominant_waste" class="form-input" value="<?= htmlspecialchars($editData['dominant_waste'] ?? '') ?>">
                    </div>
                    <div class="form-group" style="flex:1">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-input">
                            <?php 
                            $st_opts = ['menunggu','dikonfirmasi','dijadwalkan','dalam_perjalanan','sedang_diproses','sedang_cleanup','selesai','dibatalkan'];
                            foreach($st_opts as $s): 
                                $sel = ($editData['status'] ?? '') === $s ? 'selected' : '';
                            ?>
                            <option value="<?= $s ?>" <?= $sel ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Catatan</label>
                    <textarea name="catatan" class="form-input" style="height:60px"><?= htmlspecialchars($editData['catatan'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Catatan Petugas / Tugas</label>
                    <textarea name="catatan_officer" class="form-input" style="height:60px"><?= htmlspecialchars($editData['catatan_officer'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <a href="cleanup_management.php" class="btn btn-outline">Batal</a>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Preview Request -->
<?php if ($previewData): ?>
<style>
.preview-card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; background: #f8fafc; margin-bottom: 16px; }
.preview-title { font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 12px; letter-spacing: 0.5px; }
.pv-row { display: flex; font-size: 13px; margin-bottom: 8px; border-bottom: 1px dashed #e2e8f0; padding-bottom: 6px; }
.pv-row:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
.pv-lbl { min-width: 130px; font-weight: 700; color: #64748b; }
.pv-val { color: #1e293b; font-weight: 600; flex: 1; }

/* Dokumentasi Grid & Card Styles */
.doc-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-top: 8px;
}
.doc-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    transition: all 0.2s ease;
}
.doc-card:hover {
    border-color: var(--green-200);
    box-shadow: 0 4px 12px rgba(28, 100, 52, 0.06);
}
.doc-header {
    padding: 6px 10px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    font-size: 10px;
    font-weight: 800;
    color: #475569;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.doc-badge {
    font-size: 8px;
    padding: 1px 4px;
    border-radius: 4px;
    font-weight: 800;
    text-transform: uppercase;
}
.doc-badge.warga { background: #eff6ff; color: #1d4ed8; }
.doc-badge.petugas { background: #f0fdf4; color: #166534; }

.doc-img-container {
    position: relative;
    width: 100%;
    aspect-ratio: 4/3;
    background: #f1f5f9;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
}
.doc-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
    cursor: pointer;
}
.doc-img-container:hover .doc-img {
    transform: scale(1.06);
}
.doc-overlay {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.4);
    opacity: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 16px;
    transition: opacity 0.2s ease;
    pointer-events: none;
}
.doc-img-container:hover .doc-overlay {
    opacity: 1;
}
.doc-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 100%;
    color: #94a3b8;
    text-align: center;
    background: #f8fafc;
    aspect-ratio: 4/3;
    padding: 12px;
}
.doc-placeholder-icon {
    font-size: 20px;
    margin-bottom: 4px;
    opacity: 0.6;
}
.doc-placeholder-text {
    font-size: 10px;
    font-weight: 700;
}
.grid-span-2 { grid-column: span 2; }
@media (max-width: 768px) {
    .modal-body.grid-2 {
        grid-template-columns: 1fr !important;
    }
    .grid-span-2 {
        grid-column: span 1 !important;
    }
    .doc-grid {
        grid-template-columns: 1fr !important;
    }
    .pv-row {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 4px !important;
    }
    .pv-lbl {
        min-width: auto !important;
    }
    .modal { max-height: 95vh; margin: 10px; width: calc(100% - 20px); }
    .modal-body { padding: 16px; }
    .modal-footer { padding: 12px 16px; }
    .modal-header { padding: 14px 16px; }
}
</style>
<div class="modal-overlay" style="display:flex">
    <div class="modal" style="max-width:1100px; width:95%">
        <div class="modal-header">
            <h3>👁️ Detail Request: <?= htmlspecialchars($previewData['request_code']) ?></h3>
            <a href="cleanup_management.php" class="modal-close">✕</a>
        </div>
        <div class="modal-body grid-2" style="gap: 16px;">
            
            <div>
                <div class="preview-card">
                    <div class="preview-title">Informasi Pemohon</div>
                    <div class="pv-row"><span class="pv-lbl">Nama Pemohon</span><span class="pv-val"><?= htmlspecialchars($previewData['nama_pemohon']) ?></span></div>
                    <div class="pv-row"><span class="pv-lbl">WhatsApp</span><span class="pv-val"><?= htmlspecialchars($previewData['nomor_wa']) ?></span></div>
                    <div class="pv-row"><span class="pv-lbl">Layanan</span><span class="pv-val"><?= ucfirst($previewData['service_type']) ?></span></div>
                </div>

                <div class="preview-card">
                    <div class="preview-title">Lokasi & Pekerjaan</div>
                    <div class="pv-row"><span class="pv-lbl">Kecamatan</span><span class="pv-val"><?= htmlspecialchars($previewData['kecamatan']) ?></span></div>
                    <div class="pv-row"><span class="pv-lbl">Alamat Lengkap</span><span class="pv-val"><?= htmlspecialchars($previewData['alamat_jemput']) ?></span></div>

                    <div class="pv-row"><span class="pv-lbl">Sampah Dominan</span><span class="pv-val"><?= htmlspecialchars($previewData['dominant_waste'] ?: '-') ?></span></div>
                    <div class="pv-row"><span class="pv-lbl">Catatan Pemohon</span><span class="pv-val"><?= htmlspecialchars($previewData['catatan'] ?: '-') ?></span></div>
                    <div class="pv-row"><span class="pv-lbl">Catatan Petugas</span><span class="pv-val"><?= htmlspecialchars($previewData['catatan_officer'] ?: '-') ?></span></div>
                </div>
            </div>

            <div>
                <div class="preview-card">
                    <div class="preview-title">Status & Keuangan</div>
                    <div class="pv-row"><span class="pv-lbl">Status</span><span class="pv-val"><?= statusBadge($previewData['status']) ?></span></div>
                    <div class="pv-row"><span class="pv-lbl">Petugas</span><span class="pv-val"><?= htmlspecialchars($previewData['officer_nama'] ?: '—') ?></span></div>
                    <div class="pv-row"><span class="pv-lbl">Est. Jam / Biaya</span><span class="pv-val"><?= $previewData['estimasi_jam_kerja'] ?: 0 ?> Jam / Rp<?= number_format((float)$previewData['biaya_estimasi'],0,',','.') ?></span></div>
                    <div class="pv-row"><span class="pv-lbl">Akt. Jam / Biaya</span><span class="pv-val"><?= $previewData['jam_kerja_aktual'] ?: 0 ?> Jam / Rp<?= number_format((float)$previewData['biaya_aktual'],0,',','.') ?></span></div>
                </div>

                <?php if ($previewItems): ?>
                <div class="preview-card">
                    <div class="preview-title">Hasil Pemilahan / Timbangan</div>
                    <?php foreach($previewItems as $it): ?>
                        <div style="background:#fff;padding:8px;border-radius:6px;border:1px solid #e2e8f0;font-size:11px;margin-bottom:4px">
                            <span style="font-weight:800;color:var(--green-700)"><?= $it['berat_kg'] ?> kg</span> — <?= htmlspecialchars($it['catatan']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Dokumentasi Foto Grid Spanned Full Width -->
            <div class="preview-card grid-span-2" style="margin-bottom: 0;">
                <div class="preview-title">📸 Dokumentasi Layanan</div>
                <div class="doc-grid">
                    <!-- Card 1: Foto Lokasi -->
                    <div class="doc-card">
                        <div class="doc-header">
                            <span>📍 Foto Lokasi</span>
                            <span class="doc-badge warga">Warga</span>
                        </div>
                        <div class="doc-img-container">
                            <?php if ($previewData['foto_lokasi']): ?>
                                <img src="../uploads/cleanup/<?= htmlspecialchars($previewData['foto_lokasi']) ?>" class="doc-img" onclick="viewImage(this.src)">
                                <div class="doc-overlay">🔍</div>
                            <?php else: ?>
                                <div class="doc-placeholder">
                                    <span class="doc-placeholder-icon">📷</span>
                                    <span class="doc-placeholder-text">Tidak Dilampirkan</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Card 2: Foto Sebelum -->
                    <div class="doc-card">
                        <div class="doc-header">
                            <span>🧹 Sebelum Clean Up</span>
                            <span class="doc-badge petugas">Petugas</span>
                        </div>
                        <div class="doc-img-container">
                            <?php if ($previewData['foto_sebelum']): ?>
                                <img src="../uploads/cleanup/<?= htmlspecialchars($previewData['foto_sebelum']) ?>" class="doc-img" onclick="viewImage(this.src)">
                                <div class="doc-overlay">🔍</div>
                            <?php else: ?>
                                <div class="doc-placeholder">
                                    <span class="doc-placeholder-icon">📷</span>
                                    <span class="doc-placeholder-text">Belum Tersedia</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Card 3: Foto Sesudah -->
                    <div class="doc-card">
                        <div class="doc-header">
                            <span>✨ Sesudah Clean Up</span>
                            <span class="doc-badge petugas">Petugas</span>
                        </div>
                        <div class="doc-img-container">
                            <?php if ($previewData['foto_sesudah']): ?>
                                <img src="../uploads/cleanup/<?= htmlspecialchars($previewData['foto_sesudah']) ?>" class="doc-img" onclick="viewImage(this.src)">
                                <div class="doc-overlay">🔍</div>
                            <?php else: ?>
                                <div class="doc-placeholder">
                                    <span class="doc-placeholder-icon">📷</span>
                                    <span class="doc-placeholder-text">Belum Tersedia</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <div class="modal-footer">
            <a href="cleanup_management.php" class="btn btn-outline">Tutup</a>
        </div>
    </div>
</div>

<!-- Lightbox Modal -->
<div class="modal-overlay" id="modalLightbox" onclick="closeLightbox()" style="z-index:1100; background:rgba(15,23,42,0.8); backdrop-filter:blur(4px);">
    <div style="position:relative; max-width:90%; max-height:90vh; display:flex; align-items:center; justify-content:center;" onclick="event.stopPropagation()">
        <img id="lightboxImg" src="" style="max-width:100%; max-height:85vh; border-radius:12px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.5); border:4px solid #fff; object-fit:contain;">
        <button class="modal-close" onclick="closeLightbox()" style="position:absolute; top:-40px; right:0; color:#fff; font-size:30px; background:none; border:none; cursor:pointer;">✕</button>
    </div>
</div>

<script>
function viewImage(src) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('modalLightbox').style.display = 'flex';
}
function closeLightbox() {
    document.getElementById('modalLightbox').style.display = 'none';
}
</script>
<?php endif; ?>

<script>
function openModal(id){
    document.getElementById(id).style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeModal(id){
    document.getElementById(id).style.display = 'none';
    document.body.style.overflow = '';
}
function openConfirmModal(id, code, jam) {
    document.getElementById('confirmId').value = id;
    document.getElementById('confirmCode').value = code;
    document.getElementById('confirmJam').value = jam || 1;
    calcConfirmBiaya();
    openModal('modalConfirm');
}
function openAssignModal(id, code, jam, catatanOfficer) {
    document.getElementById('assignId').value = id;
    document.getElementById('assignJam').value = jam || 1;
    calcAssignBiaya();
    const catEl = document.querySelector('#modalAssign textarea[name="catatan_tugas"]');
    if (catEl) catEl.value = catatanOfficer || '';
    openModal('modalAssign');
}
function openDeleteModal(id, code) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteCode').textContent = code;
    openModal('modalDelete');
}
function calcConfirmBiaya() {
    let jam = parseFloat(document.getElementById('confirmJam').value) || 0;
    document.getElementById('confirmBiaya').value = jam * 50000;
}
function calcAssignBiaya() {
    let jam = parseFloat(document.getElementById('assignJam').value) || 0;
    let biaya = jam * 50000;
    document.getElementById('assignBiayaText').value = new Intl.NumberFormat('id-ID').format(biaya);
}
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
