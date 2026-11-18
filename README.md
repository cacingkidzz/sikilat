# SIKILAT — Sistem Koreksi Nilai Cepat

Aplikasi koreksi nilai ujian berbasis **PHP native + MySQL/MariaDB** untuk sekolah.
Panel admin/guru dilindungi login, sedangkan halaman siswa dirancang tanpa login
(menggunakan identitas peserta dan paket soal).

> **Status: Tahap 6 selesai.**
> Tahap 1 mencakup fondasi aplikasi: struktur folder, skema database, koneksi PDO,
> installer, autentikasi admin/guru, proteksi keamanan dasar, dan dashboard AdminLTE 4.
> Tahap 2 menambahkan modul manajemen data: mata pelajaran, kelas, pengguna admin/guru,
> ujian, relasi ujian–kelas, dan paket soal.
> Tahap 3 menambahkan pengelolaan kunci jawaban per paket: tabel interaktif A–E,
> input massal, progres pengisian, penguncian aktivasi ujian, dan proteksi kunci
> setelah ujian memiliki hasil peserta.
> Tahap 4 menambahkan alur siswa lengkap: pemilihan ujian aktif, identitas peserta,
> lembar jawaban dua mode yang tersinkronisasi, koreksi otomatis di server, penyimpanan
> hasil dalam transaksi, dan halaman konfirmasi hasil yang terikat sesi.
> Tahap 5 menambahkan laporan dan analisis: rekap nilai seluruh siswa/per kelas/per paket,
> rincian jawaban, ekspor Excel dan PDF, serta analisis butir per paket (tingkat
> kesukaran, daya pembeda 27%, dan efektivitas pengecoh A–E).
> Tahap 6 menambahkan halaman pengaturan administrator: identitas aplikasi, profil
> sekolah, unggah/hapus logo, KKM default, tahun ajaran, dan semester aktif.
> Tampilan audit log **belum dibangun** dan akan dikerjakan pada tahap berikutnya.

---

## 1. Ringkasan

| Item | Keterangan |
| --- | --- |
| Nama aplikasi | SIKILAT |
| Kepanjangan | Sistem Koreksi Nilai Cepat |
| Versi | 1.0.0 (Tahap 6, skema database 1.2.0) |
| Bahasa antarmuka | Indonesia |
| Backend | PHP 8.2+ native, PDO |
| Database | MySQL 8.x / MariaDB 10.4+, InnoDB, utf8mb4 |
| Frontend | AdminLTE 4.10, Bootstrap 5.3, Bootstrap Icons, jQuery 3.7 |
| Library terpasang | DataTables, SweetAlert2, Chart.js, PhpSpreadsheet, FPDF |
| Lingkungan uji | XAMPP (PHP 8.2.4, MariaDB 10.4.28, Apache) |
| Direktori proyek | `C:\xampp\htdocs\ujian` |
| URL lokal | `http://localhost/ujian/` |

Aplikasi **tidak memakai framework PHP**. Semua aset frontend disimpan lokal di
`assets/plugins/` sehingga aplikasi tidak bergantung pada CDN saat berjalan.

---

## 2. Kebutuhan Sistem

### Wajib

- PHP **8.2 atau lebih baru**
- Ekstensi PHP: `pdo_mysql`, `mbstring`, `fileinfo`
- MySQL 8.x atau MariaDB 10.4+
- Web server Apache/Nginx/LiteSpeed dengan dukungan PHP
- Folder `uploads/` dan `logs/` dapat ditulis oleh proses web server

### Opsional (dibutuhkan pada tahap ekspor/PDF)

- Ekstensi `gd`, `zip`, `xml`, `dom` (PhpSpreadsheet)
- Composer 2.x untuk memasang/memperbarui `vendor/`

> Ekspor Excel dan PDF pada Tahap 5 memerlukan `vendor/autoload.php` beserta ekstensi
> di atas. Tanpa itu, halaman laporan tetap tampil tetapi tombol ekspor akan gagal.

### Pemeriksaan otomatis

`install.php` menampilkan status kesiapan server: versi PHP, ekstensi, izin folder,
ketersediaan file SQL, dan keberadaan `vendor/autoload.php`. Pemeriksaan wajib harus
lulus sebelum instalasi database dijalankan.

---

## 3. Mulai Cepat (XAMPP)

1. Jalankan **Apache** dan **MySQL** dari XAMPP Control Panel.
2. Pastikan folder proyek berada di `C:\xampp\htdocs\ujian`.
3. (Opsional) Pasang dependensi Composer:

   ```bat
   cd C:\xampp\htdocs\ujian
   composer install
   ```

   Tahap 1 tetap dapat berjalan tanpa `vendor/`, tetapi ekspor Excel/PDF pada tahap
   berikutnya memerlukannya.

4. Buka `http://localhost/ujian/install.php`.
5. Ikuti tiga langkah installer:
   - **Cek kebutuhan sistem** — pastikan semua item wajib berstatus OK.
   - **Impor database** — membuat database `db_sikilat` dan 11 tabel.
   - **Buat akun administrator pertama** — password di-hash dengan `password_hash()`.
6. Setelah akun admin dibuat, installer otomatis terkunci.
7. Buka `http://localhost/ujian/` lalu login.

> Tidak ada akun atau password bawaan. Akun pertama hanya dibuat melalui installer
> atau melalui prosedur CLI darurat yang dijelaskan di bagian 8.

---

## 4. Instalasi Database

### 4.1 Melalui installer (disarankan)

Installer memakai kredensial dari `config/database.php`, membuat database bila belum
ada, lalu mengeksekusi `database/db_sikilat.sql` per perintah. Setelah 11 tabel
terdeteksi, form pembuatan admin ditampilkan.

Impor bersifat **destruktif**: file SQL mengandung `DROP TABLE`. Jangan jalankan
installer pada database produksi yang sudah berisi data tanpa backup.

### 4.2 Impor manual melalui phpMyAdmin

1. Buka `http://localhost/phpmyadmin`.
2. Pilih tab **Impor**.
3. Pilih `database/db_sikilat.sql`, lalu klik **Kirim**.
4. File SQL sudah membuat database `db_sikilat` secara otomatis.
5. Buat akun admin melalui `install.php` (langkah 3) atau prosedur CLI darurat.

### 4.3 Impor manual melalui terminal

```bat
C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\ujian\database\db_sikilat.sql
```

### 4.4 Akun administrator pertama

Buka `install.php` dan isi:

- Nama lengkap: 3–100 karakter
- Username: 4–50 karakter (`A-Z`, `a-z`, `0-9`, titik, garis bawah, tanda hubung)
- Email: opsional, harus valid dan unik bila diisi
- Password: minimal 8 karakter, mengandung huruf dan angka
- Konfirmasi password

Setelah admin pertama tersimpan, halaman installer menolak pembuatan admin baru.
Akun tambahan (admin/guru) dikelola melalui modul **Manajemen Pengguna** di panel.

---

## 5. Konfigurasi

### 5.1 Deteksi base URL

`config/app.php` mendeteksi base path dari `DOCUMENT_ROOT` dan lokasi aplikasi.
Pada XAMPP hasilnya `/ujian`, sedangkan pada cPanel yang document root-nya langsung
mengarah ke folder aplikasi hasilnya kosong. Tidak perlu mengubah kode saat pindah
hosting.

