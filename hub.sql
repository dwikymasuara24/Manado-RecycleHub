-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Waktu pembuatan: 01 Bulan Mei 2026 pada 03.46
-- Versi server: 10.4.32-MariaDB
-- Versi PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `hub`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `aksi` varchar(100) NOT NULL COMMENT 'e.g. login, confirm_pickup, publish_blog',
  `entitas` varchar(100) DEFAULT NULL COMMENT 'Nama tabel/entitas yang terpengaruh',
  `entitas_id` bigint(20) UNSIGNED DEFAULT NULL,
  `data_lama` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data_lama`)),
  `data_baru` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data_baru`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Log aktivitas sistem (audit trail)';

-- --------------------------------------------------------

--
-- Struktur dari tabel `blog_posts`
--

CREATE TABLE `blog_posts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `author_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Admin/Officer yang menulis',
  `judul` varchar(300) NOT NULL,
  `slug` varchar(300) NOT NULL,
  `konten` longtext DEFAULT NULL,
  `gambar_url` varchar(500) DEFAULT NULL,
  `gambar_alt` varchar(255) DEFAULT NULL,
  `sumber_gambar` varchar(255) DEFAULT NULL COMMENT 'e.g. pexels.com, Sarah Chai',
  `tags` varchar(500) DEFAULT NULL COMMENT 'Hashtag dipisah koma',
  `platform_asal` enum('website','instagram','facebook','twitter','youtube','lainnya') NOT NULL DEFAULT 'website',
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `published_at` timestamp NULL DEFAULT NULL,
  `view_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Artikel blog dan konten media sosial';

-- --------------------------------------------------------

--
-- Struktur dari tabel `diy_projects`
--

CREATE TABLE `diy_projects` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `author_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Admin yang menambahkan',
  `judul` varchar(300) NOT NULL,
  `slug` varchar(300) NOT NULL,
  `deskripsi` text DEFAULT NULL,
  `ikon_emoji` varchar(10) DEFAULT NULL,
  `level_kesulitan` enum('mudah','menengah','sulit') NOT NULL DEFAULT 'mudah',
  `bahan_baku` varchar(500) DEFAULT NULL,
  `gambar_url` varchar(500) DEFAULT NULL,
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `view_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Panduan proyek DIY daur ulang';

-- --------------------------------------------------------

--
-- Struktur dari tabel `diy_project_categories`
--

CREATE TABLE `diy_project_categories` (
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `category_id` smallint(5) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `diy_steps`
--

CREATE TABLE `diy_steps` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `urutan` tinyint(3) UNSIGNED NOT NULL,
  `judul_langkah` varchar(200) NOT NULL,
  `deskripsi` text NOT NULL,
  `gambar_url` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Langkah-langkah proyek DIY';

-- --------------------------------------------------------

--
-- Struktur dari tabel `idea_box`
--

CREATE TABLE `idea_box` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Bisa anonim via WA',
  `nama_pengirim` varchar(150) NOT NULL,
  `nomor_wa` varchar(30) DEFAULT NULL,
  `judul_ide` varchar(300) DEFAULT NULL,
  `deskripsi_ide` text NOT NULL,
  `jenis_material` varchar(255) DEFAULT NULL,
  `gambar_url` varchar(500) DEFAULT NULL,
  `status` enum('baru','ditinjau','disetujui','ditolak','direalisasi') NOT NULL DEFAULT 'baru',
  `admin_id` bigint(20) UNSIGNED DEFAULT NULL,
  `catatan_admin` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Kotak ide daur ulang dari masyarakat';

-- --------------------------------------------------------

--
-- Struktur dari tabel `mitra`
--

CREATE TABLE `mitra` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `nama_perusahaan` varchar(200) NOT NULL,
  `jenis_usaha` varchar(150) DEFAULT NULL,
  `npwp` varchar(30) DEFAULT NULL,
  `alamat_kantor` text DEFAULT NULL,
  `kota` varchar(100) DEFAULT NULL,
  `kontak_pic` varchar(150) DEFAULT NULL,
  `telepon_kantor` varchar(30) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `terverifikasi` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Detail profil mitra industri';

-- --------------------------------------------------------

--
-- Struktur dari tabel `mitra_distributions`
--

CREATE TABLE `mitra_distributions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `mitra_id` bigint(20) UNSIGNED NOT NULL,
  `admin_id` bigint(20) UNSIGNED NOT NULL,
  `tanggal` date NOT NULL,
  `catatan` text DEFAULT NULL,
  `total_berat_kg` decimal(10,2) DEFAULT NULL,
  `status` enum('draft','dikirim','diterima','selesai') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Distribusi sampah terkumpul ke mitra industri';

