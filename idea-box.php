<?php
require_once __DIR__ . '/include/config.php';
// ── Simpan ide ke DB jika dikirim via POST ────────────────────
$ideaSuccess = false;
$ideaError   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ide'])) {
    $ide    = trim($_POST['ide'] ?? '');
    $nama   = trim($_POST['nama'] ?? 'Anonim');
    $kontak = trim($_POST['kontak'] ?? '');
    if (strlen($ide) >= 10) {
        try {
            getDB()->prepare("INSERT INTO idea_box (nama_pengirim, nomor_wa, deskripsi_ide, status, created_at) VALUES (?,?,?,'baru',NOW())")
                   ->execute([$nama ?: 'Anonim', $kontak, $ide]);
            $ideaSuccess = true;
        } catch (Exception $e) {
            $ideaError = 'Gagal menyimpan. Silakan hubungi kami via WhatsApp.';
        }
    } else {
        $ideaError = 'Ide terlalu singkat (minimal 10 karakter).';
    }
}
// Konfigurasi halaman
$site_name = "Manado Recycle Hub";
$page_title = "Idea Box";
$whatsapp_number = "6281241092529";
$whatsapp_url = "https://wa.me/" . $whatsapp_number;
$google_font_url = "https://fonts.googleapis.com/css2?family=Comfortaa:wght@400;700&display=swap";
$logo_img = "Home.png"; // Letakkan file logo di folder yang sama
$banner_img = "ideae.jpeg"; // Letakkan file banner di folder yang sama

