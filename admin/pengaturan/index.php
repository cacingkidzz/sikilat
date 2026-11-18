<?php
declare(strict_types=1);

/**
 * SIKILAT - Pengaturan aplikasi dan sekolah.
 *
 * Halaman ini hanya dapat diakses administrator. Nilai disimpan pada tabel
 * `pengaturan` (key-value) sehingga langsung dipakai oleh panel, halaman siswa,
 * dan laporan. Logo sekolah disimpan sebagai nama berkas di `uploads/logo/`.
 */

require_once __DIR__ . '/../../config/app.php';

require_role(['admin']);

$currentUser = auth_user();
$currentId = (int) ($currentUser['id'] ?? 0);
$settingsPage = 'admin/pengaturan/index.php';

$errors = [];
$formData = [];
$oldSettings = settings_all();

/**
 * Daftar pengaturan teks yang dapat diubah dari halaman ini.
 *
 * @var array<string, array{label:string, max:int}>
 */
$settingFields = [
    'nama_aplikasi'      => ['label' => 'Nama aplikasi', 'max' => 50],
    'footer_laporan'     => ['label' => 'Footer laporan', 'max' => 255],
    'nama_sekolah'       => ['label' => 'Nama sekolah', 'max' => 150],
    'alamat_sekolah'     => ['label' => 'Alamat sekolah', 'max' => 255],
    'kota_sekolah'       => ['label' => 'Kota sekolah', 'max' => 100],
    'telepon_sekolah'    => ['label' => 'Telepon sekolah', 'max' => 30],
    'email_sekolah'      => ['label' => 'Email sekolah', 'max' => 150],
    'website_sekolah'    => ['label' => 'Website sekolah', 'max' => 150],
    'kepala_sekolah'     => ['label' => 'Nama kepala sekolah', 'max' => 100],
    'nip_kepala_sekolah' => ['label' => 'NIP kepala sekolah', 'max' => 30],
    'kkm_default'        => ['label' => 'KKM default', 'max' => 6],
    'tahun_ajaran_aktif' => ['label' => 'Tahun ajaran aktif', 'max' => 9],
    'semester_aktif'     => ['label' => 'Semester aktif', 'max' => 10],
];

/**
 * Hapus berkas logo lama bila berada di folder logo.
 */
$deleteLogoFile = static function (?string $filename): void {
    $filename = basename((string) $filename);

    if ($filename === '') {
        return;
    }

    $path = UPLOADS_PATH . '/logo/' . $filename;

    if (is_file($path)) {
        @unlink($path);
    }
};

/* ---------------------------------------------------------------------
 * Proses POST
 * ------------------------------------------------------------------ */