-- --------------------------------------------------------

--
-- Struktur dari tabel `mitra_distribution_items`
--

CREATE TABLE `mitra_distribution_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `distribution_id` bigint(20) UNSIGNED NOT NULL,
  `category_id` smallint(5) UNSIGNED NOT NULL,
  `berat_kg` decimal(10,2) NOT NULL,
  `harga_per_kg` decimal(12,2) DEFAULT NULL,
  `catatan` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `judul` varchar(255) NOT NULL,
  `pesan` text NOT NULL,
  `tipe` enum('pickup','idea','diy','blog','sistem','kuesioner') NOT NULL DEFAULT 'sistem',
  `referensi_id` bigint(20) UNSIGNED DEFAULT NULL,
  `referensi_tipe` varchar(50) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Notifikasi semua pengguna';

-- --------------------------------------------------------

--
-- Struktur dari tabel `officers`
--

CREATE TABLE `officers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `officer_code` varchar(20) DEFAULT NULL COMMENT 'Kode unik petugas, e.g. OFF0001',
  `nama` varchar(150) DEFAULT NULL COMMENT 'Nama lengkap petugas (denormalized untuk display cepat)',
  `nip` varchar(50) DEFAULT NULL,
  `zona_tugas` varchar(150) DEFAULT NULL,
  `kendaraan` varchar(100) DEFAULT NULL,
  `status` enum('aktif','cuti','nonaktif') NOT NULL DEFAULT 'aktif',
  `tanggal_bergabung` date DEFAULT NULL,
  `catatan` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Detail profil petugas lapangan';

-- --------------------------------------------------------

--
-- Struktur dari tabel `officer_schedules`
--

