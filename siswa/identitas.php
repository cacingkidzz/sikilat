<?php
declare(strict_types=1);

/**
 * SIKILAT - Formulir identitas peserta sebelum mengerjakan ujian.
 */

require_once __DIR__ . '/../config/app.php';
require_once INCLUDES_PATH . '/siswa.php';

siswa_no_cache();

$ujianId = is_post() ? (int) post('ujian_id', 0) : (int) input('ujian_id', 0);
$exam = $ujianId > 0 ? siswa_exam($ujianId) : null;

if ($exam === null || (string) $exam['status'] !== 'aktif') {
    flash('error', 'Ujian tidak ditemukan atau sudah tidak aktif.');
    redirect('siswa/index.php');
}

$attempt = siswa_attempt_context();
if ($attempt !== null) {
    flash('info', 'Sesi pengerjaan Anda masih berjalan. Lanjutkan ujian yang sedang dikerjakan.');
    redirect('siswa/ujian.php');
}

$totalSoal = (int) $exam['jumlah_soal'];
$classes = siswa_exam_classes($ujianId);
$allPackages = siswa_exam_packages($ujianId, $totalSoal);
$packages = array_values(array_filter(
    $allPackages,
    static fn (array $package): bool => !empty($package['complete'])
));
$classIds = array_map(static fn (array $class): int => (int) $class['id'], $classes);
$packageIds = array_map(static fn (array $package): int => (int) $package['id'], $packages);
$available = $classes !== [] && $packages !== [];

$errors = [];
$values = [
    'kelas_id'       => '',
    'nama'           => '',
    'nomor_absen'    => '',
    'paket_soal_id'  => '',
];

if (is_post()) {
    require_csrf();

    $kelasId = (int) post('kelas_id', 0);
    $paketId = (int) post('paket_soal_id', 0);
    $namaRaw = is_string(post('nama')) ? (string) post('nama') : '';
    $absenRaw = is_string(post('nomor_absen')) ? (string) post('nomor_absen') : '';
    $nama = crud_text($namaRaw, 100);
    $absen = crud_text($absenRaw, 20);

    $values = [
        'kelas_id'      => $kelasId > 0 ? (string) $kelasId : '',
        'nama'          => $nama,
        'nomor_absen'   => $absen,
        'paket_soal_id' => $paketId > 0 ? (string) $paketId : '',
    ];

    if (!in_array($kelasId, $classIds, true)) {
        $errors[] = 'Pilih kelas yang terdaftar sebagai peserta ujian ini.';
    }

    if (mb_strlen(trim($namaRaw)) < 3) {
        $errors[] = 'Nama lengkap minimal 3 karakter.';
    } elseif (mb_strlen(trim($namaRaw)) > 100) {
        $errors[] = 'Nama lengkap maksimal 100 karakter.';
    }

    if ($absen === '') {
        $errors[] = 'Nomor absen wajib diisi.';
    } elseif (mb_strlen(trim($absenRaw)) > 20) {
        $errors[] = 'Nomor absen maksimal 20 karakter.';
    } elseif (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,19}$/', $absen) !== 1) {
        $errors[] = 'Nomor absen hanya boleh berisi huruf, angka, titik, garis miring, dan tanda hubung.';
    }

    if (!in_array($paketId, $packageIds, true)) {
        $errors[] = 'Pilih paket soal yang sudah lengkap kunci jawabannya.';
    }

    if ($errors === []) {
        $stmt = db()->prepare(
            'SELECT id FROM hasil_ujian
             WHERE ujian_id = :ujian_id AND kelas_id = :kelas_id AND nomor_absen = :nomor_absen
             LIMIT 1'
        );
        $stmt->execute([
            'ujian_id'    => $ujianId,
            'kelas_id'    => $kelasId,
            'nomor_absen' => $absen,
        ]);

        if ($stmt->fetch() !== false) {
            $errors[] = 'Nomor absen ' . $absen . ' pada kelas ini sudah mengirim jawaban untuk ujian ini. '
                . 'Hubungi pengawas jika Anda merasa belum mengirim.';
        }
    }

    if ($errors === []) {
        siswa_start_attempt($exam, $kelasId, $paketId, $nama, $absen);
        flash('success', 'Identitas tersimpan. Selamat mengerjakan, ' . $nama . '!');
        redirect('siswa/ujian.php');
    }
}

$pageTitle = 'Identitas Peserta';
$pageSubtitle = 'Lengkapi data diri dan pilih paket soal sebelum mulai.';
$activeStep = 2;
$studentWide = false;
$schoolName = setting('nama_sekolah', '');
$appNameDisplay = setting('nama_aplikasi', APP_NAME);

include INCLUDES_PATH . '/siswa-header.php';
?>

<nav class="siswa-breadcrumb" aria-label="Navigasi">
    <a href="<?= e(url('siswa/index.php')) ?>"><i class="bi bi-arrow-left"></i> Kembali ke daftar ujian</a>
</nav>

<?php if (!$available): ?>
    <div class="siswa-card siswa-empty">
        <span class="siswa-empty-icon"><i class="bi bi-cone-striped"></i></span>
        <h2>Ujian belum dapat dimulai</h2>
        <p>
            <?= $classes === [] ? 'Belum ada kelas peserta aktif untuk ujian ini.' : 'Paket soal atau kunci jawaban belum lengkap.' ?>
            Hubungi pengawas ujian.
        </p>
        <a href="<?= e(url('siswa/index.php')) ?>" class="btn btn-siswa-primary">Kembali</a>
    </div>