### 5.2 Environment

| Variabel | Default | Fungsi |
| --- | --- | --- |
| `SIKILAT_ENV` | `local` untuk localhost/CLI, `production` lainnya | Mode aplikasi |
| `SIKILAT_DEBUG` | `true` di local, `false` di production | Tampilan detail error |
| `SIKILAT_APP_URL` | origin + base path | Override URL absolut |
| `SIKILAT_TIMEZONE` | `Asia/Jakarta` | Zona waktu aplikasi |
| `SIKILAT_DB_HOST` | `127.0.0.1` | Host database |
| `SIKILAT_DB_PORT` | `3306` | Port database |
| `SIKILAT_DB_NAME` | `db_sikilat` | Nama database |
| `SIKILAT_DB_USER` | `root` | User database |
| `SIKILAT_DB_PASS` | kosong | Password database |

### 5.3 Kredensial database

Urutan prioritas konfigurasi:

1. `config/database.local.php` (paling tinggi, tidak masuk repositori)
2. Environment variable `SIKILAT_DB_*`
3. Nilai default XAMPP di `config/database.php`

Contoh `config/database.local.php` untuk cPanel:

```php
<?php
return [
    'host' => 'localhost',
    'port' => 3306,
    'name' => 'user_sikilat',
    'user' => 'user_sikilat',
    'pass' => 'password-kuat',
];
```

File ini diblokir dari akses web melalui `.htaccess`, tetapi tetap disarankan berada
di luar document root bila konfigurasi hosting memungkinkan.

### 5.4 Zona waktu

Database memakai `+07:00` dan PHP memakai `Asia/Jakarta`. Keduanya dapat diubah pada
`config/database.php` (`DB_TIMEZONE`) dan `config/app.php` (`APP_TIMEZONE`).

---

## 6. Struktur Folder

```text
ujian/
├── index.php                  # Router awal: dashboard atau login
├── install.php                # Installer 3 langkah (terkunci setelah ada admin)
├── .htaccess                  # Proteksi folder internal & file sensitif
├── composer.json / .lock      # PhpSpreadsheet, FPDF
│
├── admin/
│   ├── dashboard/index.php    # Dashboard admin/guru
│   ├── mata-pelajaran/index.php  # CRUD mata pelajaran
│   ├── kelas/index.php        # CRUD kelas
│   ├── users/index.php        # CRUD pengguna (admin-only)
│   ├── ujian/index.php        # CRUD ujian + relasi kelas + status
│   ├── paket-soal/index.php   # CRUD paket soal + progres kunci
│   ├── kunci-jawaban/index.php  # Editor kunci A–E per paket + input massal
│   ├── laporan/index.php      # Rekap nilai, ekspor, dan rincian siswa
│   ├── laporan/excel.php      # Endpoint ekspor Excel (.xlsx)
│   ├── laporan/pdf.php        # Endpoint ekspor PDF
│   ├── analisis/index.php     # Analisis butir per paket
│   └── pengaturan/index.php   # Pengaturan aplikasi, sekolah, logo, akademik
├── auth/
│   ├── login.php              # Form login
│   ├── proses-login.php       # Verifikasi kredensial (POST + CSRF)
│   └── logout.php             # Logout (POST + CSRF)
├── siswa/
│   ├── index.php              # Daftar ujian aktif, lanjutkan/batalkan sesi
│   ├── identitas.php          # Kelas, nama, absen, pilih paket
│   ├── ujian.php              # Lembar jawaban dua mode + pratinjau + kirim
│   └── hasil.php              # Konfirmasi hasil terikat sesi + cetak
│
├── config/
│   ├── app.php                # Bootstrap, session, error handler, CSP
│   ├── database.php           # PDO singleton + konfigurasi DB
│   └── database.local.php     # (opsional, tidak di repositori)
│
├── includes/
│   ├── functions.php          # Helper umum (e, url, asset, flash, setting, log)
│   ├── auth.php               # Login, session, role, throttle
│   ├── csrf.php               # Token & verifikasi CSRF
│   ├── crud.php               # Helper validasi, akses, dan render modul CRUD
│   ├── siswa.php              # Alur siswa: ujian aktif, sesi, parse jawaban, koreksi
│   ├── laporan.php            # Query laporan, statistik nilai, dan analisis butir
│   ├── laporan_export.php     # Pembuat workbook Excel dan dokumen PDF
│   ├── installer.php          # Helper impor SQL & pemeriksaan sistem
│   ├── header.php             # Layout atas AdminLTE
│   ├── sidebar.php            # Navigasi admin
│   ├── footer.php             # Layout bawah + pemuatan JS
│   ├── siswa-header.php       # Layout atas halaman siswa
│   ├── siswa-footer.php       # Layout bawah halaman siswa
│   └── error-page.php         # Halaman error ramah pengguna
│
├── database/
│   ├── db_sikilat.sql         # Skema 11 tabel + 15 pengaturan default
│   ├── migrations/            # Konvensi migrasi tahap berikutnya
│   └── .htaccess
│
├── assets/
│   ├── css/app.css            # Token warna & komponen kustom panel
│   ├── css/siswa.css          # Tema halaman siswa (responsif & cetak)
│   ├── css/laporan.css        # Kartu metrik, tabel, dan grafik laporan
│   ├── js/app.js              # Perilaku umum (sidebar, konfirmasi)
│   ├── js/crud.js             # Modal CRUD, filter otomatis, pilih semua
│   ├── js/kunci-jawaban.js    # Progres, keyboard A–E, validasi input massal
│   ├── js/siswa.js            # Sinkronisasi jawaban, progres, timer, pratinjau
│   ├── js/laporan.js          # Chart.js laporan dari data-* + filter otomatis
│   ├── js/dashboard.js        # Inisialisasi Chart.js dari data-attribute
│   ├── img/                   # Logo & favicon
│   └── plugins/               # AdminLTE, Bootstrap, jQuery, DataTables, dll.
│
├── libraries/                 # Ruang untuk library internal tahap berikutnya
├── uploads/                   # Unggahan logo sekolah (diblokir dari eksekusi)
├── logs/                      # php-error.log (diblokir dari web)
├── tools/                     # Skrip CLI darurat
├── docs/                      # Dokumentasi internal (diblokir dari web)
└── vendor/                    # Dependensi Composer (diblokir dari web)
```

---

## 7. Skema Database

Database: `db_sikilat` — 11 tabel InnoDB, `utf8mb4_unicode_ci`.

| No | Tabel | Fungsi | Relasi utama |
| --- | --- | --- | --- |
| 1 | `users` | Akun admin/guru | — |
| 2 | `mata_pelajaran` | Master mata pelajaran | — |
| 3 | `kelas` | Master kelas per tahun ajaran | — |
| 4 | `ujian` | Data ujian | `mata_pelajaran_id`, `created_by` |
| 5 | `ujian_kelas` | Kelas peserta ujian | `ujian_id`, `kelas_id` |
| 6 | `paket_soal` | Paket A/B/C per ujian | `ujian_id` |
| 7 | `kunci_jawaban` | Kunci per nomor per paket | `paket_soal_id` |
| 8 | `hasil_ujian` | Hasil koreksi per peserta | `ujian_id`, `kelas_id`, `paket_soal_id` |
| 9 | `jawaban_siswa` | Rincian jawaban per nomor | `hasil_ujian_id` |
| 10 | `pengaturan` | Konfigurasi key-value | — |
| 11 | `log_aktivitas` | Jejak audit | `user_id` |

