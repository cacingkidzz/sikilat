<?php
declare(strict_types=1);

/**
 * SIKILAT - Halaman awal siswa.
 * Menampilkan ujian aktif dan melanjutkan sesi pengerjaan bila ada.
 */

require_once __DIR__ . '/../config/app.php';
require_once INCLUDES_PATH . '/siswa.php';

siswa_no_cache();

if (is_post()) {
    require_csrf();

    if ((string) post('action') === 'reset') {
        siswa_clear_attempt();
        flash('info', 'Sesi pengerjaan dibatalkan. Silakan pilih ujian kembali.');
        redirect('siswa/index.php');
    }
}

$attempt = siswa_attempt_context();
$lastResult = siswa_result_context();
$exams = siswa_active_exams();

$examCards = [];
foreach ($exams as $exam) {
    $totalSoal = (int) $exam['jumlah_soal'];
    $packages = siswa_exam_packages((int) $exam['id'], $totalSoal);
    $completePackages = array_values(array_filter(
        $packages,
        static fn (array $package): bool => !empty($package['complete'])
    ));
    $classes = siswa_exam_classes((int) $exam['id']);

    $examCards[] = [
        'exam'      => $exam,
        'packages'  => $completePackages,
        'classes'   => $classes,
        'available' => $completePackages !== [] && $classes !== [],
    ];
}

$pageTitle = 'Ujian Siswa';
$pageSubtitle = '';
$activeStep = 1;
$studentWide = false;
$schoolName = setting('nama_sekolah', '');
$appNameDisplay = setting('nama_aplikasi', APP_NAME);

include INCLUDES_PATH . '/siswa-header.php';
?>

<section class="siswa-hero">
    <div class="siswa-hero-content">
        <span class="siswa-eyebrow"><i class="bi bi-mortarboard-fill"></i> Portal Peserta Ujian</span>
        <h1>Kerjakan ujian dengan tenang dan teliti.</h1>
        <p>
            Pilih ujian yang sedang aktif, lengkapi identitas, lalu isi jawaban.
            Jawaban akan dikoreksi otomatis setelah Anda mengonfirmasi pengiriman.
        </p>
        <div class="siswa-hero-meta">
            <span><i class="bi bi-buildings"></i> <?= e($schoolName !== '' ? $schoolName : $appNameDisplay) ?></span>
            <span><i class="bi bi-journal-check"></i> <?= e((string) count($examCards)) ?> ujian aktif</span>
            <span><i class="bi bi-shield-check"></i> Kunci jawaban tidak dikirim ke perangkat siswa</span>
        </div>
    </div>
    <div class="siswa-hero-art" aria-hidden="true">
        <i class="bi bi-pencil-square"></i>
    </div>
</section>

<?php if ($attempt !== null): ?>
    <section class="siswa-resume" aria-label="Lanjutkan ujian">
        <div class="siswa-resume-icon"><i class="bi bi-play-circle-fill"></i></div>
        <div class="siswa-resume-body">
            <span class="siswa-resume-label">Sesi pengerjaan tersimpan</span>
            <strong><?= e((string) $attempt['ujian_nama']) ?></strong>
            <span class="small text-secondary">
                <?= e((string) $attempt['nama_siswa']) ?>
                &middot; <?= e((string) $attempt['kelas_nama']) ?>
                &middot; Absen <?= e((string) $attempt['nomor_absen']) ?>
            </span>
        </div>
        <div class="siswa-resume-actions">
            <a href="<?= e(url('siswa/ujian.php')) ?>" class="btn btn-siswa-primary">
                <i class="bi bi-arrow-right-circle me-1"></i> Lanjutkan
            </a>
            <form method="post" action="<?= e(url('siswa/index.php')) ?>"
                  data-confirm
                  data-confirm-title="Batalkan sesi pengerjaan?"
                  data-confirm-text="Jawaban yang belum dikirim akan hilang dan Anda harus memilih ujian dari awal."
                  data-confirm-button="Ya, batalkan">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset">
                <button type="submit" class="btn btn-siswa-ghost">Batalkan</button>
            </form>
        </div>
    </section>
<?php endif; ?>

<?php if ($lastResult !== null): ?>
    <a class="siswa-result-banner" href="<?= e(url('siswa/hasil.php')) ?>">
        <span class="siswa-result-banner-icon"><i class="bi bi-award-fill"></i></span>
        <span class="siswa-result-banner-body">
            <strong>Konfirmasi hasil terakhir sudah tersedia</strong>
            <span><?= e((string) $lastResult['ujian_nama']) ?> &middot; Nilai <?= e(format_nilai($lastResult['nilai'])) ?></span>
        </span>
        <span class="siswa-result-banner-action"><i class="bi bi-chevron-right"></i></span>
    </a>
