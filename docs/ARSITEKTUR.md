# Arsitektur SIKILAT — Tahap 1–6

Dokumen ini menjelaskan analisis kebutuhan, keputusan arsitektur, alur permintaan,
model data, aturan integritas, desain keamanan, dan rencana modul untuk tahap
berikutnya. Ruang lingkup dokumen mencakup **Tahap 1: fondasi aplikasi**,
**Tahap 2: modul manajemen data**, **Tahap 3: kunci jawaban per paket**,
**Tahap 4: alur ujian siswa serta koreksi otomatis**, **Tahap 5: laporan,
ekspor Excel/PDF, serta analisis butir per paket**, dan **Tahap 6: pengaturan
aplikasi, profil sekolah, logo, serta default akademik**.

---

## 1. Analisis Kebutuhan

### 1.1 Aktor

| Aktor | Autentikasi | Hak akses saat ini | Rencana tahap berikutnya |
| --- | --- | --- | --- |
| Administrator | Ya | Seluruh modul panel, pengguna, master data, ujian, paket soal, kunci jawaban, status, laporan, analisis, dan pengaturan | Audit log |
| Guru | Ya | Dashboard miliknya, baca master data, kelola ujian/paket/kunci miliknya, serta laporan & analisis ujian miliknya | Portal riwayat nilai |
| Siswa | Tidak | Pilih ujian aktif, isi identitas, pilih paket, isi & kirim jawaban, lihat konfirmasi hasil | Riwayat nilai, pembatasan jadwal/token ujian |

### 1.2 Kebutuhan fungsional

**Tahap 1**

1. Aplikasi dapat dipasang pada XAMPP maupun hosting cPanel tanpa mengubah kode.
2. Installer memeriksa server, membuat database, mengimpor skema, dan membuat admin
   pertama dengan password ter-hash.
3. Admin/guru dapat login memakai username atau email, logout, dan tetap login
   selama sesi aktif.
4. Dashboard menampilkan ringkasan data nyata dari database.
5. Halaman siswa tersedia sebagai penanda dan tidak memerlukan login.
6. Struktur folder dan skema database siap menampung seluruh modul berikutnya.

**Tahap 2**

7. CRUD mata pelajaran dan kelas dengan validasi server-side serta proteksi relasi.
8. CRUD pengguna admin/guru dengan hash password dan perlindungan akun minimum.
9. CRUD ujian dengan relasi banyak kelas dan pengaturan status draft/aktif/selesai.
10. CRUD paket soal dengan kode unik per ujian.
11. Daftar data memakai DataTables; konfirmasi mutasi memakai SweetAlert2; form
    memakai modal Bootstrap; filter memakai select GET yang terkirim otomatis.
12. Hak akses dibatasi per role dan kepemilikan ujian.

**Tahap 3**

13. Setiap paket soal memiliki kunci jawaban A–E per nomor sesuai `jumlah_soal` ujian.
14. Kunci dapat diisi melalui tabel interaktif atau input massal teks berurutan
    (misalnya `ABCDEABCDE`) dengan pemisah opsional.
15. Progres pengisian ditampilkan per paket dan diperbarui langsung di browser.
16. Ujian tidak dapat diaktifkan sebelum memiliki paket dan seluruh kuncinya lengkap;
    aturan ini ditegakkan pada aksi status maupun penyimpanan ujian.
17. Kunci tidak dapat diubah setelah ujian memiliki hasil peserta; editor berubah
    menjadi tampilan baca-saja dan POST tetap ditolak di server.

**Tahap 4**

18. Siswa tanpa login dapat memilih ujian berstatus aktif yang memiliki kelas peserta
    aktif dan paket dengan kunci lengkap.
19. Siswa mengisi kelas, nama lengkap, nomor absen, dan memilih paket; identitas serta
    waktu mulai disimpan di session server.
20. Lembar jawaban menyediakan input cepat berurutan dan pilihan A–E per nomor yang
    selalu tersinkronisasi, dengan pratinjau sebelum pengiriman.
21. Server memvalidasi seluruh jawaban, mengoreksi memakai kunci paket terpilih dalam
    satu transaksi, dan menyimpan hasil beserta rincian jawaban, snapshot kunci, waktu
    mulai, durasi, dan waktu kirim.
22. Nilai dihitung `benar / total_soal × 100` dengan pembulatan dua desimal; benar,
    salah, dan kosong dihitung terpisah.
23. Pengiriman ganda dicegah oleh session, pemeriksaan transaksi, dan unique key
    `(ujian_id, kelas_id, nomor_absen)`; jawaban tidak dapat diubah setelah berhasil.
24. Konfirmasi hasil hanya dapat dibuka dari sesi browser yang mengirim; ID hasil di URL
    tidak dipakai dan tidak ada enumerasi hasil siswa lain.
25. Kunci jawaban tidak pernah dikirim ke browser; halaman siswa hanya memuat opsi
    jawaban dan ringkasan milik peserta.

**Tahap 5**

26. Admin/guru dapat memilih satu ujian dan opsional satu kelas, lalu melihat rekap
    nilai seluruh siswa, rekap per kelas, rekap per paket, distribusi nilai, dan
    rincian jawaban setiap peserta.
27. Laporan menampilkan jumlah benar/salah/kosong, paket yang dipakai, nilai akhir,
    KKM, dan status tuntas. Semua data dibatasi pada ujian yang dipilih dan tidak
    boleh tercampur dengan ujian lain.
28. Analisis butir menampilkan kunci, persen benar, tingkat kesukaran, daya pembeda
    kelompok atas/bawah 27%, sebaran pilihan A–E, dan penanda pengecoh efektif.
29. Analisis butir selalu dipisah per paket; nomor soal tidak digabung antar paket
    meskipun angkanya sama.
30. Statistik nilai boleh diagregasi lintas paket karena nilai sudah dinormalisasi.
31. Tersedia ekspor Excel berisi rekap nilai, rekap kelas, rekap paket, rincian
    jawaban, dan analisis butir; serta ekspor PDF untuk hasil kelas, analisis,
    laporan individu siswa, dan distribusi nilai.
32. Grafik distribusi, rata-rata kelas/paket, persentase benar per butir, dan
    komposisi tingkat kesukaran memakai Chart.js lokal tanpa inline script.
33. Guru hanya dapat membuka laporan/analisis/ekspor untuk ujian miliknya; ID ujian
    yang ditebak harus ditolak.

**Tahap 6**

34. Administrator dapat mengubah identitas aplikasi (`nama_aplikasi`, `footer_laporan`)
    dan profil sekolah (nama, alamat, kota, telepon, email, website, kepala sekolah,
    NIP) dari panel.
35. Administrator dapat mengunggah logo PNG/JPG/WEBP (maksimal 2 MB, 3000 × 3000 piksel)
    dan menghapusnya untuk kembali ke logo bawaan.
36. Administrator dapat menetapkan KKM default, tahun ajaran aktif, dan semester aktif.
37. Seluruh nilai divalidasi di server, disimpan ke tabel `pengaturan`, dan dicatat pada
    `log_aktivitas`.
