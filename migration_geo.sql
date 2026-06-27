-- ============================================================
--  MIGRATION: Tambah kolom geografi ke pickup_requests
--  Manado Recycle Hub — jalankan sekali di MySQL
-- ============================================================

-- Tambah kolom lat/lng jika belum ada
ALTER TABLE `pickup_requests`
    ADD COLUMN IF NOT EXISTS `latitude`          DECIMAL(10,8) NULL COMMENT 'Latitude dari Google Maps Geocoding',
    ADD COLUMN IF NOT EXISTS `longitude`         DECIMAL(11,8) NULL COMMENT 'Longitude dari Google Maps Geocoding',
    ADD COLUMN IF NOT EXISTS `place_id`          VARCHAR(255)  NULL COMMENT 'Google Maps Place ID untuk referensi',
    ADD COLUMN IF NOT EXISTS `formatted_address` TEXT          NULL COMMENT 'Alamat terformat dari Google Maps',
    ADD COLUMN IF NOT EXISTS `berat_kg`          VARCHAR(20)   NULL COMMENT 'berat_kg string (legacy compat)';

-- Index untuk query geo
ALTER TABLE `pickup_requests`
    ADD INDEX IF NOT EXISTS `idx_geo` (`latitude`, `longitude`),
    ADD INDEX IF NOT EXISTS `idx_officer` (`officer_id`),
    ADD INDEX IF NOT EXISTS `idx_status_tgl` (`status`, `tanggal_jemput`);

-- Tambah kolom last_location ke officers (untuk real-time tracking)
ALTER TABLE `officers`
    ADD COLUMN IF NOT EXISTS `last_lat`      DECIMAL(10,8)    NULL,
    ADD COLUMN IF NOT EXISTS `last_lng`      DECIMAL(11,8)    NULL,
    ADD COLUMN IF NOT EXISTS `last_seen_at`  TIMESTAMP        NULL COMMENT 'Terakhir update lokasi';

-- Tabel settings jika belum ada
CREATE TABLE IF NOT EXISTS `site_settings` (
    `id`            INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    `setting_key`   VARCHAR(100)    NOT NULL UNIQUE,
    `setting_value` TEXT            NULL,
    `updated_by`    INT UNSIGNED    NULL,
    `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default settings
INSERT IGNORE INTO `site_settings` (`setting_key`, `setting_value`) VALUES
    ('google_maps_api_key', ''),
    ('site_name',           'Manado Recycle Hub'),
    ('whatsapp_number',     ''),
    ('metode_jadwal',       'priority_rule'),
    ('metode_rute',         'nearest_neighbor'),
    ('hari_jadwal',         'sabtu'),
    ('cleanup_tarif_per_jam', '50000'),
    ('max_pickup_per_day',  '20'),
    ('pickup_auto_confirm', '0');
