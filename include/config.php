<?php
// Set default timezone ke WITA (Waktu Indonesia Tengah) untuk Manado
date_default_timezone_set('Asia/Makassar');

// Mulai session secara global
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function sanitizeRichText(string $html): string {
    $allowedTags = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'a', 'ul', 'ol', 'li', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img'];

    if (trim($html) === '') {
        return '';
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $container = $doc->getElementsByTagName('div')->item(0);
    if (!$container) {
        return htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
    }

    $renderNode = function(DOMNode $node) use (&$renderNode, $allowedTags): string {
        if ($node->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES, 'UTF-8');
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $tag = strtolower($node->nodeName);
        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= $renderNode($child);
        }

        if (!in_array($tag, $allowedTags, true)) {
            return $inner;
        }

        $attrs = [];
        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            if ($href !== '' && preg_match('~^(https?://|/|#|mailto:|tel:)~i', $href)) {
                $attrs[] = 'href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"';
            }
            if (trim($node->getAttribute('target')) === '_blank') {
                $attrs[] = 'target="_blank"';
                $attrs[] = 'rel="noopener noreferrer"';
            }
        } elseif ($tag === 'img') {
            $src = trim($node->getAttribute('src'));
            if ($src !== '' && preg_match('~^(https?://|/|data:image/)~i', $src)) {
                $attrs[] = 'src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"';
            }
            $alt = trim($node->getAttribute('alt'));
            if ($alt !== '') {
                $attrs[] = 'alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '"';
            }
        }

        $attrStr = $attrs ? ' ' . implode(' ', $attrs) : '';
        if ($tag === 'br' || $tag === 'img') {
            return '<' . $tag . $attrStr . '>';
        }

        return '<' . $tag . $attrStr . '>' . $inner . '</' . $tag . '>';
    };

    $out = '';
    foreach ($container->childNodes as $child) {
        $out .= $renderNode($child);
    }

    return $out;
}

// ============================================================
//  include/config.php — Konfigurasi Global Manado Recycle Hub
//  Digunakan oleh: User Console, Admin Console, Officer Console
//  Letakkan file ini di: skripsi/include/config.php
// ============================================================

// ── Deteksi root path secara otomatis ────────────────────────
// __DIR__ selalu menunjuk ke folder include/
// PROJECT_ROOT = folder induk (skripsi/)
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', dirname(__DIR__));
}

// ── Konfigurasi Database ─────────────────────────────────────
define('DB_HOST', getenv('MRH_DB_HOST') ?: 'localhost');
define('DB_USER', getenv('MRH_DB_USER') ?: 'root');
define('DB_PASS', getenv('MRH_DB_PASS') ?: '');
define('DB_NAME', getenv('MRH_DB_NAME') ?: 'hub');
define('DB_PORT', (int)(getenv('MRH_DB_PORT') ?: 3306));