38. Perubahan pengaturan langsung dipakai pada request berikutnya: judul/sidebar panel,
    halaman siswa, nilai awal form ujian, serta header dan footer laporan PDF.
39. Guru tidak dapat membuka halaman pengaturan maupun melihat menunya; akses langsung
    ditolak dengan HTTP 403.
40. Logo divalidasi berdasarkan isi berkas (`getimagesize`), disimpan dengan nama acak
    di `uploads/logo/`, dan logo lama dihapus saat diganti.

### 1.3 Kebutuhan non-fungsional

- **Keamanan:** prepared statement, CSRF, session aman, otorisasi, output escaping,
  proteksi folder internal, error tanpa detail sensitif.
- **Keterbacaan:** kode PHP native terstruktur, komentar berbahasa Indonesia.
- **Kemandirian aset:** seluruh CSS/JS lokal, tanpa CDN saat runtime.
- **Portabilitas:** base URL otomatis, konfigurasi database yang dapat dioverride.
- **Integritas data:** foreign key, unique constraint, CHECK constraint, transaksi.
- **Auditabilitas:** log aktivitas untuk peristiwa penting.

### 1.4 Di luar lingkup saat ini

Tampilan audit log. Master data, pengguna, ujian, relasi kelas, paket soal, status ujian,
kunci jawaban, alur siswa, koreksi otomatis, konfirmasi hasil, laporan, ekspor Excel/PDF,
analisis butir, dan pengaturan aplikasi/sekolah sudah dibangun pada tahap 2–6.

---

## 2. Keputusan Arsitektur

| Keputusan | Alternatif yang ditolak | Alasan |
| --- | --- | --- |
| PHP native tanpa framework | Laravel/CodeIgniter | Sesuai permintaan; mudah di-deploy di shared hosting; dependensi minimal |
| Bootstrap file `config/app.php` | Front controller tunggal | Setiap halaman memanggil bootstrap yang sama; sederhana dan mudah dilacak |
| PDO singleton `db()` | Koneksi per fungsi / mysqli | Prepared statement native, error mode exception, satu koneksi per request |
| Session PHP + data user dari DB | JWT / session-only | Data role dan status aktif selalu segar; logout/hak akses langsung berlaku |
| Satu token CSRF per session | Token per form | Cukup untuk aplikasi panel; dirotasi saat login |
| Folder `includes/` untuk komponen | Semua di root | Memisahkan layout, helper, dan logika akses |
| Aset lokal di `assets/plugins/` | CDN | Aplikasi tetap berjalan tanpa internet dan CSP `script-src 'self'` |
| Query langsung di halaman modul | Repository/Service layer | Volume masih kecil; helper bersama `includes/crud.php` menjaga konsistensi |
| Satu halaman per modul CRUD | Controller terpisah + view | Pola POST–Redirect–GET sederhana, mudah dilacak pada PHP native |
| Modal Bootstrap untuk form | Halaman form terpisah | Konteks daftar tidak hilang; validasi gagal membuka ulang modal berisi input lama |
| DataTables client-side | Server-side processing | Jumlah data sekolah masih kecil; filter tetap dilakukan di SQL |
| Chart data via `data-*` attribute | Inline `<script>` | Kompatibel dengan CSP tanpa `unsafe-inline` untuk script |
| Installer berbasis file SQL | Migrasi bertahap | Instalasi awal sederhana dan dapat diverifikasi per tabel |
| State ujian siswa di session server | Menyimpan di hidden field/URL | Identitas, paket, dan waktu mulai tidak dapat diubah dari browser |
| Layout siswa terpisah dari AdminLTE | Memakai ulang panel admin | Tampilan ringan, fokus peserta, dan tidak membocorkan menu internal |
| INSERT biasa + unique key peserta | Cek-lalu-insert saja | Unique key menjadi pengaman terakhir saat dua pengiriman bersamaan |
| Koreksi di server saat pengiriman | Koreksi di JavaScript | Kunci tidak dikirim ke browser dan hasil tidak dapat dimanipulasi |
| Konfirmasi hasil terikat sesi | ID hasil di URL | Mencegah siswa lain membuka hasil melalui penebakan ID |
| Helper laporan bersama | Query tersebar di tiap halaman | Aturan filter ujian/kelas dan rumus statistik konsisten di halaman dan ekspor |
| Analisis butir per paket | Menggabungkan nomor lintas paket | Isi soal dan kunci dapat berbeda meski nomornya sama |
| Builder ekspor terpisah dari endpoint | Logika ekspor di dalam endpoint | Builder dapat diuji dari CLI tanpa mengirim header HTTP |
| Sel Excel bertipe eksplisit | `setValue()` biasa | Teks pengguna berawalan `=` tidak dieksekusi sebagai formula |
| PDF memakai font inti FPDF | Font Unicode kustom | Tidak perlu file font tambahan; teks UTF-8 ditransliterasi ke Latin-1 |
| Pengaturan key-value di tabel `pengaturan` | Tabel kolom tetap / file konfigurasi | Menambah pengaturan tanpa migrasi; dibaca ulang tiap request |
| `setting()` sebagai satu-satunya akses baca | Query langsung per halaman | Nilai default dan penanganan kosong konsisten di seluruh aplikasi |
| Logo disimpan sebagai nama berkas | Menyimpan blob di database | Backup folder uploads cukup; database tetap ringan |
| Validasi logo dari isi berkas | Hanya ekstensi/MIME klien | `getimagesize()` memastikan berkas benar-benar gambar |
| Halaman pengaturan admin-only | Dapat diakses guru | Pengaturan berlaku sekolah-wide, bukan milik satu guru |

### 2.1 Prinsip yang dipegang

1. **Satu sumber bootstrap.** Semua halaman memuat `config/app.php`.
2. **Output selalu di-escape.** Gunakan `e()` untuk setiap data dinamis.
3. **Mutasi wajib POST + CSRF.** Tidak ada perubahan data melalui GET.
4. **Kegagalan tidak membocorkan detail.** Pesan pengguna ramah; detail ke log.
5. **Database sebagai sumber kebenaran.** Dashboard tidak memakai angka statis.
6. **Modul belum siap ditandai jelas.** Tidak ada tautan rusak di sidebar.

---

## 3. Struktur Lapisan

```text
Request HTTP
    │
    ▼
config/app.php  ── konstanta, base URL, timezone, error handler,
    │              security header, session, autoload
    ├── config/database.php   PDO singleton
    ├── includes/functions.php helper umum
    ├── includes/csrf.php     token & verifikasi
    ├── includes/auth.php     autentikasi & otorisasi
    ├── includes/crud.php     helper validasi, akses, dan render CRUD
    └── includes/siswa.php    alur siswa, sesi pengerjaan, koreksi transaksi
    │
    ▼
Halaman (admin/, auth/, siswa/, install.php)
    │
    ├── includes/header.php   layout atas
    ├── includes/sidebar.php  navigasi
    ├── includes/footer.php   layout bawah + JS
    └── includes/error-page.php
    │
    ▼
Response HTML / redirect / JSON error
```

