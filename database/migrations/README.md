# Migrasi Database SIKILAT

Folder ini menampung perubahan skema **setelah** instalasi awal. File
`database/db_sikilat.sql` hanya dipakai untuk instalasi baru dan bersifat destruktif
(mengandung `DROP TABLE`). Jangan mengimpornya ulang pada database produksi.

## Konvensi nama file

```text
YYYYMMDD_NNN_deskripsi_singkat.sql
```

Contoh:

```text
20261201_001_tambah_kolom_nis_hasil_ujian.sql
20261201_002_buat_tabel_import_log.sql
20270110_003_tambah_indeks_hasil_nilai.sql
```

- `YYYYMMDD` — tanggal migrasi dibuat.
- `NNN` — urutan tiga digit pada tanggal tersebut.
- `deskripsi_singkat` — huruf kecil, kata dipisahkan garis bawah.

## Aturan penulisan

1. Satu file berisi satu perubahan logis.
2. Gunakan `ALTER TABLE`/`CREATE TABLE`; jangan mengubah file migrasi yang sudah
   dijalankan.
3. Sertakan komentar tujuan, tabel terdampak, dan cara rollback bila memungkinkan.
4. Gunakan `IF NOT EXISTS`/`IF EXISTS` bila didukung untuk membuat migrasi idempoten.
5. Perbarui baris `versi_database` pada tabel `pengaturan` setelah migrasi berhasil.
6. Uji pada salinan database sebelum menjalankan di produksi.
7. Backup database sebelum migrasi.

## Contoh file

```sql
-- 20261201_001_tambah_kolom_nis_hasil_ujian.sql
-- Tujuan : menyimpan NIS peserta pada hasil ujian.
-- Rollback: ALTER TABLE hasil_ujian DROP COLUMN nis;

ALTER TABLE hasil_ujian
    ADD COLUMN nis VARCHAR(30) DEFAULT NULL AFTER nama_siswa,
    ADD INDEX idx_hasil_nis (nis);
```

## Pencatatan versi

Versi skema saat ini: **1.2.0** (lihat `pengaturan.versi_database`).
Migrasi `20261116_001_tambah_waktu_pengerjaan_hasil_ujian.sql` menambahkan
`started_at` dan `durasi_detik` pada `hasil_ujian` untuk Tahap 4.
Migrasi `20261118_001_tipe_soal_dan_skor_parsial.sql` menambahkan `tipe_soal`
pada `kunci_jawaban`/`jawaban_siswa`, skor butir `jawaban_siswa.skor`, dan
`hasil_ujian.skor_total` untuk Benar/Salah dan Multiple Response berskor parsial.

Setelah migrasi:

```sql
UPDATE pengaturan
SET nilai = '1.2.0'
WHERE nama_pengaturan = 'versi_database';
```

## Catatan

- File migrasi tidak boleh diakses dari browser; folder `database/` sudah diblokir
  melalui `.htaccess`.
- Migrasi dijalankan manual melalui phpMyAdmin atau CLI:

  ```bat
  C:\xampp\mysql\bin\mysql.exe -u root db_sikilat < database\migrations\20261201_001_contoh.sql
  ```

- Belum ada runner migrasi otomatis pada tahap 1. Jika volume migrasi bertambah,
  runner CLI dapat ditambahkan pada tahap berikutnya.
