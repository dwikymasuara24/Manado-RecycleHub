<?php
// ============================================================
//  kuesioner.php — Survey Kesadaran 3R
//  Manado Recycle Hub — User Console
//  Sinkron penuh dengan analisis_data.php (admin)
//  Menyimpan semua jawaban ke tabel survey_responses
// ============================================================

// ── FIX: Session HARUS dimulai sebelum include config.php ────
// config.php mungkin memanggil session_start() juga, guard ini
// mencegah "session already started" warning.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/include/config.php';

// ── Koneksi Database (via include/config.php → getDB()) ──────
try {
    $pdo = getDB();
} catch (PDOException $e) {
    $pdo = null;
}

// ── Auto-migrasi: pastikan tabel & kolom ada ────────────────
if ($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS survey_responses (
            id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            response_code           VARCHAR(20)  NULL UNIQUE,
            q1_sampah_mendesak      VARCHAR(10)  NULL COMMENT 'Ya/Tidak',
            q2_paham_3r             VARCHAR(10)  NULL COMMENT 'Ya/Tidak',
            q3_daur_ulang_rumah     VARCHAR(10)  NULL COMMENT 'Ya/Tidak',
            q4_pilah_organik_anorganik VARCHAR(10) NULL COMMENT 'Ya/Tidak',
            q5_jenis_sampah_didaur_ulang TEXT       NULL COMMENT 'CSV pilihan checkbox',
            q6_kesulitan            TEXT         NULL COMMENT 'Teks bebas',
            q7_bersedia_pilah       VARCHAR(10)  NULL COMMENT 'Ya/Tidak',
            nama                    VARCHAR(150) NULL,
            email                   VARCHAR(200) NULL,
            nomor_wa                VARCHAR(50)  NULL,
            alamat                  TEXT         NULL,
            created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_created (created_at),
            INDEX idx_q1 (q1_sampah_mendesak),
            INDEX idx_q2 (q2_paham_3r)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Pastikan kolom-kolom baru ditambahkan jika tabel sudah ada sebelumnya
        $existingCols = array_map('strtolower', $pdo->query("SHOW COLUMNS FROM survey_responses")->fetchAll(PDO::FETCH_COLUMN));
        
        if (!in_array('response_code', $existingCols)) {
            $pdo->exec("ALTER TABLE survey_responses ADD COLUMN response_code VARCHAR(20) NULL AFTER id");
            try {
                $pdo->exec("ALTER TABLE survey_responses ADD UNIQUE KEY uq_response_code (response_code)");
            } catch (Exception $e) {}
        }
        if (!in_array('q1_sampah_mendesak', $existingCols)) {
            $pdo->exec("ALTER TABLE survey_responses ADD COLUMN q1_sampah_mendesak VARCHAR(10) NULL");
        }
        if (!in_array('q2_paham_3r', $existingCols)) {
            $pdo->exec("ALTER TABLE survey_responses ADD COLUMN q2_paham_3r VARCHAR(10) NULL");
        }
        if (!in_array('q3_daur_ulang_rumah', $existingCols)) {
            $pdo->exec("ALTER TABLE survey_responses ADD COLUMN q3_daur_ulang_rumah VARCHAR(10) NULL");
        }
        if (!in_array('q4_pilah_organik_anorganik', $existingCols)) {
            $pdo->exec("ALTER TABLE survey_responses ADD COLUMN q4_pilah_organik_anorganik VARCHAR(10) NULL");
        }
        if (!in_array('q5_jenis_sampah_didaur_ulang', $existingCols)) {
            $pdo->exec("ALTER TABLE survey_responses ADD COLUMN q5_jenis_sampah_didaur_ulang TEXT NULL");
        }
        if (!in_array('q6_kesulitan', $existingCols)) {
            $pdo->exec("ALTER TABLE survey_responses ADD COLUMN q6_kesulitan TEXT NULL");
        }
        if (!in_array('q7_bersedia_pilah', $existingCols)) {
            $pdo->exec("ALTER TABLE survey_responses ADD COLUMN q7_bersedia_pilah VARCHAR(10) NULL");
        }
        if (!in_array('updated_at', $existingCols)) {
            $pdo->exec("ALTER TABLE survey_responses ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }

        // Hapus constraint check JSON yang membatasi format data jika ada
        try {
            $pdo->exec("ALTER TABLE survey_responses DROP CHECK survey_responses_chk_1");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE survey_responses MODIFY q5_jenis_sampah_didaur_ulang TEXT NULL");
        } catch (Exception $e) {}

        // Backfill response_code untuk data yang kosong/NULL agar tidak menyebabkan error unique key atau error query
        $stmtNull = $pdo->query("SELECT id FROM survey_responses WHERE response_code IS NULL OR response_code = ''");
        $nullRows = $stmtNull->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($nullRows)) {
            $stmtUpdate = $pdo->prepare("UPDATE survey_responses SET response_code = ? WHERE id = ?");
            foreach ($nullRows as $rowId) {
                $newCode = 'SRV-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
                $stmtUpdate->execute([$newCode, $rowId]);
            }
        }
    } catch (PDOException $e) {
        error_log('[MRH Kuesioner Migration] ' . $e->getMessage());
    }
}

// ── Helper: generate kode unik response ─────────────────────
function generateResponseCode($pdo) {
    if (!$pdo) return 'SRV-' . strtoupper(substr(uniqid(), -6));
    do {
        $code = 'SRV-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
        $exists = $pdo->prepare("SELECT COUNT(*) FROM survey_responses WHERE response_code = ?");
        $exists->execute([$code]);
    } while ((int)$exists->fetchColumn() > 0);
    return $code;
}

// ── FIX: Init session hanya jika belum ada (jangan reset) ────
// Blok ini hanya menetapkan nilai default jika key belum ada,
// bukan menimpa nilai yang sudah tersimpan dari request sebelumnya.
if (!isset($_SESSION['step'])) {
    $_SESSION['step'] = 0;
}
if (!isset($_SESSION['answers'])) {
    $_SESSION['answers'] = [];
}

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action       = $_POST['action'] ?? '';
    $current_step = (int)($_POST['current_step'] ?? 0);

    if ($action === 'start') {
        // Reset jawaban saat mulai baru
        $_SESSION['answers'] = [];
        $_SESSION['step']    = 1;

    } elseif ($action === 'prev') {
        // FIX: Navigasi prev — kembali satu step dari current_step
        $_SESSION['step'] = max(0, $current_step - 1);

    } elseif ($action === 'next') {
        // Simpan jawaban jika ada, lalu maju
        if (isset($_POST['answer_multi'])) {
            // Checkbox (bisa kosong array jika tidak ada yang dicentang)
            $_SESSION['answers'][$current_step] = (array)$_POST['answer_multi'];
        } elseif (isset($_POST['answer']) && $_POST['answer'] !== '') {
            $_SESSION['answers'][$current_step] = $_POST['answer'];
        }
        // FIX: Selalu maju ke step berikutnya meski tidak ada jawaban (step opsional)
        $_SESSION['step'] = $current_step + 1;

    } elseif ($action === 'answer_go') {
        // Klik Ya/Tidak → simpan LALU langsung lanjut
        $val = $_POST['answer'] ?? '';
        if ($val !== '') {
            $_SESSION['answers'][$current_step] = $val;
        }
        $_SESSION['step'] = $current_step + 1;

    } elseif ($action === 'submit') {
        // Simpan data diri ke session
        $_SESSION['answers']['contact'] = [
            'nama'   => trim($_POST['nama']   ?? ''),
            'email'  => trim($_POST['email']  ?? ''),
            'wa'     => trim($_POST['wa']      ?? ''),
            'alamat' => trim($_POST['alamat'] ?? ''),
        ];

        // ── Simpan ke database ─────────────────────────────
        $saved        = false;
        $db_error_msg = '';
        if ($pdo) {
            try {
                $response_code = generateResponseCode($pdo);
                $ans           = $_SESSION['answers'];
                $contact       = $ans['contact'] ?? [];

                // Jenis daur ulang (array → CSV)
                $jenisDaurUlang = '';
                if (!empty($ans[8]) && is_array($ans[8])) {
                    $jenisDaurUlang = implode(', ', array_map('trim', $ans[8]));
                }

                $stmt = $pdo->prepare("INSERT INTO survey_responses
                    (response_code,
                     q1_sampah_mendesak,
                     q2_paham_3r,
                     q3_daur_ulang_rumah,
                     q4_pilah_organik_anorganik,
                     q5_jenis_sampah_didaur_ulang,
                     q6_kesulitan,
                     q7_bersedia_pilah,
                     nama, email, nomor_wa, alamat,
                     created_at, updated_at)
                    VALUES
                    (:response_code,
                     :q1, :q2, :q3, :q4, :q5, :q6, :q7,
                     :nama, :email, :wa, :alamat,
                     NOW(), NOW())");

                $stmt->execute([
                    ':response_code' => $response_code,
                    ':q1'  => $ans[1]  ?? null,   // Q1  — step 1
                    ':q2'  => $ans[3]  ?? null,   // Q2  — step 3
                    ':q3'  => $ans[6]  ?? null,   // Q3  — step 6
                    ':q4'  => $ans[7]  ?? null,   // Q4  — step 7
                    ':q5'  => $jenisDaurUlang ?: null,
                    ':q6'  => !empty($ans[9]) ? trim($ans[9]) : null,
                    ':q7'  => $ans[10] ?? null,   // Q7  — step 10
                    ':nama'  => !empty($contact['nama'])   ? $contact['nama']   : null,
                    ':email' => !empty($contact['email'])  ? $contact['email']  : null,
                    ':wa'    => !empty($contact['wa'])     ? $contact['wa']     : null,
                    ':alamat'=> !empty($contact['alamat']) ? $contact['alamat'] : null,
                ]);

                $_SESSION['response_code'] = $response_code;
                $saved = true;

            } catch (PDOException $e) {
                $db_error_msg = $e->getMessage();
                error_log('[MRH Kuesioner] PDOException: ' . $e->getMessage());
                file_put_contents(__DIR__ . '/kuesioner_error.txt', date('Y-m-d H:i:s') . ' ERROR: ' . $e->getMessage() . "\n", FILE_APPEND);
            }
        }

        $_SESSION['step']     = 12;
        $_SESSION['db_saved'] = $saved;

    } elseif ($action === 'restart') {
        $keys = ['step','answers','response_code','db_saved'];
        foreach ($keys as $k) unset($_SESSION[$k]);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // FIX: Gunakan header redirect PRG (Post/Redirect/Get) untuk
    // semua action agar tombol Back browser tidak re-submit form.
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Baca state dari session (GET request) ────────────────────
$step          = (int)($_SESSION['step'] ?? 0);
$answers       = $_SESSION['answers'] ?? [];
$response_code = $_SESSION['response_code'] ?? '';
$db_saved      = $_SESSION['db_saved'] ?? false;
$total         = 9; // total dot pertanyaan

// ── Helpers ──────────────────────────────────────────────────
function recycleSvg($w = 60, $h = 60) {
    return "<svg width=\"{$w}\" height=\"{$h}\" viewBox=\"0 0 100 100\" xmlns=\"http://www.w3.org/2000/svg\">
  <path d=\"M50 8 L63 31 L56 31 L56 46 L44 46 L44 31 L37 31 Z\" fill=\"#8bc34a\"/>
  <path d=\"M80 63 L67 40 L74 37 L58 12 L42 37 L49 40 L38 60 Z\" fill=\"#4caf50\"/>
  <path d=\"M20 63 L33 40 L26 37 L42 12 L58 37 L51 40 L62 60 Z\" fill=\"#66bb6a\"/>
  <rect x=\"22\" y=\"63\" width=\"56\" height=\"18\" rx=\"3\" fill=\"#8bc34a\"/>
</svg>";
}

function renderDots($curr, $total) {
    echo '<div class="dots-bar">';
    for ($i = 1; $i <= $total; $i++) {
        if ($i < $curr)       echo '<div class="dot done"></div>';
        elseif ($i === $curr) echo '<div class="dot active"></div>';
        else                  echo '<div class="dot"></div>';
    }
    echo '</div>';
    echo '<p class="page-count">' . $curr . ' of ' . $total . '</p>';
}

// ── Navbar ────────────────────────────────────────────────────
$site_name   = "Manado Recycle Hub";
$logo_img    = "Home.png";
$banner_img  = "kuesioner.jpeg";
$nav_items   = [
    ["label" => "Home",                  "url" => "home.php",                    "active" => false],
    ["label" => "Bin Project",           "url" => "bin_project.php",             "active" => false],
    ["label" => "Blog dan Media Sosial", "url" => "blog.php",                    "active" => false],
    ["label" => "Idea Box",              "url" => "idea-box.php",                "active" => false],
    ["label" => "Lokasi Kami",           "url" => "lokasi_kami.php",             "active" => false],
    ["label" => "DIY",                   "url" => "diy.php",                     "active" => false],
    ["label" => "Kuesioner",             "url" => "kuesioner.php",               "active" => true],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Kuesioner – <?= htmlspecialchars($site_name) ?></title>
<link rel="icon" type="image/png" href="<?= baseUrl('Title.png') ?>">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Comfortaa:wght@400;700&display=swap">
<style>
/* ===== RESET ===== */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
a { text-decoration: none; color: inherit; }
img { max-width: 100%; display: block; }

/* ===== CSS VARIABLES ===== */
:root {
    --green-primary:  rgba(28,100,52,1);
    --green-mid:      rgba(106,168,79,1);
    --green-light:    rgba(214,228,195,1);
    --text-light:     rgba(249,249,249,1);
    --dark-bg:        rgba(28,28,28,1);
    --nav-height:     64px;
    --blue-bg:        #3aade8;
    --blue-card:      #2196c4;
    --green-btn:      #8bc34a;
    --red-border:     #e53935;
}

/* ===== BODY ===== */
body {
    font-family: 'Comfortaa', sans-serif;
    background: var(--blue-bg);
    min-height: 100vh;
    color: #1c1c1c;
    display: flex;
    flex-direction: column;
}

/* ===== NAVBAR ===== */
header {
    position: fixed;
    top: 0; left: 0;
    width: 100%;
    z-index: 100;
    background-color: var(--dark-bg);
}
.navbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    max-width: 1280px;
    margin: 0 auto;
    padding: 0 48px;
    height: var(--nav-height);
}
.navbar-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    color: var(--text-light);
}
.navbar-brand img { width: 36px; height: 36px; object-fit: cover; border-radius: 4px; }
.navbar-brand .brand-name { font-size: 15pt; font-weight: 700; color: var(--text-light); }
.navbar-nav { display: flex; list-style: none; gap: 4px; }
.navbar-nav li a {
    font-family: 'Comfortaa', sans-serif;
    font-size: 12pt; font-weight: 700;
    color: var(--text-light);
    padding: 8px 14px; border-radius: 4px;
    transition: color .2s;
}
.navbar-nav li a:hover, .navbar-nav li a.active { color: rgba(249,249,249,.82); }
.navbar-nav li a.active { border-bottom: 2px solid var(--text-light); }

/* Hamburger */
.hamburger {
    display: none; flex-direction: column; gap: 5px;
    cursor: pointer; background: none; border: none; padding: 4px;
}
.hamburger span { width: 24px; height: 2px; background: var(--text-light); border-radius: 2px; }

/* Mobile Sidebar */
.sidebar-nav {
    display: none; position: fixed;
    top: 0; left: 0; height: 100%; width: 280px;
    background-color: var(--dark-bg); z-index: 200;
    padding: 48px 32px 62px 48px;
    flex-direction: column; gap: 16px;
    overflow-y: auto;
    transform: translateX(-100%);
    transition: transform .3s ease;
}
.sidebar-nav.open { transform: translateX(0); }
.sidebar-nav .sidebar-logo { margin-bottom: 24px; }
.sidebar-nav .sidebar-logo img { width: 48px; border-radius: 4px; margin-bottom: 8px; }
.sidebar-nav .sidebar-title { font-size: 20pt; font-weight: 700; color: var(--text-light); margin-bottom: 24px; }
.sidebar-nav ul { list-style: none; display: flex; flex-direction: column; gap: 4px; }
.sidebar-nav ul li a { font-size: 13pt; font-weight: 700; color: var(--text-light); display: block; padding: 8px 0; }
.sidebar-nav ul li a.active { border-left: 3px solid var(--text-light); padding-left: 8px; }
.sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 150; }
.sidebar-overlay.open { display: block; }

.hero-section {
    position: relative;
    height: 250px;
    background-color: #ffffff;
    background-image: url('<?= rawurlencode($banner_img) ?>');
    background-size: contain;
    background-position: center;
    background-repeat: no-repeat;
    margin-top: var(--nav-height);
    display: flex;
    align-items: flex-end;
    justify-content: center;
    overflow: hidden;
}
.hero-overlay {
    position: absolute;
    inset: 0;
    background: rgba(28,100,52,.45);
}
.hero-content {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 1280px;
    margin: 0 auto;
    padding: 0 7.5% 24px;
    text-align: center;
}
.hero-content h1 {
    font-family: 'Comfortaa', sans-serif;
    font-weight: 700;
    font-size: 26pt;
    color: var(--text-light);
    text-shadow: 0 2px 8px rgba(0,0,0,0.5);
    line-height: 1.38;
}

/* ===== SURVEY WRAP ===== */
.survey-wrap {
    width: 100%;
    max-width: 860px;
    margin: 0 auto;
    padding: calc(var(--nav-height) + 32px) 20px 40px;
}

/* ===== Cards ===== */
.card {
    background: #fff;
    border-radius: 8px;
    padding: 36px 44px;
    max-width: 440px;
    margin: 0 auto;
    text-align: center;
    box-shadow: 0 4px 24px rgba(0,0,0,.15);
}
.card.blue { background: var(--blue-card); color: #fff; }
.card.question { border-top: 5px solid var(--red-border); padding: 32px 40px; }

.card.blue h1 { font-size: 20px; font-weight: 700; line-height: 1.45; margin-bottom: 10px; }
.card.blue p  { font-size: 13px; opacity: .9; line-height: 1.6; margin-bottom: 6px; }
.card.blue .cnt  { font-size: 12px; opacity: .7; margin-bottom: 24px; }
.card.blue h2    { font-size: 17px; font-weight: 700; line-height: 1.4; margin-bottom: 12px; }
.card.blue .sub  { font-size: 12px; opacity: .7; margin-bottom: 20px; }

.card.question h2       { font-size: 16px; font-weight: 600; color: var(--red-border); margin-bottom: 22px; line-height: 1.5; }
.card.question h2.dark  { color: #333; }

/* ===== START Button ===== */
.btn-start {
    display: block; width: 100%;
    background: var(--green-btn); color: #fff;
    border: none; border-radius: 4px;
    padding: 13px; font-size: 15px; font-weight: 700;
    cursor: pointer; letter-spacing: .8px;
    font-family: 'Comfortaa', sans-serif;
}
.btn-start:hover { background: #7cb342; }

/* ===== Ya / Tidak buttons ===== */
.choices { display: flex; gap: 12px; justify-content: center; margin-bottom: 22px; }
.ch {
    padding: 10px 30px;
    border: 2px solid var(--blue-card);
    background: #fff;
    color: var(--blue-card);
    border-radius: 4px;
    font-size: 14px; font-weight: 700;
    cursor: pointer;
    transition: background .18s, color .18s;
    font-family: 'Comfortaa', sans-serif;
}
.ch:hover { background: #e3f2fd; }
.ch.sel { background: var(--blue-card); color: #fff; }

/* ===== Nav bar bawah ===== */
.nav {
    background: var(--green-btn);
    border-radius: 0 0 8px 8px;
    margin: 20px -44px -36px;
    padding: 10px 20px;
    display: flex; justify-content: space-between; align-items: center;
}
.nav.q { margin: 20px -40px -32px; }
.btn-nav {
    background: transparent; border: none;
    color: #fff; font-size: 14px; font-weight: 700;
    cursor: pointer; padding: 6px 10px; letter-spacing: .4px;
    font-family: 'Comfortaa', sans-serif;
}
.btn-nav:hover { opacity: .8; }
.btn-submit {
    background: #fff; color: #5a8e1a;
    border: none; padding: 9px 22px;
    border-radius: 4px; font-weight: 700; font-size: 14px;
    cursor: pointer; font-family: 'Comfortaa', sans-serif;
}
.btn-submit:hover { background: #f0f0f0; }

/* ===== Dots ===== */
.dots-bar { display: flex; gap: 10px; justify-content: center; margin-top: 20px; }
.dot {
    width: 10px; height: 10px; border-radius: 50%;
    border: 2px solid rgba(255,255,255,.7);
    background: rgba(255,255,255,.35);
}
.dot.done   { background: rgba(255,255,255,.9); border-color: rgba(255,255,255,.9); }
.dot.active { background: transparent; border-color: #fff; width: 12px; height: 12px; }
.page-count { color: rgba(255,255,255,.8); font-size: 12px; text-align: center; margin-top: 6px; }

/* ===== Textarea ===== */
textarea {
    width: 100%; border: 1px solid #ddd;
    border-radius: 4px 4px 0 0;
    padding: 12px; font-size: 14px; resize: vertical; min-height: 96px;
    font-family: 'Comfortaa', sans-serif; color: #333; outline: none;
}
textarea:focus { border-color: var(--blue-card); }
.toolbar {
    display: flex; gap: 8px; padding: 5px 10px;
    background: #f5f5f5; border: 1px solid #ddd;
    border-top: none; border-radius: 0 0 4px 4px;
    font-size: 13px; color: #555; margin-bottom: 18px; flex-wrap: wrap;
}
.toolbar span { cursor: pointer; padding: 2px 3px; }

/* ===== Checkboxes ===== */
.cb-grid {
    display: grid; grid-template-columns: 1fr 1fr 1fr;
    gap: 10px; margin-bottom: 20px; text-align: left;
}
.cb-item { display: flex; align-items: center; gap: 8px; font-size: 14px; color: #333; cursor: pointer; }
.cb-item input { width: 16px; height: 16px; cursor: pointer; accent-color: var(--blue-card); }

/* ===== Form inputs ===== */
.row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; }
.fi {
    width: 100%; padding: 10px 13px;
    border: 1px solid #ddd; border-radius: 4px;
    font-size: 14px; font-family: 'Comfortaa', sans-serif;
    outline: none; margin-bottom: 10px;
}
.fi:focus { border-color: var(--blue-card); }
.fi::placeholder { color: #aaa; }

/* ===== Chart ===== */
.chart-wrap {
    background: #fff; border-radius: 8px;
    padding: 24px 28px; max-width: 780px;
    margin: 0 auto 16px;
    box-shadow: 0 2px 12px rgba(0,0,0,.12);
}
.chart-sub { font-size: 11px; color: #666; text-align: center; line-height: 1.5; margin-bottom: 14px; }
.legend { display: flex; gap: 18px; justify-content: center; margin-bottom: 12px; font-size: 11px; }
.leg { display: flex; align-items: center; gap: 5px; }
.leg-sq { width: 12px; height: 12px; border-radius: 2px; }
.bar-row { display: flex; align-items: center; margin-bottom: 7px; font-size: 11px; }
.bar-lbl { width: 190px; text-align: right; padding-right: 10px; color: #444; flex-shrink: 0; line-height: 1.3; }
.bar-track { flex: 1; }
.bar-a { height: 7px; border-radius: 2px; background: #5b9bd5; margin-bottom: 2px; }
.bar-d { height: 7px; border-radius: 2px; background: #70ad47; }

/* ===== Thank you ===== */
.card.ty { background: var(--blue-card); color: #fff; padding: 56px 40px; max-width: 480px; }
.card.ty h1 { font-size: 28px; font-weight: 700; margin-bottom: 10px; }
.card.ty p  { font-size: 14px; opacity: .9; }
/* Kode response box */
.ty-code-box {
    display: inline-block;
    margin: 18px auto 0;
    background: rgba(255,255,255,.18);
    border: 2px solid rgba(255,255,255,.5);
    border-radius: 8px;
    padding: 10px 24px;
    font-size: 1.2rem;
    font-weight: 700;
    color: #fff;
    letter-spacing: .1em;
}
.ty-note { font-size: 12px; opacity: .7; margin-top: 8px; }
/* Ringkasan jawaban */
.ty-recap {
    margin-top: 20px;
    background: rgba(255,255,255,.12);
    border-radius: 8px;
    padding: 12px 16px;
    text-align: left;
    font-size: 12px;
}
.ty-recap .tr-row {
    display: flex;
    justify-content: space-between;
    padding: 4px 0;
    border-bottom: 1px solid rgba(255,255,255,.15);
    gap: 8px;
}
.ty-recap .tr-row:last-child { border-bottom: none; }
.ty-recap .tr-lbl { opacity: .75; flex-shrink: 0; }
.ty-recap .tr-val { font-weight: 700; text-align: right; }

/* DB error notice */
.db-notice {
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.3);
    border-radius: 6px;
    padding: 8px 14px;
    font-size: 11px;
    margin-top: 12px;
    opacity: .85;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 767px) {
    .navbar { padding: 0 20px; }
    .navbar-brand .brand-name { font-size: 13pt; }
    .navbar-nav { display: none; }
    .hamburger { display: flex; }
    .sidebar-nav { display: flex; }
    .card, .card.question { padding: 24px 18px; }
    .nav, .nav.q { margin: 20px -18px -24px; }
    .bar-lbl { width: 120px; font-size: 10px; }
    .cb-grid { grid-template-columns: 1fr 1fr; }
    .row2 { grid-template-columns: 1fr; }
    .survey-wrap { padding-top: calc(var(--nav-height) + 24px); }
    .hero-section { height: 150px; }
    .hero-content h1 { font-size: 16pt; }
}
/* ===== FOOTER ===== */
.site-footer {
    padding: 24px 0 32px;
    background: #ffffff;
    margin-top: auto;
    width: 100%;
}
.footer-text {
    font-family: 'Comfortaa', sans-serif;
    font-size: 8pt;
    font-weight: 700;
    text-align: center;
    color: rgba(28, 28, 28, 1);
    margin-bottom: 4px;
}
.footer-container {
    max-width: 1280px;
    margin: 0 auto;
    padding: 0 48px;
}
@media (max-width: 767px) {
    .footer-container { padding: 0 20px; }
}
</style>
</head>
<body>

<!-- ===== MOBILE SIDEBAR OVERLAY ===== -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ===== MOBILE SIDEBAR NAV ===== -->
<nav class="sidebar-nav" id="sidebarNav">
    <div class="sidebar-logo">
        <img src="<?= htmlspecialchars($logo_img) ?>" alt="<?= htmlspecialchars($site_name) ?> logo">
    </div>
    <a href="home.php" class="sidebar-title"><?= htmlspecialchars($site_name) ?></a>
    <ul>
        <?php foreach ($nav_items as $item): ?>
        <li>
            <a href="<?= htmlspecialchars($item['url']) ?>"
               class="<?= !empty($item['active']) ? 'active' : '' ?>">
                <?= htmlspecialchars($item['label']) ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</nav>

<!-- ===== HEADER / NAVBAR ===== -->
<header>
    <div class="navbar">
        <a href="home.php" class="navbar-brand">
            <img src="<?= htmlspecialchars($logo_img) ?>" alt="<?= htmlspecialchars($site_name) ?> logo">
            <span class="brand-name"><?= htmlspecialchars($site_name) ?></span>
        </a>
        <ul class="navbar-nav">
            <?php foreach ($nav_items as $item): ?>
            <li>
                <a href="<?= htmlspecialchars($item['url']) ?>"
                   class="<?= !empty($item['active']) ? 'active' : '' ?>">
                    <?= htmlspecialchars($item['label']) ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <button class="hamburger" onclick="openSidebar()" aria-label="Buka menu navigasi">
            <span></span><span></span><span></span>
        </button>
    </div>
</header>


<!-- ===== SURVEY CONTENT ===== -->
<div class="survey-wrap">

<?php if ($step === 0): ?>
<!-- ══════════ WELCOME ══════════ -->
<div class="card blue">
    <?= recycleSvg(58,58) ?>
    <h1 style="margin-top:14px">Survey Kesadaran Reduce, Reuse dan Recycle Masyarakat Manado</h1>
    <p>Masalah sampah perkotaan, pemilahan dan aksi partisipatif daur ulang masyarakat Kota Manado</p>
    <p class="cnt">9 Questions</p>
    <form method="POST">
        <input type="hidden" name="action" value="start">
        <button type="submit" class="btn-start">START &nbsp;&rarr;</button>
    </form>
</div>

<?php elseif ($step === 1): ?>
<!-- ══════════ Q1 ══════════ -->
<div class="card question">
    <h2>Apa Anda merasa sampah adalah masalah yang mendesak di perkotaan?*</h2>
    <div class="choices">
        <!-- FIX: Satu form tunggal dengan dua tombol submit, nilai 'answer' berbeda -->
        <form method="POST">
            <input type="hidden" name="action" value="answer_go">
            <input type="hidden" name="current_step" value="1">
            <button type="submit" name="answer" value="Ya"
                class="ch <?= ($answers[1] ?? '') === 'Ya' ? 'sel' : '' ?>">Ya</button>
            <button type="submit" name="answer" value="Tidak"
                class="ch <?= ($answers[1] ?? '') === 'Tidak' ? 'sel' : '' ?>">Tidak</button>
        </form>
    </div>
    <div class="nav q">
        <!-- Prev: kembali ke step 0 (welcome) -->
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="prev">
            <input type="hidden" name="current_step" value="1">
            <button type="submit" class="btn-nav">&larr; PREVIOUS</button>
        </form>
        <!-- Next: maju tanpa wajib pilih (jawaban sudah tersimpan saat klik Ya/Tidak) -->
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="next">
            <input type="hidden" name="current_step" value="1">
            <button type="submit" class="btn-nav">NEXT &rarr;</button>
        </form>
    </div>
</div>
<?php renderDots(1, $total) ?>

<?php elseif ($step === 2): ?>
<!-- ══════════ INFO: Chart ══════════ -->
<div class="chart-wrap">
    <h3 style="font-size:17px;text-align:center;margin-bottom:6px">
        "Sampah rata-rata harian di Manado mencapai 339,89 ton"</h3>
    <p class="chart-sub">Diagram batang di bawah adalah Angka timbulan sampah di kota dan kabupaten di Sulut 2021.
        Biru adalah timbulan sampah per kota dan kabupaten dalam tahun, dan hijau adalah timbulan sampah
        rata-rata harian per kota dan kabupaten. Sumber: artikel zonautara.com
        (timbulan-sampah-di-sulut-per-kab-kota-pada-2021)</p>
    <h3 style="text-align:center;margin-bottom:4px">Timbulan Sampah per Kab/Kota di Sulut pada 2021</h3>
    <p style="text-align:center;font-size:11px;color:#888;margin-bottom:10px">( dalam ton )</p>
    <div class="legend">
        <div class="leg"><div class="leg-sq" style="background:#5b9bd5"></div> Timbulan Sampah Tahunan</div>
        <div class="leg"><div class="leg-sq" style="background:#70ad47"></div> Rata-rata Harian</div>
    </div>
    <?php
    $bars = [
        ['Kab. Bolaang Mongondow',              65, 18],
        ['Kab. Minahasa',                        72, 20],
        ['Kab. Minahasa Selatan',                45, 12],
        ['Kab. Minahasa Utara',                  40, 11],
        ['Kab. Minahasa Tenggara',               18,  5],
        ['Kab. Bolaang Mongondow Utara',         12,  3],
        ['Kab. Kep. Siau Tagulandang Biaro',     10,  3],
        ['Kab. Bolaang Mongondow Selatan',        9,  2],
        ['Kota Manado',                          100, 28],
        ['Kota Bitung',                           50, 14],
        ['Kota Tomohon',                          22,  6],
        ['Kota Kotamobagu',                       20,  6],
    ];
    foreach ($bars as $b): ?>
    <div class="bar-row">
        <div class="bar-lbl"><?= htmlspecialchars($b[0]) ?></div>
        <div class="bar-track">
            <div class="bar-a" style="width:<?= $b[1] ?>%"></div>
            <div class="bar-d" style="width:<?= $b[2] ?>%"></div>
        </div>
    </div>
    <?php endforeach; ?>
    <p style="text-align:right;font-size:10px;color:#aaa;margin-top:8px">
        Diolah oleh Zonautara.com dari SIPSN KLHK</p>
</div>

<div class="card question" style="margin-top:0">
    <p style="font-size:13px;color:#555;margin-bottom:18px">
        Data di atas menunjukkan besarnya masalah sampah di Kota Manado.</p>
    <div class="nav q" style="margin-top:0">
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="prev">
            <input type="hidden" name="current_step" value="2">
            <button type="submit" class="btn-nav">&larr; PREVIOUS</button>
        </form>
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="next">
            <input type="hidden" name="current_step" value="2">
            <button type="submit" class="btn-nav">NEXT &rarr;</button>
        </form>
    </div>
</div>
<?php renderDots(2, $total) ?>

<?php elseif ($step === 3): ?>
<!-- ══════════ Q2 ══════════ -->
<div class="card question">
    <h2>Apa Anda paham bahwa solusi masalah sampah perkotaan dengan cara
        <em>Reduce, Reuse dan Recycle</em> (3R)?*</h2>
    <div class="choices">
        <!-- FIX: Satu form, dua tombol -->
        <form method="POST">
            <input type="hidden" name="action" value="answer_go">
            <input type="hidden" name="current_step" value="3">
            <button type="submit" name="answer" value="Ya"
                class="ch <?= ($answers[3] ?? '') === 'Ya' ? 'sel' : '' ?>">Ya</button>
            <button type="submit" name="answer" value="Tidak"
                class="ch <?= ($answers[3] ?? '') === 'Tidak' ? 'sel' : '' ?>">Tidak</button>
        </form>
    </div>
    <div class="nav q">
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="prev">
            <input type="hidden" name="current_step" value="3">
            <button type="submit" class="btn-nav">&larr; PREVIOUS</button>
        </form>
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="next">
            <input type="hidden" name="current_step" value="3">
            <button type="submit" class="btn-nav">NEXT &rarr;</button>
        </form>
    </div>
</div>
<?php renderDots(3, $total) ?>

<?php elseif ($step === 4): ?>
<!-- ══════════ INFO: Tahukah Anda ══════════ -->
<div class="card blue">
    <h2>Tahukah Anda, masalah volume sampah perkotaan bisa diatasi mulai dari rumah?</h2>
    <p>Dengan mulai memilah sampah anorganik, kita dapat mengurangi jumlah timbulan yang terus
        membebani TPA (tempat pembuangan akhir) sampah perkotaan.</p>
    <p class="sub">1 Pertanyaan</p>
    <div class="nav">
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="prev">
            <input type="hidden" name="current_step" value="4">
            <button type="submit" class="btn-nav">&larr; PREVIOUS</button>
        </form>
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="next">
            <input type="hidden" name="current_step" value="4">
            <button type="submit" class="btn-nav">NEXT &rarr;</button>
        </form>
    </div>
</div>

<?php elseif ($step === 5): ?>
<!-- ══════════ INFO: Salah satu cara ══════════ -->
<div class="card blue">
    <h2>Salah satu cara partisipatif Reuse dan Recycle/ daur ulang adalah dengan
        pemilahan sampah mulai dari Rumah Anda.</h2>
    <p>Dengan mulai memilah sampah anorganik, kita dapat mengurangi jumlah timbulan yang terus
        membebani TPA (tempat pembuangan akhir) sampah perkotaan.</p>
    <p class="sub">6 Pertanyaan</p>
    <div class="nav">
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="prev">
            <input type="hidden" name="current_step" value="5">
            <button type="submit" class="btn-nav">&larr; PREVIOUS</button>
        </form>
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="next">
            <input type="hidden" name="current_step" value="5">
            <button type="submit" class="btn-nav">NEXT &rarr;</button>
        </form>
    </div>
</div>

<?php elseif ($step === 6): ?>
<!-- ══════════ Q3 ══════════ -->
<div class="card question">
    <h2>Apa Anda mendaur ulang sampah di rumah?*</h2>
    <div class="choices">
        <!-- FIX: Satu form, dua tombol -->
        <form method="POST">
            <input type="hidden" name="action" value="answer_go">
            <input type="hidden" name="current_step" value="6">
            <button type="submit" name="answer" value="Ya"
                class="ch <?= ($answers[6] ?? '') === 'Ya' ? 'sel' : '' ?>">Ya</button>
            <button type="submit" name="answer" value="Tidak"
                class="ch <?= ($answers[6] ?? '') === 'Tidak' ? 'sel' : '' ?>">Tidak</button>
        </form>
    </div>
    <div class="nav q">
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="prev">
            <input type="hidden" name="current_step" value="6">
            <button type="submit" class="btn-nav">&larr; PREVIOUS</button>
        </form>
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="next">
            <input type="hidden" name="current_step" value="6">
            <button type="submit" class="btn-nav">NEXT &rarr;</button>
        </form>
    </div>
</div>
<?php renderDots(4, $total) ?>

<?php elseif ($step === 7): ?>
<!-- ══════════ Q4 ══════════ -->
<div class="card question">
    <h2>Apa Anda memilah sampah organik dan anorganik?*</h2>
    <div class="choices">
        <!-- FIX: Satu form, dua tombol -->
        <form method="POST">
            <input type="hidden" name="action" value="answer_go">
            <input type="hidden" name="current_step" value="7">
            <button type="submit" name="answer" value="Ya"
                class="ch <?= ($answers[7] ?? '') === 'Ya' ? 'sel' : '' ?>">Ya</button>
            <button type="submit" name="answer" value="Tidak"
                class="ch <?= ($answers[7] ?? '') === 'Tidak' ? 'sel' : '' ?>">Tidak</button>
        </form>
    </div>
    <div class="nav q">
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="prev">
            <input type="hidden" name="current_step" value="7">
            <button type="submit" class="btn-nav">&larr; PREVIOUS</button>
        </form>
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="next">
            <input type="hidden" name="current_step" value="7">
            <button type="submit" class="btn-nav">NEXT &rarr;</button>
        </form>
    </div>
</div>
<?php renderDots(5, $total) ?>

<?php elseif ($step === 8): ?>
<!-- ══════════ Q5 – Checkbox ══════════ -->
<?php
$opts = ['Kertas', 'Plastik', 'Pecah Belah', 'Logam', 'Kaleng', 'Lain-lain'];
$chk  = (array)($answers[8] ?? []);
?>
<form method="POST" id="form8">
    <!-- FIX: action default 'next', diubah ke 'prev' oleh tombol PREVIOUS -->
    <input type="hidden" name="action" value="next" id="action8">
    <input type="hidden" name="current_step" value="8">
    <div class="card question">
        <h2 class="dark">Sampah apa yang paling sering Anda daur ulang?</h2>
        <div class="cb-grid">
            <?php foreach ($opts as $o): ?>
            <label class="cb-item">
                <input type="checkbox" name="answer_multi[]"
                    value="<?= htmlspecialchars($o) ?>"
                    <?= in_array($o, $chk) ? 'checked' : '' ?>>
                <?= htmlspecialchars($o) ?>
            </label>
            <?php endforeach; ?>
        </div>
        <div class="nav q">
            <button type="button" class="btn-nav"
                onclick="document.getElementById('action8').value='prev';
                         document.getElementById('form8').submit();">
                &larr; PREVIOUS
            </button>
            <button type="submit" class="btn-nav">NEXT &rarr;</button>
        </div>
    </div>
</form>
<?php renderDots(6, $total) ?>

<?php elseif ($step === 9): ?>
<!-- ══════════ Q6 – Textarea ══════════ -->
<form method="POST" id="form9">
    <!-- FIX: action default 'next', diubah ke 'prev' oleh tombol PREVIOUS -->
    <input type="hidden" name="action" value="next" id="action9">
    <input type="hidden" name="current_step" value="9">
    <div class="card question">
        <h2 class="dark">Apa yang membuat Anda kesulitan mendaur ulang sampah?</h2>
        <textarea name="answer" placeholder="Tulis jawaban Anda di sini..."><?=
            htmlspecialchars($answers[9] ?? '') ?></textarea>
        <div class="toolbar">
            <span>¶</span><span><strong>B</strong></span><span><em>I</em></span>
            <span><u>U</u></span><span>🔗</span><span>☰</span><span>❝</span>
            <span>—</span><span>🖼</span><span>☺</span>
        </div>
        <div class="nav q" style="margin-top:0">
            <button type="button" class="btn-nav"
                onclick="document.getElementById('action9').value='prev';
                         document.getElementById('form9').submit();">
                &larr; PREVIOUS
            </button>
            <button type="submit" class="btn-nav">NEXT &rarr;</button>
        </div>
    </div>
</form>
<?php renderDots(7, $total) ?>

<?php elseif ($step === 10): ?>
<!-- ══════════ Q7 ══════════ -->
<div class="card question">
    <h2>Jika ada yang membantu mengangkut sampah daur ulang, apa anda bersedia
        memilah sampah di rumah?*</h2>
    <div class="choices">
        <!-- FIX: Satu form, dua tombol -->
        <form method="POST">
            <input type="hidden" name="action" value="answer_go">
            <input type="hidden" name="current_step" value="10">
            <button type="submit" name="answer" value="Ya"
                class="ch <?= ($answers[10] ?? '') === 'Ya' ? 'sel' : '' ?>">Ya</button>
            <button type="submit" name="answer" value="Tidak"
                class="ch <?= ($answers[10] ?? '') === 'Tidak' ? 'sel' : '' ?>">Tidak</button>
        </form>
    </div>
    <div class="nav q">
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="prev">
            <input type="hidden" name="current_step" value="10">
            <button type="submit" class="btn-nav">&larr; PREVIOUS</button>
        </form>
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="next">
            <input type="hidden" name="current_step" value="10">
            <button type="submit" class="btn-nav">NEXT &rarr;</button>
        </form>
    </div>
</div>
<?php renderDots(8, $total) ?>

<?php elseif ($step === 11): ?>
<!-- ══════════ Q8+9 – Data Diri ══════════ -->
<form method="POST" id="form11">
    <!-- FIX: action default 'submit', JS ubah ke 'prev' saat PREVIOUS diklik -->
    <input type="hidden" name="action" value="submit" id="action11">
    <input type="hidden" name="current_step" value="11">
    <div class="card question">
        <h2 class="dark" style="font-size:15px">Jika bersedia, bergabunglah menjadi pendaur-ulang kami
            dan mulai memilah sampah dari sekarang</h2>
        <p style="font-size:12px;color:#777;margin-bottom:18px">
            Bantu kami untuk mengurangi limbah plastik dan sampah daur ulang lain dan jadilah bagian dari
            perubahan perilaku memilah sampah rumah.</p>
        <div class="row2">
            <input type="text"  name="nama"  class="fi" placeholder="Nama"
                value="<?= htmlspecialchars($answers['contact']['nama']  ?? '') ?>">
            <input type="email" name="email" class="fi" placeholder="Email"
                value="<?= htmlspecialchars($answers['contact']['email'] ?? '') ?>">
        </div>
        <input type="text" name="wa" class="fi" placeholder="WA/ Nomor Telepon"
            value="<?= htmlspecialchars($answers['contact']['wa']    ?? '') ?>">
        <input type="text" name="alamat" class="fi" placeholder="Alamat"
            value="<?= htmlspecialchars($answers['contact']['alamat'] ?? '') ?>">
        <div class="nav q" style="margin-top:8px">
            <button type="button" class="btn-nav" onclick="goStep11Prev()">
                &larr; PREVIOUS
            </button>
            <button type="submit" class="btn-submit">SUBMIT</button>
        </div>
    </div>
</form>
<?php renderDots(9, $total) ?>

<?php elseif ($step >= 12): ?>
<!-- ══════════ THANK YOU ══════════ -->
<div class="card ty">
    <?= recycleSvg(58,58) ?>
    <h1 style="margin-top:14px">Terima Kasih!</h1>
    <p>Jawaban Anda telah berhasil kami terima.<br>Mari mulai memilah sampah!</p>

    <?php if ($response_code && $db_saved): ?>
    <div class="ty-code-box"><?= htmlspecialchars($response_code) ?></div>
    <div class="ty-note">Kode Respons — simpan sebagai referensi</div>
    <?php endif; ?>

    <?php if (!$db_saved && $pdo): ?>
    <div class="db-notice">⚠️ Jawaban belum tersimpan ke server. Silakan coba lagi.</div>
    <?php endif; ?>

    <!-- Ringkasan jawaban yang masuk ke DB -->
    <?php
    $contactFinal = $answers['contact'] ?? [];
    $jenisFinal   = is_array($answers[8] ?? null) ? implode(', ', $answers[8]) : '';
    ?>
    <div class="ty-recap">
        <?php
        $recapRows = [
            'Q1 — Sampah mendesak?'    => $answers[1]  ?? '—',
            'Q2 — Paham 3R?'           => $answers[3]  ?? '—',
            'Q3 — Daur ulang di rumah?'=> $answers[6]  ?? '—',
            'Q4 — Pilah organik?'      => $answers[7]  ?? '—',
            'Q5 — Jenis daur ulang'    => $jenisFinal  ?: '—',
            'Q7 — Bersedia pilah?'     => $answers[10] ?? '—',
        ];
        if (!empty($contactFinal['nama'])) $recapRows['Nama'] = htmlspecialchars($contactFinal['nama']);
        if (!empty($contactFinal['wa']))   $recapRows['WA']   = htmlspecialchars($contactFinal['wa']);
        foreach ($recapRows as $lbl => $val): ?>
        <div class="tr-row">
            <span class="tr-lbl"><?= htmlspecialchars($lbl) ?></span>
            <span class="tr-val"><?= htmlspecialchars($val) ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <br>
    <form method="POST">
        <input type="hidden" name="action" value="restart">
        <button type="submit" class="btn-start"
            style="background:rgba(255,255,255,.2);border:2px solid rgba(255,255,255,.5)">
            Isi Lagi</button>
    </form>
</div>

<?php endif; ?>

</div><!-- /survey-wrap -->

<script>
// FIX: PREVIOUS dari step 11 (data diri) harus set action='prev' sebelum submit
function goStep11Prev() {
    document.getElementById('action11').value = 'prev';
    document.getElementById('form11').submit();
}

// Sidebar mobile
function openSidebar() {
    document.getElementById('sidebarNav').classList.add('open');
    document.getElementById('sidebarOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeSidebar() {
    document.getElementById('sidebarNav').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });
</script>
<footer class="site-footer" aria-label="Footer">
    <div class="footer-container">
        <small class="footer-text" style="display:block;">
            Manado Recycle Hub 2026&nbsp;
        </small>
        <small class="footer-text" style="display:block;">
            Images are free licensed from pexels.com.
        </small>
    </div>
</footer>
</body>
</html>