Tidak ada router terpusat. `index.php` hanya mengarahkan ke dashboard atau login.
Pemetaan URL mengikuti struktur folder, sehingga mudah dipahami dan di-debug.

---

## 4. Alur Permintaan

### 4.1 Halaman panel

1. Halaman memuat `config/app.php`.
2. Bootstrap menetapkan konstanta, mendeteksi base path, menyiapkan session,
   mengirim security header, dan memuat helper.
3. Halaman memanggil `require_auth()`.
4. `auth_user()` memeriksa `user_id` di session, idle timeout, lalu memuat ulang data
   user dari database dan memastikan `is_active = 1`.
5. Jika gagal, URL saat ini disimpan sebagai `intended_url`, flash ditampilkan, dan
   pengguna diarahkan ke login.
6. Jika berhasil, halaman menjalankan query dan merender layout AdminLTE.

### 4.2 Login

```text
GET  auth/login.php
  └─ require_guest() → jika sudah login, redirect dashboard
POST auth/proses-login.php
  ├─ require_csrf()  → metode POST + token valid (gagal: 403)
  ├─ login_lock_remaining() → jika terkunci, tolak
  ├─ attempt_login(identity, password)
  │    ├─ SELECT user berdasarkan username/email (prepared)
  │    ├─ cek is_active
  │    ├─ password_verify()
  │    ├─ rehash bila algoritma berubah
  │    ├─ session_regenerate_id(true)
  │    ├─ simpan user_id + last_activity
  │    ├─ csrf_rotate()
  │    ├─ update last_login
  │    └─ log_aktivitas('auth','login')
  ├─ sukses → redirect intended_url atau dashboard
  └─ gagal  → login_record_failure(), flash error, kembali ke form
```

Pesan gagal dibuat identik untuk akun tidak ada, nonaktif, dan password salah.
`usleep(400ms)` memperlambat percobaan otomatis.

### 4.3 Logout

`POST auth/logout.php` dengan CSRF valid → `logout_user()` mencatat log, mengosongkan
session, menghapus cookie, dan menghancurkan session. `GET` ditolak dengan 405.

### 4.4 Instalasi

```text
GET install.php
  ├─ installer_checks() → daftar kebutuhan wajib/opsional
  ├─ koneksi server tanpa database
  ├─ cek keberadaan database & 11 tabel
  └─ hitung admin
POST install.php (action=install_db)
  ├─ CSRF + kebutuhan wajib
  ├─ installer_import_database() → split SQL, eksekusi, verifikasi tabel
  └─ log + flash + redirect
POST install.php (action=create_admin)
  ├─ validasi nama/username/email/password
  ├─ transaksi + SELECT ... FOR UPDATE untuk cek admin
  ├─ password_hash()
  └─ insert admin + log
```

Installer membaca ulang status setiap request. Jika admin sudah ada, form pembuatan
admin tidak ditampilkan dan permintaan pembuatan admin ditolak.

### 4.5 Modul CRUD tahap 2

```text
GET admin/<modul>/index.php
  ├─ require_auth() / require_role()
  ├─ baca filter GET (status, role, tingkat, ujian_id, dst.)
  ├─ query daftar + statistik dengan prepared statement
  └─ render: kartu statistik, filter, DataTables, modal form

POST admin/<modul>/index.php (action=save|delete|status)
  ├─ require_csrf()
  ├─ validasi server-side (format, duplikat, relasi, hak milik)
  ├─ transaksi bila menyentuh lebih dari satu tabel
  ├─ log_aktivitas()
  ├─ flash() + redirect()  ← POST–Redirect–GET
  └─ bila validasi gagal: render ulang daftar + buka modal berisi input lama
```

Aturan akses data:

- **Admin** dapat mengelola seluruh data.
- **Guru** hanya melihat master data; seluruh POST master data ditolak 403.
- **Guru** hanya melihat dan mengelola ujian dengan `created_by = user.id` serta
  paket soal milik ujian tersebut. Percobaan mengakses/mengubah data admin → 403.
- **Modul pengguna** hanya untuk admin.
- Dashboard guru memakai scope `created_by` yang sama agar angka tidak membocorkan
  data guru lain.

Proteksi penghapusan:

| Data | Ditolak bila |
| --- | --- |
| Mata pelajaran | Masih dipakai `ujian` |
| Kelas | Terhubung `ujian_kelas` atau `hasil_ujian` |
| Pengguna | Memiliki ujian, akun sendiri, atau admin aktif terakhir |
| Ujian | Memiliki `hasil_ujian` |
| Paket soal | Memiliki `hasil_ujian` |

Penghapusan ujian meng-cascade `ujian_kelas` dan `paket_soal` (beserta kunci) sesuai
foreign key. Tombol hapus juga dinonaktifkan di UI ketika relasi terdeteksi, tetapi
pemeriksaan server tetap menjadi penentu akhir.

### 4.6 Modul kunci jawaban tahap 3

```text
GET admin/kunci-jawaban/index.php[?ujian_id=&paket_id=]
  ├─ require_auth(); admin melihat semua, guru hanya ujian miliknya
  ├─ daftar paket + progres kunci (COUNT kunci 1..jumlah_soal per paket)
  ├─ bila paket_id dipilih: muat kunci, hitung terisi/kosong/persentase
  └─ bila ujian memiliki hasil: tampilkan tabel baca-saja + peringatan kunci

POST admin/kunci-jawaban/index.php
  ├─ action=save  → validasi A–E, ganti seluruh kunci paket dalam transaksi
  ├─ action=bulk  → normalisasi teks A–E, upsert nomor 1..N
  ├─ cek ulang hasil_ujian di dalam transaksi (anti-race)
  ├─ log_aktivitas() + flash() + redirect()
  └─ tolak bila kunci terkunci atau (ujian aktif dan hasil simpan tidak lengkap)
```

Keputusan penting:

- **Tanpa migrasi.** Tabel `kunci_jawaban` sudah memiliki `paket_soal_id`,
  `nomor_soal`, dan enum `jawaban` A–E dengan unik `(paket_soal_id, nomor_soal)`.
- **Kunci terpisah per paket.** Seluruh query dan mutasi memakai `paket_soal_id`,
  sehingga paket A dan B tidak saling menimpa.
- **Dua mode input.** `save` mengganti seluruh kunci paket (termasuk mengosongkan);
  `bulk` melakukan upsert hanya pada nomor yang dikirim sehingga kunci lain tetap.
- **Invariant aktivasi.** `crud_exam_key_status()` menghitung kebutuhan dan kekurangan
  per paket; `crud_exam_key_problem()` menghasilkan pesan alasan. Aksi status dan
  penyimpanan ujian menolak status `aktif` bila invariant belum terpenuhi. Ujian yang
  sudah aktif boleh diedit selama kuncinya tetap lengkap.
- **Invariant hasil.** Keberadaan satu baris `hasil_ujian` mengunci kunci seluruh paket
  ujian tersebut (UI baca-saja dan penolakan server), karena koreksi lama bergantung
  pada snapshot kunci.

### 4.7 Alur ujian siswa tahap 4