// Menu navigasi
$nav_items = [
    ["label" => "Home",                 "url" => "home.php",                    "active" => false],
    ["label" => "Bin Project",          "url" => "bin_project.php",             "active" => false],
    ["label" => "Blog dan Media Sosial","url" => "blog.php",                    "active" => false],
    ["label" => "Idea Box",             "url" => "idea-box.php",                "active" => true],
    ["label" => "Lokasi Kami",          "url" => "lokasi_kami.php",             "active" => false],
    ["label" => "DIY",                  "url" => "diy.php",                     "active" => false],
    ["label" => "Kuesioner",            "url" => "kuesioner.php",               "active" => false],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Punya Ide daur ulang?">
    <title><?= htmlspecialchars($site_name) ?> - <?= htmlspecialchars($page_title) ?></title>
    <link rel="icon" type="image/png" href="<?= baseUrl('Title.png') ?>">
    <link rel="stylesheet" href="<?= $google_font_url ?>">
    <style>
        /* ===== RESET & BASE ===== */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Comfortaa', sans-serif;
            background-color: #fff;
            color: #1c1c1c;
            min-height: 100vh;
        }
        a { text-decoration: none; color: inherit; }
        img { max-width: 100%; display: block; }

        /* ===== CSS VARIABLES ===== */
        :root {
            --green-primary: rgba(28, 100, 52, 1);
            --green-light: rgba(214, 228, 195, 1);
            --text-light: rgba(249, 249, 249, 1);
            --dark-bg: rgba(28, 28, 28, 1);
            --nav-height: 64px;
            --light-bg:         #ffffff;
            --text-dark:        rgba(28, 28, 28, 1);
        }

        /* ===== TOP NAVBAR ===== */
        header {
            position: fixed;
            top: 0;
            left: 0;
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

        .navbar-brand img {
            width: 36px;
            height: 36px;
            object-fit: cover;
            border-radius: 4px;
        }

        .navbar-brand .brand-name {
            font-size: 15pt;
            font-weight: 700;
            color: var(--text-light);
        }

        /* ===== DESKTOP NAV ===== */
        .navbar-nav {
            display: flex;
            list-style: none;
            gap: 4px;
        }

        .navbar-nav li a {
            font-family: 'Comfortaa', sans-serif;
            font-size: 12pt;
            font-weight: 700;
            color: var(--text-light);
            padding: 8px 14px;
            border-radius: 4px;
            transition: color 0.2s;
        }

        .navbar-nav li a:hover,
        .navbar-nav li a.active {
            color: rgba(249, 249, 249, 0.82);
        }

        .navbar-nav li a.active {
            font-weight: 700;
            border-bottom: 2px solid var(--text-light);
        }

        /* ===== HAMBURGER (Mobile) ===== */
        .hamburger {
            display: none;
            flex-direction: column;
            gap: 5px;
            cursor: pointer;
            background: none;
            border: none;
            padding: 4px;
        }
        .hamburger span {
            width: 24px;
            height: 2px;
            background: var(--text-light);
            border-radius: 2px;
            transition: 0.3s;
        }

        /* ===== MOBILE SIDEBAR NAV ===== */
        .sidebar-nav {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            height: 100%;
            width: 280px;
            background-color: var(--dark-bg);
            z-index: 200;
            padding: 48px 32px 62px 48px;
            flex-direction: column;
            gap: 16px;
            overflow-y: auto;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
        }
        .sidebar-nav.open { transform: translateX(0); }
        .sidebar-nav .sidebar-logo { margin-bottom: 24px; }
        .sidebar-nav .sidebar-logo img { width: 48px; border-radius: 4px; margin-bottom: 8px; }
        .sidebar-nav .sidebar-title {
            font-size: 20pt;
            font-weight: 700;
            color: var(--text-light);
            margin-bottom: 24px;
        }
        .sidebar-nav ul { list-style: none; display: flex; flex-direction: column; gap: 4px; }
        .sidebar-nav ul li a {
            font-size: 13pt;
            font-weight: 700;
            color: var(--text-light);
            display: block;
            padding: 8px 0;
            transition: color 0.2s;
        }
        .sidebar-nav ul li a:hover,
        .sidebar-nav ul li a.active {
            color: rgba(249, 249, 249, 0.82);
            font-weight: 700;
        }
        .sidebar-nav ul li a.active {
            border-left: 3px solid var(--text-light);
            padding-left: 8px;
        }
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 150;
        }
        .sidebar-overlay.open { display: block; }

        /* ===== HERO / BANNER SECTION ===== */
        .hero-section {
            position: relative;
            height: 660px;
            background-color: #ffffff;
            background-image: url('<?= baseUrl($banner_img) ?>');
            background-size: cover;
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
            background: rgba(28, 100, 52, 0.45);
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

        /* ===== SUBTITLE SECTION ===== */
        .subtitle-section {
            padding: 56px 0;
            text-align: center;
            max-width: 1280px;
            margin: 0 auto;
            padding-left: 2.5%;
            padding-right: 2.5%;
        }

        .subtitle-section h2 {
            font-family: 'Comfortaa', sans-serif;
            font-size: 18pt;
            font-weight: 700;
            color: rgba(106, 168, 79, 1);
            margin-bottom: 0;
        }

        /* ===== CONTENT SECTION (2 kolom) ===== */
        .content-section {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 5% 56px;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            align-items: center;
        }

        .content-image img {
            width: 100%;
            height: auto;
            object-fit: cover;
            border-radius: 8px;
        }

        .content-text p {
            font-family: 'Comfortaa', sans-serif;
            font-size: 14pt;
            font-weight: 700;
            color: #1c1c1c;
            line-height: 1.5;
            margin-bottom: 16px;
        }

        /* ===== CTA BUTTON ===== */
        .cta-wrapper {
            margin-top: 24px;
        }

        .cta-btn {
            display: inline-block;
            font-family: 'Comfortaa', sans-serif;
            font-size: 12pt;
            font-weight: 700;
            color: var(--text-light);
            background-color: var(--green-primary);
            border: 2px solid var(--green-primary);
            padding: 12px 28px;
            border-radius: 4px;
            height: 27pt;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.1s;
        }

        .cta-btn:hover {
            opacity: 0.88;
        }

        .cta-btn:active {
            transform: scale(0.98);
        }

        /* ===== FOOTER ===== */
        .site-footer {
            padding: 24px 0 32px;
            background: var(--light-bg);
        }

        .footer-text {
            font-family: 'Comfortaa', sans-serif;
            font-size: 8pt;
            font-weight: 700;
            text-align: center;
            color: var(--text-dark);
            margin-bottom: 4px;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 767px) {
            .navbar { padding: 0 20px; }
            .navbar-brand .brand-name { font-size: 13pt; }
            .navbar-nav { display: none; }
            .hamburger { display: flex; }
            .sidebar-nav { display: flex; }

            .hero-section { height: 150px; }
            .hero-content h1 { font-size: 16pt; }

            .content-grid {
                grid-template-columns: 1fr;
            }

            .content-section { padding: 0 16px 40px; }
            .subtitle-section { padding: 32px 16px; }
        }

        @media (min-width: 768px) and (max-width: 1279px) {
            .hero-content h1 { font-size: 34pt; }
            .subtitle-section h2 { font-size: 18pt; }
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
            <span></span>
            <span></span>
            <span></span>
        </button>
    </div>
</header>

<!-- ===== HERO SECTION ===== -->
<section class="hero-section">
    <div class="hero-overlay"></div>
    <div class="hero-content">
        <h1>💡 <?= htmlspecialchars($page_title) ?></h1>
    </div>
</section>

<!-- ===== SUBTITLE SECTION ===== -->
<section class="subtitle-section">
    <h2>Punya Ide daur ulang?</h2>
</section>

<!-- ===== MAIN CONTENT SECTION ===== -->
<section class="content-section">
    <div class="content-grid">

        <!-- Kolom Gambar -->
        <div class="content-image">
            <img src="<?= htmlspecialchars($banner_img) ?>" alt="Daur Ulang" role="img">
        </div>

        <!-- Kolom Teks & CTA -->
        <div class="content-text">
            <p>
                Upaya pengurangan sampah plastik tidak cukup hanya dengan recycle saja.
                Kami mendukung upaya Reuse bersama dengan ide daur ulangmu&nbsp;
            </p>
            <p>Sampaikan idemu di sini!</p>
            <p>
                Kami bisa membantumu mewujudkan idemu dengan mengumpulkan material
                daur ulang yang bisa di-reuse.
            </p>

            <?php if ($ideaSuccess): ?>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:16px;margin-top:16px;text-align:center">
                <div style="font-size:28px">✅</div>
                <p style="color:#1c6434;font-weight:700;margin-top:8px">Ide kamu sudah kami terima! Terima kasih.</p>
                <a class="cta-btn" href="idea-box.php" style="margin-top:12px;display:inline-flex">Kirim Ide Lain</a>
            </div>
            <?php elseif ($ideaError): ?>
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px;margin-top:12px;text-align:center;color:#dc2626;font-weight:700"><?= htmlspecialchars($ideaError) ?></div>
            <?php endif; ?>

            <?php if (!$ideaSuccess): ?>
            <form method="POST" action="idea-box.php" style="margin-top:20px;display:flex;flex-direction:column;gap:10px">
                <input type="text" name="nama" placeholder="Nama kamu (opsional)" maxlength="100"
                       style="padding:10px 14px;border:2px solid #e0e0e0;border-radius:6px;font-family:'Comfortaa',sans-serif;font-size:12pt;outline:none;width:100%">
                <input type="text" name="kontak" placeholder="WhatsApp / Email (opsional)" maxlength="100"
                       style="padding:10px 14px;border:2px solid #e0e0e0;border-radius:6px;font-family:'Comfortaa',sans-serif;font-size:12pt;outline:none;width:100%">
                <textarea name="ide" rows="4" required minlength="10" placeholder="Tuliskan idemu di sini... (minimal 10 karakter)"
                          style="padding:10px 14px;border:2px solid #e0e0e0;border-radius:6px;font-family:'Comfortaa',sans-serif;font-size:12pt;outline:none;width:100%;resize:vertical"></textarea>
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <button type="submit" class="cta-btn">💡 Kirim Ide Sekarang</button>
                    <a class="cta-btn" style="background:#fff;color:var(--green-primary);border:2px solid var(--green-primary)"
                       href="<?= htmlspecialchars($whatsapp_url) ?>" target="_blank" rel="noopener noreferrer">💬 via WhatsApp</a>
                </div>
            </form>
            <?php endif; ?>
        </div>

    </div>
</section>

<!-- ===== FOOTER ===== -->
<footer class="site-footer" aria-label="Footer">
    <div class="container">
        <small class="footer-text" style="display:block;">
            Manado Recycle Hub 2026&nbsp;
        </small>
        <small class="footer-text" style="display:block;">
            Images are free licensed from pexels.com.
        </small>
    </div>
</footer>

<!-- ===== JAVASCRIPT ===== -->
<script>
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

    // Tutup sidebar saat tekan ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeSidebar();
    });
</script>

</body>
</html>