// ── Konfigurasi SMTP untuk Notifikasi Email (Cara 2) ──────────
define('SMTP_HOST', getenv('MRH_SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', (int)(getenv('MRH_SMTP_PORT') ?: 587)); // Gunakan 587 untuk TLS atau 465 untuk SSL
define('SMTP_USER', 'mdorecyclehub@gmail.com'); 
define('SMTP_PASS', 'zezz hjxb uiyt pehv'); 
define('SMTP_FROM', 'mdorecyclehub@gmail.com');

// ── Koneksi PDO (singleton) ───────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT
             . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        // Set MySQL timezone to match WITA (+08:00)
        $pdo->exec("SET time_zone = '+08:00'");
        // Auto-migration: Tambahkan kolom is_kendala jika belum ada
        try {
            $pdo->exec("ALTER TABLE pickup_requests ADD COLUMN is_kendala TINYINT(1) DEFAULT 0 AFTER catatan_officer");
        } catch (Exception $e) {}
        // Auto-migration: Tambahkan status 'dalam_perjalanan' ke enum status pickup_requests jika belum ada
        try {
            $pdo->exec("ALTER TABLE pickup_requests MODIFY COLUMN status ENUM('menunggu','dikonfirmasi','dijadwalkan','dalam_perjalanan','sedang_diproses','selesai','dibatalkan') NOT NULL DEFAULT 'menunggu'");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE pickup_requests ADD COLUMN latitude DECIMAL(10, 8) NULL AFTER kelurahan");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE pickup_requests ADD COLUMN longitude DECIMAL(11, 8) NULL AFTER latitude");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE cleanup_requests ADD COLUMN latitude DECIMAL(10, 8) NULL AFTER kelurahan");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE cleanup_requests ADD COLUMN longitude DECIMAL(11, 8) NULL AFTER latitude");
        } catch (Exception $e) {}
        // Auto-migration: Tambahkan kolom GPS untuk tracking officers
        try {
            $pdo->exec("ALTER TABLE officers ADD COLUMN last_lat DECIMAL(10,8) NULL AFTER nomor_wa");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE officers ADD COLUMN last_lng DECIMAL(11,8) NULL AFTER last_lat");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE officers ADD COLUMN last_seen_at DATETIME NULL AFTER last_lng");
        } catch (Exception $e) {}
        // Auto-migration: Create kecamatan table
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `kecamatan` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `nama_kecamatan` VARCHAR(100) NOT NULL,
              `aktif` TINYINT(1) DEFAULT 1,
              UNIQUE KEY `uq_nama_kecamatan` (`nama_kecamatan`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
        } catch (Exception $e) {}
        // Auto-migration: Create schedules table
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `schedules` (
              `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `tanggal` DATE NOT NULL,
              `kecamatan_id` INT DEFAULT NULL,
              `kecamatan` VARCHAR(100) DEFAULT NULL,
              `officer_id` BIGINT UNSIGNED DEFAULT NULL,
              `status` ENUM('draft', 'confirmed', 'completed', 'cancelled') NOT NULL DEFAULT 'draft',
              `tipe_layanan` VARCHAR(20) DEFAULT 'pickup',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (`kecamatan_id`) REFERENCES `kecamatan`(`id`) ON DELETE SET NULL,
              FOREIGN KEY (`officer_id`) REFERENCES `officers`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
        } catch (Exception $e) {}
        // Auto-migration: Create routes table
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `routes` (
              `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `schedule_id` BIGINT UNSIGNED NOT NULL,
              `urutan` INT NOT NULL,
              `pickup_request_id` BIGINT UNSIGNED DEFAULT NULL,
              `cleanup_request_id` BIGINT UNSIGNED DEFAULT NULL,
              `dist_from_prev_km` DECIMAL(10, 4) DEFAULT 0.0000,
              FOREIGN KEY (`schedule_id`) REFERENCES `schedules`(`id`) ON DELETE CASCADE,
              FOREIGN KEY (`pickup_request_id`) REFERENCES `pickup_requests`(`id`) ON DELETE SET NULL,
              FOREIGN KEY (`cleanup_request_id`) REFERENCES `cleanup_requests`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
        } catch (Exception $e) {}
        // Auto-migration for weighing_records
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `weighing_records` (
              `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
              `pickup_request_id` bigint(20) UNSIGNED DEFAULT NULL,
              `cleanup_request_id` bigint(20) UNSIGNED DEFAULT NULL,
              `nama_entitas` varchar(150) NOT NULL,
              `berat_kg` decimal(10,2) NOT NULL,
              `jenis_sampah` varchar(150) NOT NULL,
              `tanggal_timbang` date NOT NULL,
              `officer_id` bigint(20) UNSIGNED DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_weigh_pickup` (`pickup_request_id`),
              KEY `idx_weigh_cleanup` (`cleanup_request_id`),
              KEY `idx_weigh_officer` (`officer_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
        } catch (Exception $e) {}

        // Auto-migration: tabel penghubung jadwal -> request
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `schedule_requests` (
              `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
              `schedule_id` bigint(20) UNSIGNED NOT NULL,
              `request_id` bigint(20) UNSIGNED DEFAULT NULL,
              `cleanup_request_id` bigint(20) UNSIGNED DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_sr_schedule` (`schedule_id`),
              KEY `idx_sr_request` (`request_id`),
              KEY `idx_sr_cleanup` (`cleanup_request_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
            try {
                $pdo->exec("ALTER TABLE schedule_requests ADD CONSTRAINT fk_sr_schedule FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE schedule_requests ADD CONSTRAINT fk_sr_request FOREIGN KEY (request_id) REFERENCES pickup_requests(id) ON DELETE CASCADE");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE schedule_requests ADD CONSTRAINT fk_sr_cleanup FOREIGN KEY (cleanup_request_id) REFERENCES cleanup_requests(id) ON DELETE CASCADE");
            } catch (Exception $e) {}
        } catch (Exception $e) {}

        // Auto-migration for mitra columns
        $mitraCols = [
            "target_setoran_kg" => "ALTER TABLE `mitra` ADD COLUMN `target_setoran_kg` DECIMAL(10,2) DEFAULT 0.00",
            "target_periode" => "ALTER TABLE `mitra` ADD COLUMN `target_periode` ENUM('mingguan', 'bulanan') DEFAULT 'bulanan'",
            "total_setoran_kg" => "ALTER TABLE `mitra` ADD COLUMN `total_setoran_kg` DECIMAL(10,2) DEFAULT 0.00",
            "target_achieved" => "ALTER TABLE `mitra` ADD COLUMN `target_achieved` TINYINT(1) DEFAULT 0",
            "jumlah_pembayaran" => "ALTER TABLE `mitra` ADD COLUMN `jumlah_pembayaran` DECIMAL(12,2) DEFAULT 0.00",
            "status_pembayaran" => "ALTER TABLE `mitra` ADD COLUMN `status_pembayaran` ENUM('belum_dibayar', 'dibayar') DEFAULT 'belum_dibayar'"
        ];
        foreach ($mitraCols as $col => $sql) {
            try { $pdo->exec($sql); } catch (Exception $e) {}
        }

        // Auto-migration: Create mitra_deposits and mitra_payments
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `mitra_deposits` (
              `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
              `mitra_id` bigint(20) UNSIGNED NOT NULL,
              `berat_kg` decimal(10,2) NOT NULL,
              `status` enum('pending','dibayar') NOT NULL DEFAULT 'pending',
              `payment_id` bigint(20) UNSIGNED DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
        } catch (Exception $e) {}

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `mitra_payments` (
              `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
              `mitra_id` bigint(20) UNSIGNED NOT NULL,
              `total_bayar` decimal(12,2) NOT NULL,
              `status` enum('pending','dibayar') NOT NULL DEFAULT 'pending',
              `paid_at` datetime DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
        } catch (Exception $e) {}

        // Auto-migration: Aktifkan kembali semua kategori sampah daur ulang
        try {
            $pdo->exec("UPDATE waste_categories SET is_active = 1");
        } catch (Exception $e) {}
    }
    return $pdo;
}

// ── Konstanta Tampilan ────────────────────────────────────────
define('SITE_NAME',    'Manado Recycle Hub');
define('ADMIN_NAME',   'Super Admin MRH');
define('ADMIN_AVATAR', 'SA');
define('GREEN_700',    '#1c6434');

// ── URL Helper ────────────────────────────────────────────────
// Hitung base dari PROJECT_ROOT vs DOCUMENT_ROOT — tidak bergantung
// pada REQUEST_URI sehingga aman dipanggil dari subfolder manapun
function baseUrl(string $path = ''): string {
    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $projDir = rtrim(str_replace('\\', '/', PROJECT_ROOT), '/');

    // Base = bagian relatif folder project dari document root
    if ($docRoot !== '' && strpos($projDir, $docRoot) === 0) {
        $base = substr($projDir, strlen($docRoot)); // e.g. "/skripsi"
    } else {
        // Fallback: ambil segmen pertama dari REQUEST_URI
        preg_match('#^(/[^/]+)#', $_SERVER['REQUEST_URI'] ?? '', $m);
        $base = $m[1] ?? '';
    }

    return rtrim($scheme . '://' . $host . $base, '/') . '/' . ltrim($path, '/');
}