```text
GET siswa/index.php
  ├─ siswa_active_exams() + kelengkapan paket/kelas
  ├─ tampilkan kartu ujian siap atau alasan belum siap
  ├─ bila session punya attempt → kartu "Lanjutkan" / "Batalkan"
  └─ bila session punya result → banner konfirmasi hasil terakhir

GET/POST siswa/identitas.php?ujian_id=
  ├─ validasi ujian aktif, kelas peserta aktif, paket lengkap milik ujian
  ├─ validasi nama (3–100), absen (format & panjang)
  ├─ cek hasil yang sudah ada untuk ujian+kelas+absen
  └─ sukses → siswa_start_attempt() → regenerate session → redirect ujian.php

GET/POST siswa/ujian.php
  ├─ siswa_attempt_context() memverifikasi ulang ujian/kelas/paket ke database
  ├─ GET  → render input cepat + radio A–E (tanpa kunci)
  ├─ POST → siswa_collect_answers() memvalidasi & menyinkronkan dua mode
  ├─ siswa_submit_attempt():
  │    ├─ BEGIN
  │    ├─ SELECT ujian ... FOR UPDATE (status aktif/selesai, jumlah soal)
  │    ├─ cek hasil existing FOR UPDATE → duplikat
  │    ├─ SELECT kunci paket FOR UPDATE → wajib lengkap 1..N
  │    ├─ hitung benar/salah/kosong, nilai, snapshot, durasi
  │    ├─ INSERT hasil_ujian
  │    ├─ INSERT jawaban_siswa 1..N (termasuk NULL)
  │    ├─ COMMIT + log_aktivitas + session result
  │    └─ unique key peserta sebagai pengaman terakhir (SQLSTATE 23000/1062)
  └─ redirect hasil.php atau kembali dengan pesan validasi

GET siswa/hasil.php
  ├─ siswa_result_context() mencocokkan hasil_id dengan session (ujian/kelas/nama/absen)
  ├─ bila tidak cocok → "Belum ada hasil", tanpa kebocoran data
  └─ tampilkan nilai, benar/salah/kosong, KKM, waktu, durasi, cetak

POST siswa/hasil.php (action=selesai)
  └─ require_csrf() → hapus session result → redirect index
```

Keputusan penting:

- **Session sebagai sumber kebenaran.** Browser tidak mengirim `ujian_id`, `kelas_id`,
  `paket_soal_id`, nama, atau absen saat submit jawaban; server memakai context yang
  sudah diverifikasi. Ini mencegah penggantian paket/kunci dari sisi klien.
- **Dua mode input, satu validator.** Input cepat diparse posisional (`-`/`_`/`.`
  untuk kosong); radio menjadi otoritas bila terisi. Bila keduanya terisi dan berbeda,
  server menolak. Tanpa JavaScript, input cepat tetap dapat dipakai.
- **Transaksi dan penguncian.** `SELECT ... FOR UPDATE` pada ujian, hasil existing, dan
  kunci membuat pemeriksaan konsisten; unique key tetap menjadi penentu akhir.
- **Status ujian saat submit.** Status `aktif` atau `selesai` diterima agar pekerjaan
  siswa tidak hilang bila pengawas menutup ujian di tengah pengerjaan.
- **Hasil tidak dapat diubah.** Tidak ada jalur edit/hapus hasil dari halaman siswa;
  pengiriman berikutnya ditolak oleh pengecekan dan unique key.
- **Tanpa kunci di klien.** Halaman ujian tidak memuat tabel kunci, snapshot, atau JSON
  kunci; pratinjau hanya menghitung pilihan peserta.

### 4.8 Laporan, ekspor, dan analisis tahap 5

```text
GET admin/laporan/index.php?ujian_id=&kelas_id=
  ├─ laporan_exams() → daftar ujian sesuai role (admin: semua, guru: miliknya)
  ├─ laporan_exam() memvalidasi ujian terpilih
  ├─ laporan_classes() + validasi kelas filter
  ├─ laporan_results() → hasil ter-scope ujian/kelas
  ├─ laporan_summary(), laporan_distribution()
  ├─ laporan_class_summary(), laporan_package_summary()
  ├─ grafik via data-* + tabel rekap + tautan ekspor
  └─ tautan PDF individu per hasil (jenis=siswa&hasil_id=)

GET admin/analisis/index.php?ujian_id=&kelas_id=
  ├─ validasi ujian/kelas yang sama
  ├─ laporan_item_analysis() per paket:
  │    ├─ hitung benar/salah/kosong & opsi A–E per nomor
  │    ├─ tingkat kesukaran dari persen benar
  │    ├─ urutkan nilai → kelompok atas/bawah 27%
  │    ├─ daya pembeda = benar_atas − benar_bawah (per kelompok)
  │    └─ pengecoh efektif bila ≥ 5% peserta memilih
  └─ tabel per paket + grafik persentase benar & komposisi kesukaran

GET admin/laporan/excel.php?ujian_id=&kelas_id=
  ├─ validasi ujian/kelas (render_error_page bila tidak sah)
  ├─ kumpulkan results, rekap, paket, jawaban bulk, analisis butir
  ├─ laporan_excel_build() → 5 worksheet
  └─ Xlsx writer → php://output + header unduhan

GET admin/laporan/pdf.php?jenis=&ujian_id=&kelas_id=&hasil_id=
  ├─ validasi jenis (kelas|analisis|siswa|distribusi) & hasil siswa
  ├─ kumpulkan data sesuai jenis
  ├─ laporan_pdf_build() → string PDF (FPDF)
  └─ header application/pdf + Content-Disposition
```

Keputusan penting:

- **Satu ujian per laporan.** Seluruh query helper memakai `ujian_id`; tidak ada
  laporan gabungan beberapa ujian. Filter kelas hanya mempersempit, bukan mencampur.
- **Pemeriksaan akses berlapis.** `laporan_exams()` membatasi daftar; `laporan_exam()`
  memvalidasi pilihan; `laporan_exam_accessible()` menjaga helper kelas/hasil/paket/
  jawaban/detail tetap menolak ujian yang bukan hak pengguna; endpoint memvalidasi ulang.
- **Normalisasi nilai vs identitas butir.** Statistik nilai boleh lintas paket karena
  skor sudah dinormalisasi. Analisis butir dikelompokkan per `paket_soal_id` dan tidak
  pernah menjumlahkan nomor yang sama dari paket berbeda.
- **Daya pembeda berbasis 27%.** Kelompok atas dan bawah diambil dari peringkat nilai
  total dalam paket. Minimal 4 peserta agar dua kelompok tidak tumpang tindih; bila
  kurang, daya pembeda bernilai `null` dan UI menampilkan “Data kurang”.
- **Ekspor dapat diuji.** `includes/laporan_export.php` hanya membangun objek/string;
  endpoint yang mengirim header. Ini memungkinkan validasi isi workbook dan PDF dari CLI.
- **Keamanan ekspor.** Excel memakai tipe sel eksplisit untuk mencegah formula injection;
  PDF meng-escape teks dan memotong nama panjang; nama berkas dislug dari data ujian.
