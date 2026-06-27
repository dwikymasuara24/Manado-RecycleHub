<?php
require_once __DIR__ . '/include/config.php';
// DIY (Do It Yourself) - Panduan Membuat Produk dari Sampah Daur Ulang

$site_name  = "Manado Recycle Hub";
$page_title = "DIY Daur Ulang";
$whatsapp_number = "6281241092529";
$whatsapp_url    = "https://wa.me/" . $whatsapp_number;
$google_font_url = "https://fonts.googleapis.com/css2?family=Comfortaa:wght@400;700&display=swap";
$logo_img   = "Home.png";
$banner_img = "diy.jpeg";

$nav_items = [
    ["label" => "Home",                  "url" => "home.php",                 "active" => false],
    ["label" => "Bin Project",           "url" => "bin_project.php",          "active" => false],
    ["label" => "Blog dan Media Sosial", "url" => "blog.php",                 "active" => false],
    ["label" => "Idea Box",              "url" => "idea-box.php",             "active" => false],
    ["label" => "Lokasi Kami",           "url" => "lokasi_kami.php",          "active" => false],
    ["label" => "DIY",                   "url" => "diy.php",                  "active" => true],
    ["label" => "Kuesioner",             "url" => "kuesioner.php",            "active" => false],
];

// ── Ambil proyek DIY dari DB jika tersedia ───────────────────
$diy_projects_db = [];
try {
    $stmt = getDB()->query("
        SELECT dp.id, dp.judul AS title, dp.deskripsi AS `desc`, dp.level_kesulitan AS level,
               dp.ikon_emoji AS icon, dp.bahan_baku AS bahan, dp.gambar_url AS image_url,
               GROUP_CONCAT(ds.urutan ORDER BY ds.urutan SEPARATOR '|||') AS step_nums,
               GROUP_CONCAT(ds.judul_langkah ORDER BY ds.urutan SEPARATOR '|||') AS step_titles,
               GROUP_CONCAT(ds.deskripsi ORDER BY ds.urutan SEPARATOR '|||') AS step_descs
        FROM diy_projects dp
        LEFT JOIN diy_steps ds ON ds.project_id=dp.id
        WHERE dp.status='published'
        GROUP BY dp.id ORDER BY dp.created_at DESC LIMIT 12
    ");
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        $steps = [];
        if ($r['step_titles']) {
            $titles = explode('|||', $r['step_titles']);
            $descs  = explode('|||', $r['step_descs']);
            foreach ($titles as $si => $st) {
                $steps[] = ['title'=>$st, 'desc'=>$descs[$si]??''];
            }
        }
        $diy_projects_db[] = [
            'icon'  => $r['icon'] ?: '♻️',
            'level' => $r['level'] ?: 'Mudah',
            'title' => $r['title'],
            'desc'  => $r['desc'],
            'bahan' => $r['bahan'] ?: '-',
            'steps' => $steps,
            'image' => $r['image_url'] ?? '',
        ];
    }
} catch (Exception $e) { /* fallback ke static */ }

