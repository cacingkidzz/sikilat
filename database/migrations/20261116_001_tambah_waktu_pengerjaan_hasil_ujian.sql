-- =====================================================================
-- SIKILAT - Migrasi Tahap 4
-- Tabel     : hasil_ujian
-- Tujuan    : menyimpan waktu mulai pengerjaan dan durasi pengerjaan
--             agar hasil siswa dapat menampilkan waktu mulai, waktu kirim,
--             dan lama pengerjaan.
-- Rollback  : ALTER TABLE hasil_ujian
--                 DROP COLUMN durasi_detik,
--                 DROP COLUMN started_at;
-- =====================================================================

ALTER TABLE `hasil_ujian`
    ADD COLUMN IF NOT EXISTS `started_at` DATETIME NULL DEFAULT NULL
        COMMENT 'Waktu siswa mulai mengerjakan' AFTER `kunci_snapshot`,
    ADD COLUMN IF NOT EXISTS `durasi_detik` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Lama pengerjaan dalam detik' AFTER `started_at`;

UPDATE `pengaturan`
SET `nilai` = '1.1.0'
WHERE `nama_pengaturan` = 'versi_database';