// ── Helper: Status Badge ──────────────────────────────────────
function statusBadge(string $s): string {
    $map = [
        'menunggu'        => ['badge-amber',  'Menunggu'],
        'dikonfirmasi'    => ['badge-blue',   'Dikonfirmasi'],
        'dijadwalkan'     => ['badge-purple', 'Dijadwalkan'],
        'dalam_perjalanan'=> ['badge-amber',  'Dalam Perjalanan'],
        'sedang_diproses' => ['badge-orange', 'Sedang Diproses'],
        'selesai'         => ['badge-green',  'Selesai'],
        'dibatalkan'      => ['badge-red',    'Dibatalkan'],
        'aktif'           => ['badge-green',  'Aktif'],
        'cuti'            => ['badge-amber',  'Cuti'],
        'nonaktif'        => ['badge-gray',   'Nonaktif'],
        'baru'            => ['badge-blue',   'Baru'],
        'ditinjau'        => ['badge-amber',  'Ditinjau'],
        'disetujui'       => ['badge-green',  'Disetujui'],
        'ditolak'         => ['badge-red',    'Ditolak'],
        'direalisasi'     => ['badge-purple', 'Direalisasi'],
    ];
    [$cls, $lbl] = $map[$s] ?? ['badge-gray', ucfirst($s)];
    return "<span class=\"badge $cls\">$lbl</span>";
}

// ── Helper: Format Tanggal ────────────────────────────────────
function fmtDate(?string $d, string $fmt = 'd M Y'): string {
    if (!$d) return '-';
    return date($fmt, strtotime($d));
}

if (!function_exists('decToDms')) {
    function decToDms($lat, $lng): string {
        if ($lat === null || $lng === null || $lat == 0 || $lng == 0) return '-';
        
        $getDMS = function($dec, $is_lat) {
            $direction = $is_lat ? ($dec >= 0 ? 'N' : 'S') : ($dec >= 0 ? 'E' : 'W');
            $dec = abs($dec);
            $degrees = floor($dec);
            $minfloat = ($dec - $degrees) * 60;
            $minutes = floor($minfloat);
            $secfloat = ($minfloat - $minutes) * 60;
            $seconds = round($secfloat, 1);
            if ($seconds == 60) {
                $minutes++;
                $seconds = 0;
            }
            if ($minutes == 60) {
                $degrees++;
                $minutes = 0;
            }
            return $degrees . '°' . $minutes . '\'' . $seconds . '"' . $direction;
        };

        return $getDMS($lat, true) . ' ' . $getDMS($lng, false);
    }
}