// Fallback: gunakan data statis jika DB kosong
$diy_projects_static = [
    [
        "icon"   => "🏺",
        "level"  => "⭐ Mudah",
        "title"  => "Vas Bunga dari Botol Plastik",
        "desc"   => "Ubah botol plastik bekas menjadi vas bunga yang cantik dan fungsional.",
        "bahan"  => "Botol PET, cat, dekorasi",
        "steps"  => [
            ["title" => "Persiapan Bahan", "desc" => "Cuci bersih botol plastik bekas. Pastikan tidak ada sisa cairan atau bau. Siapkan cat akrilik, kuas, dan dekorasi sesuai selera."],
            ["title" => "Potong Botol",    "desc" => "Gunting atau potong bagian atas botol sesuai ketinggian vas yang diinginkan. Haluskan tepi potongan dengan amplas agar tidak tajam."],
            ["title" => "Cat dan Hiasi",   "desc" => "Cat bagian luar botol dengan warna favorit. Biarkan kering, lalu tambahkan dekorasi seperti tali rami, stiker, atau glitter."],
            ["title" => "Finishing",       "desc" => "Lapisi dengan cat clear coat agar tahan lama. Isi dengan air secukupnya dan masukkan bunga pilihan Anda."],
        ],
    ],
    [
        "icon"   => "♻️",
        "level"  => "⭐⭐ Menengah",
        "title"  => "Tas dari Plastik Bekas",
        "desc"   => "Buat tas tangan yang kuat dan stylish dari kantong plastik bekas.",
        "bahan"  => "Plastik HDPE, benang, jarum",
        "steps"  => [
            ["title" => "Kumpulkan Plastik",  "desc" => "Kumpulkan kantong plastik HDPE yang bersih. Potong menjadi strip-strip dengan lebar sekitar 2 cm menggunakan gunting tajam."],
            ["title" => "Buat Benang Plastik", "desc" => "Sambungkan strip-strip plastik dan gulung menjadi benang plastik. Gunakan teknik simpul agar sambungan kuat."],
            ["title" => "Rajut atau Anyam",    "desc" => "Gunakan jarum besar untuk merajut atau teknik anyaman dasar untuk membentuk badan tas sesuai ukuran yang diinginkan."],
            ["title" => "Tambah Handle",       "desc" => "Buat pegangan tas dari strip plastik yang dianyam lebih tebal. Jahitkan ke badan tas dengan kuat menggunakan benang nylon."],
        ],
    ],
    [
        "icon"   => "📦",
        "level"  => "⭐ Mudah",
        "title"  => "Kotak Penyimpanan dari Kardus",
        "desc"   => "Organisir ruangan dengan kotak penyimpanan unik dari kardus bekas.",
        "bahan"  => "Kardus, cat, pita",
        "steps"  => [
            ["title" => "Potong Kardus",   "desc" => "Ukur dan potong kardus sesuai dimensi kotak yang diinginkan. Gunakan penggaris dan cutter untuk hasil potongan rapi."],
            ["title" => "Rakit Kotak",     "desc" => "Lipat dan rekatkan sisi-sisi kardus menggunakan lem tembak atau double tape yang kuat. Biarkan mengering sempurna."],
            ["title" => "Lapisi dan Cat",  "desc" => "Lapisi permukaan luar dengan kertas kado atau cat sesuai selera. Ini juga berfungsi memperkuat struktur kotak."],
            ["title" => "Tambah Dekorasi", "desc" => "Hiasi dengan pita, stiker, atau label nama. Tambahkan handle dari pita kain agar mudah dibawa."],
        ],
    ],
    [
        "icon"   => "💡",
        "level"  => "⭐⭐⭐ Sulit",
        "title"  => "Lampu Hias dari Botol Kaca",
        "desc"   => "Ciptakan lampu dekoratif yang elegan dari botol kaca bekas.",
        "bahan"  => "Botol kaca, lampu LED, kabel",
        "steps"  => [
            ["title" => "Persiapan Botol",    "desc" => "Bersihkan botol kaca hingga jernih. Jika ingin efek warna, cat bagian dalam dengan cat kaca transparan berwarna."],
            ["title" => "Bor Lubang",          "desc" => "Bor lubang di dasar botol menggunakan mata bor khusus kaca. Selalu gunakan air sebagai pendingin dan kacamata pelindung."],
            ["title" => "Pasang Kabel & Lampu","desc" => "Masukkan kabel lampu melalui lubang. Pasang dudukan lampu dan bohlam LED yang hemat energi di bagian mulut botol."],
            ["title" => "Uji dan Finishing",   "desc" => "Uji rangkaian listrik sebelum digunakan. Pastikan semua sambungan aman. Letakkan di tempat yang stabil dan jauh dari jangkauan anak."],
        ],
    ],
    [
        "icon"   => "🎨",
        "level"  => "⭐⭐ Menengah",
        "title"  => "Mainan Edukatif dari Kertas Bekas",
        "desc"   => "Buat mainan interaktif dan edukatif untuk anak-anak menggunakan kertas.",
        "bahan"  => "Kertas bekas, cat, lem",
        "steps"  => [
            ["title" => "Desain Mainan",    "desc" => "Tentukan jenis mainan: puzzle, kartu edukatif, atau boneka kertas. Gambar pola di kertas bekas yang tebal atau karton."],
            ["title" => "Potong Sesuai Pola","desc" => "Gunting pola dengan rapi. Untuk puzzle, potong menjadi kepingan dengan bentuk unik yang dapat disambung."],
            ["title" => "Warnai dan Gambar", "desc" => "Cat atau warnai dengan krayon dan spidol. Tambahkan gambar, huruf, atau angka untuk nilai edukatif."],
            ["title" => "Laminasi",          "desc" => "Lapisi dengan plastik laminating atau selotip bening agar tahan lama dan tidak mudah rusak saat dimainkan anak-anak."],
        ],
    ],
    [
        "icon"   => "🌿",
        "level"  => "⭐ Mudah",
        "title"  => "Pot Tanaman dari Kardus",
        "desc"   => "Wadah ramah lingkungan untuk menanam bunga dan sayuran organik.",
        "bahan"  => "Kardus, tanah, benih",
        "steps"  => [
            ["title" => "Persiapan Kardus",  "desc" => "Lipat kardus menjadi bentuk kotak tanpa tutup. Untuk menahan air, lapisi bagian dalam dengan kantong plastik atau aluminium foil."],
            ["title" => "Isi Media Tanam",   "desc" => "Campur tanah gembur, kompos, dan sekam bakar dengan perbandingan 2:1:1. Masukkan ke dalam pot kardus hingga ¾ penuh."],
            ["title" => "Tanam Benih",       "desc" => "Buat lubang tanam dengan kedalaman sesuai jenis benih. Masukkan benih dan tutup tipis dengan tanah, siram perlahan."],
            ["title" => "Perawatan",         "desc" => "Siram setiap pagi dan letakkan di tempat yang mendapat sinar matahari cukup. Ganti pot jika kardus mulai lapuk."],
        ],
    ],
];
$diy_projects = !empty($diy_projects_db) ? $diy_projects_db : $diy_projects_static;

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Panduan membuat produk kreatif dari sampah daur ulang">
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
            width: 36px; height: 36px;
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
            display: none;
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
        .sidebar-nav .sidebar-logo img { width: 48px; border-radius: 4px; margin-bottom: 8px; }
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

        /* ===== SUBTITLE ===== */
        .subtitle-section {
            padding: 56px 2.5%;
            text-align: center;
            max-width: 1280px;
            margin: 0 auto;
        }
        .subtitle-section h2 {
            font-family: 'Comfortaa', sans-serif;
            font-size: 18pt;
            font-weight: 700;
            color: var(--green-mid);
        }

        /* ===== MAIN CONTENT ===== */
        .content-section {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 5% 56px;
        }

        /* ===== DIY GRID ===== */
        .diy-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }
        .diy-card {
            background: #fff;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: transform .2s, box-shadow .2s;
            cursor: pointer;
        }
        .diy-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 6px 24px rgba(15,28,20,.15);
        }
        .diy-card.active-card {
            border: 2px solid var(--green-primary);
        }
        .diy-card-image {
            width: 100%; height: 180px;
            background: linear-gradient(135deg, var(--green-soft), rgba(214,228,195,1));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
        }
        .diy-card-content { padding: 1.25rem; }
        .diy-card-level {
            display: inline-block;
            padding: .25rem .75rem;
            background: var(--green-light);
            color: var(--green-primary);
            border-radius: 20px;
            font-size: .72rem;
            font-weight: 700;
            margin-bottom: .5rem;
        }
        .diy-card-title {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: .4rem;
        }
        .diy-card-desc {
            font-size: .83rem;
            color: var(--gray);
            margin-bottom: .75rem;
            line-height: 1.5;
        }
        .diy-card-materials {
            font-size: .8rem;
            color: var(--gray);
            margin-bottom: .75rem;
        }
        .diy-card-materials strong { color: #1c1c1c; }
        .diy-card-btn {
            display: inline-block;
            padding: .55rem 1.1rem;
            background: var(--green-primary);
            color: #fff;
            border-radius: 6px;
            font-size: .8rem;
            font-weight: 700;
            transition: opacity .2s;
            border: none;
            cursor: pointer;
            font-family: 'Comfortaa', sans-serif;
        }
        .diy-card-btn:hover { opacity: .85; }

        /* ===== TUTORIAL PANEL ===== */
        .tutorial-panel {
            display: none;
            background: var(--green-soft);
            border-radius: 14px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            animation: fadeIn .3s ease;
        }
        .tutorial-panel.open { display: block; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .tutorial-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .tutorial-icon { font-size: 2.5rem; }
        .tutorial-header h2 { font-size: 1.3rem; font-weight: 700; }
        .tutorial-close {
            margin-left: auto;
            background: var(--green-primary);
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: .4rem .9rem;
            font-size: .8rem;
            font-weight: 700;
            cursor: pointer;
            font-family: 'Comfortaa', sans-serif;
            transition: opacity .2s;
        }
        .tutorial-close:hover { opacity: .85; }

        .diy-steps { display: flex; flex-direction: column; gap: 1.25rem; }
        .diy-step {
            display: flex;
            gap: 1rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--border);
        }
        .diy-step:last-child { border-bottom: none; padding-bottom: 0; }
        .step-num {
            flex-shrink: 0;
            width: 34px; height: 34px;
            background: var(--green-primary);
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: .9rem;
        }
        .step-body h3 { font-size: .95rem; font-weight: 700; margin-bottom: .3rem; }
        .step-body p  { font-size: .85rem; color: var(--gray); line-height: 1.7; }

        /* ===== TIPS SECTION ===== */
        .tips-section {
            background: var(--green-soft);
            border-radius: 14px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }
        .tips-section h3 {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--green-primary);
        }
        .tips-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: .75rem;
        }
        .tip-item {
            background: #fff;
            border-radius: 10px;
            padding: .85rem 1rem;
            font-size: .85rem;
            font-weight: 700;
            color: var(--green-primary);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: .5rem;
        }

        /* ===== CTA SHARE ===== */
        .cta-share {
            background: var(--green-primary);
            border-radius: 14px;
            padding: 2rem;
            text-align: center;
            color: #fff;
            margin-bottom: 3rem;
        }
        .cta-share h3 { font-size: 1.15rem; margin-bottom: .5rem; }
        .cta-share p  { font-size: .88rem; opacity: .85; margin-bottom: 1.25rem; }
        .cta-btn {
            display: inline-flex;
            align-items: center;
            font-family: 'Comfortaa', sans-serif;
            font-size: 12pt;
            font-weight: 700;
            color: var(--text-light);
            background-color: var(--green-primary);
            border: 2px solid rgba(255,255,255,.5);
            padding: 12px 28px;
            border-radius: 4px;
            cursor: pointer;
            transition: opacity .2s, transform .1s;
        }
        .cta-btn:hover  { opacity: .88; }
        .cta-btn:active { transform: scale(.98); }
        .cta-share .cta-btn {
            background: #fff;
            color: var(--green-primary);
            border-color: transparent;
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
            .content-section { padding: 0 16px 40px; }
            .subtitle-section { padding: 32px 16px; }
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
        <h1>🔨 <?= htmlspecialchars($page_title) ?></h1>
    </div>
</section>

<!-- SUBTITLE -->
<section class="subtitle-section">
    <h2>Panduan membuat produk kreatif dari sampah yang sudah dikumpulkan</h2>
</section>

<!-- MAIN CONTENT -->
<div class="content-section">

    <!-- TUTORIAL PANEL (dinamis, muncul saat klik) -->
    <div class="tutorial-panel" id="tutorialPanel">
        <div class="tutorial-header">
            <span class="tutorial-icon" id="tutorialIcon"></span>
            <h2 id="tutorialTitle"></h2>
            <button class="tutorial-close" onclick="closeTutorial()">✕ Tutup</button>
        </div>
        <div id="tutorialMainImage" style="margin-bottom:1.5rem; display:none;">
            <img src="" alt="Main Image" style="width:100%; max-height:300px; object-fit:cover; border-radius:12px; border:1px solid var(--border);">
        </div>
        <div class="diy-steps" id="tutorialSteps"></div>
    </div>

    <!-- DIY CARD GRID -->
    <h2 style="margin-bottom:1.25rem; font-size:1.2rem; font-weight:700;">📦 Proyek DIY Terpopuler</h2>
    <div class="diy-grid">
        <?php foreach ($diy_projects as $i => $p): ?>
        <div class="diy-card" id="card-<?= $i ?>">
            <div class="diy-card-image" style="<?php if (!empty($p['image'])): ?>background-image: url('<?= htmlspecialchars($p['image']) ?>'); background-size: cover; background-position: center;<?php endif; ?>">
                <?php if (empty($p['image'])): ?>
                    <?php if (strpos($p['icon'], '/') !== false || strpos($p['icon'], '.') !== false): ?>
                        <img src="<?= htmlspecialchars($p['icon']) ?>" alt="Icon" style="width:60px; height:60px; object-fit:cover; border-radius:8px;">
                    <?php else: ?>
                        <?= htmlspecialchars($p['icon']) ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="diy-card-content">
                <span class="diy-card-level"><?= htmlspecialchars($p['level']) ?></span>
                <h3 class="diy-card-title"><?= htmlspecialchars($p['title']) ?></h3>
                <p class="diy-card-desc"><?= htmlspecialchars($p['desc']) ?></p>
                <p class="diy-card-materials"><strong>Bahan:</strong> <?= htmlspecialchars($p['bahan']) ?></p>
                <button class="diy-card-btn" onclick="showTutorial(<?= $i ?>)">Lihat Tutorial</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- TIPS -->
    <div class="tips-section">
        <h3>🌟 Tips DIY Daur Ulang</h3>
        <div class="tips-grid">
            <div class="tip-item">✅ Pilih bahan berkualitas</div>
            <div class="tip-item">🛠️ Persiapkan area kerja</div>
            <div class="tip-item">⛑️ Utamakan keselamatan</div>
            <div class="tip-item">🔧 Siapkan tool yang tepat</div>
            <div class="tip-item">💡 Cari inspirasi komunitas</div>
            <div class="tip-item">♻️ Mulai dari bahan mudah</div>
        </div>
    </div>

    <!-- CTA SHARE -->
    <div class="cta-share">
        <h3>💬 Bagikan Hasil Karya Anda!</h3>
        <p>Bagikan foto hasil DIY Anda di Instagram dengan hashtag <strong>#MRHDIYChallenge</strong> dan inspirasi orang lain untuk ikut mendaur ulang.</p>
        <a class="cta-btn"
           href="https://instagram.com/daurulangsekarang"
           target="_blank"
           rel="noopener noreferrer">
            📸 Share di Instagram
        </a>
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

<!-- DATA PROYEK (PHP → JS) -->
<script>
const diyProjects = <?= json_encode($diy_projects, JSON_UNESCAPED_UNICODE) ?>;

function showTutorial(index) {
    const p = diyProjects[index];
    const iconEl = document.getElementById('tutorialIcon');
    if (p.icon && (p.icon.indexOf('/') !== -1 || p.icon.indexOf('.') !== -1)) {
        iconEl.innerHTML = `<img src="${p.icon}" alt="Icon" style="width:48px; height:48px; object-fit:cover; border-radius:8px; vertical-align:middle;">`;
    } else {
        iconEl.textContent = p.icon || '♻️';
    }
    document.getElementById('tutorialTitle').textContent = p.title;

    // Handle main image in tutorial panel
    const imgDiv = document.getElementById('tutorialMainImage');
    const imgEl = imgDiv.querySelector('img');
    if (p.image) {
        imgEl.src = p.image;
        imgDiv.style.display = 'block';
    } else {
        imgDiv.style.display = 'none';
    }

    let stepsHtml = '';
    p.steps.forEach((s, i) => {
        stepsHtml += `
            <div class="diy-step">
                <div class="step-num">${i + 1}</div>
                <div class="step-body">
                    <h3>${s.title}</h3>
                    <p>${s.desc}</p>
                </div>
            </div>`;
    });
    document.getElementById('tutorialSteps').innerHTML = stepsHtml;

    // Tandai card aktif
    document.querySelectorAll('.diy-card').forEach(c => c.classList.remove('active-card'));
    document.getElementById('card-' + index).classList.add('active-card');

    const panel = document.getElementById('tutorialPanel');
    panel.classList.add('open');
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function closeTutorial() {
    document.getElementById('tutorialPanel').classList.remove('open');
    document.querySelectorAll('.diy-card').forEach(c => c.classList.remove('active-card'));
}

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

document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeSidebar(); closeTutorial(); } });
</script>

</body>
</html>