CREATE TABLE `officer_schedules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `officer_id` bigint(20) UNSIGNED NOT NULL,
  `pickup_id` bigint(20) UNSIGNED NOT NULL,
  `tanggal_tugas` date NOT NULL,
  `jam_mulai` time DEFAULT NULL,
  `jam_selesai` time DEFAULT NULL,
  `status` enum('terjadwal','dalam_perjalanan','tiba','selesai','batal') NOT NULL DEFAULT 'terjadwal',
  `latitude_pickup` decimal(10,8) DEFAULT NULL,
  `longitude_pickup` decimal(11,8) DEFAULT NULL,
  `catatan` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Jadwal tugas petugas lapangan';

-- --------------------------------------------------------

--
-- Struktur dari tabel `pickup_requests`
--

CREATE TABLE `pickup_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `request_code` varchar(20) NOT NULL DEFAULT '' COMMENT 'Kode unik request, format MRH########, di-generate via trigger',
  `user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'NULL jika guest',
  `nama_pemohon` varchar(150) NOT NULL,
  `area_code` varchar(10) NOT NULL DEFAULT '+62',
  `nomor_wa` varchar(30) NOT NULL,
  `alamat_jemput` text DEFAULT NULL,
  `kecamatan` varchar(100) DEFAULT NULL,
  `kelurahan` varchar(100) DEFAULT NULL,
  `catatan` text DEFAULT NULL,
  `status` enum('menunggu','dikonfirmasi','dijadwalkan','sedang_diproses','selesai','dibatalkan') NOT NULL DEFAULT 'menunggu',
  `officer_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Petugas yang ditugaskan',
  `tanggal_jemput` date DEFAULT NULL,
  `jam_jemput` time DEFAULT NULL,
  `catatan_officer` text DEFAULT NULL,
  `berat_total_kg` decimal(8,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Permintaan jemput sampah dari warga/mitra';

--
-- Dumping data untuk tabel `pickup_requests`
--

INSERT INTO `pickup_requests` (`id`, `request_code`, `user_id`, `nama_pemohon`, `area_code`, `nomor_wa`, `alamat_jemput`, `kecamatan`, `kelurahan`, `catatan`, `status`, `officer_id`, `tanggal_jemput`, `jam_jemput`, `catatan_officer`, `berat_total_kg`, `created_at`, `updated_at`, `confirmed_at`, `completed_at`) VALUES
(1, 'MRH34322926', NULL, 'Budi Santoso', '+62', '81234567890', 'Jl. Bougainville No. 12', 'Wenang', 'Wenang Selatan', NULL, 'menunggu', NULL, NULL, NULL, NULL, NULL, '2026-04-28 08:10:00', '2026-05-01 01:32:59', NULL, NULL),
(2, 'MRH23446492', NULL, 'Sari Wulandari', '+62', '82345678901', 'Jl. Pierre Tendean No. 5', 'Sario', 'Sario Utara', 'Tolong datang pagi ya', 'dikonfirmasi', NULL, '2026-05-02', '09:00:00', NULL, NULL, '2026-04-28 09:30:00', '2026-05-01 01:32:59', NULL, NULL),
(3, 'MRH94263685', NULL, 'Andi Pratama', '+62', '83456789012', 'Jl. Sudirman No. 77', 'Tikala', 'Tikala Ares', NULL, 'dijadwalkan', NULL, '2026-05-03', '10:00:00', NULL, NULL, '2026-04-29 07:45:00', '2026-05-01 01:32:59', NULL, NULL),
(4, 'MRH30978953', NULL, 'Maria Tumembouw', '+62', '84567890123', 'Jl. Sarapung No. 3', 'Malalayang', 'Malalayang Satu', 'Ada tangga, harap berhati-hati', 'sedang_diproses', NULL, '2026-04-30', '08:30:00', NULL, NULL, '2026-04-29 10:00:00', '2026-05-01 01:32:59', NULL, NULL),
(5, 'MRH42103712', NULL, 'John Ruru', '+62', '85678901234', 'Jl. Diponegoro No. 20', 'Wanea', 'Teling Bawah', NULL, 'selesai', NULL, '2026-04-27', '11:00:00', NULL, NULL, '2026-04-26 14:20:00', '2026-05-01 01:32:59', NULL, NULL),
(6, 'MRH17581703', NULL, 'Lina Sumual', '+62', '86789012345', 'Jl. Walanda Maramis No. 8', 'Mapanget', 'Paniki Bawah', 'Banyak kardus dan plastik', 'menunggu', NULL, NULL, NULL, NULL, NULL, '2026-04-30 11:00:00', '2026-05-01 01:32:59', NULL, NULL),
(7, 'MRH41597382', NULL, 'Rudi Karundeng', '+62', '87890123456', 'Jl. A. A. Maramis No. 15', 'Bunaken', 'Molas', NULL, 'dibatalkan', NULL, '2026-04-29', '14:00:00', NULL, NULL, '2026-04-27 16:30:00', '2026-05-01 01:32:59', NULL, NULL),
(8, 'MRH55241805', NULL, 'Yanti Kawulusan', '+62', '88901234567', 'Jl. Trans Sulawesi No. 1', 'Tuminting', 'Tuminting', 'Tolong hubungi 30 menit sebelum', 'menunggu', NULL, NULL, NULL, NULL, NULL, '2026-04-30 13:15:00', '2026-05-01 01:32:59', NULL, NULL),
(9, 'MRH51416884', NULL, 'Hendra Polii', '+62', '89012345678', 'Jl. Ringroad No. 45', 'Mapanget', 'Kairagi Satu', NULL, 'dikonfirmasi', NULL, '2026-05-04', '09:30:00', NULL, NULL, '2026-04-30 15:00:00', '2026-05-01 01:32:59', NULL, NULL),
(10, 'MRH81359009', NULL, 'Dewi Pangau', '+62', '81123456789', 'Jl. Veteran No. 66', 'Singkil', 'Singkil Satu', 'Elektronik bekas dan logam', 'dijadwalkan', NULL, '2026-05-05', '08:00:00', NULL, NULL, '2026-04-30 16:45:00', '2026-05-01 01:32:59', NULL, NULL);

--
-- Trigger `pickup_requests`
--
DELIMITER $$
CREATE TRIGGER `trg_pickup_request_code` BEFORE INSERT ON `pickup_requests` FOR EACH ROW BEGIN
  DECLARE v_code   VARCHAR(20);
  DECLARE v_exists INT DEFAULT 1;

  -- Hanya generate jika request_code belum diisi
  IF NEW.request_code IS NULL OR NEW.request_code = '' THEN
    -- Loop sampai kode unik ditemukan
    WHILE v_exists > 0 DO
      -- FLOOR(RAND() * 90000000) + 10000000  => 10000000–99999999 (8 digit pasti)
      SET v_code = CONCAT('MRH', LPAD(FLOOR(RAND() * 90000000) + 10000000, 8, '0'));

      SELECT COUNT(*) INTO v_exists
        FROM pickup_requests
       WHERE request_code = v_code;
    END WHILE;

    SET NEW.request_code = v_code;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Struktur dari tabel `pickup_request_items`
--

CREATE TABLE `pickup_request_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `pickup_id` bigint(20) UNSIGNED NOT NULL,
  `category_id` smallint(5) UNSIGNED NOT NULL,
  `estimasi_kg` decimal(8,2) DEFAULT NULL,
  `aktual_kg` decimal(8,2) DEFAULT NULL,
  `catatan` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Jenis sampah per permintaan jemput';

--
-- Dumping data untuk tabel `pickup_request_items`
--

INSERT INTO `pickup_request_items` (`id`, `pickup_id`, `category_id`, `estimasi_kg`, `aktual_kg`, `catatan`) VALUES
(1, 1, 1, 5.00, NULL, 'Kertas HVS'),
(2, 1, 3, 3.50, NULL, 'Botol plastik'),
(3, 2, 2, 8.00, NULL, 'Kardus'),
(4, 2, 5, 2.00, NULL, 'Kantong plastik'),
(5, 3, 10, 4.00, NULL, 'Kaleng minuman'),
(6, 4, 4, 1.50, NULL, 'Gelas plastik cup'),
(7, 4, 9, 6.00, NULL, 'Besi tua'),
(8, 5, 6, 3.00, NULL, 'Buku bekas'),
(9, 5, 8, 2.50, NULL, 'Koran lama'),
(10, 6, 2, 12.00, NULL, 'Kardus besar'),
(11, 6, 5, 5.00, NULL, 'Plastik kemasan'),
(12, 7, 11, 4.50, NULL, 'Botol kaca'),
(13, 8, 3, 2.00, NULL, 'Botol minuman'),
(14, 8, 10, 1.50, NULL, 'Kaleng cat'),
(15, 9, 2, 7.00, NULL, 'Kardus tebal'),
(16, 10, 12, 8.50, NULL, 'Laptop rusak'),
(17, 10, 9, 5.00, NULL, 'Besi/logam');

-- --------------------------------------------------------

--
-- Struktur dari tabel `roles`
--

CREATE TABLE `roles` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `name` enum('admin','officer','warga','mitra') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Peran pengguna sistem';

--
-- Dumping data untuk tabel `roles`
--

INSERT INTO `roles` (`id`, `name`, `description`, `created_at`) VALUES
(1, 'admin', 'Administrator sistem dengan akses penuh', '2026-04-30 12:11:54'),
(2, 'officer', 'Petugas lapangan penjemputan sampah', '2026-04-30 12:11:54'),
(3, 'warga', 'Warga/rumah tangga pengguna layanan', '2026-04-30 12:11:54'),
(4, 'mitra', 'Mitra industri daur ulang', '2026-04-30 12:11:54');

-- --------------------------------------------------------

--
-- Struktur dari tabel `site_settings`
--

CREATE TABLE `site_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `deskripsi` varchar(255) DEFAULT NULL,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Konfigurasi global aplikasi';

--
-- Dumping data untuk tabel `site_settings`
--

INSERT INTO `site_settings` (`setting_key`, `setting_value`, `deskripsi`, `updated_by`, `updated_at`) VALUES
('footer_text', 'Google Sites 2021', 'Teks footer', NULL, '2026-04-30 12:11:54'),
('google_font_url', 'https://fonts.googleapis.com/css2?family=Comfortaa:wght@400;700&display=swap', 'URL Google Font', NULL, '2026-04-30 12:11:54'),
('instagram_url', 'https://www.instagram.com/daurulangsekarang/', 'URL Instagram', NULL, '2026-04-30 12:11:54'),
('linktree_url', 'https://linktr.ee/daurulangsekarang', 'URL Linktree', NULL, '2026-04-30 12:11:54'),
('max_pickup_per_day', '20', 'Maks. permintaan jemput per hari per officer', NULL, '2026-04-30 12:11:54'),
('pickup_auto_confirm', '0', '1 = konfirmasi otomatis permintaan jemput', NULL, '2026-04-30 12:11:54'),
('site_name', 'Manado Recycle Hub', 'Nama situs', NULL, '2026-04-30 12:11:54'),
('whatsapp_number', '6281241092529', 'Nomor WhatsApp utama', NULL, '2026-04-30 12:11:54');

-- --------------------------------------------------------

--
-- Struktur dari tabel `survey_responses`
--

CREATE TABLE `survey_responses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `nama` varchar(150) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `nomor_wa` varchar(30) DEFAULT NULL,
  `alamat` text DEFAULT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `q1_sampah_mendesak` enum('Ya','Tidak') DEFAULT NULL,
  `q2_paham_3r` enum('Ya','Tidak') DEFAULT NULL,
  `q3_daur_ulang_rumah` enum('Ya','Tidak') DEFAULT NULL,
  `q4_pilah_organik_anorganik` enum('Ya','Tidak') DEFAULT NULL,
  `q5_jenis_sampah_didaur_ulang` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`q5_jenis_sampah_didaur_ulang`)),
  `q6_kesulitan_daur_ulang` text DEFAULT NULL,
  `q7_bersedia_pilah` enum('Ya','Tidak') DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `session_id` varchar(191) DEFAULT NULL,
  `status` enum('selesai','tidak_selesai') NOT NULL DEFAULT 'selesai',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Jawaban kuesioner warga';

-- --------------------------------------------------------

--
-- Struktur dari tabel `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `role_id` tinyint(3) UNSIGNED NOT NULL,
  `nama` varchar(150) NOT NULL,
  `email` varchar(191) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `nomor_wa` varchar(20) DEFAULT NULL,
  `alamat` text DEFAULT NULL,
  `kota` varchar(100) DEFAULT 'Manado',
  `kecamatan` varchar(100) DEFAULT NULL,
  `kelurahan` varchar(100) DEFAULT NULL,
  `foto_profil` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Master semua pengguna';

--
-- Dumping data untuk tabel `users`
--

INSERT INTO `users` (`id`, `role_id`, `nama`, `email`, `password_hash`, `nomor_wa`, `alamat`, `kota`, `kecamatan`, `kelurahan`, `foto_profil`, `is_active`, `email_verified`, `last_login_at`, `created_at`, `updated_at`) VALUES
(1, 1, 'Super Admin MRH', 'admin@manadurecyclehub.id', '$2y$12$ExampleHashChangeThisBeforeDeployment1234567890ABCDE', '6281241092529', NULL, 'Manado', NULL, NULL, NULL, 1, 1, NULL, '2026-04-30 12:11:54', '2026-04-30 12:11:54');

-- --------------------------------------------------------

--
-- Struktur dari tabel `waste_categories`
--

CREATE TABLE `waste_categories` (
  `id` smallint(5) UNSIGNED NOT NULL,
  `kode` varchar(20) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `deskripsi` text DEFAULT NULL,
  `ikon_emoji` varchar(10) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Kategori jenis sampah daur ulang';

--
-- Dumping data untuk tabel `waste_categories`
--

INSERT INTO `waste_categories` (`id`, `kode`, `name`, `deskripsi`, `ikon_emoji`, `is_active`, `created_at`) VALUES
(1, 'kertas_hvs', 'Kertas HVS', 'Kertas cetak/fotokopi bekas', '📄', 1, '2026-04-30 12:11:54'),
(2, 'kardus', 'Kardus', 'Karton/kardus bekas kemasan', '📦', 1, '2026-04-30 12:11:54'),
(3, 'botol_plastik', 'Botol Plastik', 'Botol PET bekas minuman', '🍶', 1, '2026-04-30 12:11:54'),
(4, 'gelas_plastik', 'Gelas Plastik', 'Gelas plastik/cup minuman', '🥤', 1, '2026-04-30 12:11:54'),
(5, 'plastik_lain', 'Plastik Lain', 'Plastik PP, HDPE, LDPE, kantong', '🛍️', 1, '2026-04-30 12:11:54'),
(6, 'buku_bekas', 'Buku Bekas', 'Buku, majalah, koran bekas', '📚', 1, '2026-04-30 12:11:54'),
(7, 'furniture_bekas', 'Furniture Bekas', 'Perabot rumah tangga bekas', '🪑', 1, '2026-04-30 12:11:54'),
(8, 'kertas_koran', 'Koran/Majalah', 'Kertas koran dan majalah bekas', '📰', 1, '2026-04-30 12:11:54'),
(9, 'logam', 'Logam', 'Besi, aluminium, tembaga bekas', '🔩', 1, '2026-04-30 12:11:54'),
(10, 'kaleng', 'Kaleng', 'Kaleng minuman/makanan bekas', '🥫', 1, '2026-04-30 12:11:54'),
(11, 'kaca', 'Kaca/Botol Kaca', 'Botol kaca dan pecah belah', '🍾', 1, '2026-04-30 12:11:54'),
(12, 'elektronik', 'Elektronik/E-Waste', 'Barang elektronik bekas', '💻', 1, '2026-04-30 12:11:54');

--
-- Indexes for dumped tables
--

--
-- Indeks untuk tabel `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_log_user` (`user_id`),
  ADD KEY `idx_log_aksi` (`aksi`),
  ADD KEY `idx_log_entitas` (`entitas`,`entitas_id`);

--
-- Indeks untuk tabel `blog_posts`
--
ALTER TABLE `blog_posts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_blog_slug` (`slug`),
  ADD KEY `idx_blog_author` (`author_id`),
  ADD KEY `idx_blog_status` (`status`);

--
-- Indeks untuk tabel `diy_projects`
--
ALTER TABLE `diy_projects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_diy_slug` (`slug`),
  ADD KEY `idx_diy_author` (`author_id`);

--
-- Indeks untuk tabel `diy_project_categories`
--
ALTER TABLE `diy_project_categories`
  ADD PRIMARY KEY (`project_id`,`category_id`),
  ADD KEY `fk_diycat_category` (`category_id`);

--
-- Indeks untuk tabel `diy_steps`
--
ALTER TABLE `diy_steps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_diystep_project` (`project_id`);

--
-- Indeks untuk tabel `idea_box`
--
ALTER TABLE `idea_box`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_idea_user` (`user_id`),
  ADD KEY `idx_idea_admin` (`admin_id`),
  ADD KEY `idx_idea_status` (`status`);

--
-- Indeks untuk tabel `mitra`
--
ALTER TABLE `mitra`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_mitra_user` (`user_id`);

--
-- Indeks untuk tabel `mitra_distributions`
--
ALTER TABLE `mitra_distributions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dist_mitra` (`mitra_id`),
  ADD KEY `idx_dist_admin` (`admin_id`);

--
-- Indeks untuk tabel `mitra_distribution_items`
--
ALTER TABLE `mitra_distribution_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_distitem_dist` (`distribution_id`),
  ADD KEY `fk_distitem_cat` (`category_id`);

--
-- Indeks untuk tabel `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notif_user` (`user_id`),
  ADD KEY `idx_notif_unread` (`user_id`,`is_read`);

--
-- Indeks untuk tabel `officers`
--
ALTER TABLE `officers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_officers_user` (`user_id`),
  ADD UNIQUE KEY `uq_officers_code` (`officer_code`);

--
-- Indeks untuk tabel `officer_schedules`
--
ALTER TABLE `officer_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sched_officer` (`officer_id`),
  ADD KEY `idx_sched_pickup` (`pickup_id`),
  ADD KEY `idx_sched_tgl` (`tanggal_tugas`);

--
-- Indeks untuk tabel `pickup_requests`
--
ALTER TABLE `pickup_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pr_request_code` (`request_code`),
  ADD KEY `idx_pr_user` (`user_id`),
  ADD KEY `idx_pr_officer` (`officer_id`),
  ADD KEY `idx_pr_status` (`status`),
  ADD KEY `idx_pr_created` (`created_at`);

--
-- Indeks untuk tabel `pickup_request_items`
--
ALTER TABLE `pickup_request_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pri_pickup` (`pickup_id`),
  ADD KEY `idx_pri_category` (`category_id`);

--
-- Indeks untuk tabel `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_roles_name` (`name`);

--
-- Indeks untuk tabel `site_settings`
--
ALTER TABLE `site_settings`
  ADD PRIMARY KEY (`setting_key`),
  ADD KEY `fk_settings_user` (`updated_by`);

--
-- Indeks untuk tabel `survey_responses`
--
ALTER TABLE `survey_responses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_surv_user` (`user_id`),
  ADD KEY `idx_surv_email` (`email`);

--
-- Indeks untuk tabel `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD KEY `idx_users_role` (`role_id`);

--
-- Indeks untuk tabel `waste_categories`
--
ALTER TABLE `waste_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_waste_cat_kode` (`kode`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `blog_posts`
--
ALTER TABLE `blog_posts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `diy_projects`
--
ALTER TABLE `diy_projects`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `diy_steps`
--
ALTER TABLE `diy_steps`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `idea_box`
--
ALTER TABLE `idea_box`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `mitra`
--
ALTER TABLE `mitra`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `mitra_distributions`
--
ALTER TABLE `mitra_distributions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `mitra_distribution_items`
--
ALTER TABLE `mitra_distribution_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `officers`
--
ALTER TABLE `officers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `officer_schedules`
--
ALTER TABLE `officer_schedules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `pickup_requests`
--
ALTER TABLE `pickup_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT untuk tabel `pickup_request_items`
--
ALTER TABLE `pickup_request_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT untuk tabel `roles`
--
ALTER TABLE `roles`
  MODIFY `id` tinyint(3) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT untuk tabel `survey_responses`
--
ALTER TABLE `survey_responses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `waste_categories`
--
ALTER TABLE `waste_categories`
  MODIFY `id` smallint(5) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- Ketidakleluasaan untuk tabel pelimpahan (Dumped Tables)
--

--
-- Ketidakleluasaan untuk tabel `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Ketidakleluasaan untuk tabel `blog_posts`
--
ALTER TABLE `blog_posts`
  ADD CONSTRAINT `fk_blog_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`);

--
-- Ketidakleluasaan untuk tabel `diy_projects`
--
ALTER TABLE `diy_projects`
  ADD CONSTRAINT `fk_diy_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`);

