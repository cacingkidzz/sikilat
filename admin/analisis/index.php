<?php
declare(strict_types=1);

/**
 * SIKILAT - Analisis hasil ujian (Tahap 5).
 *
 * Menampilkan statistik nilai serta analisis butir per paket: tingkat
 * kesukaran, daya pembeda (upper/lower 27%), dan efektivitas pengecoh.
 * Kolom opsi mengikuti tipe soal tiap nomor (PG A-E, Benar/Salah B/S,
 * Multiple Response A-E dengan skor parsial).
 * Nomor soal tidak pernah digabung antar paket karena isi butir dapat berbeda.
 */

require_once __DIR__ . '/../../config/app.php';
require_once INCLUDES_PATH . '/laporan.php';

require_auth();

$examList = laporan_exams();

$examId = (int) input('ujian_id', 0);
if ($examId <= 0 && $examList !== []) {
    foreach ($examList as $candidate) {
        if ((int) $candidate['peserta'] > 0) {
            $examId = (int) $candidate['id'];
            break;
        }
    }

    if ($examId === 0) {
        $examId = (int) $examList[0]['id'];
    }
}

$exam = $examId > 0 ? laporan_exam($examId) : null;
if ($exam === null) {
    $examId = 0;
}

$classes = $exam !== null ? laporan_classes($examId) : [];
$classId = (int) input('kelas_id', 0);
if ($classId > 0 && !laporan_class_valid($examId, $classId)) {
    $classId = 0;
}

$selectedClass = null;
foreach ($classes as $class) {
    if ((int) $class['id'] === $classId) {
        $selectedClass = $class;
        break;
    }
}

$kkm = $exam !== null ? (float) $exam['kkm'] : (float) setting('kkm_default', '75');
$results = $exam !== null ? laporan_results($examId, $classId > 0 ? $classId : null) : [];
$summary = laporan_summary($results, $kkm);
$distribution = laporan_distribution($results);
$packages = $exam !== null ? laporan_packages($examId) : [];
$totalSoal = $exam !== null ? (int) $exam['jumlah_soal'] : 0;
$items = $exam !== null
    ? laporan_item_analysis($examId, $classId > 0 ? $classId : null, $results, $totalSoal)
    : [];

$difficultyTotals = ['Mudah' => 0, 'Sedang' => 0, 'Sukar' => 0];
$itemTotal = 0;
$effectiveDistractors = 0;
$distractorTotal = 0;
foreach ($items as $package) {
    foreach ($difficultyTotals as $label => $value) {
        $difficultyTotals[$label] += (int) ($package['tingkat_counts'][$label] ?? 0);
    }
    $itemTotal += count($package['items']);
    $effectiveDistractors += (int) $package['pengecoh_efektif'];
    $distractorTotal += (int) $package['pengecoh_total'];
}

$effectivePercent = $distractorTotal > 0
    ? round($effectiveDistractors / $distractorTotal * 100, 2)
    : 0.0;

$filterQuery = http_build_query([
    'ujian_id' => $examId,
    'kelas_id' => $classId,
]);
$exportBase = 'admin/laporan/';

$distributionLabels = array_map(static fn (array $bin): string => (string) $bin['label'], $distribution);
$distributionValues = array_map(static fn (array $bin): int => (int) $bin['jumlah'], $distribution);
$distributionColors = ['#dc3545', '#fd7e14', '#ffc107', '#20c997', '#0d6efd', '#0a58ca'];

$pageTitle = 'Analisis Ujian';
$pageSubtitle = 'Analisis nilai, tingkat kesukaran, daya pembeda, dan efektivitas pengecoh per paket.';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url('admin/dashboard/index.php')],
    ['label' => 'Analisis Ujian'],
];
$pageStyles = ['css/laporan.css'];
$useChartJs = true;
$pageScripts = ['js/laporan.js'];

require INCLUDES_PATH . '/header.php';
?>

<?php if ($examList === []): ?>
    <div class="card">
        <div class="card-body report-empty">
            <span class="report-empty-icon"><i class="bi bi-graph-up-arrow"></i></span>
            <h3>Belum ada ujian</h3>
            <p>Analisis dapat dibuat setelah ujian memiliki jawaban siswa.</p>
            <a href="<?= e(url('admin/ujian/index.php')) ?>" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i> Buat Ujian
            </a>
        </div>
    </div>
