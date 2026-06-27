<?php
require_once __DIR__ . '/include/config.php';

try {
    $pdo = getDB();
    
    // 1. Create cleanup_requests
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `cleanup_requests` (
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
          `status` enum('menunggu','dikonfirmasi','dijadwalkan','sedang_diproses','selesai','dibatalkan') NOT NULL DEFAULT 'menunggu',
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    // 2. Create cleanup_items
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `cleanup_items` (
          `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
          `cleanup_id` bigint(20) UNSIGNED NOT NULL,
          `category_id` smallint(5) UNSIGNED NOT NULL,
          `berat_kg` decimal(10,2) DEFAULT NULL,
          `catatan` varchar(255) DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_cleanup_item_req` (`cleanup_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    // 3. Trigger for cleanup_request_code
    $pdo->exec("DROP TRIGGER IF EXISTS `trg_cleanup_request_code` ");
    $pdo->exec("
        CREATE TRIGGER `trg_cleanup_request_code` BEFORE INSERT ON `cleanup_requests` FOR EACH ROW BEGIN
          DECLARE v_code   VARCHAR(20);
          DECLARE v_exists INT DEFAULT 1;

          IF NEW.request_code IS NULL OR NEW.request_code = '' THEN
            WHILE v_exists > 0 DO
              SET v_code = CONCAT('CLN', LPAD(FLOOR(RAND() * 90000000) + 10000000, 8, '0'));
              SELECT COUNT(*) INTO v_exists FROM cleanup_requests WHERE request_code = v_code;
            END WHILE;
            SET NEW.request_code = v_code;
          END IF;
        END
    ");

    echo "Migration successful: Cleanup tables and trigger created.";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage();
}