--
-- Ketidakleluasaan untuk tabel `diy_project_categories`
--
ALTER TABLE `diy_project_categories`
  ADD CONSTRAINT `fk_diycat_category` FOREIGN KEY (`category_id`) REFERENCES `waste_categories` (`id`),
  ADD CONSTRAINT `fk_diycat_project` FOREIGN KEY (`project_id`) REFERENCES `diy_projects` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `diy_steps`
--
ALTER TABLE `diy_steps`
  ADD CONSTRAINT `fk_diystep_project` FOREIGN KEY (`project_id`) REFERENCES `diy_projects` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `idea_box`
--
ALTER TABLE `idea_box`
  ADD CONSTRAINT `fk_idea_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_idea_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Ketidakleluasaan untuk tabel `mitra`
--
ALTER TABLE `mitra`
  ADD CONSTRAINT `fk_mitra_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `mitra_distributions`
--
ALTER TABLE `mitra_distributions`
  ADD CONSTRAINT `fk_dist_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_dist_mitra` FOREIGN KEY (`mitra_id`) REFERENCES `mitra` (`id`);

--
-- Ketidakleluasaan untuk tabel `mitra_distribution_items`
--
ALTER TABLE `mitra_distribution_items`
  ADD CONSTRAINT `fk_distitem_cat` FOREIGN KEY (`category_id`) REFERENCES `waste_categories` (`id`),
  ADD CONSTRAINT `fk_distitem_dist` FOREIGN KEY (`distribution_id`) REFERENCES `mitra_distributions` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `officers`