<?php else: ?>
    <?php if ($exam !== null): ?>
        <div class="report-hero mb-3">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
                <div>
                    <h2><?= e($exam['nama']) ?></h2>
                    <p>
                        <?= e($exam['mapel_nama']) ?> (<?= e($exam['mapel_kode']) ?>)
                        &middot; <?= e(format_tanggal((string) $exam['tanggal_ujian'])) ?>
                        &middot; <?= e($totalSoal) ?> soal
                        &middot; KKM <?= e(format_nilai($kkm)) ?>
                    </p>
                </div>
                <?= status_badge((string) $exam['status']) ?>
            </div>
            <div class="report-hero-meta">
                <span><i class="bi bi-people"></i><?= e((int) $summary['jumlah']) ?> peserta</span>
                <span><i class="bi bi-collection"></i><?= e(count($items)) ?> paket dianalisis</span>
                <span><i class="bi bi-list-check"></i><?= e($itemTotal) ?> butir</span>
                <span><i class="bi bi-mortarboard"></i><?= e($selectedClass !== null ? $selectedClass['nama'] : 'Semua kelas') ?></span>
            </div>
        </div>

        <div class="report-filter-card mb-3">
            <div class="card-body">
                <form method="get" action="<?= e(url('admin/analisis/index.php')) ?>" data-auto-submit>
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-5">
                            <label for="filterUjianAnalisis">Ujian</label>
                            <select class="form-select" id="filterUjianAnalisis" name="ujian_id">
                                <?php foreach ($examList as $examOption): ?>
                                    <option value="<?= (int) $examOption['id'] ?>"<?= crud_selected($examId, (int) $examOption['id']) ?>>
                                        <?= e($examOption['nama']) ?> &middot; <?= e($examOption['mapel_kode']) ?>
                                        (<?= e((int) $examOption['peserta']) ?> peserta)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="filterKelasAnalisis">Kelas</label>
                            <select class="form-select" id="filterKelasAnalisis" name="kelas_id">
                                <option value="0">Semua kelas</option>
                                <?php foreach ($classes as $classOption): ?>
                                    <option value="<?= (int) $classOption['id'] ?>"<?= crud_selected($classId, (int) $classOption['id']) ?>>
                                        <?= e($classOption['nama']) ?> &middot; <?= e((int) $classOption['peserta']) ?> peserta
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <div class="report-actions justify-content-md-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel"></i> Terapkan
                                </button>
                                <a href="<?= e(url('admin/analisis/index.php?ujian_id=' . $examId)) ?>" class="btn btn-light border">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset kelas
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($exam === null): ?>
        <div class="card">
            <div class="card-body report-empty">
                <span class="report-empty-icon"><i class="bi bi-search"></i></span>
                <h3>Ujian tidak ditemukan</h3>
                <p>Pilih ujian lain atau kembali ke daftar ujian.</p>
                <a href="<?= e(url('admin/ujian/index.php')) ?>" class="btn btn-outline-primary">Daftar Ujian</a>
            </div>
        </div>
    <?php elseif ($results === []): ?>
        <div class="card">
            <div class="card-body report-empty">
                <span class="report-empty-icon"><i class="bi bi-inbox"></i></span>
                <h3>Belum ada hasil untuk dianalisis</h3>
                <p>
                    Ujian ini belum memiliki jawaban siswa<?= $selectedClass !== null ? ' pada kelas ' . e($selectedClass['nama']) : '' ?>.
                    Analisis butir membutuhkan jawaban yang sudah dikirim.
                </p>
                <a href="<?= e(url('admin/laporan/index.php?' . $filterQuery)) ?>" class="btn btn-outline-primary">
                    <i class="bi bi-clipboard-data me-1"></i> Buka Laporan
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <div class="col-6 col-xl">
                <div class="report-metric">
                    <span class="report-metric-icon bg-primary-subtle text-primary"><i class="bi bi-people"></i></span>
                    <div class="min-w-0">
                        <div class="report-metric-label">Peserta</div>
                        <div class="report-metric-value"><?= e((int) $summary['jumlah']) ?></div>
                        <div class="report-metric-meta"><?= e(count($items)) ?> paket</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl">
                <div class="report-metric">
                    <span class="report-metric-icon bg-info-subtle text-info"><i class="bi bi-graph-up"></i></span>
                    <div class="min-w-0">
                        <div class="report-metric-label">Rata-rata</div>
                        <div class="report-metric-value"><?= e(format_nilai($summary['rata'])) ?></div>
                        <div class="report-metric-meta">Tertinggi <?= e(format_nilai($summary['tertinggi'])) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl">
                <div class="report-metric">
                    <span class="report-metric-icon bg-success-subtle text-success"><i class="bi bi-patch-check"></i></span>
                    <div class="min-w-0">
                        <div class="report-metric-label">Ketuntasan</div>
                        <div class="report-metric-value"><?= e(laporan_percent((float) $summary['persen_tuntas'])) ?></div>
                        <div class="report-metric-meta"><?= e((int) $summary['tuntas']) ?> dari <?= e((int) $summary['jumlah']) ?> siswa</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl">
                <div class="report-metric">
                    <span class="report-metric-icon bg-warning-subtle text-warning"><i class="bi bi-list-check"></i></span>
                    <div class="min-w-0">
                        <div class="report-metric-label">Butir Dianalisis</div>
                        <div class="report-metric-value"><?= e($itemTotal) ?></div>
                        <div class="report-metric-meta"><?= e($difficultyTotals['Mudah']) ?> mudah &middot; <?= e($difficultyTotals['Sedang']) ?> sedang &middot; <?= e($difficultyTotals['Sukar']) ?> sukar</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl">
                <div class="report-metric">
                    <span class="report-metric-icon bg-danger-subtle text-danger"><i class="bi bi-bullseye"></i></span>
                    <div class="min-w-0">
                        <div class="report-metric-label">Pengecoh Efektif</div>
                        <div class="report-metric-value"><?= e(laporan_percent((float) $effectivePercent)) ?></div>
                        <div class="report-metric-meta"><?= e($effectiveDistractors) ?> dari <?= e($distractorTotal) ?> pengecoh dipilih &ge; 5%</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="report-note report-note-warning mt-3">
            <i class="bi bi-diagram-3"></i>
            <span>
                <strong>Analisis lintas paket:</strong> setiap butir dihitung di dalam paketnya masing-masing.
                Nomor soal yang sama pada paket A dan paket B tidak otomatis mengukur butir yang sama karena
                isi soal dan kunci jawaban dapat berbeda. Perbandingan dilakukan pada tingkat nilai dan
                karakteristik butir, bukan dengan menggabungkan nomor soal.
            </span>
        </div>

        <div class="row g-3 mt-1">
            <div class="col-xl-7">
                <div class="card h-100">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span><i class="bi bi-bar-chart-line me-1 text-primary"></i> Distribusi Nilai</span>
                        <span class="small text-secondary fw-normal"><?= e((int) $summary['jumlah']) ?> peserta</span>
                    </div>
                    <div class="card-body">
                        <div class="report-chart">
                            <canvas data-report-chart="bar"
                                    data-labels="<?= e(json_encode($distributionLabels, JSON_UNESCAPED_UNICODE)) ?>"
                                    data-values="<?= e(json_encode($distributionValues)) ?>"
                                    data-colors="<?= e(json_encode($distributionColors)) ?>"
                                    data-label="Jumlah siswa"
                                    aria-label="Grafik distribusi nilai"
                                    role="img"></canvas>
                        </div>
                        <div class="report-legend">
                            <?php foreach ($distribution as $index => $bin): ?>
                                <span>
                                    <span class="report-legend-dot" style="background: <?= e($distributionColors[$index]) ?>;"></span>
                                    <?= e($bin['label']) ?>: <strong><?= e((int) $bin['jumlah']) ?></strong>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="card h-100">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span><i class="bi bi-pie-chart me-1 text-primary"></i> Komposisi Tingkat Kesukaran</span>
                        <span class="small text-secondary fw-normal">Seluruh paket</span>
                    </div>
                    <div class="card-body">
                        <div class="report-chart">
                            <canvas data-report-chart="doughnut"
                                    data-labels="<?= e(json_encode(['Mudah', 'Sedang', 'Sukar'])) ?>"
                                    data-values="<?= e(json_encode([$difficultyTotals['Mudah'], $difficultyTotals['Sedang'], $difficultyTotals['Sukar']])) ?>"
                                    data-colors="<?= e(json_encode(['#20c997', '#ffc107', '#dc3545'])) ?>"
                                    aria-label="Grafik komposisi tingkat kesukaran"
                                    role="img"></canvas>
                        </div>
                        <div class="report-legend justify-content-center">
                            <span><i class="bi bi-info-circle"></i> Mudah &ge; 70% benar</span>
                            <span>Sedang 30&ndash;69,99%</span>
                            <span>Sukar &lt; 30%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="report-section-title">
            <div>
                <h3><i class="bi bi-collection me-1 text-primary"></i> Analisis Butir per Paket</h3>
                <p>Daya pembeda memakai kelompok atas dan bawah 27% berdasarkan nilai total.</p>
            </div>
            <div class="report-actions">
                <a href="<?= e(url($exportBase . 'excel.php?' . $filterQuery)) ?>" class="btn btn-sm btn-outline-success">
                    <i class="bi bi-file-earmark-excel me-1"></i> Excel
                </a>
                <a href="<?= e(url($exportBase . 'pdf.php?jenis=analisis&' . $filterQuery)) ?>" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-file-earmark-pdf me-1"></i> PDF Analisis
                </a>
            </div>
        </div>

        <?php foreach ($items as $package): ?>
            <?php
            $packageLabels = [];
            $packageValues = [];
            $packageColors = [];
            foreach ($package['items'] as $item) {
                $packageLabels[] = (string) $item['nomor'];
                $packageValues[] = (float) $item['persen_benar'];
                $packageColors[] = match ($item['tingkat_kesukaran']) {
                    'Mudah' => '#20c997',
                    'Sedang' => '#ffc107',
                    default => '#dc3545',
                };
            }
            ?>
            <?php
            $packageLetters = is_array($package['opsi_letters'] ?? null)
                ? $package['opsi_letters']
                : ['A', 'B', 'C', 'D', 'E'];
            $packageHasMulti = false;
            $packageHasBs = false;
            foreach ($package['items'] as $packageItem) {
                if (($packageItem['tipe_soal'] ?? 'pg') === 'multiple_response') {
                    $packageHasMulti = true;
                }
                if (($packageItem['tipe_soal'] ?? 'pg') === 'benar_salah') {
                    $packageHasBs = true;
                }
            }
            ?>
            <div class="report-package">
                <div class="report-package-head">
                    <div>
                        <h3>
                            <i class="bi bi-box-seam text-primary"></i>
                            Paket <?= e($package['kode_paket']) ?>
                            <span class="badge text-bg-light border fw-normal"><?= e((int) $package['peserta']) ?> peserta</span>
                        </h3>
                        <p>
                            <?= e($package['jumlah_soal']) ?> butir
                            &middot; rata-rata <?= e(format_nilai($package['rata_nilai'])) ?>
                            &middot; tertinggi <?= e(format_nilai($package['nilai_tertinggi'])) ?>
                            &middot; terendah <?= e(format_nilai($package['nilai_terendah'])) ?>
                        </p>
                    </div>
                    <div class="report-package-stats">
                        <span class="text-success">Mudah <?= e($package['tingkat_counts']['Mudah']) ?></span>
                        <span class="text-warning">Sedang <?= e($package['tingkat_counts']['Sedang']) ?></span>
                        <span class="text-danger">Sukar <?= e($package['tingkat_counts']['Sukar']) ?></span>
                        <span>Pengecoh efektif <?= e($package['pengecoh_efektif']) ?>/<?= e($package['pengecoh_total']) ?></span>
                    </div>
                </div>
                <div class="report-package-body">
                    <?php if (!$package['daya_pembeda_tersedia']): ?>
                        <div class="report-note report-note-warning mb-3">
                            <i class="bi bi-exclamation-triangle"></i>
                            <span>
                                Daya pembeda belum dapat dihitung untuk paket ini. Dibutuhkan minimal 4 peserta
                                agar kelompok atas dan bawah 27% tidak tumpang tindih.
                            </span>
                        </div>
                    <?php endif; ?>

                    <div class="report-mini-chart">
                        <canvas data-report-chart="bar"
                                data-labels="<?= e(json_encode($packageLabels)) ?>"
                                data-values="<?= e(json_encode($packageValues)) ?>"
                                data-colors="<?= e(json_encode($packageColors)) ?>"
                                data-label="Jawaban benar"
                                data-suffix="%"
                                aria-label="Persentase jawaban benar Paket <?= e($package['kode_paket']) ?>"
                                role="img"></canvas>
                    </div>

                    <div class="report-analysis-scroll">
                        <table class="table table-sm table-bordered report-analysis-table">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tipe</th>
                                    <th>Kunci</th>
                                    <th>Benar</th>
                                    <th>Tingkat</th>
                                    <th>Daya Pembeda</th>
                                    <?php foreach ($packageLetters as $letter): ?>
                                        <th><?= e($letter) ?></th>
                                    <?php endforeach; ?>
                                    <th>Kosong</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($package['items'] as $item): ?>
                                    <tr>
                                        <td class="report-item-number"><?= e((int) $item['nomor']) ?></td>
                                        <td>
                                            <span class="badge text-bg-light border text-secondary" title="<?= e((string) ($item['tipe_label'] ?? soal_tipe_label($item['tipe_soal'] ?? 'pg'))) ?>">
                                                <?= e(soal_tipe_short($item['tipe_soal'] ?? 'pg')) ?>
                                            </span>
                                        </td>
                                        <td><span class="badge text-bg-primary"><?= e((string) ($item['kunci_tampil'] ?? $item['kunci'])) ?></span></td>
                                        <td class="report-option-cell">
                                            <strong><?= e((int) $item['benar']) ?></strong>
                                            <small><?= e(laporan_percent((float) $item['persen_benar'])) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge text-bg-<?= e(laporan_difficulty_class((string) $item['tingkat_kesukaran'])) ?>">
                                                <?= e($item['tingkat_kesukaran']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($item['daya_pembeda'] !== null): ?>
                                                <strong><?= e(number_format((float) $item['daya_pembeda'], 2, ',', '.')) ?></strong>
                                                <small class="d-block text-<?= e(laporan_discrimination_class((string) $item['daya_pembeda_label'])) ?>">
                                                    <?= e($item['daya_pembeda_label']) ?>
                                                </small>
                                            <?php else: ?>
                                                <span class="text-secondary small">Data kurang</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php foreach ($packageLetters as $letter): ?>
                                            <?php $option = $item['opsi'][$letter] ?? ['count' => 0, 'persen' => 0, 'is_key' => false, 'effective' => false]; ?>
                                            <td class="report-option-cell<?= $option['is_key'] ? ' report-option-key' : ($option['effective'] ? ' report-option-effective' : '') ?>">
                                                <strong><?= e((int) $option['count']) ?></strong>
                                                <small><?= e(laporan_percent((float) $option['persen'])) ?></small>
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="report-option-cell">
                                            <strong><?= e((int) $item['kosong']) ?></strong>
                                            <small><?= e(laporan_percent((float) $item['kosong_persen'])) ?></small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($packageHasMulti || $packageHasBs): ?>
                        <div class="report-legend">
                            <?php if ($packageHasBs): ?>
                                <span><i class="bi bi-info-circle"></i> Benar/Salah memakai kolom B &amp; S.</span>
                            <?php endif; ?>
                            <?php if ($packageHasMulti): ?>
                                <span><i class="bi bi-info-circle"></i> Multiple Response: pilihan peserta dapat lebih dari satu, kolom opsi menghitung setiap huruf yang dipilih.</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="report-legend">
                        <span><span class="report-legend-dot" style="background:#dcebff;"></span> Kunci jawaban</span>
                        <span><span class="report-legend-dot" style="background:#fff3d6;"></span> Pengecoh efektif (&ge; 5%)</span>
                        <span><span class="report-legend-dot" style="background:#20c997;"></span> Mudah</span>
                        <span><span class="report-legend-dot" style="background:#ffc107;"></span> Sedang</span>
                        <span><span class="report-legend-dot" style="background:#dc3545;"></span> Sukar</span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="report-note mt-3">
            <i class="bi bi-info-circle"></i>
            <span>
                Tingkat kesukaran: Mudah &ge; 70% benar, Sedang 30&ndash;69,99%, Sukar &lt; 30%.
                Daya pembeda: Baik &ge; 0,40; Cukup 0,30&ndash;0,39; Rendah 0,20&ndash;0,29; Jelek &lt; 0,20.
                Pengecoh dinilai efektif bila dipilih minimal 5% peserta.
                Soal Multiple Response memakai skor parsial; kolom <em>Benar</em> hanya menghitung jawaban yang tepat seluruhnya.
            </span>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