- **Grafik patuh CSP.** `assets/js/laporan.js` membaca `data-labels`, `data-values`,
  `data-colors`, dan `data-*` lain dari `<canvas>`; tidak ada inline script.

### 4.9 Pengaturan aplikasi dan sekolah tahap 6

```text
GET admin/pengaturan/index.php
  ├─ require_role(['admin']) → guru ditolak 403
  ├─ settings_all() → nilai saat ini
  ├─ ringkasan identitas + versi skema
  ├─ form identitas, profil sekolah, dan akademik
  ├─ kartu logo: pratinjau, unggah, hapus (bila logo khusus aktif)
  └─ informasi sistem (versi aplikasi/skema, jumlah pengaturan, admin)

POST admin/pengaturan/index.php  action=save
  ├─ require_csrf()
  ├─ normalisasi + validasi seluruh field
  ├─ bila tidak ada perubahan → flash info, redirect
  ├─ transaksi UPSERT ke `pengaturan`
  ├─ log_aktivitas('pengaturan', 'update', field berubah)
  └─ flash sukses + redirect (POST–Redirect–GET)

POST admin/pengaturan/index.php  action=upload_logo (multipart)
  ├─ cek error unggah, ukuran ≤ 2 MB
  ├─ getimagesize() → MIME png/jpeg/webp + dimensi ≤ 3000 px
  ├─ pindahkan ke uploads/logo/logo-YYYYmmddHHMMSS-xxxxxxxx.ext (0644)
  ├─ UPSERT `logo_sekolah`
  ├─ hapus berkas logo lama
  └─ log_aktivitas('pengaturan', 'upload_logo')

POST admin/pengaturan/index.php  action=remove_logo
  ├─ kosongkan `logo_sekolah`
  ├─ hapus berkas logo khusus
  └─ log_aktivitas('pengaturan', 'remove_logo')
```

Keputusan penting:

- **Satu sumber kebenaran.** Semua halaman membaca pengaturan melalui `setting()` /
  `settings_all()`; tidak ada duplikasi nilai di file konfigurasi.
- **Tanpa perubahan skema.** Tabel `pengaturan` sudah ada sejak Tahap 1; halaman ini
  hanya menulis kunci yang sudah didefinisikan pada `db_sikilat.sql`.
- **UPSERT, bukan UPDATE.** Kunci yang belum ada tetap dapat disimpan tanpa migrasi;
  `ON DUPLICATE KEY UPDATE` menjaga satu baris per kunci.
- **Cache per request.** `settings_all()` menyimpan hasil query selama satu request.
  Perubahan selalu diikuti redirect sehingga request berikutnya membaca nilai baru.
- **Logo bukan aset publik sembarangan.** Nama berkas diacak, ekstensi ditentukan dari
  MIME hasil deteksi server, dan folder `uploads/` memblokir eksekusi PHP/SVG.
- **Profil sekolah dipakai di laporan.** Nama sekolah dan baris kontak
  (alamat, kota, telepon, email, website) dirender pada header PDF.

---

## 5. Model Data

### 5.1 ERD ringkas

```text
users (1) ──── (0..N) ujian [created_by]
users (1) ──── (0..N) log_aktivitas [user_id]

mata_pelajaran (1) ──── (0..N) ujian
ujian (1) ──── (0..N) ujian_kelas ──── (1) kelas
ujian (1) ──── (0..N) paket_soal
paket_soal (1) ──── (0..N) kunci_jawaban

ujian (1) ──── (0..N) hasil_ujian
kelas (1) ──── (0..N) hasil_ujian
paket_soal (1) ──── (0..N) hasil_ujian
hasil_ujian (1) ──── (0..N) jawaban_siswa
```

### 5.2 Peran tabel

| Tabel | Peran | Catatan desain |
| --- | --- | --- |
| `users` | Akun panel | Role `admin`/`guru`; username dan email unik; email boleh NULL |
| `mata_pelajaran` | Master | Kode dan nama unik; status aktif |
| `kelas` | Master | Unik per `(nama, tahun_ajaran)`; tingkat enum |
| `ujian` | Transaksi utama | Mapel wajib; pembuat boleh NULL bila akun dihapus |
| `ujian_kelas` | Penghubung peserta | Unik `(ujian_id, kelas_id)`; hapus ujian meng-cascade |
| `paket_soal` | Varian soal | Kode unik per ujian; hapus ujian meng-cascade |
| `kunci_jawaban` | Kunci per nomor | Unik `(paket_soal_id, nomor_soal)`; enum A–E |
| `hasil_ujian` | Hasil per peserta | Unik `(ujian_id, kelas_id, nomor_absen)`; snapshot kunci, waktu mulai, durasi |
| `jawaban_siswa` | Detail jawaban | Unik `(hasil_ujian_id, nomor_soal)`; menyimpan kunci & status benar |
| `pengaturan` | Konfigurasi | Key-value; 15 baris default |
| `log_aktivitas` | Audit | User boleh NULL; indeks waktu dan modul/aksi |

### 5.3 Keputusan model data

- **Identitas peserta tanpa tabel siswa.** Tahap 1 belum memiliki master siswa;
  nama dan nomor absen disimpan langsung di `hasil_ujian`. Ini mempercepat alur
  koreksi dan tetap mencegah duplikasi melalui unique key.
- **Snapshot kunci.** `kunci_snapshot` dan kolom `kunci` pada `jawaban_siswa`
  memastikan hasil lama tidak berubah saat kunci diperbarui.
- **Paket milik ujian.** Relasi `paket_soal.ujian_id` memastikan paket tidak berdiri
  sendiri. Konsistensi `hasil_ujian.paket_soal_id` terhadap ujian yang sama harus
  divalidasi di aplikasi karena tidak dapat dinyatakan dengan FK sederhana.
- **Delete policy.** Data master dilindungi `RESTRICT`; anak transaksi memakai
  `CASCADE`; referensi opsional memakai `SET NULL`.
- **Waktu pengerjaan.** Sejak skema 1.1.0, `started_at` dan `durasi_detik` disimpan
  pada `hasil_ujian`. `started_at` berasal dari session dan dibatasi tidak melebihi
  waktu server saat submit; `durasi_detik` dihitung dari selisih tersebut.
- **Snapshot dan rincian.** `kunci_snapshot` menyimpan peta nomor→kunci saat koreksi,
  sedangkan `jawaban_siswa` menyimpan jawaban peserta (`NULL` bila kosong), kunci per
  nomor, dan `is_benar` (`1`/`0`/`NULL`). Keduanya menjaga audit hasil lama.
- **Tipe soal per nomor dan skor parsial.** Sejak skema 1.2.0, `kunci_jawaban` dan
  `jawaban_siswa` menyimpan `tipe_soal` (`pg`, `benar_salah`, `multiple_response`).
  Format jawaban: PG `A`–`E`, Benar/Salah `B`/`S`, Multiple Response berupa huruf
  terurut dipisah koma (`A,C`). `jawaban_siswa.skor` menyimpan skor butir 0–1 dan
  `hasil_ujian.skor_total` menyimpan jumlah skor. Multiple Response memakai skor
  parsial `max(0, (benar - salah) / total_kunci)` dengan skor penuh hanya bila himpunan
  jawaban sama persis; nilai = `skor_total / total_soal × 100`.
