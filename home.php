<?php
require_once __DIR__ . '/include/config.php';
// Manado Recycle Hub - PHP Version
// Konversi dari HTML Google Sites

$site_name = "Manado Recycle Hub";
$page_title = "Home - Manado Recycle Hub";
$description = "Manado Recycle Hub adalah jasa jemput sampah daur ulang di Manado dan sekitarnya. Kami melakukan pengumpulan sampah anorganik terpilah.";

// Data navigasi
$nav_items = [
    ["label" => "Home",                 "url" => "index.php",                   "active" => true],
    ["label" => "Bin Project",          "url" => "bin_project.php",             "active" => false],
    ["label" => "Blog dan Media Sosial","url" => "blog.php",                    "active" => false],
    ["label" => "Idea Box",             "url" => "idea-box.php",                   "active" => false],
    ["label" => "Lokasi Kami",          "url" => "lokasi_kami.php",              "active" => false],
    ["label" => "DIY",                  "url" => "diy.php",                        "active" => false],
    ["label" => "Kuesioner",            "url" => "kuesioner.php",                  "active" => false],
];


// Data kategori daur ulang
$recycle_items = [
    ["image" => "hvs.png", "label" => "Kertas HVS"],
    ["image" => "kardus.png", "label" => "Kardus"],
    ["image" => "pet.png", "label" => "Botol PET"],
    ["image" => "pp.png", "label" => "Plastik PP"],
    ["image" => "hdpe.png", "label" => "Plastik HDPE/LDPE"],
    ["image" => "bukubekas.png", "label" => "Buku Bekas"],
];

