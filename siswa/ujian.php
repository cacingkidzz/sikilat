<?php
declare(strict_types=1);

/**
 * SIKILAT - Lembar jawaban siswa.
 * Pilihan jawaban disinkronkan antara input cepat dan input per nomor.
 * Kunci jawaban tidak pernah dimuat atau dikirim ke halaman ini.
 */

require_once __DIR__ . '/../config/app.php';
require_once INCLUDES_PATH . '/siswa.php';

siswa_no_cache();

$context = siswa_attempt_context();
if ($context === null) {
    flash('warning', 'Sesi pengerjaan tidak ditemukan. Silakan pilih ujian kembali.');
    redirect('siswa/index.php');
}

if (is_post() && (string) post('action') === 'reset') {
    require_csrf();
    siswa_clear_attempt();
    flash('info', 'Sesi pengerjaan dibatalkan. Jawaban yang belum dikirim tidak disimpan.');
    redirect('siswa/index.php');
}

if (empty($context['keys_complete'])) {
    siswa_clear_attempt();
    flash('error', 'Paket soal yang Anda pilih belum memiliki kunci lengkap. Hubungi pengawas ujian.');
    redirect('siswa/index.php');
}

$totalSoal = (int) $context['jumlah_soal'];
$types = $context['tipe_soal'] ?? soal_types_complete($totalSoal);
$hasMulti = (bool) ($context['has_multi'] ?? soal_types_have_multi($types));
$answers = array_fill(1, $totalSoal, null);
$bulkValue = '';
$errors = [];

if (is_post()) {
    require_csrf();

    $bulkRaw = is_string(post('jawaban_massal')) ? (string) post('jawaban_massal') : '';
    $collected = siswa_collect_answers(post('jawaban'), $bulkRaw, $totalSoal, $types);

    if (!$collected['ok']) {
        $errors[] = $collected['error'];
        $bulkValue = $bulkRaw;

        $postedAnswers = post('jawaban');
        if (is_array($postedAnswers)) {
            foreach ($postedAnswers as $number => $postedValue) {
                $number = (int) $number;
                if ($number < 1 || $number > $totalSoal) {
                    continue;
                }

                $normalized = soal_answer_normalize($types[$number] ?? 'pg', $postedValue);
                if ($normalized['ok'] && $normalized['value'] !== null) {
                    $answers[$number] = $normalized['value'];
                }
            }
        }
    } else {
        $answers = $collected['answers'];
        $bulkValue = siswa_bulk_text($answers, $totalSoal, $types);
        $submission = siswa_submit_attempt($context, $answers);

        if ($submission['ok']) {
            flash('success', 'Jawaban berhasil dikirim dan dikoreksi otomatis.');
            redirect('siswa/hasil.php');
        }

        if ($submission['duplicate']) {
            siswa_clear_attempt();
            if (siswa_result_context() !== null) {
                redirect('siswa/hasil.php');
            }

            flash('error', $submission['message'] . ' Hubungi pengawas ujian jika perlu.');
            redirect('siswa/index.php');
        }

        $errors[] = $submission['message'];
    }
}

$pageTitle = 'Lembar Jawaban';
$pageSubtitle = 'Isi jawaban dengan input cepat, pilihan per nomor, atau kombinasi keduanya.';
$activeStep = 3;
$studentWide = true;
$schoolName = setting('nama_sekolah', '');
$appNameDisplay = setting('nama_aplikasi', APP_NAME);

include INCLUDES_PATH . '/siswa-header.php';
?>

<?= crud_errors_html($errors) ?>

<noscript>
    <div class="alert siswa-alert alert-warning d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill siswa-alert-icon"></i>
        <div>JavaScript diperlukan untuk pratinjau dan konfirmasi jawaban. Aktifkan JavaScript di browser Anda sebelum mengirim.</div>
    </div>
</noscript>