if (is_post()) {
    require_csrf();
    $action = (string) post('action', '');

    if ($action === 'save') {
        foreach ($settingFields as $key => $definition) {
            $raw = post($key);
            $formData[$key] = is_string($raw) ? trim($raw) : '';

            if (mb_strlen($formData[$key]) > $definition['max']) {
                $errors[] = $definition['label'] . ' maksimal ' . $definition['max'] . ' karakter.';
            }
        }

        if ($formData['nama_aplikasi'] === '') {
            $errors[] = 'Nama aplikasi wajib diisi.';
        } elseif (mb_strlen($formData['nama_aplikasi']) < 2) {
            $errors[] = 'Nama aplikasi minimal 2 karakter.';
        }

        if ($formData['telepon_sekolah'] !== ''
            && preg_match('/^[0-9+()\-\s.]*$/u', $formData['telepon_sekolah']) !== 1) {
            $errors[] = 'Nomor telepon hanya boleh berisi angka, spasi, tanda +, tanda hubung, titik, dan tanda kurung.';
        }

        if ($formData['email_sekolah'] !== ''
            && filter_var($formData['email_sekolah'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Format email sekolah tidak valid.';
        }

        if ($formData['website_sekolah'] !== '' && !is_valid_url($formData['website_sekolah'])) {
            $errors[] = 'Website sekolah harus berupa URL lengkap, contoh https://sekolah.sch.id.';
        }

        if ($formData['nip_kepala_sekolah'] !== ''
            && preg_match('/^[0-9.\-\s]*$/u', $formData['nip_kepala_sekolah']) !== 1) {
            $errors[] = 'NIP hanya boleh berisi angka, spasi, titik, dan tanda hubung.';
        }

        if ($formData['kkm_default'] === '' || !is_numeric($formData['kkm_default'])) {
            $errors[] = 'KKM default wajib berupa angka.';
        } else {
            $kkmValue = (float) $formData['kkm_default'];
            if ($kkmValue < 0 || $kkmValue > 100) {
                $errors[] = 'KKM default harus berada di antara 0 dan 100.';
            }
            $formData['kkm_default'] = (string) round($kkmValue, 2);
        }

        if ($formData['tahun_ajaran_aktif'] !== '') {
            if (preg_match('/^(\d{4})\/(\d{4})$/', $formData['tahun_ajaran_aktif'], $tahunMatch) !== 1) {
                $errors[] = 'Tahun ajaran harus berformat YYYY/YYYY, contoh 2026/2027.';
            } elseif ((int) $tahunMatch[2] !== (int) $tahunMatch[1] + 1) {
                $errors[] = 'Tahun ajaran harus berurutan, contoh 2026/2027.';
            }
        }

        if (!in_array($formData['semester_aktif'], ['Ganjil', 'Genap'], true)) {
            $errors[] = 'Semester aktif harus Ganjil atau Genap.';
        }

        if ($errors === []) {
            $changed = [];
            foreach (array_keys($settingFields) as $key) {
                $oldValue = (string) ($oldSettings[$key] ?? '');
                if ($oldValue !== $formData[$key]) {
                    $changed[] = $settingFields[$key]['label'];
                }
            }

            if ($changed === []) {
                flash('info', 'Tidak ada perubahan pengaturan.');
                redirect($settingsPage);
            }

            try {
                $pdo = db();
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    'INSERT INTO pengaturan (nama_pengaturan, nilai)
                     VALUES (:nama, :nilai)
                     ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)'
                );

                foreach (array_keys($settingFields) as $key) {
                    $stmt->execute([
                        'nama'  => $key,
                        'nilai' => $formData[$key],
                    ]);
                }

                $pdo->commit();

                log_aktivitas(
                    'pengaturan',
                    'update',
                    'Memperbarui pengaturan: ' . implode(', ', $changed)
                );

                flash('success', 'Pengaturan berhasil disimpan.');
                redirect($settingsPage);
            } catch (Throwable $exception) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('[SIKILAT] Gagal menyimpan pengaturan: ' . $exception->getMessage());
                $errors[] = 'Pengaturan gagal disimpan. Silakan coba lagi.';
            }
        }
    } elseif ($action === 'upload_logo') {
        $file = $_FILES['logo'] ?? null;

        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash('error', 'Pilih berkas logo terlebih dahulu.');
            redirect($settingsPage);
        }

        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            flash('error', 'Berkas logo gagal diunggah. Kode kesalahan: ' . $uploadError . '.');
            redirect($settingsPage);
        }

        $tempPath = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0 || $size > 2 * 1024 * 1024) {
            flash('error', 'Ukuran logo maksimal 2 MB.');
            redirect($settingsPage);
        }

        if (!is_uploaded_file($tempPath) && PHP_SAPI !== 'cli') {
            flash('error', 'Berkas logo tidak valid.');
            redirect($settingsPage);
        }

        $imageInfo = @getimagesize($tempPath);
        $mimeToExtension = [
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];

        $mime = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
        if (!isset($mimeToExtension[$mime])) {
            flash('error', 'Logo harus berupa gambar PNG, JPG/JPEG, atau WEBP.');
            redirect($settingsPage);
        }

        if ((int) ($imageInfo[0] ?? 0) > 3000 || (int) ($imageInfo[1] ?? 0) > 3000) {
            flash('error', 'Dimensi logo maksimal 3000 x 3000 piksel.');
            redirect($settingsPage);
        }

        $logoDir = UPLOADS_PATH . '/logo';
        if (!is_dir($logoDir) && !@mkdir($logoDir, 0775, true) && !is_dir($logoDir)) {
            flash('error', 'Folder logo tidak tersedia. Periksa izin folder uploads.');
            redirect($settingsPage);
        }

        $filename = 'logo-' . date('YmdHis') . '-' . bin2hex(random_bytes(4))
            . '.' . $mimeToExtension[$mime];
        $targetPath = $logoDir . '/' . $filename;

        if (!move_uploaded_file($tempPath, $targetPath)) {
            flash('error', 'Logo gagal disimpan. Periksa izin tulis folder uploads/logo.');
            redirect($settingsPage);
        }

        @chmod($targetPath, 0644);

        try {
            $stmt = db()->prepare(
                'INSERT INTO pengaturan (nama_pengaturan, nilai)
                 VALUES (:nama, :nilai)
                 ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)'
            );
            $stmt->execute(['nama' => 'logo_sekolah', 'nilai' => $filename]);
        } catch (Throwable $exception) {
            @unlink($targetPath);
            error_log('[SIKILAT] Gagal menyimpan logo: ' . $exception->getMessage());
            flash('error', 'Logo gagal disimpan ke database.');
            redirect($settingsPage);
        }

        $deleteLogoFile((string) ($oldSettings['logo_sekolah'] ?? ''));

        log_aktivitas('pengaturan', 'upload_logo', 'Mengunggah logo sekolah baru: ' . $filename);
        flash('success', 'Logo sekolah berhasil diperbarui.');
        redirect($settingsPage);
    } elseif ($action === 'remove_logo') {
        $currentLogo = (string) ($oldSettings['logo_sekolah'] ?? '');

        if ($currentLogo === '') {
            flash('info', 'Belum ada logo khusus yang dapat dihapus.');
            redirect($settingsPage);
        }

        try {
            $stmt = db()->prepare(
                "INSERT INTO pengaturan (nama_pengaturan, nilai)
                 VALUES ('logo_sekolah', '')
                 ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)"
            );
            $stmt->execute();
        } catch (Throwable $exception) {
            error_log('[SIKILAT] Gagal menghapus logo: ' . $exception->getMessage());
            flash('error', 'Logo gagal dihapus dari database.');
            redirect($settingsPage);
        }

        $deleteLogoFile($currentLogo);

        log_aktivitas('pengaturan', 'remove_logo', 'Menghapus logo sekolah khusus.');
        flash('success', 'Logo sekolah dikembalikan ke logo bawaan.');
        redirect($settingsPage);
    }
}