$instagram_url    = "https://www.instagram.com/daurulangsekarang/";
$logo_url         = "Home.png";
$banner_img       = "https://lh3.googleusercontent.com/sitesv/AA5AbUD0EyDqS92joFFxyKABM0Ex4SbQdmdZEPHsUj_I1dKtXP-ZuOCF8xFdMk0jKN5gv8swHOC_b0B8QVZgZoq2sDz40mDMcKIAuEj4kXH4RqfoNNR5t3yM_IQ-ybSHQOID0XCwQ5VnrfCPByq0rEI6xUEb_acgtV1jRA4oJAykev3KmCx6dRl8k02bMJU=w1600";
$qr_image_url     = "Utama.png";
$card_image_url   = "Footer.jpeg";

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($description); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($site_name); ?>">
    <meta property="og:type" content="website">
    <meta property="og:description" content="<?php echo htmlspecialchars($description); ?>">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/png" href="<?= baseUrl('Title.png') ?>">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Comfortaa:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* =====================================================
           RESET & BASE
        ===================================================== */
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --primary-green:    #1c6434;
            --light-bg:         #ffffff;
            --text-dark:        rgba(28, 28, 28, 1);
            --text-light:       rgba(249, 249, 249, 1);
            --nav-bg:           rgba(28, 28, 28, 1);
            --section-padding:  56px 0;
            --font-main:        'Comfortaa', Arial, sans-serif;
            --hero-height:      340px;
            --spring-transit: cubic-bezier(0.34, 1.56, 0.64, 1);
            --smooth-transit: cubic-bezier(0.16, 1, 0.3, 1);
        }

        html { scroll-behavior: smooth; }

        @keyframes heroLogoIn {
            from { opacity: 0; transform: scale(0.82) translateY(30px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        @keyframes heroTextIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        body {
            font-family: var(--font-main);
            color: var(--text-dark);
            background: var(--light-bg);
            line-height: 1.6;
        }

        /* =====================================================
           NAVIGATION
        ===================================================== */
        .site-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 100;
            background-color: transparent;
            transition: background-color 0.3s ease, box-shadow 0.3s ease;
        }

        .site-header.scrolled {
            background-color: rgba(255, 255, 255, 0.95);
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }

        .navbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 48px;
            height: 64px;
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .nav-brand img {
            height: 32px;
            width: auto;
            border-radius: 4px;
        }

        .nav-brand span {
            font-family: var(--font-main);
            font-size: 15pt;
            font-weight: 700;
            color: #1c1c1c;
            white-space: nowrap;
        }

        .nav-links {
            display: flex;
            align-items: center;
            list-style: none;
            gap: 4px;
        }

        .nav-links a {
            font-family: var(--font-main);
            font-size: 12pt;
            font-weight: 700;
            color: #1c1c1c;
            text-decoration: none;
            padding: 6px 14px;
            border-radius: 4px;
            transition: opacity 0.2s;
        }

        .nav-links a:hover { opacity: 0.82; }

        .nav-links .active a {
            font-weight: 700;
            border-bottom: 2px solid #1c1c1c;
        }

        /* Mobile hamburger */
        .nav-toggle {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            color: #1c1c1c;
        }

        .nav-toggle svg { display: block; }

        /* =====================================================
           HERO SECTION
        ===================================================== */
        .hero-section {
            position: relative;
            height: 100vh;
            background-color: #ffffff;
            background-image: url('images/dotted_map.png');
            background-size: cover;
            background-position: center;
            margin-top: 0;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            align-items: center;
        }

        .hero-overlay {
            position: absolute;
            inset: 0;
            background: transparent;
        }

        .hero-logo {
            width: 300px;
            height: 300px;
            margin: 0 auto 24px;
            background-image: url('Utama.png');
            background-size: contain;
            background-position: center;
            background-repeat: no-repeat;
            animation: heroLogoIn 0.85s var(--spring-transit) both;
        }

        .hero-content {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 5% 100px;
            text-align: center;
        }

        .hero-title {
            font-family: var(--font-main);
            font-weight: 700;
            font-size: 48pt;
            color: #1c1c1c;
            line-height: 1.2;
            margin-bottom: 16px;
            animation: heroTextIn 0.8s var(--smooth-transit) both 0.1s;
        }

        .hero-subtitle {
            font-family: var(--font-main);
            font-weight: 700;
            font-size: 24pt;
            color: #1c1c1c;
            margin-bottom: 8px;
            line-height: 1.3;
            animation: heroTextIn 0.8s var(--smooth-transit) both 0.2s;
        }

        .hero-subtext {
            font-family: var(--font-main);
            font-weight: 700;
            font-size: 24pt;
            color: #1c1c1c;
            line-height: 1.3;
            animation: heroTextIn 0.8s var(--smooth-transit) both 0.28s;
        }

        .hero-scroll-btn {
            position: absolute;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 10;
            background: none;
            border: none;
            cursor: pointer;
            color: #718096;
            transition: color 0.2s;
            animation: bounce 2s infinite;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .hero-scroll-btn:hover { color: #1c1c1c; }

        .hero-info-icon {
            position: absolute;
            bottom: 20px;
            left: 20px;
            color: #718096;
            cursor: pointer;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateX(-50%) translateY(0);
            }
            40% {
                transform: translateX(-50%) translateY(-14px);
            }
            60% {
                transform: translateX(-50%) translateY(-7px);
            }
        }

        /* =====================================================
           GENERIC SECTION
        ===================================================== */
        .section {
            padding: var(--section-padding);
        }

        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 48px;
        }

        /* =====================================================
           INTRO TEXT
        ===================================================== */
        .intro-section {
            padding: 40px 0 20px;
        }

        .intro-section p {
            font-family: var(--font-main);
            font-size: 12pt;
            font-weight: 400;
            text-align: center;
            color: var(--text-dark);
            line-height: 1.65;
            margin-bottom: 0.5em;
        }

        /* =====================================================
           CTA BUTTON
        ===================================================== */
        .cta-section {
            padding: 20px 0 30px;
            text-align: center;
        }

        .btn-primary {
            display: inline-block;
            background-color: var(--primary-green);
            color: rgba(249, 249, 249, 1);
            font-family: var(--font-main);
            font-size: 12pt;
            font-weight: 700;
            text-decoration: none;
            padding: 14px 40px;
            border-radius: 8px;
            border: 2px solid var(--primary-green);
            transition: background-color 0.25s, border-color 0.25s, transform 0.25s var(--spring-transit), box-shadow 0.25s ease;
            cursor: pointer;
        }

        .btn-primary:hover {
            background-color: #155229;
            border-color: #155229;
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 8px 16px rgba(28,100,52,0.2);
        }
        
        .btn-primary:active {
            transform: scale(0.97);
        }

        /* =====================================================
           SOCIAL ICONS
        ===================================================== */
        .social-section {
            padding: 16px 0 30px;
        }

        .social-icons {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 18px;
        }

        .social-icons a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .social-icons img {
            width: 48px;
            height: 48px;
            transition: transform 0.2s;
        }

        .social-icons img:hover { transform: scale(1.1); }

        /* =====================================================
           DIVIDER
        ===================================================== */
        .divider-section {
            padding: 8px 0;
        }

        .divider-line {
            max-width: 1280px;
            margin: 0 auto;
            height: 2px;
            background-color: rgba(0, 0, 0, 0.15);
        }

        /* =====================================================
           RECYCLE INFO SECTION
        ===================================================== */
        .info-section {
            padding: 40px 0 20px;
        }

        .section-heading {
            font-family: var(--font-main);
            font-size: 20pt;
            font-weight: 700;
            text-align: center;
            color: var(--primary-green);
            margin-bottom: 16px;
        }

        .section-desc {
            font-family: var(--font-main);
            font-size: 12pt;
            font-weight: 400;
            text-align: center;
            color: var(--text-dark);
            margin-bottom: 0;
        }

        /* =====================================================
           RECYCLE ITEMS GRID
        ===================================================== */
        .recycle-grid-section {
            padding: 24px 0 40px;
        }

        .recycle-grid {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 24px;
        }

        .recycle-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            width: 170px;
            transition: transform 0.3s var(--spring-transit), box-shadow 0.3s;
        }

        .recycle-item:hover {
            transform: translateY(-6px) scale(1.04);
        }

        .recycle-item img {
            width: 140px;
            height: 140px;
            object-fit: contain;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
            background-color: #fcfcfc;
            border: 1px solid #eaeaea;
            padding: 10px;
        }

        .recycle-item p {
            font-family: var(--font-main);
            font-size: 11pt;
            font-weight: 700;
            text-align: center;
            color: var(--text-dark);
        }

        /* =====================================================
           NEXT STEPS SECTION (h1 big heading)
        ===================================================== */
        .heading-section {
            padding: 40px 0 10px;
        }

        .big-heading {
            font-family: var(--font-main);
            font-size: 34pt;
            font-weight: 700;
            text-align: center;
            color: var(--text-dark);
            line-height: 1.38;
        }

        /* =====================================================
           STEP CARDS
        ===================================================== */
        .steps-section {
            padding: 10px 0 40px;
        }

        .step-block {
            margin-bottom: 32px;
        }

        .step-heading {
            font-family: var(--font-main);
            font-size: 18pt;
            font-weight: 700;
            text-align: center;
            color: var(--primary-green);
            margin-bottom: 10px;
        }

        .step-desc {
            font-family: var(--font-main);
            font-size: 12pt;
            font-weight: 400;
            text-align: center;
            color: var(--text-dark);
        }

        /* =====================================================
           QR / CARD SECTION
        ===================================================== */
        .qr-section {
            padding: 24px 0 40px;
        }

        .qr-inner {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 32px;
            justify-content: center;
        }

        .qr-card img {
            width: 100%;
            max-width: 600px;
            border-radius: 8px;
            display: block;
        }

        .qr-right {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }

        .qr-right img {
            width: 180px;
            height: 180px;
            object-fit: contain;
        }

        .qr-caption {
            font-family: var(--font-main);
            font-size: 8pt;
            text-align: center;
            color: var(--text-dark);
        }

        /* =====================================================
           FOOTER
        ===================================================== */
        .site-footer {
            padding: 24px 0 32px;
            background: var(--light-bg);
        }

        .footer-text {
            font-family: var(--font-main);
            font-size: 8pt;
            font-weight: 700;
            text-align: center;
            color: var(--text-dark);
            margin-bottom: 4px;
        }

        /* =====================================================
           RESPONSIVE
        ===================================================== */
        @media (max-width: 767px) {
            :root { --hero-height: 250px; }

            .site-header {
                background-color: #ffffff !important;
                box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            }

            .navbar { padding: 0 20px; }
            .container { padding: 0 16px; }

            .nav-links { display: none; flex-direction: column; gap: 0; }
            .nav-links.open { display: flex; }
            .nav-toggle { display: block; color: #1c1c1c; }

            .navbar { flex-wrap: wrap; height: auto; padding: 12px 20px; }
            .nav-brand { margin-bottom: 0; }

            .nav-links {
                width: 100%;
                padding: 8px 0 12px;
            }
            .nav-links li { width: 100%; }
            .nav-links a { display: block; padding: 8px 4px; color: #1c1c1c; }

            .hero-section {
                height: 80vh;
            }
            .hero-title {
                font-size: 28pt;
            }
            .hero-subtitle, .hero-subtext {
                font-size: 15pt;
            }
            .hero-content {
                padding-bottom: 80px;
            }

            .hero-logo {
                width: 180px;
                height: 180px;
                margin-bottom: 16px;
            }

            .big-heading { font-size: 25pt; }
            .section-heading { font-size: 17pt; }

            .recycle-item { width: 90px; }
            .recycle-item img { width: 80px; height: 80px; }
        }

        @media (min-width: 480px) and (max-width: 767px) {
            .big-heading { font-size: 30pt; }
            .section-heading { font-size: 17pt; }
        }
    </style>
</head>
<body>

<!-- =====================================================
     HEADER / NAVIGATION
===================================================== -->
<header class="site-header">
    <nav class="navbar" role="navigation" aria-label="Navigasi Utama">

        <!-- Brand / Logo -->
        <a class="nav-brand" href="<?php echo htmlspecialchars($nav_items[0]['url']); ?>">
            <img src="<?php echo htmlspecialchars($logo_url); ?>"
                 alt="Logo Manado Recycle Hub"
                 onerror="this.style.display='none'">
            <span><?php echo htmlspecialchars($site_name); ?></span>
        </a>

        <!-- Mobile toggle -->
        <button class="nav-toggle" id="navToggle"
                aria-controls="navMenu" aria-expanded="false"
                aria-label="Buka menu navigasi">
            <svg width="24" height="24" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round">
                <line x1="3" y1="6"  x2="21" y2="6"/>
                <line x1="3" y1="12" x2="21" y2="12"/>
                <line x1="3" y1="18" x2="21" y2="18"/>
            </svg>
        </button>

        <!-- Nav links -->
        <ul class="nav-links" id="navMenu" role="menubar">
            <?php foreach ($nav_items as $item): ?>
            <li <?php if ($item['active']) echo 'class="active"'; ?> role="none">
                <a href="<?php echo htmlspecialchars($item['url']); ?>"
                   role="menuitem"
                   <?php if ($item['active']) echo 'aria-current="page"'; ?>>
                    <?php echo htmlspecialchars($item['label']); ?>
                </a>
            </li>
            <?php endforeach; ?>
            <li role="none">
                <a href="#" role="menuitem" aria-label="Cari" style="display: inline-flex; align-items: center; padding: 6px 14px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                </a>
            </li>
        </ul>

    </nav>
</header>

<!-- =====================================================
     MAIN CONTENT
===================================================== -->
<main id="main-content" tabindex="-1">

    <!-- HERO SECTION -->
    <section class="hero-section" aria-label="Banner Utama">
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <div class="hero-logo"></div>
            <h1 class="hero-title">Manado Recycle Hub</h1>
            <p class="hero-subtitle">Jasa Jemput Sampah Daur Ulang</p>
            <p class="hero-subtext">di Manado, Tomohon dan Minahasa Utara</p>
        </div>
        
        <!-- Info Icon (bottom-left) -->
        <div class="hero-info-icon" title="Informasi Latar Belakang">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="16" x2="12" y2="12"></line>
                <line x1="12" y1="8" x2="12.01" y2="8"></line>
            </svg>
        </div>

        <!-- Scroll down indicator -->
        <button class="hero-scroll-btn"
                aria-label="Scroll ke bawah"
                onclick="document.querySelector('.intro-section').scrollIntoView({behavior:'smooth'})">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 12 15 18 9"></polyline>
            </svg>
        </button>
    </section>

    <!-- INTRO TEXT -->
    <section class="intro-section" aria-label="Deskripsi Singkat">
        <div class="container">
            <p>Manado Recycle Hub adalah jasa jemput sampah daur ulang di Manado dan sekitarnya.&nbsp;</p>
            <p>Kami melakukan pengumpulan sampah anorganik terpilah.</p>
        </div>
    </section>

    <!-- CTA BUTTON: Daur Ulang Sekarang -->
    <section class="cta-section" aria-label="Ajakan Bertindak">
        <div class="container">
            <a class="btn-primary"
               href="daur_ulang.php"
               aria-label="Daur Ulang Sekarang">
                Daur Ulang Sekarang
            </a>
        </div>
    </section>

    <!-- COMMITMENT TEXT -->
    <section class="intro-section" style="padding-top: 10px;" aria-label="Komitmen Kami">
        <div class="container">
            <p>
                Dengan komitmen membantu mengurangi volume sampah perkotaan, kami siap mengumpulkan
                limbah yang bisa didaur ulang dan melakukan pemanfaatan kembali atau didistribusikan
                kepada industri daur ulang yang bertanggung jawab.
            </p>
        </div>
    </section>

    <!-- SOCIAL ICONS -->
    <section class="social-section" aria-label="Media Sosial">
        <div class="container">
            <div class="social-icons">
                <a href="<?php echo htmlspecialchars($instagram_url); ?>"
                   target="_blank"
                   rel="noopener noreferrer"
                   aria-label="Instagram Daur Ulang Sekarang">
                    <img src="Instagram.jpg"
                         alt="Instagram"
                         width="48" height="48"
                         onerror="this.alt='Instagram'">
                </a>
            </div>
        </div>
    </section>

    <!-- RECYCLE INFO SECTION -->
    <section class="info-section" aria-labelledby="recycle-heading">
        <div class="container">
            <h2 id="recycle-heading" class="section-heading">
                Apa yang bisa didaur ulang?
            </h2>
            <p class="section-desc">
                Sekarang hampir semua sampah anorganik dapat didaur ulang, seperti kertas dan plastik.&nbsp;<br>
                Dengan proses pemilahan yang baik, kami siap mengangkutnya dari rumah Anda.
            </p>
        </div>
    </section>

    <!-- RECYCLE ITEMS GRID -->
    <section class="recycle-grid-section" aria-label="Kategori Sampah Daur Ulang">
        <div class="container">
            <div class="recycle-grid">
                <?php foreach ($recycle_items as $item): ?>
                <div class="recycle-item">
                    <img src="<?php echo htmlspecialchars($item['image']); ?>"
                         alt="<?php echo htmlspecialchars($item['label']); ?>"
                         onerror="this.style.opacity='0.4'">
                    <p><?php echo htmlspecialchars($item['label']); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- HEADING: Apa yang Anda dapat lakukan selanjutnya? -->
    <section class="heading-section" aria-labelledby="next-heading">
        <div class="container">
            <h1 id="next-heading" class="big-heading">
                Apa yang Anda dapat lakukan selanjutnya?
            </h1>
        </div>
    </section>

    <!-- STEP 1: Mulai Memilah -->
    <section class="steps-section" aria-labelledby="step1-heading">
        <div class="container">
            <div class="step-block">
                <h2 id="step1-heading" class="step-heading">
                    Mulai Memilah Sampah Sekarang
                </h2>
                <p class="step-desc">
                    Sampah di rumah dapat dipilah ke dalam kategori organik dan anorganik yakni
                    sampah jenis plastik, kertas dan bahan recycable lain.&nbsp;
                </p>
            </div>
        </div>
    </section>

    <!-- CTA BUTTON: Layanan Jemput Sampah -->
    <section class="cta-section" aria-label="Layanan Jemput Sampah">
        <div class="container">
            <a class="btn-primary"
               href="cleanup_service.php"
               aria-label="Layanan Jemput Sampah">
                Layanan Jemput Sampah
            </a>
        </div>
    </section>

    <!-- STEP 2: Mulai Memilah (contact) -->
    <section class="steps-section" aria-labelledby="step2-heading">
        <div class="container">
            <div class="step-block">
                <h2 id="step2-heading" class="step-heading">
                    Mulai Memilah Sampah Sekarang
                </h2>
                <p class="step-desc">
                    Hubungi kami, Manado Recycle Hub, kami akan mengangkut sampah yang bisa kami daur ulang
                </p>
            </div>
        </div>
    </section>

    <!-- DIVIDER -->
    <section class="divider-section" aria-hidden="true">
        <div class="container">
            <div class="divider-line"></div>
        </div>
    </section>

    <!-- QR / CARD SECTION -->
    <section class="qr-section" aria-label="Informasi Kontak QR Code">
        <div class="container">
            <div class="qr-inner">
                <!-- Card Image -->
                <div class="qr-card">
                    <img src="<?php echo htmlspecialchars($card_image_url); ?>"
                         alt="Manado Recycle Hub Card"
                         onerror="this.style.opacity='0.3'">
                </div>
            </div>
        </div>
    </section>

</main><!-- /main -->

<!-- =====================================================
     FOOTER
===================================================== -->
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

<!-- =====================================================
     JAVASCRIPT
===================================================== -->
<script>
    // Mobile nav toggle
    const toggle = document.getElementById('navToggle');
    const menu   = document.getElementById('navMenu');

    if (toggle && menu) {
        toggle.addEventListener('click', function () {
            const isOpen = menu.classList.toggle('open');
            toggle.setAttribute('aria-expanded', isOpen.toString());
        });

        // Close menu when a link is clicked (mobile)
        menu.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                menu.classList.remove('open');
                toggle.setAttribute('aria-expanded', 'false');
            });
        });
    }

    // Navbar scroll effect
    window.addEventListener('scroll', function () {
        const header = document.querySelector('.site-header');
        if (header) {
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        }
    });
</script>

</body>
</html>
