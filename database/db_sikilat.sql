-- =====================================================================
-- SIKILAT - Sistem Koreksi Nilai Cepat
-- Skema Database Versi 1.2.0
-- ---------------------------------------------------------------------
-- DBMS      : MySQL 8.x / MariaDB 10.4+
-- Engine    : InnoDB
-- Charset   : utf8mb4 / utf8mb4_unicode_ci
-- Database  : db_sikilat
-- ---------------------------------------------------------------------
-- PERINGATAN:
-- File ini mengandung DROP TABLE. Impor pada database yang masih kosong
-- atau saat instalasi ulang. Jangan diimpor pada database produksi yang
-- sudah berisi data tanpa backup terlebih dahulu.
-- ---------------------------------------------------------------------
-- Cara impor melalui phpMyAdmin:
--   1. Buka http://localhost/phpmyadmin
--   2. Menu "Impor" -> pilih file ini -> klik "Kirim"
--   (Berkas ini sudah membuat database db_sikilat secara otomatis)
--
-- Cara impor melalui terminal:
--   C:\xampp\mysql\bin\mysql.exe -u root < database\db_sikilat.sql
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

CREATE DATABASE IF NOT EXISTS `db_sikilat`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `db_sikilat`;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `log_aktivitas`;
DROP TABLE IF EXISTS `jawaban_siswa`;
DROP TABLE IF EXISTS `hasil_ujian`;
DROP TABLE IF EXISTS `kunci_jawaban`;
DROP TABLE IF EXISTS `paket_soal`;
DROP TABLE IF EXISTS `ujian_kelas`;
DROP TABLE IF EXISTS `ujian`;
DROP TABLE IF EXISTS `kelas`;
DROP TABLE IF EXISTS `mata_pelajaran`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `pengaturan`;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- 1. USERS
--    Akun admin dan guru. Password disimpan sebagai hash bcrypt
--    (password_hash dengan PASSWORD_DEFAULT).
-- =====================================================================
CREATE TABLE `users` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `nama`        VARCHAR(100)    NOT NULL COMMENT 'Nama lengkap pengguna',
    `username`    VARCHAR(50)     NOT NULL COMMENT 'Username unik untuk login',
    `email`       VARCHAR(150)    DEFAULT NULL COMMENT 'Email opsional, unik jika diisi',
    `password`    VARCHAR(255)    NOT NULL COMMENT 'Hash password (password_hash)',
    `role`        ENUM('admin','guru') NOT NULL DEFAULT 'guru',
    `is_active`   TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '1 = aktif, 0 = nonaktif',
    `last_login`  DATETIME        DEFAULT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_username` (`username`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_role_active` (`role`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Akun admin dan guru';

-- =====================================================================
-- 2. MATA_PELAJARAN
-- =====================================================================
CREATE TABLE `mata_pelajaran` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `kode`        VARCHAR(20)     NOT NULL COMMENT 'Kode mata pelajaran, contoh: MTK',
    `nama`        VARCHAR(100)    NOT NULL,
    `deskripsi`   TEXT            DEFAULT NULL,
    `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_mapel_kode` (`kode`),
    UNIQUE KEY `uq_mapel_nama` (`nama`),
    KEY `idx_mapel_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Master mata pelajaran';

-- =====================================================================
-- 3. KELAS
-- =====================================================================
CREATE TABLE `kelas` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `nama`         VARCHAR(50)     NOT NULL COMMENT 'Contoh: X IPA 1',
    `tingkat`      ENUM('VII','VIII','IX','X','XI','XII') NOT NULL,
    `tahun_ajaran` VARCHAR(9)      NOT NULL COMMENT 'Format: 2026/2027',
    `is_active`    TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_kelas_nama_tahun` (`nama`, `tahun_ajaran`),
    KEY `idx_kelas_tahun_active` (`tahun_ajaran`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Master kelas per tahun ajaran';

-- =====================================================================
-- 4. UJIAN
--    Satu ujian memiliki satu mata pelajaran, daftar kelas peserta,
--    dan satu atau beberapa paket soal.
-- =====================================================================
CREATE TABLE `ujian` (
    `id`                INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `mata_pelajaran_id` INT UNSIGNED      NOT NULL,
    `created_by`        INT UNSIGNED      DEFAULT NULL,
    `nama`              VARCHAR(150)      NOT NULL,
    `deskripsi`         TEXT              DEFAULT NULL,
    `jumlah_soal`       SMALLINT UNSIGNED NOT NULL,
    `kkm`               DECIMAL(5,2)      NOT NULL DEFAULT 75.00 COMMENT 'Kriteria Ketuntasan Minimal',
    `tanggal_ujian`     DATE              NOT NULL,
    `waktu_mulai`       TIME              NOT NULL,
    `waktu_selesai`     TIME              NOT NULL,
    `status`            ENUM('draft','aktif','selesai') NOT NULL DEFAULT 'draft',
    `created_at`        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ujian_mapel` (`mata_pelajaran_id`),
    KEY `idx_ujian_status_tanggal` (`status`, `tanggal_ujian`),
    KEY `idx_ujian_created_by` (`created_by`),
    CONSTRAINT `fk_ujian_mapel` FOREIGN KEY (`mata_pelajaran_id`)
        REFERENCES `mata_pelajaran` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_ujian_created_by` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `chk_ujian_jumlah_soal` CHECK (`jumlah_soal` BETWEEN 1 AND 200),
    CONSTRAINT `chk_ujian_kkm` CHECK (`kkm` BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Data ujian';

-- =====================================================================
-- 5. UJIAN_KELAS
--    Relasi banyak-ke-banyak antara ujian dan kelas peserta.
-- =====================================================================
CREATE TABLE `ujian_kelas` (
    `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ujian_id`  INT UNSIGNED NOT NULL,
    `kelas_id`  INT UNSIGNED NOT NULL,
    `created_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ujian_kelas` (`ujian_id`, `kelas_id`),
    KEY `idx_ujian_kelas_kelas` (`kelas_id`),
    CONSTRAINT `fk_ujian_kelas_ujian` FOREIGN KEY (`ujian_id`)
        REFERENCES `ujian` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_ujian_kelas_kelas` FOREIGN KEY (`kelas_id`)
        REFERENCES `kelas` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Kelas peserta ujian';

-- =====================================================================
-- 6. PAKET_SOAL
--    Paket A, B, C, ... milik satu ujian. Kode paket unik per ujian.
-- =====================================================================
CREATE TABLE `paket_soal` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `ujian_id`    INT UNSIGNED  NOT NULL,
    `kode_paket`  VARCHAR(10)   NOT NULL COMMENT 'Contoh: A, B, C',
    `keterangan`  VARCHAR(100)  DEFAULT NULL,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_paket_ujian_kode` (`ujian_id`, `kode_paket`),
    CONSTRAINT `fk_paket_ujian` FOREIGN KEY (`ujian_id`)
        REFERENCES `ujian` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Paket soal milik ujian';

-- =====================================================================
-- 7. KUNCI_JAWABAN
--    Satu baris per nomor soal per paket.
-- =====================================================================
CREATE TABLE `kunci_jawaban` (
    `id`            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `paket_soal_id` INT UNSIGNED      NOT NULL,
    `nomor_soal`    SMALLINT UNSIGNED NOT NULL,
    `tipe_soal`     ENUM('pg','benar_salah','multiple_response')
                    NOT NULL DEFAULT 'pg' COMMENT 'Tipe soal per nomor',
    `jawaban`       VARCHAR(20)       NOT NULL
                    COMMENT 'PG: A-E; Benar/Salah: B/S; Multiple Response: A,C',
    `created_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_kunci_paket_nomor` (`paket_soal_id`, `nomor_soal`),
    CONSTRAINT `fk_kunci_paket` FOREIGN KEY (`paket_soal_id`)
        REFERENCES `paket_soal` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `chk_kunci_nomor` CHECK (`nomor_soal` BETWEEN 1 AND 200)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Kunci jawaban per paket soal';

-- =====================================================================
-- 8. HASIL_UJIAN
--    Identitas peserta diinput manual. Kombinasi ujian + kelas +
--    nomor absen wajib unik untuk mencegah pengiriman ganda.
--    kunci_snapshot menyimpan salinan kunci saat pengiriman agar
--    perubahan kunci di kemudian hari tidak mengubah hasil lama.
-- =====================================================================
CREATE TABLE `hasil_ujian` (
    `id`             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `ujian_id`       INT UNSIGNED      NOT NULL,
    `kelas_id`       INT UNSIGNED      NOT NULL,
    `paket_soal_id`  INT UNSIGNED      NOT NULL,
    `nama_siswa`     VARCHAR(100)      NOT NULL,
    `nomor_absen`    VARCHAR(20)       NOT NULL,
    `total_soal`     SMALLINT UNSIGNED NOT NULL,
    `jumlah_benar`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `jumlah_salah`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `jumlah_kosong`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `skor_total`     DECIMAL(8,4)      NOT NULL DEFAULT 0.0000
                     COMMENT 'Jumlah skor seluruh butir (0..total_soal)',
    `nilai`          DECIMAL(5,2)      NOT NULL DEFAULT 0.00,
    `kunci_snapshot` LONGTEXT          DEFAULT NULL COMMENT 'JSON kunci jawaban saat pengiriman',
    `started_at`     DATETIME          DEFAULT NULL COMMENT 'Waktu siswa mulai mengerjakan',
    `durasi_detik`   INT UNSIGNED      DEFAULT NULL COMMENT 'Lama pengerjaan dalam detik',
    `submitted_at`   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip_address`     VARCHAR(45)       DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_hasil_peserta` (`ujian_id`, `kelas_id`, `nomor_absen`),
    KEY `idx_hasil_ujian_kelas` (`ujian_id`, `kelas_id`),
    KEY `idx_hasil_ujian_nilai` (`ujian_id`, `nilai`),
    KEY `idx_hasil_kelas` (`kelas_id`),
    KEY `idx_hasil_paket` (`paket_soal_id`),
    CONSTRAINT `fk_hasil_ujian` FOREIGN KEY (`ujian_id`)
        REFERENCES `ujian` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_hasil_kelas` FOREIGN KEY (`kelas_id`)
        REFERENCES `kelas` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_hasil_paket` FOREIGN KEY (`paket_soal_id`)
        REFERENCES `paket_soal` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `chk_hasil_nilai` CHECK (`nilai` BETWEEN 0 AND 100),
    CONSTRAINT `chk_hasil_skor_total` CHECK (`skor_total` BETWEEN 0 AND `total_soal`),
    CONSTRAINT `chk_hasil_jumlah` CHECK (`jumlah_benar` + `jumlah_salah` + `jumlah_kosong` = `total_soal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Hasil koreksi ujian per peserta';

-- =====================================================================
-- 9. JAWABAN_SISWA
--    Rincian jawaban per nomor. Menyimpan kunci saat koreksi dan
--    status benar/salah agar analisis butir soal tetap akurat.
-- =====================================================================
CREATE TABLE `jawaban_siswa` (
    `id`             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `hasil_ujian_id` INT UNSIGNED      NOT NULL,
    `nomor_soal`     SMALLINT UNSIGNED NOT NULL,
    `tipe_soal`      ENUM('pg','benar_salah','multiple_response')
                     NOT NULL DEFAULT 'pg' COMMENT 'Snapshot tipe soal saat koreksi',
    `jawaban`        VARCHAR(20)       DEFAULT NULL
                     COMMENT 'NULL = kosong; multiple response dipisah koma',
    `kunci`          VARCHAR(20)       DEFAULT NULL COMMENT 'Snapshot kunci saat koreksi',
    `skor`           DECIMAL(5,4)      NOT NULL DEFAULT 0.0000
                     COMMENT 'Skor butir 0-1; Multiple Response memakai skor parsial',
    `is_benar`       TINYINT(1)        DEFAULT NULL COMMENT '1 = benar penuh, 0 = salah/parsial, NULL = kosong',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_jawaban_hasil_nomor` (`hasil_ujian_id`, `nomor_soal`),
    KEY `idx_jawaban_nomor` (`nomor_soal`),
    CONSTRAINT `fk_jawaban_hasil` FOREIGN KEY (`hasil_ujian_id`)
        REFERENCES `hasil_ujian` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `chk_jawaban_nomor` CHECK (`nomor_soal` BETWEEN 1 AND 200),
    CONSTRAINT `chk_jawaban_skor` CHECK (`skor` BETWEEN 0 AND 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Rincian jawaban peserta per nomor soal';

-- =====================================================================
-- 10. PENGATURAN
--     Konfigurasi aplikasi berbasis key-value.
-- =====================================================================
CREATE TABLE `pengaturan` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nama_pengaturan`  VARCHAR(50)  NOT NULL COMMENT 'Kunci pengaturan',
    `nilai`            TEXT         DEFAULT NULL,
    `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pengaturan_nama` (`nama_pengaturan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Pengaturan aplikasi';

-- =====================================================================
-- 11. LOG_AKTIVITAS
--     Jejak audit aktivitas penting pengguna.
-- =====================================================================
CREATE TABLE `log_aktivitas` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED    DEFAULT NULL,
    `modul`       VARCHAR(50)     NOT NULL,
    `aksi`        VARCHAR(50)     NOT NULL,
    `deskripsi`   TEXT            DEFAULT NULL,
    `ip_address`  VARCHAR(45)     DEFAULT NULL,
    `user_agent`  VARCHAR(255)    DEFAULT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_log_user` (`user_id`),
    KEY `idx_log_created` (`created_at`),
    KEY `idx_log_modul` (`modul`, `aksi`),
    CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Log aktivitas pengguna';

-- =====================================================================
-- DATA AWAL PENGATURAN (bukan data dummy, hanya konfigurasi default)
-- =====================================================================
INSERT INTO `pengaturan` (`nama_pengaturan`, `nilai`) VALUES
    ('versi_database', '1.2.0'),
    ('nama_aplikasi', 'SIKILAT'),
    ('nama_sekolah', ''),
    ('alamat_sekolah', ''),
    ('telepon_sekolah', ''),
    ('email_sekolah', ''),
    ('website_sekolah', ''),
    ('logo_sekolah', ''),
    ('kota_sekolah', ''),
    ('kepala_sekolah', ''),
    ('nip_kepala_sekolah', ''),
    ('kkm_default', '75'),
    ('tahun_ajaran_aktif', ''),
    ('semester_aktif', 'Ganjil'),
    ('footer_laporan', 'Dicetak melalui SIKILAT - Sistem Koreksi Nilai Cepat')
ON DUPLICATE KEY UPDATE `nama_pengaturan` = VALUES(`nama_pengaturan`);

-- =====================================================================
-- SELESAI
-- Akun admin pertama dibuat melalui halaman /install.php agar password
-- di-hash langsung oleh PHP (password_hash), bukan disimpan di file SQL.
-- =====================================================================
