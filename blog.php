<?php
require_once __DIR__ . '/include/config.php';
// Manado Recycle Hub - Blog dan Media Sosial
$pageTitle = "Manado Recycle Hub - Blog dan Media Sosial";
$siteTitle = "Manado Recycle Hub";
$banner_img = "medsos.jpeg";

$navLinks = [
    ["label" => "Home",               "href" => "home.php",             "active" => false],
    ["label" => "Bin Project",        "href" => "bin_project.php",      "active" => false],
    ["label" => "Blog dan Media Sosial", "href" => "blog.php",          "active" => true],
    ["label" => "Idea Box",           "href" => "idea-box.php",         "active" => false],
    ["label" => "Lokasi Kami",        "href" => "lokasi_kami.php",      "active" => false],
    ["label" => "DIY",                "href" => "diy.php",              "active" => false],
    ["label" => "Kuesioner",          "href" => "kuesioner.php",        "active" => false],
];

// ── Ambil post dari DB jika tersedia, fallback ke data statis ──
$blogPosts = [];
try {
    $stmt = getDB()->query("SELECT judul AS title, konten AS content, gambar_url AS image_url, created_at FROM blog_posts WHERE status='published' ORDER BY created_at DESC LIMIT 20");
    $dbPosts = $stmt->fetchAll();
    if ($dbPosts) {
        foreach ($dbPosts as $p) {
            $blogPosts[] = ['image'=>$p['image_url']??'', 'imageAlt'=>htmlspecialchars($p['title']), 'title'=>$p['title'], 'content'=>$p['content']];
        }
    }
} catch (Exception $e) { /* fallback ke static */ }