<form method="post" action="<?= e(url('siswa/ujian.php')) ?>" id="formJawaban"
      class="answer-form" data-answer-form data-total="<?= e((string) $totalSoal) ?>"
      data-types="<?= e(json_encode($types, JSON_UNESCAPED_UNICODE)) ?>"
      data-has-multi="<?= $hasMulti ? '1' : '0' ?>"
      data-attempt-token="<?= e((string) $context['attempt_token']) ?>"
      data-started-ts="<?= e((string) $context['started_ts']) ?>" autocomplete="off">
    <?= csrf_field() ?>

    <div class="exam-layout">
        <aside class="exam-sidebar">
            <section class="siswa-card exam-progress-card">
                <div class="exam-progress-head">
                    <span class="exam-progress-label">Progres jawaban</span>
                    <span class="exam-timer" data-siswa-timer data-start-ts="<?= e((string) $context['started_ts']) ?>">
                        <i class="bi bi-stopwatch"></i> <span data-timer-text>00:00</span>
                    </span>
                </div>
                <div class="exam-metrics">
                    <div class="exam-metric">
                        <strong data-answered-count>0</strong>
                        <span>Terisi</span>
                    </div>
                    <div class="exam-metric">
                        <strong data-empty-count><?= e((string) $totalSoal) ?></strong>
                        <span>Kosong</span>
                    </div>
                    <div class="exam-metric">
                        <strong><span data-progress-percent>0</span>%</strong>
                        <span>Selesai</span>
                    </div>
                </div>
                <div class="progress exam-progress-bar" role="progressbar" aria-label="Progres jawaban"
                     aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-progress-track>
                    <div class="progress-bar" data-progress-bar style="width:0%"></div>
                </div>
                <p class="exam-progress-hint mb-0">Periksa kembali nomor yang masih kosong sebelum mengirim.</p>
            </section>

            <section class="siswa-card exam-identity-card">
                <h2><i class="bi bi-person-badge"></i> Peserta</h2>
                <dl class="exam-identity-list">
                    <div><dt>Nama</dt><dd><?= e((string) $context['nama_siswa']) ?></dd></div>
                    <div><dt>Kelas</dt><dd><?= e((string) $context['kelas_nama']) ?></dd></div>
                    <div><dt>Absen</dt><dd><?= e((string) $context['nomor_absen']) ?></dd></div>
                    <div><dt>Paket</dt><dd>Paket <?= e((string) $context['kode_paket']) ?></dd></div>
                    <div><dt>Ujian</dt><dd><?= e((string) $context['ujian_nama']) ?></dd></div>
                </dl>
            </section>

            <section class="siswa-card bulk-card">
                <div class="bulk-head">
                    <h2><i class="bi bi-lightning-charge-fill"></i> Input cepat</h2>
                    <span class="small text-secondary" data-bulk-counter>0 / <?= e((string) $totalSoal) ?> terisi</span>
                </div>
                <label class="visually-hidden" for="jawaban_massal">Input cepat jawaban</label>
                <textarea class="form-control siswa-bulk" id="jawaban_massal" name="jawaban_massal"
                          rows="3" spellcheck="false" autocapitalize="characters" data-bulk-input
                          placeholder="<?= $hasMulti ? 'A AC B S - D' : 'ABCDEABCDE' ?>"><?= e($bulkValue) ?></textarea>
                <div class="bulk-actions">
                    <button type="button" class="btn btn-sm btn-siswa-ghost" data-clear-all>
                        <i class="bi bi-eraser"></i> Kosongkan
                    </button>
                    <span class="bulk-message" data-bulk-message aria-live="polite"></span>
                </div>
                <p class="form-text mb-0">
                    <?php if ($hasMulti): ?>
                        Tulis satu jawaban per nomor, dipisah spasi atau koma.
                        PG memakai <code>A</code>–<code>E</code>, Benar/Salah <code>B</code>/<code>S</code>,
                        dan Multiple Response digabung tanpa spasi (mis. <code>AC</code>).
                        Gunakan <code>-</code> untuk nomor kosong.
                    <?php else: ?>
                        Tulis jawaban berurutan mulai nomor 1.
                        PG memakai <code>A</code>–<code>E</code>, Benar/Salah memakai <code>B</code>/<code>S</code>.
                        Gunakan <code>-</code> untuk nomor kosong.
                    <?php endif; ?>
                </p>
            </section>

            <section class="siswa-card exam-help-card">
                <h2><i class="bi bi-lightbulb"></i> Cara mengisi</h2>
                <ul>
                    <li>Ketik atau tempel jawaban berurutan pada input cepat.</li>
                    <li>Atau pilih jawaban langsung pada nomor soal.</li>
                    <li>Soal <strong>Multiple Response</strong> boleh memilih lebih dari satu opsi; tombol <strong>–</strong> mengosongkan nomor.</li>
                    <li>Kedua metode selalu tersinkronisasi otomatis.</li>
                    <?php if ($hasMulti): ?>
                        <li>Jawaban Multiple Response yang tidak persis sama tetap mendapat skor parsial.</li>
                    <?php endif; ?>
                </ul>
                <a href="<?= e(url('siswa/index.php')) ?>" class="exam-cancel-link">
                    <i class="bi bi-arrow-left"></i> Kembali ke awal (batalkan sesi)
                </a>
            </section>
        </aside>

        <section class="siswa-card answer-sheet">
            <div class="answer-sheet-head">
                <div>
                    <h2><i class="bi bi-ui-checks"></i> Nomor soal</h2>
                    <p><?= e((string) $totalSoal) ?> soal &middot; <?= e((string) $context['mapel_nama']) ?> &middot; <?= e((string) $context['ujian_nama']) ?></p>
                </div>
                <span class="answer-sheet-badge">Paket <?= e((string) $context['kode_paket']) ?></span>
            </div>

            <div class="answer-grid">
                <?php for ($number = 1; $number <= $totalSoal; $number++): ?>
                    <?php
                    $selected = $answers[$number] ?? null;
                    $tipe = $types[$number] ?? 'pg';
                    $selectedLetters = soal_answer_letters($selected);
                    ?>
                    <div class="answer-row answer-row-<?= e($tipe) ?><?= $selected !== null ? ' is-answered' : '' ?>"
                         data-answer-row="<?= e((string) $number) ?>" data-tipe="<?= e($tipe) ?>"
                         id="soal-<?= e((string) $number) ?>">
                        <span class="answer-number" id="nomor-soal-<?= e((string) $number) ?>"><?= e((string) $number) ?></span>
                        <?php if ($tipe !== 'pg'): ?>
                            <span class="answer-type-badge" title="<?= e(soal_tipe_label($tipe)) ?>">
                                <?= e(soal_tipe_short($tipe)) ?>
                            </span>
                        <?php endif; ?>
                        <div class="answer-options" role="group" aria-labelledby="nomor-soal-<?= e((string) $number) ?>">
                            <?php if ($tipe === 'multiple_response'): ?>
                                <?php foreach (soal_tipe_options($tipe) as $option): ?>
                                    <?php $inputId = 'jawaban-' . $number . '-' . strtolower($option); ?>
                                    <input class="btn-check" type="checkbox" name="jawaban[<?= e((string) $number) ?>][]"
                                           id="<?= e($inputId) ?>" value="<?= e($option) ?>"
                                           data-answer-input="<?= e((string) $number) ?>"
                                           <?= in_array($option, $selectedLetters, true) ? 'checked' : '' ?>>
                                    <label class="answer-option" for="<?= e($inputId) ?>"><?= e($option) ?></label>
                                <?php endforeach; ?>
                                <button type="button" class="answer-option answer-option-empty"
                                        data-clear-multi="<?= e((string) $number) ?>"
                                        title="Kosongkan nomor <?= e((string) $number) ?>"
                                        aria-label="Kosongkan nomor <?= e((string) $number) ?>">–</button>
                            <?php else: ?>
                                <?php foreach (soal_tipe_options($tipe) as $option): ?>
                                    <?php
                                    $inputId = 'jawaban-' . $number . '-' . strtolower($option);
                                    $optionLabel = $tipe === 'benar_salah'
                                        ? ($option === 'B' ? 'Benar' : 'Salah')
                                        : $option;
                                    ?>
                                    <input class="btn-check" type="radio" name="jawaban[<?= e((string) $number) ?>]"
                                           id="<?= e($inputId) ?>" value="<?= e($option) ?>"
                                           data-answer-input="<?= e((string) $number) ?>"
                                           <?= $selected === $option ? 'checked' : '' ?>>
                                    <label class="answer-option<?= $tipe === 'benar_salah' ? ' answer-option-wide' : '' ?>"
                                           for="<?= e($inputId) ?>"><?= e($optionLabel) ?></label>
                                <?php endforeach; ?>
                                <?php $emptyId = 'jawaban-' . $number . '-kosong'; ?>
                                <input class="btn-check" type="radio" name="jawaban[<?= e((string) $number) ?>]"
                                       id="<?= e($emptyId) ?>" value=""
                                       data-answer-input="<?= e((string) $number) ?>">
                                <label class="answer-option answer-option-empty" for="<?= e($emptyId) ?>"
                                       title="Kosongkan nomor <?= e((string) $number) ?>">–</label>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>

            <div class="answer-sheet-footer">
                <div class="answer-sheet-summary">
                    <strong><span data-footer-answered>0</span> dari <?= e((string) $totalSoal) ?> terjawab</strong>
                    <span class="text-secondary">Jawaban belum dikirim sampai Anda mengonfirmasi.</span>
                </div>
                <button type="submit" class="btn btn-siswa-primary btn-lg" data-open-review>
                    <i class="bi bi-clipboard-check me-1"></i> Periksa &amp; Kirim
                </button>
            </div>
        </section>
    </div>
