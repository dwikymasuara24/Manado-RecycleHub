<?php
require_once __DIR__ . '/../include/config.php';

$db = getDB();

$statements = [
    "ALTER TABLE `pickup_requests` ADD COLUMN `latitude` DECIMAL(10, 8) NULL AFTER `kelurahan`" => "latitude column",
    "ALTER TABLE `pickup_requests` ADD COLUMN `longitude` DECIMAL(11, 8) NULL AFTER `latitude`" => "longitude column",
    "CREATE TABLE IF NOT EXISTS `kecamatan` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `nama_kecamatan` VARCHAR(100) NOT NULL,
      `aktif` TINYINT(1) DEFAULT 1,
      UNIQUE KEY `uq_nama_kecamatan` (`nama_kecamatan`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci" => "kecamatan table",
    "CREATE TABLE IF NOT EXISTS `schedules` (
      `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `tanggal` DATE NOT NULL,
      `kecamatan_id` INT DEFAULT NULL,
      `kecamatan` VARCHAR(100) DEFAULT NULL,
      `officer_id` BIGINT UNSIGNED DEFAULT NULL,
      `status` ENUM('draft', 'confirmed', 'completed', 'cancelled') NOT NULL DEFAULT 'draft',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`kecamatan_id`) REFERENCES `kecamatan`(`id`) ON DELETE SET NULL,
      FOREIGN KEY (`officer_id`) REFERENCES `officers`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci" => "schedules table",
    "CREATE TABLE IF NOT EXISTS `routes` (
      `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `schedule_id` BIGINT UNSIGNED NOT NULL,
      `urutan` INT NOT NULL,
      `pickup_request_id` BIGINT UNSIGNED DEFAULT NULL,
      `dist_from_prev_km` DECIMAL(10, 4) DEFAULT 0.0000,
      FOREIGN KEY (`schedule_id`) REFERENCES `schedules`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`pickup_request_id`) REFERENCES `pickup_requests`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci" => "routes table",
    "CREATE TABLE IF NOT EXISTS `schedule_requests` (
      `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `schedule_id` BIGINT UNSIGNED NOT NULL,
      `request_id` BIGINT UNSIGNED NOT NULL,
      FOREIGN KEY (`schedule_id`) REFERENCES `schedules`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`request_id`) REFERENCES `pickup_requests`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci" => "schedule_requests table"
];

foreach ($statements as $sql => $desc) {
    try {
        $db->exec($sql);
        echo "Successfully created/updated: $desc\n";
    } catch (PDOException $e) {
        if ($e->getCode() == '42S21') { // Column already exists
            echo "Skipped (already exists): $desc\n";
        } else {
            echo "Error on $desc: " . $e->getMessage() . "\n";
        }
    }
}
echo "Migration finished.\n";
