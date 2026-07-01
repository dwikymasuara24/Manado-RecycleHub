<?php
require_once __DIR__ . '/include/config.php';

$site_name  = "Manado Recycle Hub";
$page_title = "Lokasi Kami";
$logo_img   = "Home.png";
$banner_img = "lokasi.png";

$nav_items = [
    ["label" => "Home",                 "url" => "home.php",                    "active" => false],
    ["label" => "Bin Project",          "url" => "bin_project.php",             "active" => false],
    ["label" => "Blog dan Media Sosial","url" => "blog.php",                    "active" => false],
    ["label" => "Idea Box",             "url" => "idea-box.php",                "active" => false],
    ["label" => "Lokasi Kami",          "url" => "lokasi_kami.php",             "active" => true],
    ["label" => "DIY",                  "url" => "diy.php",                     "active" => false],
    ["label" => "Kuesioner",            "url" => "kuesioner.php",               "active" => false],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Lokasi Kami - Manado Recycle Hub Depot">
    <title><?= htmlspecialchars($site_name) ?> - <?= htmlspecialchars($page_title) ?></title>
    <link rel="icon" type="image/png" href="<?= baseUrl('Title.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Comfortaa:wght@400;700&display=swap" rel="stylesheet">
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
            --green-primary:  rgba(28, 100, 52, 1);
            --green-light:    rgba(214, 228, 195, 1);
            --green-mid:      rgba(106, 168, 79, 1);
            --green-soft:     #e8f5ee;
            --text-light:     rgba(249, 249, 249, 1);
            --dark-bg:        rgba(28, 28, 28, 1);
            --gray:           #5C6B62;
            --border:         #D4E4DA;
            --shadow:         0 2px 16px rgba(15,28,20,.08);
            --nav-height:     64px;
            --light-bg:         #ffffff;
            --text-dark:        rgba(28, 28, 28, 1);
        }

        /* ===== TOP NAVBAR ===== */
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
        .navbar-brand img {
            height: 32px; width: auto;
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
            transition: color .2s;
        }
        .navbar-nav li a:hover,
        .navbar-nav li a.active { color: rgba(249,249,249,.82); }
        .navbar-nav li a.active {
            border-bottom: 2px solid var(--text-light);
        }

        /* ===== HAMBURGER ===== */
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
            width: 24px; height: 2px;
            background: var(--text-light);
            border-radius: 2px;
            transition: .3s;
        }

        /* ===== MOBILE SIDEBAR ===== */
        .sidebar-nav {
            display: flex;
            position: fixed;
            top: 0; left: 0;
            height: 100%; width: 280px;
            background-color: var(--dark-bg);
            z-index: 200;
            padding: 48px 32px 62px 48px;
            flex-direction: column;
            gap: 16px;
            overflow-y: auto;
            transform: translateX(-100%);
            transition: transform .3s ease;
        }
        .sidebar-nav.open { transform: translateX(0); }
        .sidebar-nav .sidebar-logo { margin-bottom: 24px; }
        .sidebar-nav .sidebar-logo img { height: 48px; border-radius: 4px; margin-bottom: 8px; }
        .sidebar-nav .sidebar-title {
            font-size: 20pt; font-weight: 700;
            color: var(--text-light);
            margin-bottom: 24px;
        }
        .sidebar-nav ul { list-style: none; display: flex; flex-direction: column; gap: 4px; }
        .sidebar-nav ul li a {
            font-size: 13pt; font-weight: 700;
            color: var(--text-light);
            display: block;
            padding: 8px 0;
            transition: color .2s;
        }
        .sidebar-nav ul li a:hover,
        .sidebar-nav ul li a.active { color: rgba(249,249,249,.82); }
        .sidebar-nav ul li a.active {
            border-left: 3px solid var(--text-light);
            padding-left: 8px;
        }
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 150;
        }
        .sidebar-overlay.open { display: block; }

        .hero-section {
            position: relative;
            height: 380px;
            background-color: #ffffff;
            background-image: url('<?= baseUrl($banner_img) ?>');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            margin-top: var(--nav-height);
            display: flex;
            align-items: center;
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
            padding: 0 7.5%;
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

        /* ===== CONTENT SECTION ===== */
        .content-section {
            max-width: 1280px;
            margin: 0 auto;
            padding: 48px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 48px;
            align-items: start;
        }

        /* ===== LEFT COLUMN ===== */
        .info-col {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .info-title {
            font-size: 20pt;
            font-weight: 700;
            color: var(--green-primary);
        }
        .info-desc {
            font-size: 11pt;
            color: var(--gray);
            line-height: 1.7;
        }
        .details-card {
            background-color: var(--green-soft);
            border-radius: 12px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .detail-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .detail-icon {
            font-size: 16pt;
            flex-shrink: 0;
        }
        .detail-text strong {
            display: block;
            font-size: 11pt;
            color: var(--green-primary);
            margin-bottom: 4px;
        }
        .detail-text p {
            font-size: 10pt;
            color: var(--gray);
            line-height: 1.5;
        }
        .btn-gmaps {
            display: inline-block;
            background-color: var(--green-primary);
            color: #fff;
            font-weight: 700;
            font-size: 11pt;
            padding: 14px 28px;
            border-radius: 8px;
            text-align: center;
            transition: opacity 0.2s;
            align-self: flex-start;
        }
        .btn-gmaps:hover {
            opacity: 0.9;
        }

        /* ===== RIGHT COLUMN (MAP) ===== */
        .map-col {
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            height: 480px;
        }
        .map-col iframe {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
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
            .hero-section { height: 180px; }
            .hero-content h1 { font-size: 18pt; }
            .content-section {
                grid-template-columns: 1fr;
                padding: 32px 20px;
                gap: 32px;
            }
            .map-col {
                height: 320px;
            }
        }
    </style>
</head>
<body>

<!-- MOBILE SIDEBAR OVERLAY -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- MOBILE SIDEBAR NAV -->
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

<!-- HEADER / NAVBAR -->
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

<!-- HERO SECTION -->
<section class="hero-section">
    <div class="hero-overlay"></div>
    <div class="hero-content">
        <h1>📍 <?= htmlspecialchars($page_title) ?></h1>
    </div>
</section>

<!-- MAIN CONTENT -->
<div class="content-section">
    <!-- Info Column -->
    <div class="info-col">
        <h2 class="info-title">Hubungi Depot Kami</h2>
        <p class="info-desc">Depot Manado Recycle Hub melayani drop-off sampah daur ulang dan koordinasi armada penjemputan wilayah kota Manado secara terpadu.</p>
        
        <div class="details-card">
            <div class="detail-item">
                <span class="detail-icon">🏭</span>
                <div class="detail-text">
                    <strong>Alamat Depot</strong>
                    <p>Manado Recycle Hub, Titiwungan Utara, Kec. Sario, Kota Manado, Sulawesi Utara</p>
                </div>
            </div>
            <div class="detail-item">
                <span class="detail-icon">⏰</span>
                <div class="detail-text">
                    <strong>Jam Operasional</strong>
                    <p>Senin – Sabtu: 08:00 – 17:00 WITA<br>Minggu: Libur</p>
                </div>
            </div>
            <div class="detail-item">
                <span class="detail-icon">📱</span>
                <div class="detail-text">
                    <strong>WhatsApp</strong>
                    <p>+62 812-4109-2529</p>
                </div>
            </div>
        </div>

        <a href="https://maps.app.goo.gl/qeSc4ue695jXRgQS6" target="_blank" class="btn-gmaps">🧭 Buka di Google Maps</a>
    </div>

    <!-- Map Column -->
    <div class="map-col">
        <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3988.829141097241!2d124.83636627585503!3d1.4650736616239127!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x32877500139b8b07%3A0xf61f65f86d2ea829!2sManado%20Recycle%20Hub!5e0!3m2!1sid!2sid!4v1716382000000!5m2!1sid!2sid" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
    </div>
</div>

<!-- FOOTER -->
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

document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeSidebar(); } });
</script>

</body>
</html>