- **Tanpa tabel laporan.** Tahap 5 tidak menambah tabel atau kolom. Laporan dihitung
  langsung dari `ujian`, `kelas`, `paket_soal`, `hasil_ujian`, `jawaban_siswa`, dan
  `kunci_snapshot`/`kunci`. Nilai yang sudah tersimpan tidak dihitung ulang, sehingga
  laporan selalu konsisten dengan hasil koreksi.
- **Pengaturan key-value dipakai penuh.** Tahap 6 tidak menambah tabel/kolom; halaman
  pengaturan menulis kunci yang sudah ada pada `pengaturan`. `logo_sekolah` menyimpan
  nama berkas (bukan path), sedangkan berkasnya berada di `uploads/logo/`.

### 5.4 Aturan integritas

| Aturan | Penegakan |
| --- | --- |
| Username unik | `uq_users_username` |
| Email unik bila diisi | `uq_users_email` (NULL diizinkan berulang) |
| Kode/nama mapel unik | `uq_mapel_kode`, `uq_mapel_nama` |
| Kelas unik per tahun | `uq_kelas_nama_tahun` |
| Satu kelas satu kali per ujian | `uq_ujian_kelas` |
| Kode paket unik per ujian | `uq_paket_ujian_kode` |
| Satu kunci per nomor per paket | `uq_kunci_paket_nomor` |
| Satu peserta satu kali per ujian+kelas | `uq_hasil_peserta` |
| Satu jawaban per nomor per hasil | `uq_jawaban_hasil_nomor` |
| Jumlah soal 1–200 | `chk_ujian_jumlah_soal` |
| KKM dan nilai 0–100 | `chk_ujian_kkm`, `chk_hasil_nilai` |
| Nomor soal 1–200 | `chk_kunci_nomor`, `chk_jawaban_nomor` |
| Jumlah benar+salah+kosong = total | `chk_hasil_jumlah` |
| Referensi valid | Foreign key InnoDB |

Validasi aplikasi tetap wajib karena CHECK dan FK tidak dapat menangani seluruh
aturan bisnis (misalnya paket harus milik ujian yang sama).

---

## 6. Desain Keamanan

### 6.1 Lapisan pertahanan

1. **Transport & header** — CSP ketat, anti-clickjacking, nosniff, referrer policy.
2. **Session** — cookie `HttpOnly`, `SameSite=Lax`, `Secure` di HTTPS, strict mode,
   regenerasi ID saat login, idle timeout 2 jam.
3. **Autentikasi** — hash bcrypt, rehash otomatis, pesan gagal seragam, throttle.
4. **Otorisasi** — `require_auth()`, `require_role()`, status aktif dicek ulang dari DB.
5. **CSRF** — token session 32 byte, `hash_equals`, rotasi setelah login.
6. **Input & query** — prepared statement, validasi server-side, transaksi untuk
   operasi multi-langkah.
7. **Output** — `e()` pada seluruh data dinamis; atribut `data-*` di-escape.
8. **Filesystem** — `.htaccess` memblokir folder internal dan ekstensi sensitif;
   `uploads/` tidak menjalankan PHP.

### 6.2 Matriks ancaman

| Ancaman | Mitigasi |
| --- | --- |
| SQL injection | PDO prepared, emulasi prepare dimatikan |
| XSS | Escaping output, CSP tanpa inline script |
| CSRF | Token per session pada semua POST |
| Session fixation | `session_regenerate_id(true)` saat login |
| Session hijacking | HttpOnly, SameSite, Secure di HTTPS, idle timeout |
| Brute force | Maksimal 5 gagal / 300 detik, delay 400 ms |
| User enumeration | Pesan login seragam, email/username tidak diungkap |
| Akses file internal | RewriteRule + deny pada folder sensitif |
| Error disclosure | Halaman error generik; detail hanya di log |
| Double submit | CSRF + unique constraint peserta |
| Manipulasi paket/jawaban siswa | Context ujian/paket hanya di session server; validasi ulang rentang & isi jawaban |
| Akses hasil siswa lain | Hasil dicocokkan ke session (ujian/kelas/nama/absen); ID URL diabaikan |
| Kebocoran kunci jawaban | Kunci hanya dibaca di server; HTML siswa tidak memuat kunci/snapshot |
| Perubahan kunci retroaktif | Snapshot kunci pada hasil |

### 6.3 Keterbatasan keamanan yang diketahui

- Throttle login berbasis session; penyerang dapat menghapus cookie. Untuk produksi,
  tambahkan tabel percobaan login berbasis IP/akun.
- Belum ada 2FA, audit login perangkat, atau kebijakan password berkala.
- Installer tidak dihapus otomatis; setelah instalasi, batasi akses ke `install.php`.
- CSP masih mengizinkan `style-src 'unsafe-inline'` karena beberapa komponen
  AdminLTE memakai atribut style. Script tetap `'self'`.
- Konfirmasi hasil siswa terikat pada sesi browser; belum ada portal login siswa atau
  verifikasi identitas ulang untuk melihat riwayat nilai.
- Ketersediaan ujian memakai status `aktif`; belum ada token sesi ujian atau
  pembatasan ketat berdasarkan tanggal/jam.

---

## 7. Penanganan Error dan Logging

- `set_exception_handler` mencatat detail ke `logs/php-error.log` lalu menampilkan
  halaman 500 generik.
- `register_shutdown_function` menangani fatal error dan memastikan halaman error.
- Permintaan AJAX/JSON menerima `{success:false, message:...}` tanpa stack trace.
- `APP_DEBUG` hanya menampilkan detail di lingkungan local.
- `log_aktivitas()` mencatat modul, aksi, deskripsi, IP, user agent, dan waktu.
- Log tidak dapat diakses dari browser.

---

## 8. Desain Antarmuka

### 8.1 Komponen

- **AdminLTE 4** sebagai kerangka panel: sidebar, navbar, content wrapper, footer.
- **Bootstrap 5.3** untuk grid, card, badge, alert, form.
- **Bootstrap Icons** untuk ikon menu dan statistik.
- **Chart.js** untuk grafik dashboard, diinisialisasi dari `data-*`, serta grafik
  laporan/analisis melalui `assets/js/laporan.js` dengan pola data yang sama.
- **DataTables** untuk daftar data: pencarian, pengurutan, dan paginasi sisi klien.
- **SweetAlert2** untuk notifikasi flash dan konfirmasi hapus/ubah status.
- **CSS kustom** di `assets/css/app.css` untuk token warna dan komponen statistik;
  `assets/css/laporan.css` untuk kartu metrik, tabel analisis, dan wadah grafik;
  **JS kustom** `assets/js/crud.js` untuk isi modal, auto-open validasi, filter otomatis,
  dan tombol pilih semua kelas.