$settings = settings_all();

if ($formData === []) {
    foreach (array_keys($settingFields) as $key) {
        $formData[$key] = (string) ($settings[$key] ?? '');
    }
}

$logoSetting = (string) ($settings['logo_sekolah'] ?? '');
$logoFilename = basename($logoSetting);
$hasCustomLogo = $logoFilename !== '' && is_file(UPLOADS_PATH . '/logo/' . $logoFilename);
$logoUrl = $hasCustomLogo
    ? url('uploads/logo/' . rawurlencode($logoFilename))
    : asset('img/logo-sikilat.svg');

$appNameDisplay = (string) ($formData['nama_aplikasi'] !== '' ? $formData['nama_aplikasi'] : APP_NAME);
$schemaVersion = (string) ($settings['versi_database'] ?? '-');
$settingCount = count($settings);

$pageTitle = 'Pengaturan';
$pageSubtitle = 'Identitas aplikasi, profil sekolah, logo, dan pengaturan akademik.';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url('admin/dashboard/index.php')],
    ['label' => 'Pengaturan'],
];

require INCLUDES_PATH . '/header.php';
?>

<div class="row g-3">
    <div class="col-12">
        <div class="card">
            <div class="card-body d-flex flex-wrap align-items-center gap-3">
                <img src="<?= e($logoUrl) ?>" alt="Logo <?= e($appNameDisplay) ?>"
                     class="rounded border bg-white p-2" style="width:72px;height:72px;object-fit:contain;">
                <div class="flex-grow-1 min-w-0">
                    <h2 class="h5 mb-1"><?= e($appNameDisplay) ?></h2>
                    <p class="text-secondary mb-0 small">
                        <?= e($formData['nama_sekolah'] !== '' ? $formData['nama_sekolah'] : 'Nama sekolah belum diisi') ?>
                        <?php if ($formData['tahun_ajaran_aktif'] !== '' || $formData['semester_aktif'] !== ''): ?>
                            &middot;
                            <?= e($formData['tahun_ajaran_aktif'] !== '' ? $formData['tahun_ajaran_aktif'] : '-') ?>
                            <?= e($formData['semester_aktif'] !== '' ? 'Semester ' . $formData['semester_aktif'] : '') ?>
                        <?php endif; ?>
                    </p>
                </div>
                <span class="badge text-bg-light border">Skema <?= e($schemaVersion) ?></span>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-8">
        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i> Pengaturan belum disimpan</div>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url($settingsPage)) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">

            <div class="card mb-3">
                <div class="card-header">
                    <i class="bi bi-window me-1 text-primary"></i> Identitas Aplikasi
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="namaAplikasi" class="form-label">Nama aplikasi <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="namaAplikasi" name="nama_aplikasi"
                               value="<?= e($formData['nama_aplikasi']) ?>" maxlength="50" required>
                        <div class="form-text">Dipakai pada judul halaman, sidebar, halaman siswa, dan laporan.</div>
                    </div>
                    <div>
                        <label for="footerLaporan" class="form-label">Footer laporan</label>
                        <input type="text" class="form-control" id="footerLaporan" name="footer_laporan"
                               value="<?= e($formData['footer_laporan']) ?>" maxlength="255">
                        <div class="form-text">Teks footer pada ekspor PDF. Kosongkan untuk memakai teks bawaan.</div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <i class="bi bi-building me-1 text-primary"></i> Identitas Sekolah
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="namaSekolah" class="form-label">Nama sekolah</label>
                            <input type="text" class="form-control" id="namaSekolah" name="nama_sekolah"
                                   value="<?= e($formData['nama_sekolah']) ?>" maxlength="150">
                        </div>
                        <div class="col-12">
                            <label for="alamatSekolah" class="form-label">Alamat</label>
                            <textarea class="form-control" id="alamatSekolah" name="alamat_sekolah"
                                      rows="2" maxlength="255"><?= e($formData['alamat_sekolah']) ?></textarea>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="kotaSekolah" class="form-label">Kota</label>
                            <input type="text" class="form-control" id="kotaSekolah" name="kota_sekolah"
                                   value="<?= e($formData['kota_sekolah']) ?>" maxlength="100">
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="teleponSekolah" class="form-label">Telepon</label>
                            <input type="tel" class="form-control" id="teleponSekolah" name="telepon_sekolah"
                                   value="<?= e($formData['telepon_sekolah']) ?>" maxlength="30"
                                   placeholder="(021) 1234567">
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="emailSekolah" class="form-label">Email</label>
                            <input type="email" class="form-control" id="emailSekolah" name="email_sekolah"
                                   value="<?= e($formData['email_sekolah']) ?>" maxlength="150"
                                   placeholder="sekolah@example.sch.id">
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="websiteSekolah" class="form-label">Website</label>
                            <input type="url" class="form-control" id="websiteSekolah" name="website_sekolah"
                                   value="<?= e($formData['website_sekolah']) ?>" maxlength="150"
                                   placeholder="https://sekolah.sch.id">
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="kepalaSekolah" class="form-label">Nama kepala sekolah</label>
                            <input type="text" class="form-control" id="kepalaSekolah" name="kepala_sekolah"
                                   value="<?= e($formData['kepala_sekolah']) ?>" maxlength="100">
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="nipKepalaSekolah" class="form-label">NIP kepala sekolah</label>
                            <input type="text" class="form-control" id="nipKepalaSekolah" name="nip_kepala_sekolah"
                                   value="<?= e($formData['nip_kepala_sekolah']) ?>" maxlength="30"
                                   inputmode="numeric" placeholder="19700101 199001 1 001">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <i class="bi bi-mortarboard me-1 text-primary"></i> Pengaturan Akademik
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label for="kkmDefault" class="form-label">KKM default <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="kkmDefault" name="kkm_default"
                                   value="<?= e($formData['kkm_default']) ?>" min="0" max="100" step="0.01" required>
                            <div class="form-text">Dipakai untuk ujian baru.</div>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="tahunAjaranAktif" class="form-label">Tahun ajaran aktif</label>
                            <input type="text" class="form-control" id="tahunAjaranAktif" name="tahun_ajaran_aktif"
                                   value="<?= e($formData['tahun_ajaran_aktif']) ?>" maxlength="9"
                                   placeholder="2026/2027">
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="semesterAktif" class="form-label">Semester aktif</label>
                            <select class="form-select" id="semesterAktif" name="semester_aktif">
                                <option value="Ganjil"<?= crud_selected($formData['semester_aktif'], 'Ganjil') ?>>Ganjil</option>
                                <option value="Genap"<?= crud_selected($formData['semester_aktif'], 'Genap') ?>>Genap</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check2-circle me-1"></i> Simpan Pengaturan
                </button>
                <a href="<?= e(url($settingsPage)) ?>" class="btn btn-light border">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Muat Ulang
                </a>
            </div>
        </form>
    </div>

    <div class="col-12 col-xl-4">
        <div class="card mb-3">
            <div class="card-header">
                <i class="bi bi-image me-1 text-primary"></i> Logo Sekolah
            </div>
            <div class="card-body">
                <div class="text-center mb-3">
                    <img src="<?= e($logoUrl) ?>" alt="Pratinjau logo sekolah"
                         class="rounded border bg-white p-2"
                         style="width:120px;height:120px;object-fit:contain;">
                    <div class="small text-secondary mt-2">
                        <?= $hasCustomLogo ? 'Logo khusus aktif' : 'Memakai logo bawaan' ?>
                    </div>
                </div>

                <form method="post" action="<?= e(url($settingsPage)) ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_logo">
                    <div class="mb-2">
                        <label for="logoFile" class="form-label">Unggah logo baru</label>
                        <input type="file" class="form-control" id="logoFile" name="logo"
                               accept="image/png,image/jpeg,image/webp" required>
                        <div class="form-text">PNG, JPG/JPEG, atau WEBP. Maksimal 2 MB dan 3000 x 3000 piksel.</div>
                    </div>
                    <button type="submit" class="btn btn-outline-primary w-100">
                        <i class="bi bi-upload me-1"></i> Unggah Logo
                    </button>
                </form>

                <?php if ($hasCustomLogo): ?>
                    <form method="post" action="<?= e(url($settingsPage)) ?>" class="mt-2"
                          data-confirm="Logo khusus akan dihapus dan tampilan kembali memakai logo bawaan."
                          data-confirm-title="Hapus logo sekolah?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove_logo">
                        <button type="submit" class="btn btn-outline-danger w-100">
                            <i class="bi bi-trash me-1"></i> Hapus Logo
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <i class="bi bi-info-circle me-1 text-primary"></i> Informasi Sistem
            </div>
            <div class="card-body">
                <dl class="row small mb-0">
                    <dt class="col-6 text-secondary fw-normal">Versi aplikasi</dt>
                    <dd class="col-6 text-end mb-2"><?= e(APP_VERSION) ?></dd>
                    <dt class="col-6 text-secondary fw-normal">Versi skema</dt>
                    <dd class="col-6 text-end mb-2"><?= e($schemaVersion) ?></dd>
                    <dt class="col-6 text-secondary fw-normal">Jumlah pengaturan</dt>
                    <dd class="col-6 text-end mb-2"><?= e($settingCount) ?></dd>
                    <dt class="col-6 text-secondary fw-normal">Administrator</dt>
                    <dd class="col-6 text-end mb-0 text-truncate" title="<?= e((string) ($currentUser['nama'] ?? '')) ?>">
                        <?= e((string) ($currentUser['nama'] ?? '-')) ?>
                    </dd>
                </dl>
                <hr>
                <p class="small text-secondary mb-0">
                    Perubahan langsung dipakai pada panel, halaman siswa, dan ekspor laporan.
                    Identitas sekolah juga tampil pada header laporan PDF.
                </p>
            </div>
        </div>
    </div>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
