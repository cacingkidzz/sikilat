<?php
declare(strict_types=1);

/**
 * SIKILAT - Konfirmasi hasil ujian siswa.
 * Hasil hanya dibaca dari session; tidak menerima ID hasil dari URL.
 */

require_once __DIR__ . '/../config/app.php';
require_once INCLUDES_PATH . '/siswa.php';

siswa_no_cache();

if (is_post()) {
    require_csrf();

    if ((string) post('action') === 'selesai') {
        siswa_clear_result();
        flash('success', 'Sesi ujian selesai. Terima kasih.');
        redirect('siswa/index.php');
    }
}

$result = siswa_result_context();

$pageTitle = 'Hasil Ujian';
$pageSubtitle = $result !== null ? 'Konfirmasi pengiriman dan ringkasan hasil koreksi.' : '';
$activeStep = 4;
$studentWide = false;
$schoolName = setting('nama_sekolah', '');
$appNameDisplay = setting('nama_aplikasi', APP_NAME);

include INCLUDES_PATH . '/siswa-header.php';
?>

<span data-clear-siswa-draft hidden></span>

<?php if ($result === null): ?>
    <div class="siswa-card siswa-empty">
        <span class="siswa-empty-icon"><i class="bi bi-file-earmark-x"></i></span>
        <h2>Belum ada hasil untuk ditampilkan</h2>
        <p>Hasil hanya dapat dilihat dari sesi browser yang mengirim jawaban. Jika Anda baru saja mengirim, buka kembali dari perangkat yang sama.</p>
        <a href="<?= e(url('siswa/index.php')) ?>" class="btn btn-siswa-primary">Kembali ke Halaman Ujian</a>
    </div>