### Aturan integritas penting

- `hasil_ujian` memiliki unik `(ujian_id, kelas_id, nomor_absen)` untuk mencegah
  pengiriman ganda oleh peserta yang sama.
- `ujian_kelas` memiliki unik `(ujian_id, kelas_id)`.
- `paket_soal` memiliki unik `(ujian_id, kode_paket)`.
- `kunci_jawaban` memiliki unik `(paket_soal_id, nomor_soal)`.
- `jawaban_siswa` memiliki unik `(hasil_ujian_id, nomor_soal)`.
- `CHECK` membatasi jumlah soal 1–200, KKM 0–100, nilai 0–100, dan memastikan
  `jumlah_benar + jumlah_salah + jumlah_kosong = total_soal`.
- `kunci_snapshot` pada `hasil_ujian` menyimpan salinan kunci saat pengiriman agar
  perubahan kunci di kemudian hari tidak mengubah hasil lama.
- Sejak skema 1.1.0, `hasil_ujian` menyimpan `started_at` (waktu mulai) dan
  `durasi_detik` (lama pengerjaan) untuk ditampilkan pada konfirmasi hasil. Perubahan
  ini diterapkan melalui migrasi bernomor, bukan impor ulang skema.
- Sejak skema 1.2.0, `kunci_jawaban` dan `jawaban_siswa` menyimpan `tipe_soal`
  (`pg`, `benar_salah`, `multiple_response`) per nomor. `jawaban_siswa.skor` menyimpan
  skor butir 0–1 dan `hasil_ujian.skor_total` menyimpan jumlah skor seluruh butir.
  Multiple Response memakai skor parsial, sehingga nilai dihitung dari `skor_total`
  dibagi jumlah soal. Baris lama dimigrasikan sebagai `pg` tanpa mengubah nilai.
- Foreign key memakai `ON DELETE RESTRICT` untuk data master yang masih dipakai,
  `CASCADE` untuk relasi anak (paket, kunci, jawaban), dan `SET NULL` untuk pembuat
  ujian serta user pada log.

### Data awal

Hanya 15 baris konfigurasi default di `pengaturan` (nama aplikasi, identitas sekolah,
KKM default, tahun ajaran, semester, footer laporan, `versi_database`). **Tidak ada
akun, ujian, atau data dummy** yang dimasukkan ke database.

---

## 8. Alur Siswa (Tahap 4)

Halaman siswa terpisah dari panel admin dan tidak memerlukan login. Seluruh state
pengerjaan disimpan di session server; browser hanya mengirim pilihan jawaban.

```text
siswa/index.php          → daftar ujian aktif + kartu "Lanjutkan" bila sesi ada
siswa/identitas.php      → pilih kelas, isi nama & absen, pilih paket lengkap
siswa/ujian.php          → lembar jawaban (input cepat + pilihan per nomor)
siswa/hasil.php          → konfirmasi nilai, benar/salah/kosong, waktu, cetak
```

Ketentuan alur:

- Hanya ujian `aktif` dengan minimal satu kelas peserta aktif dan minimal satu paket
  yang kunci jawabannya lengkap 1..`jumlah_soal` yang dapat dimulai.
- Identitas, paket, dan `started_at` disimpan di `$_SESSION['siswa_attempt']`; tidak
  ada ID hasil atau token yang dapat ditebak di URL.
- Dua mode input selalu sinkron: teks berurutan `ABCDEABCDE` dan radio A–E per nomor.
  Karakter `-`, `_`, `.` menandai nomor kosong; spasi/baris baru/koma/titik koma
  diabaikan. Bila keduanya terisi, server mewajibkan isinya sama.
- Koreksi dilakukan di server memakai kunci paket yang dipilih. Nilai =
  `round(benar / total_soal × 100, 2)`. Jawaban kosong disimpan sebagai `NULL` dengan
  `is_benar = NULL` dan dihitung kosong.
- Penyimpanan `hasil_ujian`, seluruh `jawaban_siswa`, snapshot kunci, waktu mulai,
  durasi, dan waktu kirim dilakukan dalam satu transaksi.
- Unique key `(ujian_id, kelas_id, nomor_absen)` menjadi pengaman terakhir terhadap
  pengiriman ganda; pelanggaran ditangkap dan diterjemahkan menjadi pesan yang ramah.
- Setelah berhasil, sesi pengerjaan dihapus dan hasil hanya dapat dibuka dari sesi
  browser yang sama. ID hasil di URL diabaikan.
- Kunci jawaban tidak pernah dimuat ke HTML siswa; pratinjau hanya menampilkan pilihan
  milik peserta dan jumlah terisi/kosong.

---

## 9. Laporan dan Analisis (Tahap 5)

Tahap 5 menambahkan dua halaman panel dan empat jenis ekspor. Semua laporan memakai
data aktual dari database dan selalu dibatasi pada satu ujian; filter kelas bersifat
opsional. Ujian yang dipilih tidak pernah tercampur dengan ujian lain, dan guru hanya
dapat membuka ujian miliknya sendiri.

```text
admin/laporan/index.php     → hub laporan: metrik, distribusi, rekap kelas/paket, daftar nilai
admin/analisis/index.php    → analisis nilai dan analisis butir per paket
admin/laporan/excel.php     → unduhan .xlsx (5 lembar)
admin/laporan/pdf.php       → unduhan .pdf (kelas | analisis | siswa | distribusi)
```

### 9.1 Isi laporan

| Bagian | Data |
| --- | --- |
| Rekap nilai seluruh siswa | Nama, absen, kelas, paket, benar, salah, kosong, nilai, KKM, keterangan |
| Rekap per kelas | Peserta, rata-rata, tertinggi/terendah, tuntas/belum, persentase, total jawaban |
| Rekap per paket | Statistik nilai tiap paket; butir tidak digabung antar paket |
| Rincian jawaban | Nomor soal, jawaban siswa, kunci, hasil (benar/salah/kosong) per peserta |
| Distribusi nilai | Enam rentang: 0–49, 50–59, 60–69, 70–79, 80–89, 90–100 |
| Analisis butir | Kunci, jumlah/persen benar, tingkat kesukaran, daya pembeda, sebaran A–E, pengecoh efektif |

### 9.2 Rumus dan ambang batas

- Nilai akhir memakai nilai ternormalisasi yang sudah tersimpan pada `hasil_ujian`.
- Ketuntasan: `nilai >= KKM` ujian. Rata-rata, nilai tertinggi/terendah, dan persentase
  ketuntasan dihitung dari seluruh peserta pada filter aktif.
- Tingkat kesukaran: **Mudah** bila persen benar ≥ 70%, **Sedang** 30–69,99%,
  **Sukar** < 30%.
- Daya pembeda memakai kelompok atas dan bawah **27%** berdasarkan nilai total.
  Kategori: **Baik** ≥ 0,40; **Cukup** 0,30–0,39; **Rendah** 0,20–0,29; **Jelek** < 0,20.
  Perhitungan memerlukan minimal 4 peserta agar kedua kelompok tidak tumpang tindih;
  bila data kurang, nilai daya pembeda ditampilkan sebagai “Data kurang”.
