-- Seed akun role warga untuk Manado Recycle Hub
-- Jalankan setelah schema dan tabel roles/users sudah ada

INSERT INTO `users` (
  `role_id`,
  `nama`,
  `email`,
  `password_hash`,
  `nomor_wa`,
  `alamat`,
  `kota`,
  `kecamatan`,
  `kelurahan`,
  `foto_profil`,
  `is_active`,
  `email_verified`,
  `created_at`,
  `updated_at`
)
SELECT
  r.id,
  'Demo Warga',
  'warga.demo@manadorecyclehub.id',
  '$2y$10$alBBBc4HM8SsmitSkEpL4uveWus3usQv9SsB1Zn/i.LI0FJu6k99O',
  '6281234567890',
  'Manado',
  'Manado',
  NULL,
  NULL,
  NULL,
  1,
  1,
  NOW(),
  NOW()
FROM `roles` r
WHERE r.`name` = 'warga'
ON DUPLICATE KEY UPDATE
  `role_id` = VALUES(`role_id`),
  `nama` = VALUES(`nama`),
  `password_hash` = VALUES(`password_hash`),
  `nomor_wa` = VALUES(`nomor_wa`),
  `alamat` = VALUES(`alamat`),
  `kota` = VALUES(`kota`),
  `kecamatan` = VALUES(`kecamatan`),
  `kelurahan` = VALUES(`kelurahan`),
  `foto_profil` = VALUES(`foto_profil`),
  `is_active` = VALUES(`is_active`),
  `email_verified` = VALUES(`email_verified`),
  `updated_at` = NOW();