</form>

<div class="modal fade" id="modalReview" tabindex="-1" aria-labelledby="modalReviewLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content siswa-modal">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title h5" id="modalReviewLabel">Periksa jawaban Anda</h2>
                    <p class="small text-secondary mb-0">Pastikan pilihan sudah benar sebelum dikirim.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="review-summary">
                    <div><strong data-review-answered>0</strong><span>Terjawab</span></div>
                    <div><strong data-review-empty>0</strong><span>Kosong</span></div>
                    <div><strong><?= e((string) $totalSoal) ?></strong><span>Total soal</span></div>
                </div>
                <div class="review-warning" data-review-warning hidden>
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span>Masih ada nomor kosong. Nomor kosong tidak mendapat skor dan tetap dapat dikirim.</span>
                </div>
                <div class="review-list" data-review-list aria-label="Ringkasan pilihan jawaban"></div>
                <div class="form-check review-check">
                    <input class="form-check-input" type="checkbox" id="reviewConfirm" data-review-confirm>
                    <label class="form-check-label" for="reviewConfirm">
                        Saya sudah memeriksa jawaban dan yakin mengirimnya. Jawaban tidak dapat diubah setelah dikirim.
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-siswa-ghost" data-bs-dismiss="modal">Kembali periksa</button>
                <button type="button" class="btn btn-siswa-primary" data-confirm-submit disabled>
                    <i class="bi bi-send-check me-1"></i> Kirim Jawaban Sekarang
                </button>
            </div>
        </div>
    </div>
</div>

<?php include INCLUDES_PATH . '/siswa-footer.php'; ?>
