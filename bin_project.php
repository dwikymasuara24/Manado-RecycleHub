<?php
require_once __DIR__ . '/include/config.php';

$site_name  = "Manado Recycle Hub";
$page_title = "Bin Project";
$logo_img   = "Home.png";
$banner_img = "bin.png";

$nav_items = [
    ["label" => "Home",                 "url" => "home.php",                    "active" => false],
    ["label" => "Bin Project",          "url" => "bin_project.php",             "active" => true],
    ["label" => "Blog dan Media Sosial","url" => "blog.php",                    "active" => false],
    ["label" => "Idea Box",             "url" => "idea-box.php",                "active" => false],
    ["label" => "Lokasi Kami",          "url" => "lokasi_kami.php",             "active" => false],
    ["label" => "DIY",                  "url" => "diy.php",                     "active" => false],
    ["label" => "Kuesioner",            "url" => "kuesioner.php",               "active" => false],
];

$bins = [
    [
        "name" => "Bin Sario (Taman Bersejarah)",
        "location" => "Jl. Ahmad Yani, Sario Tumpaan, Kec. Sario, Kota Manado",
        "capacity" => 65,
        "status" => "Tersedia",
        "coords" => "1.4623, 124.8322"
    ],
    [
        "name" => "Bin Wenang (Megamall Area)",
        "location" => "Kawasan Megamas, Kec. Wenang, Kota Manado",
        "capacity" => 85,
        "status" => "Hampir Penuh",
        "coords" => "1.4851, 124.8365"
    ],
    [
        "name" => "Bin Tikala (Kantor Walikota)",
        "location" => "Jl. Balai Kota, Tikala Ares, Kec. Tikala, Kota Manado",
        "capacity" => 30,
        "status" => "Tersedia",
        "coords" => "1.4795, 124.8488"
    ]
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Penempatan Plastic Collection Bin - Manado Recycle Hub">
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

        /* ===== SUBTITLE ===== */
        .subtitle-section {
            padding: 48px 24px 24px;
            text-align: center;
            max-width: 1280px;
            margin: 0 auto;
        }
        .subtitle-section h2 {
            font-family: 'Comfortaa', sans-serif;
            font-size: 18pt;
            font-weight: 700;
            color: var(--green-primary);
            margin-bottom: 12px;
        }
        .subtitle-section p {
            font-size: 12pt;
            color: var(--gray);
            line-height: 1.6;
        }

        /* ===== CONTENT SECTION ===== */
        .content-section {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 48px 56px;
        }

        /* ===== BIN GRID ===== */
        .bin-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
            margin-top: 32px;
        }
        .bin-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid var(--border);
            padding: 24px;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .bin-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .bin-title {
            font-size: 13pt;
            font-weight: 700;
            color: var(--green-primary);
        }
        .bin-badge {
            font-size: 9pt;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 12px;
        }
        .bin-badge.available {
            background-color: var(--green-soft);
            color: var(--green-primary);
        }
        .bin-badge.warning {
            background-color: #fef3c7;
            color: #d97706;
        }
        .bin-info p {
            font-size: 10pt;
            color: var(--gray);
            line-height: 1.5;
            margin-bottom: 8px;
        }
        .bin-info strong {
            color: #1c1c1c;
        }
        .progress-wrap {
            margin-top: 8px;
        }
        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 9pt;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .progress-bar {
            height: 8px;
            background-color: #f3f4f6;
            border-radius: 4px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background-color: var(--green-mid);
            border-radius: 4px;
        }
        .btn-maps {
            display: block;
            text-align: center;
            background-color: var(--green-primary);
            color: #fff;
            font-weight: 700;
            font-size: 10pt;
            padding: 12px;
            border-radius: 8px;
            transition: opacity 0.2s;
            margin-top: auto;
        }
        .btn-maps:hover {
            opacity: 0.9;
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
            .content-section { padding: 0 20px 40px; }
            .subtitle-section { padding: 32px 20px; }
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
        <h1>🗑️ <?= htmlspecialchars($page_title) ?></h1>
    </div>
</section>

<!-- SUBTITLE -->
<section class="subtitle-section">
    <div class="infographic-container" style="margin-bottom: 32px; display: flex; justify-content: center;">
        <img src="botol_plastik.jpg" alt="Botol Plastik Masuk Ke Sini" style="max-width: 100%; width: 420px; border-radius: 16px; box-shadow: var(--shadow); border: 1px solid var(--border);">
    </div>
    <h2>Penempatan Plastic Collection Bin</h2>
    <p>Kami menempatkan tempat pengumpulan botol plastik PET khusus di berbagai titik strategis kota Manado guna mendukung ekosistem sirkular yang mandiri dan bersih.</p>
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        .leaflet-popup-content-wrapper, .leaflet-popup-tip {
            font-family: 'Comfortaa', sans-serif !important;
            border-radius: 12px;
        }
        .leaflet-container {
            font-family: 'Comfortaa', sans-serif !important;
        }
    </style>
    <div class="map-container" style="margin-top: 32px; display: flex; justify-content: center; flex-direction: column; align-items: center; width: 100%;">
        <div id="binMap" style="height: 480px; width: 100%; max-width: 800px; border-radius: 16px; box-shadow: var(--shadow); border: 1px solid var(--border); z-index: 1;"></div>
    </div>
    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            var map = L.map('binMap').setView([1.4748, 124.8400], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 19
            }).addTo(map);

            var bins = <?= json_encode($bins, JSON_UNESCAPED_UNICODE) ?>;
            var markersGroup = L.featureGroup();

            var greenIcon = L.divIcon({
                className: '',
                html: '<div style="background:#1c6434;color:#fff;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3)">🗑️</div>',
                iconSize: [32, 32],
                iconAnchor: [16, 16]
            });

            bins.forEach(function(bin) {
                if (bin.coords) {
                    var parts = bin.coords.split(',');
                    var lat = parseFloat(parts[0]);
                    var lng = parseFloat(parts[1]);
                    if (!isNaN(lat) && !isNaN(lng)) {
                        var marker = L.marker([lat, lng], {icon: greenIcon});
                        var popupContent = '<div style="font-family: \'Comfortaa\', sans-serif; font-size: 10pt; line-height: 1.5; min-width: 200px; padding: 4px 0;">' +
                            '<strong style="color: #1c6434; font-size: 11pt; display: block; margin-bottom: 4px;">' + bin.name + '</strong>' +
                            '<span style="color: #666; display: block; margin-bottom: 8px;">📍 ' + bin.location + '</span>' +
                            '<div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #eee; padding-top: 8px; margin-top: 4px;">' +
                            '<span>🔋 Kapasitas:</span>' +
                            '<strong style="color: ' + (bin.capacity >= 80 ? '#d97706' : '#1c6434') + '">' + bin.capacity + '%</strong>' +
                            '</div>' +
                            '<div style="font-size: 9pt; color: #888; margin-top: 4px;">Status: ' + bin.status + '</div>' +
                            '<a href="https://www.google.com/maps?q=' + encodeURIComponent(bin.coords) + '" target="_blank" style="display: inline-block; margin-top: 8px; color: #1c6434; text-decoration: underline; font-weight: bold; font-size: 9pt;">Buka di Google Maps →</a>' +
                            '</div>';
                        marker.bindPopup(popupContent);
                        markersGroup.addLayer(marker);
                    }
                }
            });

            markersGroup.addTo(map);
            if (markersGroup.getLayers().length > 0) {
                map.fitBounds(markersGroup.getBounds().pad(0.2));
            }
        });
    </script>