--
ALTER TABLE `officers`
  ADD CONSTRAINT `fk_officers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `officer_schedules`
--
ALTER TABLE `officer_schedules`
  ADD CONSTRAINT `fk_sched_officer` FOREIGN KEY (`officer_id`) REFERENCES `officers` (`id`),
  ADD CONSTRAINT `fk_sched_pickup` FOREIGN KEY (`pickup_id`) REFERENCES `pickup_requests` (`id`);

--
-- Ketidakleluasaan untuk tabel `pickup_requests`
--
ALTER TABLE `pickup_requests`
  ADD CONSTRAINT `fk_pr_officer` FOREIGN KEY (`officer_id`) REFERENCES `officers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Ketidakleluasaan untuk tabel `pickup_request_items`
--
ALTER TABLE `pickup_request_items`
  ADD CONSTRAINT `fk_pri_category` FOREIGN KEY (`category_id`) REFERENCES `waste_categories` (`id`),
  ADD CONSTRAINT `fk_pri_pickup` FOREIGN KEY (`pickup_id`) REFERENCES `pickup_requests` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `site_settings`
--
ALTER TABLE `site_settings`
  ADD CONSTRAINT `fk_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Ketidakleluasaan untuk tabel `survey_responses`
--
ALTER TABLE `survey_responses`
  ADD CONSTRAINT `fk_surv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Ketidakleluasaan untuk tabel `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