<?php else: ?>
    <div class="identity-grid">
        <aside class="siswa-card exam-summary">
            <span class="exam-summary-badge"><?= e((string) $exam['mapel_nama']) ?></span>
            <h2><?= e((string) $exam['nama']) ?></h2>
            <?php if (trim((string) $exam['deskripsi']) !== ''): ?>
                <p class="exam-summary-description"><?= e((string) $exam['deskripsi']) ?></p>
            <?php endif; ?>

            <dl class="exam-summary-list">
                <div>
                    <dt><i class="bi bi-calendar3"></i> Tanggal</dt>
                    <dd><?= e(format_tanggal((string) $exam['tanggal_ujian'])) ?></dd>
                </div>
                <div>
                    <dt><i class="bi bi-clock"></i> Waktu</dt>
                    <dd><?= e(substr((string) $exam['waktu_mulai'], 0, 5)) ?>–<?= e(substr((string) $exam['waktu_selesai'], 0, 5)) ?> WIB</dd>
                </div>
                <div>
                    <dt><i class="bi bi-list-ol"></i> Jumlah soal</dt>
                    <dd><?= e((string) $totalSoal) ?> soal</dd>
                </div>
                <div>
                    <dt><i class="bi bi-bullseye"></i> KKM</dt>
                    <dd><?= e(format_nilai($exam['kkm'])) ?></dd>
                </div>
                <div>
                    <dt><i class="bi bi-people"></i> Kelas peserta</dt>
                    <dd><?= e((string) count($classes)) ?> kelas</dd>
                </div>
                <div>
                    <dt><i class="bi bi-collection"></i> Paket tersedia</dt>
                    <dd><?= e((string) count($packages)) ?> paket</dd>
                </div>
            </dl>

            <div class="exam-summary-note">
                <i class="bi bi-info-circle"></i>
                <span>Pastikan data yang Anda isi benar. Identitas yang sudah tersimpan tidak dapat diubah selama sesi berlangsung.</span>
            </div>
        </aside>

        <section class="siswa-card identity-form-card">
            <div class="identity-form-head">
                <span class="identity-form-icon"><i class="bi bi-person-vcard"></i></span>
                <div>
                    <h2>Data peserta</h2>
                    <p>Isi sesuai daftar hadir kelas Anda.</p>
                </div>
            </div>

            <?= crud_errors_html($errors) ?>

            <form method="post" action="<?= e(url('siswa/identitas.php?ujian_id=' . $ujianId)) ?>"
                  class="identity-form" autocomplete="off" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="ujian_id" value="<?= e((string) $ujianId) ?>">

                <div class="siswa-field">
                    <label for="kelas_id" class="siswa-label">Kelas <span class="text-danger">*</span></label>
                    <select class="form-select siswa-control" id="kelas_id" name="kelas_id" required>
                        <option value="">— Pilih kelas —</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= e((string) $class['id']) ?>"<?= crud_selected($values['kelas_id'], $class['id']) ?>>
                                <?= e((string) $class['nama']) ?> (Tingkat <?= e((string) $class['tingkat']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="siswa-field">
                    <label for="nama" class="siswa-label">Nama lengkap <span class="text-danger">*</span></label>
                    <input type="text" class="form-control siswa-control" id="nama" name="nama"
                           value="<?= e((string) $values['nama']) ?>" maxlength="100" minlength="3"
                           placeholder="Contoh: Nadia Putri Ramadhani" autocomplete="name" required>
                    <div class="form-text">Tulis nama lengkap sesuai daftar hadir.</div>
                </div>

                <div class="siswa-field">
                    <label for="nomor_absen" class="siswa-label">Nomor absen <span class="text-danger">*</span></label>
                    <input type="text" class="form-control siswa-control" id="nomor_absen" name="nomor_absen"
                           value="<?= e((string) $values['nomor_absen']) ?>" maxlength="20"
                           placeholder="Contoh: 07" inputmode="text" autocomplete="off" required>
                    <div class="form-text">Gunakan nomor absen yang sama dengan daftar hadir.</div>
                </div>

                <div class="siswa-field">
                    <span class="siswa-label">Paket soal <span class="text-danger">*</span></span>
                    <div class="package-options">
                        <?php foreach ($packages as $package): ?>
                            <?php $packageInputId = 'paket_' . (int) $package['id']; ?>
                            <label class="package-card" for="<?= e($packageInputId) ?>">
                                <input class="package-input" type="radio" name="paket_soal_id"
                                       id="<?= e($packageInputId) ?>" value="<?= e((string) $package['id']) ?>"
                                       <?= (string) $values['paket_soal_id'] === (string) $package['id'] ? 'checked' : '' ?>
                                       required>
                                <span class="package-card-body">
                                    <span class="package-code"><?= e((string) $package['kode_paket']) ?></span>
                                    <span class="package-text">
                                        <strong>Paket <?= e((string) $package['kode_paket']) ?></strong>
                                        <small><?= e(trim((string) $package['keterangan']) !== '' ? (string) $package['keterangan'] : 'Kunci jawaban lengkap') ?></small>
                                    </span>
                                    <span class="package-check"><i class="bi bi-check-lg"></i></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text">Paket yang tampil sudah memiliki kunci jawaban lengkap.</div>
                </div>

                <div class="identity-actions">
                    <button type="submit" class="btn btn-siswa-primary btn-lg">
                        <i class="bi bi-play-fill me-1"></i> Mulai Mengerjakan
                    </button>
                    <a href="<?= e(url('siswa/index.php')) ?>" class="btn btn-siswa-ghost btn-lg">Batal</a>
                </div>

                <p class="identity-privacy">
                    <i class="bi bi-shield-lock"></i>
                    Data identitas dan waktu mulai disimpan di sesi server. Kunci jawaban tidak pernah dikirim ke browser.
                </p>
            </form>
        </section>
    </div>
<?php endif; ?>

<?php include INCLUDES_PATH . '/siswa-footer.php'; ?>