</section>

<!-- MAIN CONTENT -->
<div class="content-section">
    <div class="bin-grid">
        <?php foreach ($bins as $bin): ?>
        <div class="bin-card">
            <div class="bin-header">
                <h3 class="bin-title"><?= htmlspecialchars($bin['name']) ?></h3>
                <span class="bin-badge <?= $bin['capacity'] >= 80 ? 'warning' : 'available' ?>">
                    <?= htmlspecialchars($bin['status']) ?>
                </span>
            </div>
            <div class="bin-info">
                <p>📍 <strong>Alamat:</strong> <?= htmlspecialchars($bin['location']) ?></p>
                <p>🌐 <strong>Koordinat:</strong> <?= htmlspecialchars($bin['coords']) ?></p>
            </div>
            <div class="progress-wrap">
                <div class="progress-label">
                    <span>Kapasitas Bin</span>
                    <span><?= $bin['capacity'] ?>%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?= $bin['capacity'] ?>%; background-color: <?= $bin['capacity'] >= 80 ? '#d97706' : 'rgba(106, 168, 79, 1)' ?>;"></div>
                </div>
            </div>
            <a href="https://www.google.com/maps?q=<?= urlencode($bin['coords']) ?>" target="_blank" class="btn-maps">🧭 Petunjuk Rute</a>
        </div>
        <?php endforeach; ?>
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