<?php endif; ?>

<div class="siswa-section-head">
    <div>
        <h2>Ujian yang tersedia</h2>
        <p>Hanya ujian berstatus aktif dengan paket dan kunci lengkap yang dapat dikerjakan.</p>
    </div>
</div>

<?php if ($examCards === []): ?>
    <div class="siswa-card siswa-empty">
        <span class="siswa-empty-icon"><i class="bi bi-calendar-x"></i></span>
        <h2>Belum ada ujian aktif</h2>
        <p>Ujian akan muncul di sini setelah pengajar mengaktifkannya. Silakan hubungi pengawas atau pengajar Anda.</p>
    </div>
<?php else: ?>
    <div class="exam-grid">
        <?php foreach ($examCards as $card): ?>
            <?php
            $exam = $card['exam'];
            $classes = $card['classes'];
            $packages = $card['packages'];
            $available = (bool) $card['available'];
            $classNames = array_map(static fn (array $class): string => (string) $class['nama'], $classes);
            $shownClasses = array_slice($classNames, 0, 4);
            $remainingClasses = max(0, count($classNames) - count($shownClasses));
            ?>
            <article class="exam-card">
                <div class="exam-card-top">
                    <span class="exam-subject"><?= e((string) $exam['mapel_nama']) ?></span>
                    <span class="exam-status"><span></span> Aktif</span>
                </div>
                <h2><?= e((string) $exam['nama']) ?></h2>
                <?php if (trim((string) $exam['deskripsi']) !== ''): ?>
                    <p class="exam-description"><?= e(str_limit((string) $exam['deskripsi'], 130)) ?></p>
                <?php endif; ?>

                <div class="exam-meta">
                    <span><i class="bi bi-list-ol"></i> <?= e((string) $exam['jumlah_soal']) ?> soal</span>
                    <span><i class="bi bi-bullseye"></i> KKM <?= e(format_nilai($exam['kkm'])) ?></span>
                    <span><i class="bi bi-calendar3"></i> <?= e(format_tanggal((string) $exam['tanggal_ujian'])) ?></span>
                    <span><i class="bi bi-clock"></i> <?= e(substr((string) $exam['waktu_mulai'], 0, 5)) ?>–<?= e(substr((string) $exam['waktu_selesai'], 0, 5)) ?></span>
                </div>

                <div class="exam-package-line">
                    <i class="bi bi-collection"></i>
                    <?= e((string) count($packages)) ?> paket siap:
                    <strong><?= e(implode(', ', array_map(static fn (array $package): string => 'Paket ' . $package['kode_paket'], $packages))) ?></strong>
                </div>

                <?php if ($shownClasses !== []): ?>
                    <div class="exam-classes">
                        <?php foreach ($shownClasses as $className): ?>
                            <span><?= e($className) ?></span>
                        <?php endforeach; ?>
                        <?php if ($remainingClasses > 0): ?>
                            <span>+<?= e((string) $remainingClasses) ?> kelas</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="exam-card-footer">
                    <?php if ($available): ?>
                        <a href="<?= e(url('siswa/identitas.php?ujian_id=' . (int) $exam['id'])) ?>" class="btn btn-siswa-primary w-100">
                            Pilih Ujian <i class="bi bi-arrow-right ms-1"></i>
                        </a>
                    <?php else: ?>
                        <button type="button" class="btn btn-siswa-disabled w-100" disabled>
                            <i class="bi bi-exclamation-circle me-1"></i> Belum siap
                        </button>
                        <p class="exam-unavailable">
                            <?= $classes === [] ? 'Belum ada kelas peserta aktif.' : 'Paket atau kunci jawaban belum lengkap.' ?>
                        </p>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="siswa-tips">
    <div><i class="bi bi-phone"></i><span>Gunakan HP atau komputer. Satu peserta sebaiknya memakai satu perangkat.</span></div>
    <div><i class="bi bi-save2"></i><span>Jawaban disimpan di sesi browser sampai Anda menekan tombol kirim.</span></div>
    <div><i class="bi bi-lock"></i><span>Setelah berhasil dikirim, jawaban tidak dapat diubah lagi.</span></div>
</div>

<?php include INCLUDES_PATH . '/siswa-footer.php'; ?>