// Fallback data statis jika DB kosong
if (empty($blogPosts)):
$blogPosts = [
    [
        "image"    => "images/global-recycling-day.png",
        "imageAlt" => "Global Recycling Day",
        "title"    => "Global Recycling Day",
        "content"  => "<p>Kemana sampah kita berakhir? Kebanyakan ke tempat pembuangan akhir yang kapasitasnya semakin terbatas.&nbsp;</p>
                        <p>Mulai mendaur ulang berarti kita ikut membantu mengatasi masalah sampah.</p>",
    ],
    [
        "image"    => "images/hari-daur-ulang.png",
        "imageAlt" => "Hari Daur Ulang",
        "title"    => "Hari Daur Ulang",
        "content"  => "<p>Tanggal 18 Maret diperingati sebagai hari daur ulang sedunia.</p>",
    ],
    [
        "image"    => "images/do-you-recycle.jpg",
        "imageAlt" => "Do You Recycle?",
        "title"    => "Do You Recycle?",
        "content"  => "<p>Dampak pengurangan sampah akan berasa apabila kamu ikut mendaur ulang sampah di rumah. Kalau bukan kita, siapa lagi?</p>",
    ],
    [
        "image"    => "images/instagram-30-oktober-2021.jpg",
        "imageAlt" => "Instagram 30 Oktober 2021",
        "title"    => "Instagram 30 Oktober 2021",
        "content"  => "<p>Akhir minggu, saatnya bersantai.</p>
                        <p>Nikmati secangkir kopi dan mulai merencanakan kegiatan yang menyenangkan.</p>
                        <p>Ada pertanyaan tentang material yang dapat didaur ulang? Jangan ragu menghubungi
                           <a href='https://www.instagram.com/explore/tags/upcyclemdc/' target='_blank'>#</a>daurulangsekarang
                           melalui whatsapp atau kunjungi website kami.</p>
                        <p>Happy <a href='https://www.instagram.com/explore/tags/weekend/' target='_blank'>#weekend</a>, all.</p>
                        <p>
                          <a href='https://www.instagram.com/explore/tags/daurulang/' target='_blank'>#daurulang</a>
                          <a href='https://www.instagram.com/explore/tags/recycle/' target='_blank'>#recycle</a>
                          <a href='https://www.instagram.com/explore/tags/manado/' target='_blank'>#manado</a>
                        </p>
                        <p>Free image from pexels.com. Sarah Chai</p>",
    ],
    [
        "image"    => "images/instagram-25-oktober-2021.jpg",
        "imageAlt" => "Instagram 25 Oktober 2021",
        "title"    => "Instagram 25 Oktober 2021",
        "content"  => "<p>Selamat hari Senin, teman-teman.</p>
                        <p>Saatnya memulai minggu ini dengan semangat perubahan.</p>
                        <p>Mulailah memilah sampah, dan jadilah</p>
                        <p>rekan kami.</p>
                        <p>#recycle #daurulang #wastecollection #waste #sampah</p>
                        <p>Free image from pexels.com. shvets production</p>",
    ],
    [
        "image"    => "images/instagram-22-oktober-2021.jpg",
        "imageAlt" => "Instagram 22 Oktober 2021",
        "title"    => "Instagram 22 Oktober 2021",
        "content"  => "<p>Ke mana sampah daur ulang terpilah akan dibuang? Jangan biarkan berakhir di Tempat Pembuangan Akhir (TPA) sampah perkotaan.</p>
                        <p>Kini telah hadir di Kota Manado, jasa jemput sampah daur ulang bagi industri, perdagangan dan perumahan.</p>
                        <p>Mulailah memilah sampah di toko, kantor atau di rumah, dan jadilah pendaur ulang kami.</p>
                        <p>Lihat profil kami di bio dan website.</p>
                        <p>(Image adalah free picture dari pexels.com, Stan Knop)</p>",
    ],
];
endif;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="icon" type="image/png" href="<?= baseUrl('Title.png') ?>">
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>">
    <meta property="og:type" content="website">
    <meta property="og:description" content="Global Recycling Day">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Comfortaa:wght@400;700&display=swap" rel="stylesheet">
    <style>
        /* ===== CSS RESET & BASE ===== */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --green-dark:   rgba(28, 100, 52, 1);
            --green-light:  rgba(106, 168, 79, 1);
            --bg-dark:      rgba(28, 28, 28, 1);
            --text-light:   rgba(249, 249, 249, 1);
            --text-dark:    rgba(28, 28, 28, 1);
            --white:        #ffffff;
            --font-family:  'Comfortaa', Arial, sans-serif;
            --nav-height:   64px;
            --max-width:    1280px;
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: var(--font-family);
            font-size: 12pt;
            color: var(--text-dark);
            background: var(--white);
            min-height: 100vh;
        }

        a { color: inherit; text-decoration: none; }
        a:hover { text-decoration: underline; }
        img { max-width: 100%; display: block; }

        /* ===== NAVBAR ===== */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            z-index: 100;
            background-color: var(--bg-dark);
        }

        .navbar-inner {
            max-width: var(--max-width);
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            height: var(--nav-height);
            gap: 16px;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
            color: var(--text-light);
            font-size: 15pt;
            font-weight: 700;
            text-decoration: none;
        }

        .navbar-brand img {
            width: 40px;
            height: 40px;
            object-fit: contain;
            border-radius: 4px;
        }

        .navbar-brand span { white-space: nowrap; }

        .navbar-nav {
            display: flex;
            align-items: center;
            list-style: none;
            gap: 4px;
        }

        .nav-item a {
            display: block;
            padding: 6px 14px;
            color: var(--text-light);
            font-family: var(--font-family);
            font-size: 12pt;
            border-radius: 4px;
            transition: color 0.2s, background-color 0.2s;
            white-space: nowrap;
        }

        .nav-item a:hover { color: rgba(249,249,249,0.82); text-decoration: none; }
        .nav-item.active a { font-weight: 700; }

        /* Hamburger */
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
            display: block;
            width: 24px;
            height: 2px;
            background: var(--text-light);
            border-radius: 2px;
            transition: transform 0.3s, opacity 0.3s;
        }

        .mobile-nav {
            display: none;
            flex-direction: column;
            background-color: var(--bg-dark);
            padding: 8px 24px 16px;
        }
        .mobile-nav.open { display: flex; }
        .mobile-nav a {
            color: var(--text-light);
            font-family: var(--font-family);
            font-size: 13pt;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }
        .mobile-nav a:last-child { border-bottom: none; }
        .mobile-nav a.active { font-weight: 700; }

        /* ===== HERO SECTION ===== */
        .hero {
            position: relative;
            height: 250px;
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
            background: rgba(28,100,52,.45);
        }

        .hero-content {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: var(--max-width);
            margin: 0 auto;
            padding: 0 7.5% 24px;
            text-align: center;
        }

        .hero-title {
            font-family: var(--font-family);
            font-size: 26pt;
            font-weight: 700;
            color: var(--text-light);
            text-shadow: 0 2px 8px rgba(0,0,0,0.5);
            line-height: 1.38;
        }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            max-width: var(--max-width);
            margin: 0 auto;
            padding: 56px 24px;
        }

        /* ===== BLOG GRID ===== */
        .blog-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 32px;
        }

        /* ===== BLOG CARD ===== */
        .blog-card {
            background: var(--white);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08), 0 1px 4px rgba(0,0,0,0.04);
            transition: box-shadow 0.2s, transform 0.2s;
            display: flex;
            flex-direction: column;
        }

        .blog-card:hover {
            box-shadow: 0 8px 32px rgba(0,0,0,0.12), 0 2px 8px rgba(0,0,0,0.08);
            transform: translateY(-2px);
        }

        .blog-card-img-wrap {
            width: 100%;
            aspect-ratio: 4 / 3;
            overflow: hidden;
            background: #e8e8e8;
        }

        .blog-card-img-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            transition: transform 0.3s;
        }

        .blog-card:hover .blog-card-img-wrap img { transform: scale(1.03); }

        .blog-card-body {
            padding: 20px 20px 24px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            flex: 1;
        }

        .blog-card-title {
            font-family: var(--font-family);
            font-size: 12pt;
            font-weight: 700;
            color: var(--green-light);
            line-height: 1.5;
            text-align: center;
        }

        .blog-card-text {
            font-family: var(--font-family);
            font-size: 8pt;
            font-weight: 400;
            color: var(--text-dark);
            line-height: 1.2;
            text-align: center;
        }

        .blog-card-text p { margin-bottom: 6px; }
        .blog-card-text p:last-child { margin-bottom: 0; }
        .blog-card-text a { color: var(--green-dark); text-decoration: underline; }

        /* ===== FOOTER ===== */
        .site-footer {
            padding: 24px 0 32px;
            background: var(--white);
        }

        .footer-text {
            font-family: var(--font-family);
            font-size: 8pt;
            font-weight: 700;
            text-align: center;
            color: var(--text-dark);
            margin-bottom: 4px;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 767px) {
            .navbar-nav { display: none; }
            .hamburger  { display: flex; }
            .hero       { height: 150px; }
            .hero-title { font-size: 16pt; }
            .hero-content { padding: 0 7.5% 12px; }
            .main-content { padding: 32px 16px; }
            .blog-grid  { grid-template-columns: 1fr; gap: 20px; }
        }

        @media (min-width: 480px) and (max-width: 767px) {
            .blog-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

    <!-- ===== NAVIGATION ===== -->
    <header>
        <nav class="navbar">
            <div class="navbar-inner">
                <a class="navbar-brand" href="home.php">
                    <img src="Home.png" alt="daurulangsekarang" onerror="this.style.display='none'">
                    <span><?= htmlspecialchars($siteTitle) ?></span>
                </a>

                <ul class="navbar-nav">
                    <?php foreach ($navLinks as $link): ?>
                        <li class="nav-item<?= $link['active'] ? ' active' : '' ?>">
                            <a href="<?= htmlspecialchars($link['href']) ?>"
                               <?= $link['active'] ? 'aria-current="page"' : '' ?>>
                                <?= htmlspecialchars($link['label']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <button class="hamburger" id="hamburgerBtn" aria-label="Menu" aria-expanded="false">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>

            <!-- Mobile nav -->
            <nav class="mobile-nav" id="mobileNav" role="navigation">
                <?php foreach ($navLinks as $link): ?>
                    <a href="<?= htmlspecialchars($link['href']) ?>"
                       class="<?= $link['active'] ? 'active' : '' ?>"
                       <?= $link['active'] ? 'aria-current="page"' : '' ?>>
                        <?= htmlspecialchars($link['label']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </nav>
    </header>

    <!-- ===== HERO ===== -->
    <section class="hero" aria-label="Header halaman">
        <div class="hero-overlay" aria-hidden="true"></div>
        <div class="hero-content">
            <h1 class="hero-title">📰 Blog dan Media Sosial</h1>
        </div>
    </section>

    <!-- ===== BLOG POSTS ===== -->
    <main class="main-content" id="main-content">
        <div class="blog-grid">
            <?php foreach ($blogPosts as $post): ?>
                <article class="blog-card">
                    <div class="blog-card-img-wrap">
                        <?php 
                        $imageSrc = !empty($post['image']) ? htmlspecialchars($post['image']) : 'logo_square.png'; 
                        ?>
                        <img
                            src="<?= $imageSrc ?>"
                            alt="<?= htmlspecialchars($post['imageAlt']) ?>"
                            loading="lazy"
                            onerror="this.src='logo_square.png'; this.onerror=null;">
                    </div>
                    <div class="blog-card-body">
                        <h2 class="blog-card-title"><?= htmlspecialchars($post['title']) ?></h2>
                        <div class="blog-card-text">
                            <?= sanitizeRichText($post['content']) ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </main>

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

    <!-- ===== SCRIPTS ===== -->
    <script>
        // Mobile hamburger toggle
        const hamburgerBtn = document.getElementById('hamburgerBtn');
        const mobileNav    = document.getElementById('mobileNav');

        hamburgerBtn.addEventListener('click', function () {
            const isOpen = mobileNav.classList.toggle('open');
            this.setAttribute('aria-expanded', isOpen);
        });
    </script>
</body>
</html>