- **Tema siswa** di `assets/css/siswa.css` dan `assets/js/siswa.js`: kartu responsif,
  target sentuh besar, sinkronisasi input cepat/radio, progres, timer, pratinjau,
  draft `sessionStorage`, dan proteksi klik ganda. Layout memakai
  `includes/siswa-header.php` dan `includes/siswa-footer.php`, bukan sidebar admin.
- **Form pengaturan** memakai grid Bootstrap dengan kartu terpisah untuk identitas,
  profil sekolah, akademik, logo, dan informasi sistem. Unggah logo memakai
  `enctype="multipart/form-data"` dan pratinjau gambar; hapus logo memakai
  `data-confirm` yang ditangani SweetAlert2.

### 8.2 Prinsip UI

1. Bahasa Indonesia konsisten, termasuk pesan error dan status kosong.
2. Sidebar hanya menautkan halaman yang benar-benar ada; modul lain diberi badge
   “Segera” dan tidak dapat diklik.
3. Status kosong menjelaskan langkah berikutnya, bukan menampilkan angka palsu.
4. Warna status mengikuti Bootstrap: biru (info), kuning (warning), hijau (success),
   merah (danger).
5. Layout responsif: kartu statistik menyesuaikan dari 2 kolom ke 5 kolom.
6. Fokus aksesibilitas: label form, `aria-*` pada navigasi, kontras teks memadai.

### 8.3 Halaman

| Halaman | Status | Isi |
| --- | --- | --- |
| `auth/login.php` | Aktif | Form login, flash, info lockout |
| `admin/dashboard/index.php` | Aktif | 5 statistik, grafik, ringkasan status, tabel ujian terbaru (ter-scope per role) |
| `admin/mata-pelajaran/index.php` | Aktif | CRUD mapel, filter status, DataTables |
| `admin/kelas/index.php` | Aktif | CRUD kelas, filter tingkat/tahun/status |
| `admin/users/index.php` | Aktif | CRUD pengguna (admin-only), proteksi akun |
| `admin/ujian/index.php` | Aktif | CRUD ujian, relasi multi-kelas, status |
| `admin/paket-soal/index.php` | Aktif | CRUD paket soal, filter ujian, progres kunci |
| `admin/kunci-jawaban/index.php` | Aktif | Editor kunci A–E, input massal, progres, kunci hasil |
| `siswa/index.php` | Aktif | Daftar ujian aktif, status siap/belum siap, lanjutkan/batalkan sesi |
| `siswa/identitas.php` | Aktif | Kelas, nama, absen, pilih paket lengkap, validasi & cek duplikat |
| `siswa/ujian.php` | Aktif | Lembar jawaban dua mode, progres, timer, pratinjau, kirim |
| `siswa/hasil.php` | Aktif | Konfirmasi nilai, benar/salah/kosong, KKM, waktu, cetak |
| `admin/laporan/index.php` | Aktif | Filter ujian/kelas, metrik, distribusi, rekap kelas/paket, daftar nilai, ekspor |
| `admin/analisis/index.php` | Aktif | Analisis nilai, tingkat kesukaran, daya pembeda, pengecoh per paket |
| `admin/laporan/excel.php` | Aktif | Endpoint unduhan `.xlsx` 5 lembar |
| `admin/laporan/pdf.php` | Aktif | Endpoint unduhan PDF kelas/analisis/siswa/distribusi |
| `admin/pengaturan/index.php` | Aktif | Identitas aplikasi, profil sekolah, logo, default akademik (admin-only) |
| `install.php` | Aktif | Pemeriksaan sistem, impor, pembuatan admin |
| Modul sidebar lainnya | Nonaktif | Badge “Segera” |

---

## 9. Roadmap Modul

| Tahap | Modul | Ketergantungan | Status |
| --- | --- | --- | --- |
| 1 | Fondasi, installer, autentikasi, dashboard | — | Selesai |
| 2 | Master mata pelajaran & kelas | Skema tahap 1 | Selesai |
| 2 | Manajemen pengguna admin/guru | `users` | Selesai |
| 2 | Data ujian + relasi kelas + status | `ujian`, `ujian_kelas` | Selesai |
| 2 | Paket soal | `paket_soal` | Selesai |
| 3 | Kunci jawaban per paket | `kunci_jawaban` | Selesai |
| 3 | Progres & penguncian aktivasi ujian | `kunci_jawaban`, `hasil_ujian` | Selesai |
| 4 | Halaman siswa & input jawaban | `ujian`, `paket_soal`, `hasil_ujian`, `jawaban_siswa` | Selesai |
| 4 | Koreksi otomatis & konfirmasi hasil | `hasil_ujian`, `jawaban_siswa` | Selesai |
| 5 | Hasil & analisis butir soal (rekap admin/guru) | Semua transaksi | Selesai |
| 5 | Laporan Excel/PDF | PhpSpreadsheet, FPDF | Selesai |
| 6 | Pengaturan aplikasi, sekolah, logo, dan default akademik | `pengaturan`, `uploads/logo` | Selesai |
| 7 | Tampilan audit log | `log_aktivitas` | Belum |

Urutan ini menjaga agar setiap modul memiliki data induk yang sudah stabil.

---

## 10. Konvensi Pengembangan Berikutnya

### 10.1 Halaman baru

1. Letakkan di folder modul yang sesuai (`admin/<modul>/`).
2. Muat `config/app.php` dengan path relatif yang benar.
3. Panggil `require_auth()` atau `require_role([...])`.
4. Gunakan `csrf_field()` pada form dan `require_csrf()` pada pemroses POST.
5. Escape seluruh output dengan `e()`.
6. Gunakan `flash()` + `redirect()` setelah mutasi (pola POST–Redirect–GET).
7. Tambahkan entri sidebar hanya setelah halaman benar-benar tersedia.
8. Gunakan helper `includes/crud.php` (`crud_text()`, `crud_int_list()`,
   `crud_valid_date()`, `crud_accessible_exams()`, `crud_exam_key_status()`,
   `crud_exam_key_problem()`, `crud_key_bulk_normalize()`, dan lainnya) agar validasi
   konsisten.
9. Gunakan `data-crud-form`, `data-record`, dan `data-auto-modal` agar modal serta
   validasi berjalan tanpa inline script (patuh CSP).
10. Tambahkan `data-confirm` pada form destruktif agar dikonfirmasi SweetAlert2.
11. Halaman siswa memakai `includes/siswa-header.php`/`siswa-footer.php`, tidak memuat
    sidebar admin, dan memanggil `siswa_no_cache()`.
12. State pengerjaan siswa disimpan di `$_SESSION['siswa_attempt']`; jangan menaruh
    identitas/paket di hidden input atau URL. Gunakan helper `includes/siswa.php`
    (`siswa_attempt_context()`, `siswa_collect_answers()`, `siswa_submit_attempt()`).
13. Jangan pernah mengirim kunci jawaban ke HTML siswa; koreksi hanya di server.
14. Halaman laporan/analisis memakai `includes/laporan.php`; ekspor memakai
    `includes/laporan_export.php` agar builder tetap dapat diuji dari CLI.
15. Setiap query laporan wajib dibatasi `ujian_id` (dan `kelas_id` bila dipilih).
    Jangan pernah menggabungkan hasil beberapa ujian dalam satu rekap.