<?php else: ?>
    <?php
    $nilai = (float) $result['nilai'];
    $kkm = (float) $result['kkm'];
    $lulus = ($nilai + 0.00001) >= $kkm;
    $totalSoal = (int) $result['total_soal'];
    $benar = (int) $result['jumlah_benar'];
    $salah = (int) $result['jumlah_salah'];
    $kosong = (int) $result['jumlah_kosong'];
    $skorTotal = (float) ($result['skor_total'] ?? $benar);
    $hasMulti = !empty($result['has_multi']);
    $durasi = $result['durasi_detik'] !== null ? (int) $result['durasi_detik'] : null;
    $scorePercent = max(0.0, min(100.0, $nilai));
    ?>

    <section class="result-hero">
        <div class="result-success-icon"><i class="bi bi-check-lg"></i></div>
        <span class="result-eyebrow">Pengiriman berhasil</span>
        <h1>Jawaban Anda sudah diterima.</h1>
        <p>
            Terima kasih, <strong><?= e((string) $result['nama_siswa']) ?></strong>.
            Jawaban telah dikoreksi otomatis dan disimpan. Jawaban tidak dapat diubah lagi.
        </p>

        <div class="result-score-wrap">
            <div class="result-score" style="--score: <?= e(number_format($scorePercent, 2, '.', '')) ?>%"
                 data-result-score="<?= e(number_format($scorePercent, 2, '.', '')) ?>">
                <span>
                    <strong><?= e(format_nilai($nilai)) ?></strong>
                    <small>Nilai</small>
                </span>
            </div>
            <div class="result-score-side">
                <span class="result-status <?= $lulus ? 'is-pass' : 'is-fail' ?>">
                    <i class="bi bi-<?= $lulus ? 'patch-check-fill' : 'info-circle-fill' ?>"></i>
                    <?= $lulus ? 'Mencapai KKM' : 'Belum mencapai KKM' ?>
                </span>
                <span class="text-secondary small">KKM <?= e(format_nilai($kkm)) ?></span>
                <span class="text-secondary small">
                    Nilai = total skor ÷ jumlah soal × 100
                </span>
                <span class="text-secondary small">
                    Skor total <?= e(format_nilai($skorTotal)) ?> / <?= e((string) $totalSoal) ?>
                </span>
                <?php if ($hasMulti): ?>
                    <span class="text-secondary small">
                        Soal Multiple Response memakai skor parsial.
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="result-stats">
        <div class="result-stat is-correct">
            <span class="result-stat-icon"><i class="bi bi-check-circle-fill"></i></span>
            <strong><?= e((string) $benar) ?></strong>
            <span>Benar</span>
        </div>
        <div class="result-stat is-wrong">
            <span class="result-stat-icon"><i class="bi bi-x-circle-fill"></i></span>
            <strong><?= e((string) $salah) ?></strong>
            <span>Salah</span>
        </div>
        <div class="result-stat is-empty">
            <span class="result-stat-icon"><i class="bi bi-dash-circle-fill"></i></span>
            <strong><?= e((string) $kosong) ?></strong>
            <span>Kosong</span>
        </div>
        <div class="result-stat">
            <span class="result-stat-icon"><i class="bi bi-list-ol"></i></span>
            <strong><?= e((string) $totalSoal) ?></strong>
            <span>Total soal</span>
        </div>
    </div>

    <div class="result-grid">
        <section class="siswa-card result-card">
            <h2><i class="bi bi-person-vcard"></i> Identitas peserta</h2>
            <dl class="result-list">
                <div><dt>Nama lengkap</dt><dd><?= e((string) $result['nama_siswa']) ?></dd></div>
                <div><dt>Kelas</dt><dd><?= e((string) $result['kelas_nama']) ?></dd></div>
                <div><dt>Nomor absen</dt><dd><?= e((string) $result['nomor_absen']) ?></dd></div>
                <div><dt>Mata pelajaran</dt><dd><?= e((string) $result['mapel_nama']) ?></dd></div>
                <div><dt>Ujian</dt><dd><?= e((string) $result['ujian_nama']) ?></dd></div>
                <div><dt>Paket soal</dt><dd>Paket <?= e((string) $result['kode_paket']) ?></dd></div>
            </dl>
        </section>

        <section class="siswa-card result-card">
            <h2><i class="bi bi-clock-history"></i> Waktu pengerjaan</h2>
            <dl class="result-list">
                <div>
                    <dt>Mulai mengerjakan</dt>
                    <dd><?= e($result['started_at'] !== null ? format_tanggal((string) $result['started_at'], true) : '-') ?></dd>
                </div>
                <div>
                    <dt>Waktu pengiriman</dt>
                    <dd><?= e(format_tanggal((string) $result['submitted_at'], true)) ?></dd>
                </div>
                <div>
                    <dt>Durasi pengerjaan</dt>
                    <dd><?= e(siswa_format_duration($durasi)) ?></dd>
                </div>
                <div>
                    <dt>Status penyimpanan</dt>
                    <dd><span class="result-saved"><i class="bi bi-shield-check"></i> Tersimpan &amp; terkunci</span></dd>
                </div>
            </dl>
        </section>
    </div>

    <div class="result-note">
        <i class="bi bi-info-circle-fill"></i>
        <p>
            Simpan atau cetak konfirmasi ini sebagai bukti pengiriman. Untuk koreksi atau pertanyaan nilai,
            hubungi pengawas ujian dengan menyebutkan nama, kelas, dan nomor absen Anda.
            <?php if ($hasMulti): ?>
                Nilai Multiple Response dihitung dari opsi benar yang dipilih dikurangi opsi salah,
                dibagi jumlah kunci, sehingga jawaban yang belum tepat seluruhnya tetap dapat memperoleh skor sebagian.
            <?php endif; ?>
        </p>
    </div>

    <div class="result-actions">
        <button type="button" class="btn btn-siswa-outline btn-lg" data-print-result>
            <i class="bi bi-printer me-1"></i> Cetak Konfirmasi
        </button>
        <form method="post" action="<?= e(url('siswa/hasil.php')) ?>"
              data-confirm
              data-confirm-title="Selesaikan sesi?"
              data-confirm-text="Konfirmasi hasil akan ditutup. Pastikan Anda sudah mencatat nilai dan data penting."
              data-confirm-button="Ya, selesai">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="selesai">
            <button type="submit" class="btn btn-siswa-primary btn-lg">
                <i class="bi bi-check2-circle me-1"></i> Selesai
            </button>
        </form>
    </div>
<?php endif; ?>

<?php include INCLUDES_PATH . '/siswa-footer.php'; ?>