- Pengecoh dinilai **efektif** bila dipilih minimal 5% peserta pada paket tersebut.
  Opsi kunci tidak dihitung sebagai pengecoh.

### 9.3 Aturan lintas paket

- Statistik **nilai** boleh diagregasi lintas paket karena skor sudah dinormalisasi
  (benar / jumlah soal × 100).
- Analisis **butir** selalu dihitung dan ditampilkan per `paket_soal_id`. Nomor soal
  yang sama pada Paket A dan Paket B tidak otomatis merujuk butir yang sama karena isi
  soal dan kunci dapat berbeda. Tidak ada penggabungan nomor lintas paket.

### 9.4 Ekspor

- **Excel** — satu berkas `.xlsx` berisi lembar `Rekap Nilai`, `Rekap per Kelas`,
  `Rekap Paket`, `Rincian Jawaban`, dan `Analisis Butir`. Sel ditulis dengan tipe
  eksplisit sehingga teks berawalan `=` tidak dieksekusi sebagai formula.
- **PDF** — FPDF dengan font inti Helvetica; teks UTF-8 ditransliterasi ke Latin-1.
  Jenis: `kelas` (rekap + daftar nilai), `analisis` (nilai + butir per paket),
  `siswa` (identitas + rincian jawaban), `distribusi` (sebaran + rekap kelas).
- Nama berkas memuat nama ujian, kelas (bila dipilih), dan tanggal ekspor.
- Ekspor memakai filter yang sama dengan halaman; parameter ujian/kelas/hasil
  divalidasi ulang di server. Ujian atau hasil yang bukan hak akses pengguna ditolak.

### 9.5 Grafik

Grafik memakai Chart.js lokal dan membaca data dari atribut `data-*` pada `<canvas>`,
sehingga tetap patuh pada CSP `script-src 'self'` tanpa inline script. Grafik yang
tersedia: distribusi nilai, rata-rata per paket, rata-rata per kelas, persentase benar
per butir, dan komposisi tingkat kesukaran.

### 9.6 Hak akses

- Admin melihat seluruh ujian; guru hanya ujian dengan `created_by = user.id`.
- Pemeriksaan dilakukan pada daftar ujian, halaman, helper laporan, dan endpoint ekspor
  (defense in depth), sehingga ID ujian yang ditebak tidak membocorkan data.

---

## 10. Pengaturan (Tahap 6)

Halaman `admin/pengaturan/index.php` hanya dibuka untuk administrator. Nilai disimpan
pada tabel `pengaturan` (key-value) dan dibaca ulang pada setiap request melalui
`setting()`, sehingga perubahan langsung tampak tanpa login ulang.

| Kelompok | Pengaturan | Dipakai pada |
| --- | --- | --- |
| Identitas aplikasi | `nama_aplikasi`, `footer_laporan` | Judul halaman, sidebar, halaman siswa, header/footer PDF |
| Profil sekolah | `nama_sekolah` | Sidebar, halaman siswa, header PDF |
| Profil sekolah | `alamat_sekolah`, `kota_sekolah`, `telepon_sekolah`, `email_sekolah`, `website_sekolah` | Baris kontak pada header PDF |
| Profil sekolah | `kepala_sekolah`, `nip_kepala_sekolah` | Arsip identitas sekolah untuk dokumen cetak |
| Akademik | `kkm_default` | Nilai awal KKM saat membuat ujian baru |
| Akademik | `tahun_ajaran_aktif`, `semester_aktif` | Penanda periode pada halaman siswa |
| Logo | `logo_sekolah` | Logo sidebar, halaman siswa, dan pratinjau pengaturan |

Aturan validasi:

- Nama aplikasi wajib 2–50 karakter.
- Email sekolah, website (`http`/`https`), telepon, dan NIP divalidasi formatnya.
- KKM default harus angka 0–100; tahun ajaran berformat `YYYY/YYYY` dan berurutan;
  semester hanya `Ganjil` atau `Genap`.
- Logo menerima PNG, JPG/JPEG, atau WEBP, maksimal 2 MB dan 3000 × 3000 piksel.
  Berkas disimpan sebagai nama acak di `uploads/logo/`; logo lama dihapus saat diganti.
- Tombol hapus logo mengosongkan `logo_sekolah` dan mengembalikan logo bawaan.
- Setiap perubahan dicatat ke `log_aktivitas` (`update`, `upload_logo`, `remove_logo`).

---

## 11. Keamanan yang Sudah Diterapkan