// ── Helper: Log Aktivitas ─────────────────────────────────────
function logActivity(PDO $db, ?int $userId, string $aksi, string $entitas = '', ?int $entitasId = null, array $dataLama = [], array $dataBaru = []): void {
    try {
        $stmt = $db->prepare("INSERT INTO activity_logs
            (user_id, aksi, entitas, entitas_id, data_lama, data_baru, ip_address, user_agent)
            VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $userId,
            $aksi,
            $entitas ?: null,
            $entitasId,
            $dataLama ? json_encode($dataLama, JSON_UNESCAPED_UNICODE) : null,
            $dataBaru ? json_encode($dataBaru, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Exception $e) {
        error_log('[MRH logActivity] ' . $e->getMessage());
    }
}

// ── Helper: JSON Response (AJAX) ─────────────────────────────
function jsonResponse(bool $success, string $message = '', array $data = []): void {
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Helper: Flash Message (via session) ──────────────────────
function flash(string $type, string $msg): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

// ── Helper: Get Flash Message ────────────────────────────────
function getFlash(): ?array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ── Helper: Sanitasi Input (dipakai User Console) ────────────
function clean(string $str): string {
    return htmlspecialchars(strip_tags(trim($str)), ENT_QUOTES, 'UTF-8');
}

// ── Helper: Generate Kode Unik (dipakai daur_ulang.php) ──────
function generateUniqueCode(PDO $pdo, string $table, string $column, string $prefix, int $length = 8): string {
    do {
        $code   = $prefix . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, $length));
        $exists = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?");
        $exists->execute([$code]);
    } while ((int)$exists->fetchColumn() > 0);
    return $code;
}

// ── Helper: Generate Kode Cerdas (MRH-S-001) ──────────────
function generateSmartCode(PDO $pdo, string $table, string $column, string $prefix): string {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` LIKE ?");
    $stmt->execute([$prefix . '-%']);
    $count = (int)$exists = $stmt->fetchColumn();
    
    $seq = str_pad($count + 1, 3, '0', STR_PAD_LEFT);
    $code = $prefix . '-' . $seq;
    
    // Safety: jika tabrakan, tambahkan random
    $check = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?");
    $check->execute([$code]);
    if ((int)$check->fetchColumn() > 0) {
        $code .= strtoupper(substr(md5(uniqid()), 0, 3));
    }
    return $code;
}

// ── Helper: Google Maps API Key ───────────────────────────────
function getGmapsKey(): string {
    try {
        return getDB()->query("SELECT setting_value FROM site_settings WHERE setting_key='google_maps_api_key'")->fetchColumn() ?: '';
    } catch (Exception $e) {
        return '';
    }
}

// ── Deteksi modul aktif (untuk link antar-konsol) ────────────
// Dipakai oleh layout/header.php
function getModulePaths(): array {
    // Relative dari folder masing-masing modul ke root
    $uri = $_SERVER['PHP_SELF'] ?? '';
    if (str_contains($uri, '/officer/')) {
        $root = '../';
    } elseif (str_contains($uri, '/admin/')) {
        $root = '../';
    } else {
        $root = '';
    }
    return [
        'root'    => $root,
        'user'    => $root,          // home.php, dll di root
        'admin'   => $root . 'admin/',  // jika pakai subfolder admin (opsional)
        'officer' => $root . 'officer/',
    ];
}

// ── Helper: Record Weighing for Pickup Requests ────────────────
function recordWeighing(PDO $db, int $pickupRequestId): void {
    try {
        $stmt = $db->prepare("
            SELECT pr.*, 
                   COALESCE(NULLIF(pr.partner_name,''), pr.nama_pemohon) AS nama_entitas,
                   (SELECT GROUP_CONCAT(wc.name SEPARATOR ', ')
                    FROM pickup_request_items pri 
                    JOIN waste_categories wc ON wc.id = pri.category_id
                    WHERE pri.pickup_id = pr.id) AS jenis_sampah
            FROM pickup_requests pr
            WHERE pr.id = ?
        ");
        $stmt->execute([$pickupRequestId]);
        $pr = $stmt->fetch();
        
        if (!$pr) return;
        
        if ($pr['status'] !== 'selesai') {
            $db->prepare("DELETE FROM weighing_records WHERE pickup_request_id = ?")->execute([$pickupRequestId]);
            return;
        }

        // Auto-distribusi berat_total_kg ke pickup_request_items jika total aktual_kg masih kosong/0
        $berat_total = (float)($pr['berat_total_kg'] ?? 0);
        if ($berat_total > 0) {
            $items = $db->query("SELECT id, estimasi_kg, aktual_kg FROM pickup_request_items WHERE pickup_id = $pickupRequestId")->fetchAll();
            if (!empty($items)) {
                $sum_akt = 0;
                foreach ($items as $item) {
                    $sum_akt += (float)($item['aktual_kg'] ?? 0);
                }
                
                if ($sum_akt == 0) {
                    $num_items = count($items);
                    if ($num_items === 1) {
                        $db->prepare("UPDATE pickup_request_items SET aktual_kg = ? WHERE id = ?")
                           ->execute([$berat_total, $items[0]['id']]);
                    } else {
                        $total_est = 0;
                        foreach ($items as $item) {
                            $total_est += (float)($item['estimasi_kg'] ?? 0);
                        }
                        
                        if ($total_est > 0) {
                            $stmt_update = $db->prepare("UPDATE pickup_request_items SET aktual_kg = ? WHERE id = ?");
                            foreach ($items as $item) {
                                $prop = (float)($item['estimasi_kg'] ?? 0) / $total_est;
                                $akt_calc = round($berat_total * $prop, 2);
                                $stmt_update->execute([$akt_calc, $item['id']]);
                            }
                        } else {
                            $avg_weight = round($berat_total / $num_items, 2);
                            $stmt_update = $db->prepare("UPDATE pickup_request_items SET aktual_kg = ? WHERE id = ?");
                            foreach ($items as $item) {
                                $stmt_update->execute([$avg_weight, $item['id']]);
                            }
                        }
                    }
                }
            }
        }

        // Auto-distribusi catatan_officer ke pickup_request_items jika catatan item masih kosong
        $catatan_officer = trim($pr['catatan_officer'] ?? '');
        if ($catatan_officer !== '') {
            $items = $db->query("SELECT id, catatan FROM pickup_request_items WHERE pickup_id = $pickupRequestId")->fetchAll();
            if (!empty($items)) {
                $all_cat_empty = true;
                foreach ($items as $item) {
                    if (trim($item['catatan'] ?? '') !== '') {
                        $all_cat_empty = false;
                        break;
                    }
                }
                
                if ($all_cat_empty) {
                    $db->prepare("UPDATE pickup_request_items SET catatan = ? WHERE id = ?")
                       ->execute([$catatan_officer, $items[0]['id']]);
                }
            }
        }
        
        $nama_entitas = $pr['nama_entitas'];
        $berat_kg = (float)($pr['berat_total_kg'] ?? 0);
        $jenis_sampah = $pr['jenis_sampah'] ?: 'Botol Plastik';
        $tanggal_timbang = !empty($pr['completed_at']) ? date('Y-m-d', strtotime($pr['completed_at'])) : date('Y-m-d');
        $officer_id = $pr['officer_id'];
        
        $check = $db->prepare("SELECT id FROM weighing_records WHERE pickup_request_id = ?");
        $check->execute([$pickupRequestId]);
        $existing = $check->fetchColumn();
        
        if ($existing) {
            $db->prepare("
                UPDATE weighing_records 
                SET nama_entitas = ?, berat_kg = ?, jenis_sampah = ?, tanggal_timbang = ?, officer_id = ?
                WHERE id = ?
            ")->execute([$nama_entitas, $berat_kg, $jenis_sampah, $tanggal_timbang, $officer_id, $existing]);
        } else {
            $db->prepare("
                INSERT INTO weighing_records (pickup_request_id, nama_entitas, berat_kg, jenis_sampah, tanggal_timbang, officer_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$pickupRequestId, $nama_entitas, $berat_kg, $jenis_sampah, $tanggal_timbang, $officer_id]);
        }
    } catch (Exception $e) {
        error_log('[recordWeighing Error] ' . $e->getMessage());
    }
}

// ── Helper: Record Weighing for Cleanup Requests ───────────────
function recordCleanupWeighing(PDO $db, int $cleanupId): void {
    try {
        $stmt = $db->prepare("
            SELECT cr.*, 
                   cr.nama_pemohon AS nama_entitas,
                   (SELECT GROUP_CONCAT(wc.name SEPARATOR ', ')
                    FROM cleanup_items ci 
                    JOIN waste_categories wc ON wc.id = ci.category_id
                    WHERE ci.cleanup_id = cr.id) AS jenis_sampah
            FROM cleanup_requests cr
            WHERE cr.id = ?
        ");
        $stmt->execute([$cleanupId]);
        $cr = $stmt->fetch();
        
        if (!$cr) return;
        
        if ($cr['status'] !== 'selesai') {
            $db->prepare("DELETE FROM weighing_records WHERE cleanup_request_id = ?")->execute([$cleanupId]);
            return;
        }
        
        $nama_entitas = $cr['nama_entitas'];
        
        $weightStmt = $db->prepare("SELECT SUM(berat_kg) FROM cleanup_items WHERE cleanup_id = ?");
        $weightStmt->execute([$cleanupId]);
        $berat_kg = (float)$weightStmt->fetchColumn();
        
        $jenis_sampah = $cr['jenis_sampah'] ?: 'Botol Plastik';
        $tanggal_timbang = !empty($cr['completed_at']) ? date('Y-m-d', strtotime($cr['completed_at'])) : date('Y-m-d');
        $officer_id = $cr['officer_id'];
        
        $check = $db->prepare("SELECT id FROM weighing_records WHERE cleanup_request_id = ?");
        $check->execute([$cleanupId]);
        $existing = $check->fetchColumn();
        
        if ($existing) {
            $db->prepare("
                UPDATE weighing_records 
                SET nama_entitas = ?, berat_kg = ?, jenis_sampah = ?, tanggal_timbang = ?, officer_id = ?
                WHERE id = ?
            ")->execute([$nama_entitas, $berat_kg, $jenis_sampah, $tanggal_timbang, $officer_id, $existing]);
        } else {
            $db->prepare("
                INSERT INTO weighing_records (cleanup_request_id, nama_entitas, berat_kg, jenis_sampah, tanggal_timbang, officer_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$cleanupId, $nama_entitas, $berat_kg, $jenis_sampah, $tanggal_timbang, $officer_id]);
        }
    } catch (Exception $e) {
        error_log('[recordCleanupWeighing Error] ' . $e->getMessage());
    }
}

// ── Helper: Buat Notifikasi Sistem Real-time ──
function createNotification(PDO $db, $target, string $judul, string $pesan, string $tipe = 'sistem', ?int $referensi_id = null, ?string $referensi_tipe = null): bool {
    try {
        if (is_numeric($target)) {
            // Target is a specific user_id
            $stmt = $db->prepare("INSERT INTO notifications (user_id, judul, pesan, tipe, referensi_id, referensi_tipe, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())");
            return $stmt->execute([$target, $judul, $pesan, $tipe, $referensi_id, $referensi_tipe]);
        } else {
            // Target is a role name like 'admin' or 'officer'
            $stmtRole = $db->prepare("SELECT id FROM roles WHERE name = ? LIMIT 1");
            $stmtRole->execute([$target]);
            $role_id = $stmtRole->fetchColumn();
            if (!$role_id) return false;

            $stmtUsers = $db->prepare("SELECT id FROM users WHERE role_id = ? AND is_active = 1");
            $stmtUsers->execute([$role_id]);
            $users = $stmtUsers->fetchAll(PDO::FETCH_COLUMN);

            if (empty($users)) return false;

            $stmtIns = $db->prepare("INSERT INTO notifications (user_id, judul, pesan, tipe, referensi_id, referensi_tipe, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())");
            foreach ($users as $uid) {
                $stmtIns->execute([$uid, $judul, $pesan, $tipe, $referensi_id, $referensi_tipe]);
            }
            return true;
        }
    } catch (Exception $e) {
        error_log('[createNotification Error] ' . $e->getMessage());
        return false;
    }
}

// ── Helper: Kirim Notifikasi WhatsApp Otomatis via Gateway (Simulasi / API) ──
function sendWhatsAppNotification(string $phone, string $message): bool {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strpos($phone, '0') === 0) {
        $phone = '62' . substr($phone, 1);
    } elseif (strpos($phone, '+') === 0) {
        $phone = substr($phone, 1);
    }
    
    error_log("[MRH WhatsApp Gateway] Mengirim pesan ke $phone: $message");
    
    $token = getenv('MRH_WA_GATEWAY_TOKEN') ?: '';
    if ($token === '') {
        return false;
    }
    $url = "https://api.fonnte.com/send";
    
    $payload = [
        'target' => $phone,
        'message' => $message,
        'countryCode' => '62',
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: $token"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return true;
}

// ── Helper: Trigger WhatsApp Notification On Status Change ──
function triggerWhatsAppOnStatusChange(PDO $db, int $requestId, string $newStatus, string $type = 'daur_ulang'): void {
    try {
        if ($type === 'daur_ulang') {
            $stmt = $db->prepare("
                SELECT pr.request_code, pr.nama_pemohon, pr.nomor_wa, pr.tanggal_jemput,
                       o.nama AS officer_nama
                FROM   pickup_requests pr
                LEFT   JOIN officers o ON o.id = pr.officer_id
                WHERE  pr.id = ?
            ");
            $stmt->execute([$requestId]);
            $row = $stmt->fetch();
            if (!$row) return;
            
            $code = $row['request_code'];
            $nama = $row['nama_pemohon'];
            $wa = $row['nomor_wa'];
            $tgl = $row['tanggal_jemput'] ? date('d-m-Y', strtotime($row['tanggal_jemput'])) : '';
            $officer = $row['officer_nama'] ?: 'Petugas Lapangan';
            
            switch ($newStatus) {
                case 'dikonfirmasi':
                    $msg = "Halo $nama, permintaan daur ulang Anda dengan kode $code telah DIKONFIRMASI oleh Admin. Petugas akan segera dijadwalkan.";
                    break;
                case 'dijadwalkan':
                    $msg = "Halo $nama, penjemputan daur ulang Anda dengan kode $code telah DIJADWALKAN pada tanggal $tgl. Petugas: $officer akan menjemput sampah Anda.";
                    break;
                case 'dalam_perjalanan':
                    $msg = "Halo $nama, $officer sedang DALAM PERJALANAN menuju lokasi Anda untuk menjemput sampah daur ulang $code. Silakan pantau posisinya secara langsung di website Manado Recycle Hub.";
                    break;
                case 'sedang_diproses':
                    $msg = "Halo $nama, sampah daur ulang Anda dengan kode $code sedang DIPROSES oleh $officer di lokasi.";
                    break;
                case 'selesai':
                    $msg = "Halo $nama, penjemputan sampah daur ulang Anda dengan kode $code telah SELESAI dilakukan. Terima kasih telah membantu menjaga kebersihan Kota Manado!";
                    break;
                case 'dibatalkan':
                    $msg = "Halo $nama, permintaan daur ulang Anda dengan kode $code telah DIBATALKAN.";
                    break;
                default:
                    return;
            }
            sendWhatsAppNotification($wa, $msg);
            
        } else {
            $stmt = $db->prepare("
                SELECT cr.request_code, cr.nama_pemohon, cr.nomor_wa, cr.tanggal_tugas, cr.biaya_estimasi,
                       o.nama AS officer_nama
                FROM   cleanup_requests cr
                LEFT   JOIN officers o ON o.id = cr.officer_id
                WHERE  cr.id = ?
            ");
            $stmt->execute([$requestId]);
            $row = $stmt->fetch();
            if (!$row) return;
            
            $code = $row['request_code'];
            $nama = $row['nama_pemohon'];
            $wa = $row['nomor_wa'];
            $tgl = $row['tanggal_tugas'] ? date('d-m-Y', strtotime($row['tanggal_tugas'])) : '';
            $biaya = $row['biaya_estimasi'] ? number_format($row['biaya_estimasi'], 0, ',', '.') : '0';
            $officer = $row['officer_nama'] ?: 'Tim Clean Up';
            
            switch ($newStatus) {
                case 'dikonfirmasi':
                    $msg = "Halo $nama, permintaan Clean Up Service Anda dengan kode $code telah DIKONFIRMASI oleh Admin dengan estimasi biaya Rp$biaya.";
                    break;
                case 'dijadwalkan':
                    $msg = "Halo $nama, layanan Clean Up Service Anda dengan kode $code telah DIJADWALKAN pada tanggal $tgl. Tim Petugas: $officer akan datang ke lokasi Anda.";
                    break;
                case 'dalam_perjalanan':
                    $msg = "Halo $nama, $officer sedang DALAM PERJALANAN menuju lokasi Anda untuk melakukan Clean Up Service $code. Silakan pantau lokasi mereka secara live di website kami.";
                    break;
                case 'sedang_diproses':
                case 'sedang_cleanup':
                    $msg = "Halo $nama, proses Clean Up Service dengan kode $code sedang BERLANGSUNG di lokasi Anda.";
                    break;
                case 'selesai':
                    $msg = "Halo $nama, layanan Clean Up Service dengan kode $code telah SELESAI dikerjakan. Terima kasih telah menggunakan layanan Manado Recycle Hub!";
                    break;
                case 'dibatalkan':
                    $msg = "Halo $nama, layanan Clean Up Service Anda dengan kode $code telah DIBATALKAN.";
                    break;
                default:
                    return;
            }
            sendWhatsAppNotification($wa, $msg);
        }
    } catch (Exception $e) {
        error_log('[triggerWhatsAppOnStatusChange Error] ' . $e->getMessage());
    }
}

/**
 * Mengirim notifikasi email otomatis ke admin/manajemen saat order baru masuk.
 * Mencoba menggunakan mail() native php dan juga menulis log di folder uploads/email_logs.txt agar mudah ditest.
 */
function triggerNewOrderEmail(PDO $db, int $pickupRequestId): void {
    try {
        $stmt = $db->prepare("
            SELECT pr.*, 
                   (SELECT COUNT(*) FROM pickup_request_items pri WHERE pri.pickup_id = pr.id) AS jumlah_titik
            FROM   pickup_requests pr
            WHERE  pr.id = ?
        ");
        $stmt->execute([$pickupRequestId]);
        $order = $stmt->fetch();
        if (!$order) return;
        
        $nama = $order['nama_pemohon'];
        $code = $order['request_code'];
        // Format kecamatan (get readable string if key is used)
        $kec_opts = [
            'bunaken'           => 'Bunaken',
            'bunaken_kepulauan' => 'Bunaken Kepulauan',
            'malalayang'        => 'Malalayang',
            'mapanget'          => 'Mapanget',
            'paal_dua'          => 'Paal Dua',
            'paal_empat'        => 'Paal Empat',
            'sario'             => 'Sario',
            'singkil'           => 'Singkil',
            'tikala'            => 'Tikala',
            'tuminting'         => 'Tuminting',
            'wanea'             => 'Wanea',
            'wenang'            => 'Wenang',
        ];
        $kecKey = strtolower($order['kecamatan']);
        $kecamatan = $kec_opts[$kecKey] ?? $order['kecamatan'];
        $waktu = date('d M Y H:i:s', strtotime($order['created_at']));
        
        // Formatting tanggal untuk subjek email, e.g. "26 Mei 2026"
        $monthsIndo = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
            7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        $timestamp = strtotime($order['created_at']);
        $dayNum = date('j', $timestamp);
        $monthNum = (int)date('n', $timestamp);
        $monthName = $monthsIndo[$monthNum] ?? date('F', $timestamp);
        $year = date('Y', $timestamp);
        $subjectDate = "$dayNum $monthName $year";
        
        $jumlah_titik = (int)$order['jumlah_titik'];
        // Jika 0, default ke 1 (karena minimal ada 1 request/titik penjemputan)
        if ($jumlah_titik === 0) {
            $jumlah_titik = 1;
        }

        $subject = "[ORDER BARU] Pickup - Kecamatan $kecamatan - $subjectDate";
        
        $body = "Halo Admin,\n\n";
        $body .= "Terdapat order penjemputan baru yang masuk ke sistem:\n";
        $body .= "--------------------------------------------------\n";
        $body .= "ID / Kode Order  : $code\n";
        $body .= "Nama Pemesan     : $nama\n";
        $body .= "PIC              : " . ($order['partner_name'] ?? '-') . "\n";
        $body .= "Kecamatan Pickup : $kecamatan\n";
        $body .= "Kelurahan        : " . ($order['kelurahan'] ?? '-') . "\n";
        $body .= "Alamat Jemput    : " . ($order['alamat_jemput'] ?? '-') . "\n";
        $body .= "Tanggal & Jam    : $waktu WITA\n";
        $body .= "Estimasi Berat   : " . ($order['berat_kg'] ?? '-') . " kg\n";
        $body .= "Jumlah Titik     : $jumlah_titik titik\n";
        $body .= "--------------------------------------------------\n\n";
        $body .= "Silakan login ke Admin Console Manado Recycle Hub untuk memproses order ini.\n\n";
        $body .= "Salam,\nSystem Manado Recycle Hub";
        
        // Target email: Ambil email admin aktif dari database, fallback ke default jika tidak ditemukan
        $to = "mdorecyclehub@gmail.com";
        try {
            $adminQuery = $db->query("
                SELECT u.email 
                FROM   users u
                JOIN   roles r ON r.id = u.role_id
                WHERE  r.name = 'admin' AND u.is_active = 1
                LIMIT  1
            ");
            $dbAdminEmail = $adminQuery->fetchColumn();
            if ($dbAdminEmail) {
                $to = $dbAdminEmail;
            }
        } catch (Exception $e) {
            error_log('[triggerNewOrderEmail Admin Email Query Error] ' . $e->getMessage());
        }

        // Jika target email menggunakan domain default yang tidak valid, arahkan ke mdorecyclehub@gmail.com
        if (strpos($to, 'manadurecyclehub.id') !== false) {
            $to = "mdorecyclehub@gmail.com";
        }
        
        // Headers untuk email
        $fromEmail = defined('SMTP_FROM') ? SMTP_FROM : "mdorecyclehub@gmail.com";
        if (strpos($fromEmail, 'manadurecyclehub.id') !== false) {
            $fromEmail = "mdorecyclehub@gmail.com";
        }
        $headers = "From: $fromEmail\r\n";
        $headers .= "Reply-To: $fromEmail\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        
        // 1. Kirim via SMTP riil / PHP mail() fallback
        sendRealEmailViaSMTP($to, $subject, $body, $headers);
        
        // 2. Tulis ke file log di uploads/email_logs.txt agar admin bisa memverifikasi email notifikasi secara lokal tanpa SMTP server
        $logDir = PROJECT_ROOT . '/uploads';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $logFile = $logDir . '/email_logs.txt';
        $logEntry = "[" . date('Y-m-d H:i:s') . "] EMAIL SENT TO: $to\nSubject: $subject\nHeaders: $headers\n\n$body\n========================================================================\n\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);
        
        error_log("[MRH Email Gateway] Berhasil mengirim email notifikasi order baru ke $to");
    } catch (Exception $e) {
        error_log('[triggerNewOrderEmail Error] ' . $e->getMessage());
    }
}

/**
 * Mengirim notifikasi email otomatis ke admin/manajemen saat order clean up baru masuk.
 */
function triggerCleanupOrderEmail(PDO $db, int $cleanupRequestId): void {
    try {
        $stmt = $db->prepare("
            SELECT cr.* FROM cleanup_requests cr WHERE cr.id = ?
        ");
        $stmt->execute([$cleanupRequestId]);
        $order = $stmt->fetch();
        if (!$order) return;
        
        $nama = $order['nama_pemohon'];
        $code = $order['request_code'];
        
        $kec_opts = [
            'bunaken'           => 'Bunaken',
            'bunaken_kepulauan' => 'Bunaken Kepulauan',
            'malalayang'        => 'Malalayang',
            'mapanget'          => 'Mapanget',
            'paal_dua'          => 'Paal Dua',
            'paal_empat'        => 'Paal Empat',
            'sario'             => 'Sario',
            'singkil'           => 'Singkil',
            'tikala'            => 'Tikala',
            'tuminting'         => 'Tuminting',
            'wanea'             => 'Wanea',
            'wenang'            => 'Wenang',
        ];
        $kecKey = strtolower($order['kecamatan']);
        $kecamatan = $kec_opts[$kecKey] ?? $order['kecamatan'];
        $waktu = date('d M Y H:i:s', strtotime($order['created_at']));
        
        $cleanup_types = [
            'acara'  => 'Bersih-bersih Acara',
            'rumah'  => 'Pembersihan Rumah',
            'kantor' => 'Pembersihan Kantor',
        ];
        $svcKey = strtolower($order['service_type']);
        $service = $cleanup_types[$svcKey] ?? $order['service_type'];

        // Formatting tanggal untuk subjek email
        $monthsIndo = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
            7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        $timestamp = strtotime($order['created_at']);
        $dayNum = date('j', $timestamp);
        $monthNum = (int)date('n', $timestamp);
        $monthName = $monthsIndo[$monthNum] ?? date('F', $timestamp);
        $year = date('Y', $timestamp);
        $subjectDate = "$dayNum $monthName $year";

        $subject = "[ORDER BARU] Clean Up - Kecamatan $kecamatan - $subjectDate";
        
        $body = "Halo Admin,\n\n";
        $body .= "Terdapat order Clean Up baru yang masuk ke sistem:\n";
        $body .= "--------------------------------------------------\n";
        $body .= "ID / Kode Order  : $code\n";
        $body .= "Nama Pemesan     : $nama\n";
        $body .= "Layanan Clean Up : $service\n";
        $body .= "Kecamatan        : $kecamatan\n";
        $body .= "Kelurahan        : " . ($order['kelurahan'] ?? '-') . "\n";
        $body .= "Alamat Lokasi    : " . ($order['alamat_jemput'] ?? '-') . "\n";
        $body .= "Tanggal & Jam    : $waktu WITA\n";
        $body .= "Dominan Sampah   : " . ($order['dominant_waste'] ?? '-') . "\n";
        $body .= "--------------------------------------------------\n\n";
        $body .= "Silakan login ke Admin Console Manado Recycle Hub untuk memproses order ini.\n\n";
        $body .= "Salam,\nSystem Manado Recycle Hub";
        
        // Target email: Ambil email admin aktif dari database
        $to = "mdorecyclehub@gmail.com";
        try {
            $adminQuery = $db->query("
                SELECT u.email 
                FROM   users u
                JOIN   roles r ON r.id = u.role_id
                WHERE  r.name = 'admin' AND u.is_active = 1
                LIMIT  1
            ");
            $dbAdminEmail = $adminQuery->fetchColumn();
            if ($dbAdminEmail) {
                $to = $dbAdminEmail;
            }
        } catch (Exception $e) {
            error_log('[triggerCleanupOrderEmail Admin Email Query Error] ' . $e->getMessage());
        }

        // Jika target email menggunakan domain default yang tidak valid, gunakan mdorecyclehub@gmail.com sebagai fallback
        if (strpos($to, 'manadurecyclehub.id') !== false) {
            $to = "mdorecyclehub@gmail.com";
        }
        
        $fromEmail = defined('SMTP_FROM') ? SMTP_FROM : "mdorecyclehub@gmail.com";
        if (strpos($fromEmail, 'manadurecyclehub.id') !== false) {
            $fromEmail = "mdorecyclehub@gmail.com";
        }
        $headers = "From: $fromEmail\r\n";
        $headers .= "Reply-To: $fromEmail\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        
        // 1. Kirim via SMTP riil / PHP mail() fallback
        sendRealEmailViaSMTP($to, $subject, $body, $headers);
        
        // 2. Tulis ke file log
        $logDir = PROJECT_ROOT . '/uploads';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $logFile = $logDir . '/email_logs.txt';
        $logEntry = "[" . date('Y-m-d H:i:s') . "] CLEANUP EMAIL SENT TO: $to\nSubject: $subject\nHeaders: $headers\n\n$body\n========================================================================\n\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);
        
        error_log("[MRH Email Gateway] Berhasil mengirim email notifikasi cleanup baru ke $to");
    } catch (Exception $e) {
        error_log('[triggerCleanupOrderEmail Error] ' . $e->getMessage());
    }
}

/**
 * Mengirim email menggunakan koneksi socket SMTP langsung (tanpa pustaka eksternal)
 */
function sendRealEmailViaSMTP(string $to, string $subject, string $body, string $headers = ''): bool {
    // Membaca konfigurasi dari konstanta
    $smtp_host = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
    $smtp_port = defined('SMTP_PORT') ? SMTP_PORT : 587;
    $smtp_user = defined('SMTP_USER') ? SMTP_USER : 'mdorecyclehub@gmail.com';
    $smtp_pass = defined('SMTP_PASS') ? SMTP_PASS : 'xxxx xxxx xxxx xxxx';
    $from_email = defined('SMTP_FROM') ? SMTP_FROM : 'mdorecyclehub@gmail.com';
    
    // Jika email pengirim menggunakan domain default yang tidak valid, gunakan mdorecyclehub@gmail.com sebagai fallback
    if (strpos($from_email, 'manadurecyclehub.id') !== false) {
        $from_email = 'mdorecyclehub@gmail.com';
    }
    
    // Fallback ke php mail() jika password SMTP masih default atau kosong
    if ($smtp_pass === 'xxxx xxxx xxxx xxxx' || empty($smtp_pass)) {
        return @mail($to, $subject, $body, $headers);
    }

    try {
        $socket = fsockopen($smtp_host, $smtp_port, $errno, $errstr, 15);
        if (!$socket) {
            error_log("[SMTP Connection Error] $errstr ($errno)");
            return @mail($to, $subject, $body, $headers);
        }

        $getResponse = function($socket) {
            $response = '';
            while (($line = fgets($socket, 515)) !== false) {
                $response .= $line;
                if (substr($line, 3, 1) === ' ') break;
            }
            return $response;
        };

        $getResponse($socket); // Read greeting

        // Send EHLO
        fwrite($socket, "EHLO localhost\r\n");
        $getResponse($socket);

        // Start TLS
        fwrite($socket, "STARTTLS\r\n");
        $response = $getResponse($socket);
        if (strpos($response, '220') === false) {
            fclose($socket);
            error_log("[SMTP TLS Error] STARTTLS failed: $response");
            return @mail($to, $subject, $body, $headers);
        }

        // Encrypt the connection
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            error_log("[SMTP TLS Error] Encryption enablement failed.");
            return @mail($to, $subject, $body, $headers);
        }

        // Send EHLO again after TLS start
        fwrite($socket, "EHLO localhost\r\n");
        $getResponse($socket);

        // Authenticate
        fwrite($socket, "AUTH LOGIN\r\n");
        $getResponse($socket);

        fwrite($socket, base64_encode($smtp_user) . "\r\n");
        $getResponse($socket);

        fwrite($socket, base64_encode($smtp_pass) . "\r\n");
        $authResponse = $getResponse($socket);
        if (strpos($authResponse, '235') === false) {
            fclose($socket);
            error_log("[SMTP Auth Error] Authentication failed for user $smtp_user: $authResponse");
            return @mail($to, $subject, $body, $headers);
        }

        // MAIL FROM
        fwrite($socket, "MAIL FROM: <$from_email>\r\n");
        $getResponse($socket);

        // RCPT TO
        fwrite($socket, "RCPT TO: <$to>\r\n");
        $getResponse($socket);

        // DATA
        fwrite($socket, "DATA\r\n");
        $getResponse($socket);

        // Send Headers & Body
        $mail_data = "To: $to\r\n";
        $mail_data .= "Subject: $subject\r\n";
        $mail_data .= "MIME-Version: 1.0\r\n";
        $mail_data .= "Content-Type: text/plain; charset=utf-8\r\n";
        if (!empty($headers)) {
            $mail_data .= trim($headers) . "\r\n";
        }
        $mail_data .= "\r\n" . $body . "\r\n.\r\n";

        fwrite($socket, $mail_data);
        $response = $getResponse($socket);

        // QUIT
        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        return strpos($response, '250') !== false;
    } catch (Exception $e) {
        error_log("[SMTP Socket Exception] " . $e->getMessage());
        return @mail($to, $subject, $body, $headers);
    }
}