16. Analisis butir selalu dikelompokkan per `paket_soal_id`. Nomor soal tidak boleh
    dijumlahkan atau dirata-ratakan lintas paket.
17. Ekspor Excel harus menulis sel dengan tipe eksplisit (`TYPE_STRING`/`TYPE_NUMERIC`)
    agar input pengguna tidak menjadi formula.
18. Grafik baru memakai pola `canvas[data-report-chart]` dengan data JSON di atribut,
    bukan inline script.
19. Pengaturan baru wajib ditambahkan ke `db_sikilat.sql` sebagai baris default dan
    dibaca melalui `setting()`; jangan menyimpan konfigurasi sekolah di file kode.
20. Halaman yang mengubah pengaturan sekolah wajib `require_role(['admin'])` dan
    mencatat perubahan ke `log_aktivitas`.
21. Unggahan berkas divalidasi dari isi (`getimagesize()`/finfo), ukuran, dan dimensi,
    disimpan dengan nama acak, lalu berkas lama dihapus setelah penggantian berhasil.

### 10.2 Query

- Gunakan `db()` dan prepared statement.
- Untuk operasi multi-tabel, bungkus dengan `beginTransaction()`/`commit()` dan
  `rollBack()` pada `Throwable`.
- Batasi kolom yang dipilih; jangan `SELECT *` pada tabel besar.
- Gunakan `LIMIT` untuk daftar yang tidak butuh seluruh data.

### 10.3 Migrasi database

- Perubahan skema produksi **tidak** dilakukan dengan mengimpor ulang `db_sikilat.sql`.
- Tambahkan file bernomor di `database/migrations/` (lihat README folder tersebut).
- Perbarui `versi_database` pada tabel `pengaturan` setelah migrasi berhasil.
- Versi skema saat ini `1.2.0`; migrasi `20261118_001` menambahkan tipe soal per nomor,
  skor butir parsial (`jawaban_siswa.skor`), dan `hasil_ujian.skor_total`.
- Selalu sediakan backup sebelum migrasi.

### 10.4 Gaya kode

- `declare(strict_types=1);` pada setiap file PHP.
- Komentar dan pesan pengguna berbahasa Indonesia.
- Nama fungsi dan variabel berbahasa Inggris; label UI berbahasa Indonesia.
- Tidak menambahkan inline `<script>` atau `onclick` karena CSP.
- Aset baru disimpan di `assets/`, bukan di CDN.

---

## 11. Risiko dan Rencana Mitigasi

| Risiko | Dampak | Mitigasi |
| --- | --- | --- |
| Skema berubah saat modul bertambah | Data lama tidak kompatibel | Migrasi bernomor + versi database |
| Double submit jawaban siswa | Data ganda | Unique key + transaksi + disable tombol |
| Kunci jawaban berubah setelah ujian | Hasil tidak konsisten | Snapshot kunci |
| Beban query dashboard | Dashboard lambat | Indeks tanggal/status, batasi agregasi |
| Unggahan logo berbahaya | Eksekusi file | Validasi MIME/ukuran, folder tanpa PHP |
| Lockout login disalahgunakan | Akun tidak bisa masuk | Reset session/CLI, throttle per akun di produksi |
| Shared hosting membatasi ekstensi | Ekspor gagal | Pemeriksaan installer, fallback pesan jelas |
| Siswa kehilangan sesi sebelum kirim | Jawaban hilang | Draft `sessionStorage`, peringatan sebelum meninggalkan halaman, mulai ulang dari identitas |
| Kunci berubah saat ujian berlangsung | Koreksi tidak konsisten | Snapshot kunci, penguncian kunci setelah ada hasil, validasi kelengkapan saat submit |
| Analisis lintas paket salah tafsir | Kesimpulan butir keliru | Analisis selalu per paket; UI memberi peringatan eksplisit; nomor tidak digabung |
| Ekspor besar memakan memori | Proses gagal/timeout | Data sekolah kecil; rincian jawaban diambil sekali per ujian (bulk), bukan per siswa |
| Formula injection dari nama siswa | Spreadsheet berbahaya | Sel bertipe string eksplisit, tidak memakai `setValue()` untuk teks pengguna |
| Font PDF tidak mendukung Unicode | Teks rusak | Transliterasi Latin-1 dengan fallback karakter aman |
| Logo diganti saat halaman lain terbuka | Logo lama terhapus | Nama berkas disimpan di DB dan dicek keberadaannya; fallback ke logo bawaan |
| Dua admin menyimpan pengaturan bersamaan | Nilai terakhir menimpa | Form memuat semua field; perubahan dicatat di audit log untuk penelusuran |
| Pengaturan tidak sengaja dikosongkan | Tampilan kehilangan identitas | Default dari `setting()` dan validasi field wajib |

---

## 12. Kesimpulan Tahap 1–6

Fondasi aplikasi lengkap dan dapat diuji: instalasi terpandu, database ternormalisasi,
autentikasi aman, dashboard nyata, serta proteksi dasar. Tahap 2 menambahkan seluruh
modul manajemen data dengan validasi server-side, pembatasan role, proteksi relasi,
DataTables, modal Bootstrap, dan konfirmasi SweetAlert2. Tahap 3 menambahkan editor
kunci jawaban per paket yang cepat dipakai untuk 40–100 soal, progres langsung,
input massal, penguncian aktivasi berbasis kelengkapan kunci, serta mode baca-saja
setelah ujian memiliki hasil.

Tahap 4 melengkapi sisi peserta: halaman siswa terpisah dan responsif, pemilihan ujian
aktif, identitas dan paket yang divalidasi, lembar jawaban dua mode yang sinkron,
koreksi otomatis di server dalam transaksi, penyimpanan rincian jawaban dan snapshot
kunci, perlindungan pengiriman ganda, serta konfirmasi hasil yang terikat sesi tanpa
membocorkan kunci atau hasil siswa lain.

Tahap 5 melengkapi sisi penilaian: rekap nilai seluruh siswa/per kelas/per paket,
distribusi nilai, daftar nilai dan rincian jawaban, ekspor Excel lima lembar, empat
jenis laporan PDF, serta analisis butir per paket (tingkat kesukaran, daya pembeda 27%,
dan efektivitas pengecoh A–E). Semua laporan terikat pada satu ujian dan menghormati
kepemilikan ujian guru. Tidak ada perubahan skema pada tahap ini.

Tahap 6 menambahkan pengaturan terpusat: identitas aplikasi, profil sekolah, unggah dan
hapus logo, KKM default, tahun ajaran, dan semester aktif. Nilai disimpan pada tabel
`pengaturan`, divalidasi di server, dicatat pada audit log, dan langsung dipakai oleh
panel, halaman siswa, form ujian baru, serta header/footer PDF. Halaman ini khusus
administrator dan tidak menambah tabel atau kolom baru.

Tampilan audit log sengaja belum dibangun agar setiap tahap dapat ditinjau sebelum
dilanjutkan. Tahap berikutnya dimulai setelah instruksi diberikan.