| Area | Implementasi |
| --- | --- |
| SQL Injection | PDO prepared statements, `ATTR_EMULATE_PREPARES = false` |
| Password | `password_hash()` / `password_verify()` (bcrypt) |
| Session | Nama cookie khusus, `HttpOnly`, `SameSite=Lax`, `Secure` otomatis di HTTPS, `use_strict_mode`, idle timeout 2 jam, regenerasi ID saat login |
| CSRF | Token per session pada seluruh POST; gagal → HTTP 403 |
| XSS | Output memakai helper `e()` (`htmlspecialchars`) |
| Akses langsung | Folder `config`, `includes`, `database`, `libraries`, `logs`, `docs`, `tools`, `vendor` diblokir `.htaccess`; file SQL/MD/JSON/LOG diblokir |
| Otorisasi | `require_auth()` pada halaman admin; `require_role(['admin'])` untuk modul pengguna dan pengaturan; guru hanya dapat mengelola ujian/paket miliknya sendiri |
| Error | Detail exception hanya tampil saat `APP_DEBUG`; production menampilkan halaman umum |
| Header | CSP `script-src 'self'`, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy` |
| Login | Maksimal 5 percobaan gagal, lockout 300 detik (berbasis session) |
| Audit | Login berhasil/gagal, logout, dan impor database dicatat ke `log_aktivitas` |
| Sesi siswa | Identitas/paket/waktu mulai hanya di session server; hasil terikat sesi; ID hasil di URL diabaikan |
| Koreksi siswa | Kunci tidak dikirim ke browser; validasi server, transaksi, unique key peserta, dan penanganan duplikat |
| Laporan | Semua query dibatasi `ujian_id`; guru hanya ujian miliknya; analisis butir dipisah per paket |
| Ekspor | Parameter divalidasi ulang di endpoint; sel Excel bertipe eksplisit agar bebas formula injection |
| Unggahan | Folder `uploads/` tidak mengeksekusi PHP/SVG melalui `.htaccess`; logo divalidasi lewat `getimagesize()` (MIME, ukuran, dimensi) dan disimpan dengan nama acak |
| Pengaturan | Hanya admin; validasi server untuk email/URL/telepon/NIP/KKM/tahun/semester; perubahan dicatat ke `log_aktivitas` |

Catatan: CSP membatasi skrip ke `'self'`, sehingga semua JavaScript harus berupa file
lokal. Data untuk grafik/flash dikirim melalui `data-*` attribute, bukan inline script.

---

## 12. Skrip CLI

Dijalankan dari terminal, bukan browser.

### Membuat hash password

```bat
cd C:\xampp\htdocs\ujian
php tools\hash-password.php "PasswordKuat123"
```

Menghasilkan hash bcrypt 60 karakter yang dapat dipakai manual di SQL.

### Mengubah password akun

```bat
php tools\set-password.php admin "PasswordBaru123"
```

Berguna sebelum modul Manajemen Pengguna tersedia. Perintah ini juga mengaktifkan
kembali akun (`is_active = 1`) dan menulis log aktivitas.

### Prosedur darurat membuat admin

Jika akun admin terkunci atau hilang:

1. Buat hash: `php tools\hash-password.php "PasswordDarurat123"`.
2. Jalankan SQL berikut melalui phpMyAdmin:

   ```sql
   INSERT INTO users (nama, username, email, password, role, is_active)
   VALUES ('Administrator', 'admin', NULL, '<hash-dari-langkah-1>', 'admin', 1);
   ```

3. Login, lalu segera ubah password melalui modul **Manajemen Pengguna**
   atau dengan `tools/set-password.php`.

---

## 13. Pengujian Tahap 1–6

Pengujian dilakukan pada XAMPP dengan Apache dan MySQL aktif.

### Instalasi dan database

- [x] Installer menampilkan pemeriksaan sistem dan seluruh item wajib lulus.
- [x] Impor SQL membuat 11 tabel InnoDB/utf8mb4 dan 15 baris pengaturan.
- [x] Pembuatan admin pertama berhasil dan password tersimpan sebagai hash bcrypt.
- [x] Installer terkunci setelah akun administrator ada.
- [x] Duplikasi username/email ditolak.
- [x] Impor ulang pada database berisi data menghapus tabel lama (sesuai sifat file SQL).

### Autentikasi

- [x] Login benar → dashboard.
- [x] Password salah → pesan gagal, tanpa membocorkan akun mana yang salah.
- [x] Login memakai email berhasil.
- [x] Redirect ke halaman yang diminta sebelum login (`intended URL`).
- [x] Logout hanya melalui POST + CSRF.
- [x] Token CSRF tidak valid → HTTP 403.
- [x] `GET auth/logout.php` → HTTP 405.
- [x] Setelah 5 kegagalan → lockout 300 detik dengan pesan yang sesuai.
- [x] Akses dashboard tanpa login → redirect ke login (HTTP 302).

### Dashboard dan aset

- [x] Statistik, ringkasan status, tabel ujian terbaru, dan grafik memakai query nyata.
- [x] Data uji sementara ditampilkan dengan benar, lalu dihapus kembali.
- [x] AdminLTE, Bootstrap, Icons, jQuery, Chart.js termuat (HTTP 200).
- [x] Tidak ada error JavaScript pada konsol browser.
- [x] Menu yang belum tersedia ditampilkan nonaktif dengan badge “Segera”.

### Proteksi dan integritas

- [x] Akses langsung ke `config/`, `includes/`, `database/`, `vendor/`, `logs/` → 403.
- [x] Duplikasi peserta → error 1062.
- [x] Pelanggaran `CHECK` jumlah jawaban → error 4025.
- [x] Duplikasi paket/kunci → error 1062.
- [x] Foreign key tidak valid → error 1452.
- [x] `php -l` lulus untuk seluruh file PHP non-vendor.

### Kondisi akhir

- Database terpasang, hanya akun administrator hasil installer, data ujian kosong, 15 pengaturan default.
- Log uji dibersihkan.
- Tidak ada akun atau data dummy yang tertinggal.

### Modul Tahap 2

Seluruh pengujian HTTP dilakukan dengan sesi admin/guru nyata dan data sementara yang
dihapus kembali setelah pengujian.

- [x] **Mata pelajaran** — tambah, ubah, hapus, validasi kode/nama duplikat, filter status, DataTables.
- [x] **Kelas** — tambah, ubah, hapus, validasi format tahun ajaran `YYYY/YYYY` berurutan, filter tingkat/tahun/status.
- [x] **Pengguna** — tambah, ubah, hapus, hash password, validasi password lemah, filter role/status, guru mendapat 403.
- [x] **Ujian** — tambah, ubah, hapus, relasi banyak kelas dalam satu transaksi, validasi jam selesai > mulai, filter status/mapel.
- [x] **Paket soal** — tambah, ubah, hapus, kode unik per ujian, filter ujian, guru hanya mengakses paket ujian miliknya.
- [x] **Status ujian** — ubah draft/aktif/selesai. Aturan aktivasi berbasis kelengkapan paket dan kunci diuji pada tahap 3.
- [x] **Proteksi relasi** — mapel dipakai ujian, kelas terhubung ujian/hasil, pengguna memiliki ujian, ujian/paket memiliki hasil: semua ditolak dengan pesan jelas.
- [x] **Proteksi akun** — admin tidak dapat menghapus/menonaktifkan/menurunkan role akun sendiri; admin aktif terakhir dilindungi.
- [x] **Kepemilikan data** — guru tidak melihat ujian/paket admin, tidak dapat mengubah/menghapus ujian admin (403), dan dashboard guru hanya menghitung ujian miliknya.
- [x] **UI** — DataTables aktif (cari, urut, paginasi), filter select otomatis, modal edit terisi otomatis, validasi server membuka ulang modal, konfirmasi hapus SweetAlert2 tampil dan dapat dibatalkan.
- [x] **Penghapusan positif** — ujian dihapus setelah hasil dummy dibersihkan; paket dan relasi kelas ikut terhapus (cascade).
- [x] `php -l` lulus untuk seluruh file PHP yang dibuat/diubah pada tahap 2.
- [x] Database akhir bersih: hanya akun `admin` yang tersisa, tanpa ujian/paket/hasil/data master uji, 15 pengaturan utuh.

### Modul Tahap 3 (kunci jawaban)

Pengujian memakai ujian 10, 40, dan 100 soal dengan dua paket pada ujian yang sama,
akun admin/guru sementara, serta satu hasil peserta buatan. Seluruh data uji dihapus
kembali setelah pengujian.

- [x] **Tanpa perubahan skema** — `kunci_jawaban` yang ada sudah mendukung kunci A–E per
  paket dengan unik `(paket_soal_id, nomor_soal)`; tidak ada migrasi yang diperlukan.
- [x] **Daftar paket** — filter ujian, DataTables, kartu ringkasan, dan progres `x/y`
  per paket tampil benar; tombol Kunci menuju editor paket yang tepat.
- [x] **Editor interaktif** — 40 dan 100 baris nomor dengan pilihan A–E; pilihan
  tersimpan dimuat kembali sesuai paket.
- [x] **Progres langsung** — angka terisi/kosong, persen, warna bar, dan label tombol
  simpan berubah saat pilihan diubah, dikosongkan, atau diisi massal.
- [x] **Input massal** — teks `ABCDEABCDE` mengisi nomor 1–10 tanpa menghapus nomor
  lain; pemisah spasi/enter/koma/titik/tanda hubung diterima; 100 kunci berhasil
  dimasukkan; karakter tidak valid (X) dan jumlah melebihi soal (41 dari 40) ditolak.
- [x] **Validasi simpan** — nilai selain A–E ditolak; simpan penuh berhasil; simpan
  sebagian pada ujian aktif ditolak agar kunci tidak menjadi tidak lengkap.
- [x] **Pemisahan paket** — pengisian paket B tidak mengubah kunci paket A; kunci
  tersimpan dan progres dihitung per `paket_soal_id`.
- [x] **Aktivasi ujian** — aktivasi ditolak dengan pesan rinci bila paket belum ada
  atau kunci kurang; pembuatan ujian baru berstatus aktif juga ditolak; aktivasi
  berhasil setelah semua paket lengkap (diuji pada 40 dan 100 soal).
- [x] **Ujian aktif** — perubahan nama/konfigurasi ujian yang sudah aktif tetap dapat
  disimpan selama kunci lengkap.
- [x] **Kunci terkunci** — setelah ada `hasil_ujian`, editor berubah menjadi tampilan
  baca-saja; POST simpan dan input massal ditolak di server dan data tidak berubah.
- [x] **Hak akses** — guru mendapat 403 pada ujian/paket milik admin, hanya melihat
  ujian miliknya, dan dapat mengisi kunci paketnya sendiri.
- [x] **UI/JS** — navigasi keyboard A–E, panah atas/bawah/kiri/kanan, Delete/Backspace,
  tombol nomor kosong, kosongkan baris, kosongkan semua dengan konfirmasi SweetAlert2,
  sanitasi input massal, dan peringatan perubahan belum disimpan berfungsi.
- [x] `php -l` lulus untuk seluruh file PHP yang diubah; `node --check` lulus untuk
  `assets/js/kunci-jawaban.js`; tidak ada error JavaScript yang tidak tertangani.
- [x] Database akhir bersih: hanya akun `admin`, tanpa ujian/paket/kunci/hasil/data
  master uji, 15 pengaturan utuh.

### Alur siswa Tahap 4

Pengujian memakai 3 mata pelajaran, 4 kelas (satu nonaktif), 4 ujian aktif
(10 soal dengan dua paket lengkap dan satu paket tidak lengkap, 5 soal pada ujian
berbeda, 100 soal, serta ujian tanpa kelas), dan 1 ujian draft. Seluruh data uji dan
hasil dihapus kembali setelah pengujian.

- [x] **Migrasi skema** — `20261116_001_tambah_waktu_pengerjaan_hasil_ujian.sql`
  menambahkan `started_at` dan `durasi_detik`; `versi_database` menjadi `1.1.0`.
  `db_sikilat.sql` untuk instalasi baru sudah memuat kedua kolom.
- [x] **Daftar ujian** — hanya ujian aktif yang tampil; ujian draft, ujian tanpa kelas
  aktif, dan paket dengan kunci tidak lengkap ditandai belum siap atau disembunyikan.
  Kelas nonaktif tidak muncul sebagai pilihan peserta.
- [x] **Validasi identitas** — nama < 3 karakter, absen kosong/format salah, kelas
  bukan peserta, kelas nonaktif, paket tidak lengkap, dan paket milik ujian lain
  ditolak dengan pesan spesifik.
- [x] **Duplikat identitas** — absen yang sudah mengirim pada ujian+kelas yang sama
  ditolak saat pengisian identitas.
- [x] **Sinkronisasi jawaban** — mengetik `abcde` mengisi nomor 1–5 dan progres
  `5/10`; memilih radio nomor 7 mengubah input cepat menjadi `ABCDE-B`; tombol
  kosong dan kosongkan semua berfungsi; draft bertahan di `sessionStorage`.
- [x] **Validasi server** — huruf di luar A–E, nomor di luar rentang, format kunci
  non-numerik, karakter massal tidak valid, ketidakcocokan input cepat vs radio, dan
  jumlah jawaban melebihi soal ditolak tanpa menyisipkan hasil.
- [x] **Koreksi otomatis** — hasil 60 (6 benar, 4 kosong), 0 (semua kosong), 100 pada
  paket B, 20 pada paket A dengan jawaban yang sama (pemisahan paket benar), 100 pada
  ujian 100 soal, dan 100 pada ujian/kelas berbeda.
- [x] **Detail tersimpan** — 10/100/5 baris `jawaban_siswa` per hasil, jawaban kosong
  `NULL`, `is_benar` kosong `NULL`, snapshot kunci JSON, waktu mulai, waktu kirim, dan
  durasi terisi.
- [x] **Konkurensi** — dua sesi berbeda mengirim identitas yang sama hampir bersamaan;
  tepat satu hasil tersimpan, sesi lain menerima pesan “sudah pernah dikirim”, tanpa
  error PHP. Pengujian diulang dua kali dengan hasil sama.
- [x] **Isolasi hasil** — sesi baru yang membuka `hasil.php?hasil_id=4&id=4` melihat
  “Belum ada hasil untuk ditampilkan”; nama, nilai, dan kunci tidak bocor.
- [x] **Kunci tidak bocor** — HTML lembar jawaban tidak memuat `kunci_snapshot`,
  nama tabel kunci, maupun pola JSON kunci; hanya opsi A–E dan placeholder.
- [x] **UI/JS** — tampilan responsif HP/desktop, timer berjalan, pratinjau dengan
  jumlah terisi/kosong, konfirmasi centang, proteksi klik ganda, dan peringatan
  sebelum meninggalkan halaman. `node --check assets/js/siswa.js` lulus.
- [x] `php -l` lulus untuk seluruh file PHP baru/diubah pada tahap 4.
- [x] **Kondisi akhir** — hanya akun `admin`, 15 pengaturan, tanpa data master/ujian/
  paket/kunci/hasil/jawaban/log uji; auto-increment direset.

### Laporan dan analisis Tahap 5

Pengujian memakai fixture deterministik: 2 ujian berbeda mapel, 3 kelas, 3 paket
(2 paket pada ujian utama dengan kunci berbeda), 16 hasil, dan 140 baris jawaban.
Seluruh fixture dan berkas uji dihapus kembali setelah validasi.

- [x] **Tanpa perubahan skema** — `hasil_ujian` dan `jawaban_siswa` sudah memuat data
  yang dibutuhkan; `versi_database` tetap `1.1.0`.
- [x] **Isolasi ujian** — 12 hasil ujian 1 dan 4 hasil ujian 2 tidak pernah tercampur;
  filter kelas 1 menghasilkan 6 siswa; kelas tanpa hasil tetap terdaftar dengan 0 peserta.
- [x] **Statistik nilai** — peserta 12, rata-rata 58,33, tertinggi 100, terendah 20,
  4 tuntas, 8 belum, ketuntasan 33,33%, total benar/salah/kosong 70/47/3.
- [x] **Rekap kelas dan paket** — rata-rata X IPA 1 = 81,67 dan X IPA 2 = 35,00;
  Paket A = 56,67 dan Paket B = 60,00.
- [x] **Distribusi** — jumlah per rentang `[4, 2, 1, 1, 2, 2]` dan totalnya 12.
- [x] **Analisis butir per paket** — kunci, jumlah benar, tingkat kesukaran, dan daya
  pembeda Paket A/B diverifikasi terpisah; kunci nomor 1 berbeda antar paket dan tidak
  digabung. Paket dengan 3 peserta menampilkan daya pembeda “Data kurang”.
- [x] **Pengecoh** — opsi D nomor 3 Paket A terpilih 1 kali (16,67%) dan ditandai efektif;
  kunci C ditandai sebagai kunci; total pengecoh efektif Paket A = 8 dari 40.
- [x] **Detail siswa** — 10 jawaban Ahmad Fauzi dimuat; hasil dengan ujian atau kelas
  yang salah ditolak (`null`).
- [x] **Excel** — workbook 5 lembar dengan nama sesuai, nilai dan total benar, rincian
  120+ baris termasuk jawaban kosong, serta 20 baris analisis butir. Teks `=SUM(1,2)`
  tersimpan sebagai string (bukan formula).
- [x] **PDF** — jenis kelas, analisis, distribusi, dan siswa menghasilkan berkas diawali
  `%PDF-`, berakhiran `%%EOF`, dan minimal satu halaman; analisis dan rekap kelas
  multi-halaman.
- [x] **Endpoint** — ekspor seluruh ujian, per kelas, dan ujian kedua menghasilkan isi
  yang berbeda dan sesuai filter; jenis laporan tidak valid ditolak dengan pesan
  “Jenis Laporan Tidak Valid”.
- [x] **Halaman** — laporan/analisis tampil tanpa warning/notice/fatal pada kondisi
  berisi, kosong, dan ujian tidak ditemukan; grafik dan `js/laporan.js` termuat.
- [x] **Hak akses guru** — akun guru sementara tidak melihat ujian admin, helper
  laporan menolak ujian tersebut, dan endpoint Excel menolak dengan pesan yang sesuai.
- [x] **Regresi** — 110 pemeriksaan helper/rumus/ekspor dan 31 pemeriksaan endpoint
  lulus; tidak ada log aktivitas baru dari proses baca laporan.
- [x] **Kondisi akhir** — hanya akun `admin`, 15 pengaturan, skema `1.1.0`, tanpa data
  master/ujian/paket/kunci/hasil/jawaban/log uji; auto-increment direset.

### Pengaturan Tahap 6

Pengujian memakai server bawaan PHP dan cookie sesi sementara agar unggahan multipart
dan redirect HTTP benar-benar diuji. Router dan berkas uji dihapus setelah validasi.

- [x] **Render admin** — GET halaman menampilkan seluruh field (identitas, profil
  sekolah, akademik, logo, informasi sistem) dan tautan sidebar tanpa PHP error.
- [x] **Validasi** — nama aplikasi kosong, email salah, website bukan `http(s)`,
  telepon/NIP berisi huruf, KKM 150, tahun `2025/2027`, dan semester tidak dikenal
  ditolak dengan HTTP 200; database tidak berubah.
- [x] **Simpan** — POST valid mengembalikan 302, seluruh nilai tersimpan, dan
  `log_aktivitas` mencatat daftar field yang berubah. POST tanpa perubahan memberi
  pesan “Tidak ada perubahan pengaturan.” tanpa menulis log.
- [x] **Efek langsung** — nama sekolah baru tampil pada sidebar, halaman pengaturan,
  dan halaman siswa pada request berikutnya.
- [x] **Logo** — PNG dan JPG berhasil diunggah ke `uploads/logo/` dengan nama acak;
  logo lama dihapus saat diganti. Berkas teks berekstensi `.png`, berkas 3 MB, dan
  unggahan tanpa berkas ditolak dengan pesan yang sesuai.
- [x] **Hapus logo** — `logo_sekolah` dikosongkan, berkas dihapus, tampilan kembali ke
  logo bawaan; penghapusan kedua memberi pesan informatif.
- [x] **CSRF** — token salah ditolak dengan HTTP 403 dan nilai tidak berubah.
- [x] **Hak akses** — akun guru sementara mendapat HTTP 403 tanpa form pengaturan dan
  tidak melihat menu Pengaturan di sidebar; akun uji dihapus setelah pengujian.
- [x] **PDF** — header laporan memuat nama sekolah dan baris kontak dari pengaturan;
  dokumen tetap diawali `%PDF-` dan berakhiran `%%EOF`.
- [x] **Kebersihan** — nilai pengaturan yang diubah pengujian dikembalikan, log uji
  dihapus, berkas logo uji dihapus, dan `logs/php-error.log` tetap kosong.

### Tipe soal Benar/Salah dan Multiple Response (skema 1.2.0)

Pengujian memakai database uji terpisah (`db_sikilat_test`) berisi satu paket campuran
PG, Benar/Salah, dan Multiple Response, tiga hasil dengan skor parsial, serta paket
pada ujian draft untuk menguji editor kunci yang belum terkunci. Database uji dan
berkas sementara dihapus setelah validasi; database produksi tidak diubah selain
migrasi.

- [x] **Migrasi** — `20261118_001_tipe_soal_dan_skor_parsial.sql` menambahkan
  `tipe_soal` pada `kunci_jawaban`/`jawaban_siswa`, `jawaban_siswa.skor`, dan
  `hasil_ujian.skor_total`; `versi_database` menjadi `1.2.0`. Data lama tetap `pg`,
  `skor_total` terisi dari jumlah benar, dan nilai hasil lama tidak berubah
  (72,00 / 92,00 / 84,00).
- [x] **Helper** — 29/29 pemeriksaan lulus untuk normalisasi jawaban, skor parsial
  (potongan `A` dari kunci `A,C` = 0,5; `A,B` dari `A,B,D` = 2/3; `A,C,E` dari `A,C`
  = 0,5), input cepat per tipe, dan kolom opsi dinamis.
- [x] **Ekspor** — 40/40 pemeriksaan lulus pada database uji: header `Tipe`/`Skor`
  dan status `Parsial` di Excel “Rincian Jawaban”, kolom `S %` dinamis pada analisis,
  serta tabel PDF memuat `Tipe`, `Skor`, dan catatan skor parsial.
- [x] **Data produksi** — 18/18 pemeriksaan ekspor pada `db_sikilat` (PDF kelas,
  analisis, distribusi, siswa dan Excel) tetap valid; 3 hasil, 75 jawaban, dan 100
  kunci utuh.
- [x] **UI/HTTP** — 38/38 pemeriksaan lulus pada server bawaan PHP: analisis
  menampilkan kolom `Tipe` dengan lencana `B/S`/`MR`, editor kunci menampilkan
  pemilih tipe, input Benar/Salah, kotak centang Multiple Response, dan input cepat
  sadar tipe; halaman siswa menampilkan kontrol per tipe; pengiriman nyata menghasilkan
  `Skor total 3,17`, nilai `52,78`, 2 benar, 4 salah/parsial, dan catatan skor parsial.
- [x] **Kebersihan** — server uji dihentikan, database uji dihapus, berkas sementara
  dibersihkan, dan `logs/php-error.log` kosong.

---

## 14. Batasan Saat Ini

1. **Tampilan audit log belum tersedia.** Pengaturan aplikasi/sekolah, laporan, ekspor
   Excel/PDF, analisis butir, alur siswa, dan koreksi otomatis sudah aktif.
2. **Throttle login berbasis session.** Penyerang yang membuang cookie dapat mengulang
   percobaan. Pembatasan berbasis IP/akun disarankan pada tahap produksi.
3. **Manajemen pengguna sudah tersedia**, tetapi belum ada fitur reset password mandiri
   oleh guru. Reset masih dilakukan admin melalui modal ubah pengguna atau CLI.
4. **DataTables, SweetAlert2, Chart.js, PhpSpreadsheet, dan FPDF sudah dipakai.**
   Ekspor memerlukan `vendor/autoload.php` serta ekstensi `zip`, `xml`, dan `dom`.
5. **Belum ada backup otomatis.** Backup database masih manual melalui phpMyAdmin.
6. **Installer tidak memverifikasi kepemilikan folder** selain melalui pemeriksaan izin
   tulis. Setelah instalasi, disarankan menghapus atau membatasi `install.php`.
7. **Belum ada test suite formal** (PHPUnit). Validasi Tahap 5–6 memakai skrip CLI/HTTP
   sementara untuk memeriksa rumus, ekspor, unggahan, dan hak akses.
8. **Email belum dikirim** karena belum ada modul notifikasi.
9. **Ketersediaan ujian memakai status.** Ujian dianggap tersedia bila berstatus
   `aktif`; belum ada pembatasan ketat berdasarkan tanggal/jam atau token sesi ujian.
10. **Hasil terikat perangkat.** Konfirmasi hasil hanya dapat dibuka dari sesi browser
    yang mengirim jawaban; belum ada portal login siswa untuk melihat riwayat nilai.
11. **Identitas peserta belum terhubung master siswa.** Nama dan nomor absen diinput
    manual; keunikan dijaga per ujian+kelas+absen.

---

## 15. Deployment ke cPanel

1. Upload seluruh proyek (termasuk `vendor/` bila ekspor Excel/PDF diperlukan).
2. Arahkan domain/subdomain ke folder aplikasi.
3. Buat database dan user MySQL melalui cPanel.
4. Buat `config/database.local.php` berisi kredensial hosting.
5. Impor `database/db_sikilat.sql` melalui phpMyAdmin **hanya pada database kosong**.
6. Buka `install.php` untuk membuat admin pertama, lalu kunci akses installer.
7. Pastikan `uploads/` dan `logs/` dapat ditulis (permission 755/775 sesuai hosting).
8. Aktifkan HTTPS. Cookie session otomatis memakai flag `Secure` bila HTTPS terdeteksi.
9. Aplikasi otomatis memakai mode production di luar localhost. Set
   `SIKILAT_ENV=production` hanya bila ingin memaksa mode tersebut pada lingkungan
   lain, dan gunakan `SIKILAT_DEBUG=false` untuk menyembunyikan detail error.

### 15.1 Sumber di GitHub

Repositori memuat kode aplikasi, aset lokal, skema database, migrasi, dan
dokumentasi. Berkas berikut sengaja tidak diunggah:

- `vendor/` — dependensi Composer; pasang dengan `composer install` (Composer 2.x)
  sebelum memakai ekspor Excel/PDF.
- `config/database.local.php` dan `.env` — kredensial hosting bersifat lokal.
- `logs/*.log` dan `uploads/logo/*` — data runtime dan unggahan pengguna.
- Cadangan serta ekspor sementara (`*.bak`, `*.sql` di root, `*.pdf`, `*.xlsx`).

Alur singkat dari repositori:

```bash
git clone <url-repositori> ujian
cd ujian
composer install
```

Setelah itu lanjutkan langkah 3-9 di atas. Untuk hosting tanpa Composer, jalankan
`composer install --no-dev` di komputer lokal, lalu unggah/ZIP seluruh proyek
**termasuk** `vendor/` (tetap jangan sertakan `config/database.local.php` dan
`logs/*.log`).

---

## 16. Pemecahan Masalah

| Gejala | Penyebab umum | Solusi |
| --- | --- | --- |
| “Koneksi database gagal” | MySQL mati / kredensial salah | Jalankan MySQL, periksa `config/database.local.php` |
| Halaman installer tidak bisa impor | Izin folder atau file SQL tidak terbaca | Periksa `database/db_sikilat.sql` dan permission |
| Aset tampil tanpa gaya | Base URL salah | Pastikan aplikasi diakses via `http://localhost/ujian/` |
| Login selalu gagal | Akun nonaktif / password salah | Aktifkan akun atau reset via `tools/set-password.php` |
| “Terlalu banyak percobaan login” | Lockout 5 menit | Tunggu 300 detik atau hapus cookie session |
| Error 403 saat membuka file | Proteksi `.htaccess` | Normal untuk folder internal; akses melalui halaman aplikasi |
| Grafik tidak tampil | JavaScript diblokir / aset hilang | Periksa konsol dan folder `assets/plugins/chartjs/` |
| Ujian tidak muncul di halaman siswa | Status draft/selesai, belum ada kelas aktif, atau kunci tidak lengkap | Aktifkan ujian, tambahkan kelas peserta, lengkapi kunci paket |
| “Sudah pernah dikirim” | Absen tersebut sudah punya hasil pada ujian+kelas yang sama | Periksa `hasil_ujian`; hubungi pengawas, jangan mengulang dari perangkat lain |
| Hasil tidak tampil setelah kirim | Sesi browser berbeda/terhapus | Buka dari perangkat dan sesi yang sama; hasil tidak dapat diakses lewat ID di URL |
| Tombol Excel/PDF gagal | `vendor/` atau ekstensi `zip`/`xml`/`dom` tidak tersedia | Jalankan `composer install`, aktifkan ekstensi PHP |
| Laporan kosong padahal ada hasil | Ujian/kelas pada filter tidak cocok, atau guru membuka ujian admin | Pilih ujian yang benar; guru hanya melihat ujian miliknya |
| Daya pembeda “Data kurang” | Peserta per paket < 4 sehingga kelompok 27% tumpang tindih | Tambah peserta atau gunakan analisis seluruh ujian |
| Angka butir berbeda antar paket | Nomor soal tidak otomatis setara lintas paket | Bandingkan dalam paket yang sama; jangan gabungkan nomor |
| Logo gagal diunggah | Format/ukuran tidak sesuai atau folder tidak dapat ditulis | Gunakan PNG/JPG/WEBP ≤ 2 MB dan 3000 px; periksa izin `uploads/logo/` |
| Perubahan pengaturan tidak terlihat | Halaman lama masih di cache browser | Muat ulang halaman; nilai dibaca ulang pada setiap request |
| Menu Pengaturan tidak muncul | Akun bukan administrator | Login sebagai admin; guru tidak boleh mengubah pengaturan sekolah |
| Error 500 setelah pindah hosting | Ekstensi PHP kurang | Aktifkan `pdo_mysql`, `mbstring`, `fileinfo` |
| Ingin mengulang instalasi | Database berisi data lama | Backup, lalu impor ulang SQL (destruktif) |

Log error PHP ditulis ke `logs/php-error.log` dan tidak dapat diakses dari browser.

---

## 17. Dokumentasi Lanjutan

- `docs/ARSITEKTUR.md` — analisis kebutuhan, keputusan arsitektur, alur request/auth,
  ERD, aturan integritas, desain keamanan, dan roadmap modul.
- `database/migrations/README.md` — konvensi migrasi skema untuk tahap berikutnya.

---

## 18. Tahap Berikutnya

Tahap 6 sudah selesai. Tahap 7 **belum dimulai** dan menunggu instruksi. Rencana modul:

1. Tampilan audit log aktivitas pengguna (filter modul, pengguna, dan tanggal).
2. Peningkatan lanjutan: portal riwayat nilai siswa, pembatasan jadwal/token ujian,
   dan pengujian otomatis (PHPUnit).
