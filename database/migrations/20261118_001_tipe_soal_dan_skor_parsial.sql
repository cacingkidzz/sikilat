-- =====================================================================
-- SIKILAT - Migrasi Skema 1.2.0
-- Tipe soal per nomor (PG, Benar/Salah, Multiple Response)
-- dan skor parsial untuk Multiple Response
-- ---------------------------------------------------------------------
-- Aman dijalankan pada database yang sudah berisi data:
--   * seluruh baris lama dianggap bertipe `pg`;
--   * `skor` lama diisi 1 untuk jawaban benar dan 0 untuk lainnya;
--   * `skor_total` lama diisi dari `jumlah_benar`;
--   * nilai (0-100) lama tidak diubah.
-- ---------------------------------------------------------------------
-- Jalankan melalui phpMyAdmin atau:
--   C:\xampp\mysql\bin\mysql.exe -u root db_sikilat < database\migrations\20261118_001_tipe_soal_dan_skor_parsial.sql
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

USE `db_sikilat`;

-- ---------------------------------------------------------------------
-- 1. Kunci jawaban: tipe soal + kolom jawaban diperlebar
--    PG              : A, B, C, D, E
--    Benar/Salah     : B atau S
--    Multiple Response: daftar opsi terurut dipisah koma, contoh A,C
-- ---------------------------------------------------------------------
ALTER TABLE `kunci_jawaban`
    ADD COLUMN `tipe_soal` ENUM('pg','benar_salah','multiple_response')
        NOT NULL DEFAULT 'pg'
        COMMENT 'Tipe soal per nomor' AFTER `nomor_soal`,
    MODIFY COLUMN `jawaban` VARCHAR(20) NOT NULL
        COMMENT 'PG: A-E; Benar/Salah: B/S; Multiple Response: A,C';

-- ---------------------------------------------------------------------
-- 2. Jawaban siswa: snapshot tipe, jawaban multi, dan skor butir (0-1)
-- ---------------------------------------------------------------------
ALTER TABLE `jawaban_siswa`
    ADD COLUMN `tipe_soal` ENUM('pg','benar_salah','multiple_response')
        NOT NULL DEFAULT 'pg'
        COMMENT 'Snapshot tipe soal saat koreksi' AFTER `nomor_soal`,
    MODIFY COLUMN `jawaban` VARCHAR(20) DEFAULT NULL
        COMMENT 'NULL = kosong; multiple response dipisah koma',
    MODIFY COLUMN `kunci` VARCHAR(20) DEFAULT NULL
        COMMENT 'Snapshot kunci saat koreksi',
    ADD COLUMN `skor` DECIMAL(5,4) NOT NULL DEFAULT 0.0000
        COMMENT 'Skor butir 0-1; Multiple Response memakai skor parsial' AFTER `kunci`,
    ADD CONSTRAINT `chk_jawaban_skor` CHECK (`skor` BETWEEN 0 AND 1);

-- ---------------------------------------------------------------------
-- 3. Hasil ujian: total skor butir (bisa pecahan karena skor parsial)
-- ---------------------------------------------------------------------
ALTER TABLE `hasil_ujian`
    ADD COLUMN `skor_total` DECIMAL(8,4) NOT NULL DEFAULT 0.0000
        COMMENT 'Jumlah skor seluruh butir (0..total_soal)' AFTER `jumlah_kosong`,
    ADD CONSTRAINT `chk_hasil_skor_total` CHECK (`skor_total` BETWEEN 0 AND `total_soal`);

-- ---------------------------------------------------------------------
-- 4. Isi kolom baru untuk data lama tanpa mengubah nilai yang tersimpan
-- ---------------------------------------------------------------------
UPDATE `hasil_ujian`
SET `skor_total` = `jumlah_benar`;

UPDATE `jawaban_siswa`
SET `skor` = IF(`is_benar` = 1, 1.0000, 0.0000);

-- ---------------------------------------------------------------------
-- 5. Perbarui versi skema
-- ---------------------------------------------------------------------
UPDATE `pengaturan`
SET `nilai` = '1.2.0'
WHERE `nama_pengaturan` = 'versi_database';
