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

$tab = (string) input('tab', 'soal');
if (!in_array($tab, ['soal', 'rombel'], true)) {
    $tab = 'soal';
}

$classSummary = $exam !== null ? laporan_class_summary($results, $kkm) : [];
$classDistribution = $exam !== null ? laporan_class_distribution($results) : [];
$itemOverview = laporan_item_overview($items);
$itemNarrative = laporan_item_narrative($items, $itemOverview, (int) $summary['jumlah']);
$rombelNarrative = laporan_rombel_narrative($classSummary, $classDistribution, $summary, $kkm);

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

$tabUrls = [
    'soal'   => url('admin/analisis/index.php?' . http_build_query([
        'ujian_id' => $examId,
        'kelas_id' => $classId,
        'tab'      => 'soal',
    ])),
    'rombel' => url('admin/analisis/index.php?' . http_build_query([
        'ujian_id' => $examId,
        'kelas_id' => $classId,
        'tab'      => 'rombel',
    ])),
];

$chartPalette = [
    '#0d6efd', '#20c997', '#fd7e14', '#6f42c1',
    '#d63384', '#198754', '#0dcaf0', '#dc3545',
];
$rombelClassesForChart = array_slice($classDistribution, 0, 8);
$rombelDatasets = [];
foreach ($rombelClassesForChart as $index => $classDist) {
    $rombelDatasets[] = [
        'label'  => $classDist['kelas_nama'],
        'values' => $classDist['persen'],
        'color'  => $chartPalette[$index % count($chartPalette)],
    ];
}

$rombelTuntasDatasets = [
    [
        'label'  => 'Tuntas',
        'values' => array_map(static fn (array $class): int => (int) $class['tuntas'], $classSummary),
        'color'  => '#198754',
        'stack'  => 'ketuntasan',
    ],
    [
        'label'  => 'Belum tuntas',
        'values' => array_map(static fn (array $class): int => (int) $class['belum'], $classSummary),
        'color'  => '#e35d6a',
        'stack'  => 'ketuntasan',
    ],
];

$rombelRataDatasets = [[
    'label'  => 'Rata-rata nilai',
    'values' => array_map(static fn (array $class): float => (float) ($class['rata'] ?? 0), $classSummary),
    'colors' => array_map(
        static fn (array $class): string => (float) ($class['rata'] ?? 0) + 0.00001 >= $kkm
            ? '#198754'
            : '#dc3545',
        $classSummary
    ),
    'color'  => '#0d6efd',
]];

$rombelRataGuides = [[
    'axis'  => 'x',
    'value' => $kkm,
    'label' => 'KKM ' . format_nilai($kkm),
    'color' => '#dc3545',
]];

$distributionBins = laporan_distribution_bins();
$rombelMode = [];
foreach ($classDistribution as $classDist) {
    $maxIndex = 0;
    foreach ($classDist['counts'] as $binIndex => $jumlah) {
        if ($jumlah > $classDist['counts'][$maxIndex]) {
            $maxIndex = $binIndex;
        }
    }

    $rombelMode[(int) $classDist['kelas_id']] = [
        'label'  => (string) $distributionBins[$maxIndex]['label'],
        'persen' => (float) $classDist['persen'][$maxIndex],
    ];
}

$pageTitle = 'Analisis Ujian';
$pageSubtitle = 'Analisis butir per paket serta perbandingan capaian antarkelas (rombel), dilengkapi grafik dan narasi.';
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
                    <input type="hidden" name="tab" value="<?= e($tab) ?>">
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
                                <a href="<?= e(url('admin/analisis/index.php?ujian_id=' . $examId . '&tab=' . $tab)) ?>" class="btn btn-light border">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset kelas
                                </a>
                                <a href="<?= e(url('admin/laporan/pdf.php?' . http_build_query([
                                    'jenis'    => 'analisis',
                                    'ujian_id' => $examId,
                                    'kelas_id' => $classId,
                                ]))) ?>" class="btn btn-outline-danger">
                                    <i class="bi bi-file-earmark-pdf"></i> PDF Analisis
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
        <ul class="nav nav-pills report-tabs mb-3" role="tablist">
            <li class="nav-item" role="presentation">
                <a class="nav-link<?= $tab === 'soal' ? ' active' : '' ?>"
                   href="<?= e($tabUrls['soal']) ?>"
                   role="tab"
                   aria-selected="<?= $tab === 'soal' ? 'true' : 'false' ?>">
                    <i class="bi bi-list-check me-1"></i> Analisis Soal
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link<?= $tab === 'rombel' ? ' active' : '' ?>"
                   href="<?= e($tabUrls['rombel']) ?>"
                   role="tab"
                   aria-selected="<?= $tab === 'rombel' ? 'true' : 'false' ?>">
                    <i class="bi bi-mortarboard me-1"></i> Analisis Rombel
                </a>
            </li>
        </ul>

        <?php if ($tab === 'rombel'): ?>
            <div class="row g-3">
                <div class="col-6 col-xl-3">
                    <div class="report-metric">
                        <span class="report-metric-icon bg-primary-subtle text-primary"><i class="bi bi-people"></i></span>
                        <div class="min-w-0">
                            <div class="report-metric-label">Peserta</div>
                            <div class="report-metric-value"><?= e((int) $summary['jumlah']) ?></div>
                            <div class="report-metric-meta"><?= e(count($classSummary)) ?> kelas</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-xl-3">
                    <div class="report-metric">
                        <span class="report-metric-icon bg-info-subtle text-info"><i class="bi bi-graph-up"></i></span>
                        <div class="min-w-0">
                            <div class="report-metric-label">Rata-rata</div>
                            <div class="report-metric-value"><?= e(format_nilai($summary['rata'])) ?></div>
                            <div class="report-metric-meta">KKM <?= e(format_nilai($kkm)) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-xl-3">
                    <div class="report-metric">
                        <span class="report-metric-icon bg-success-subtle text-success"><i class="bi bi-patch-check"></i></span>
                        <div class="min-w-0">
                            <div class="report-metric-label">Ketuntasan</div>
                            <div class="report-metric-value"><?= e(laporan_percent((float) $summary['persen_tuntas'])) ?></div>
                            <div class="report-metric-meta"><?= e((int) $summary['tuntas']) ?> tuntas &middot; <?= e((int) $summary['belum']) ?> belum</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-xl-3">
                    <div class="report-metric">
                        <span class="report-metric-icon bg-warning-subtle text-warning"><i class="bi bi-arrows-expand"></i></span>
                        <div class="min-w-0">
                            <div class="report-metric-label">Rentang Nilai</div>
                            <div class="report-metric-value"><?= e(format_nilai($summary['terendah'])) ?>&ndash;<?= e(format_nilai($summary['tertinggi'])) ?></div>
                            <div class="report-metric-meta">nilai terendah&ndash;tertinggi</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="report-insight mt-3">
                <div class="report-insight-head">
                    <span class="report-insight-icon"><i class="bi bi-mortarboard"></i></span>
                    <div>
                        <h3>Interpretasi Analisis Rombel</h3>
                        <p>Penjelasan otomatis berdasarkan perbandingan rata-rata, ketuntasan, dan sebaran nilai tiap kelas.</p>
                    </div>
                </div>

                <?php foreach ($rombelNarrative['ringkasan'] as $paragraph): ?>
                    <p><?= e($paragraph) ?></p>
                <?php endforeach; ?>

                <?php if ($rombelNarrative['sorotan'] !== []): ?>
                    <div class="report-insight-label">Sorotan per kelas</div>
                    <ul class="report-insight-list">
                        <?php foreach ($rombelNarrative['sorotan'] as $sorotan): ?>
                            <li><?= e($sorotan) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($rombelNarrative['rekomendasi'] !== []): ?>
                    <div class="report-insight-label">Tindak lanjut</div>
                    <ul class="report-insight-list report-insight-list-action">
                        <?php foreach ($rombelNarrative['rekomendasi'] as $rekomendasi): ?>
                            <li><?= e($rekomendasi) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-xl-7">
                    <div class="card h-100">
                        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span><i class="bi bi-graph-up-arrow me-1 text-primary"></i> Kurva Sebaran Nilai per Kelas</span>
                            <span class="small text-secondary fw-normal">Persentase peserta tiap rentang</span>
                        </div>
                        <div class="card-body">
                            <?php if ($rombelDatasets !== []): ?>
                                <div class="report-chart">
                                    <canvas data-report-chart="line"
                                            data-labels="<?= e(json_encode($distributionLabels, JSON_UNESCAPED_UNICODE)) ?>"
                                            data-datasets="<?= e(json_encode($rombelDatasets, JSON_UNESCAPED_UNICODE)) ?>"
                                            data-y-min="0"
                                            data-y-max="100"
                                            data-y-suffix="%"
                                            data-legend="1"
                                            aria-label="Kurva sebaran nilai per kelas"
                                            role="img"></canvas>
                                </div>
                                <?php if (count($classDistribution) > count($rombelClassesForChart)): ?>
                                    <div class="report-note mt-2">
                                        <i class="bi bi-info-circle"></i>
                                        <span>Grafik menampilkan <?= e(count($rombelClassesForChart)) ?> kelas dengan peserta terbanyak. Seluruh kelas tetap tercantum pada tabel di bawah.</span>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="report-chart-empty">
                                    <i class="bi bi-bar-chart"></i>
                                    <span>Sebaran nilai belum tersedia.</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-xl-5">
                    <div class="card h-100">
                        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span><i class="bi bi-bar-chart-steps me-1 text-primary"></i> Ketuntasan per Kelas</span>
                            <span class="small text-secondary fw-normal">Jumlah siswa</span>
                        </div>
                        <div class="card-body">
                            <div class="report-chart">
                                <canvas data-report-chart="bar"
                                        data-labels="<?= e(json_encode(array_map(static fn (array $class): string => (string) $class['kelas_nama'], $classSummary), JSON_UNESCAPED_UNICODE)) ?>"
                                        data-datasets="<?= e(json_encode($rombelTuntasDatasets, JSON_UNESCAPED_UNICODE)) ?>"
                                        data-horizontal="1"
                                        data-stacked="1"
                                        data-legend="1"
                                        data-x-precision="0"
                                        aria-label="Grafik ketuntasan per kelas"
                                        role="img"></canvas>
                            </div>
                            <div class="report-legend">
                                <span><span class="report-legend-dot" style="background:#198754;"></span> Tuntas</span>
                                <span><span class="report-legend-dot" style="background:#e35d6a;"></span> Belum tuntas</span>
                                <span><i class="bi bi-info-circle"></i> Batas tuntas KKM <?= e(format_nilai($kkm)) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span><i class="bi bi-bar-chart me-1 text-primary"></i> Rata-rata Nilai per Kelas</span>
                    <span class="small text-secondary fw-normal">Hijau di atas KKM, merah di bawah KKM</span>
                </div>
                <div class="card-body">
                    <div class="report-chart">
                        <canvas data-report-chart="bar"
                                data-labels="<?= e(json_encode(array_map(static fn (array $class): string => (string) $class['kelas_nama'], $classSummary), JSON_UNESCAPED_UNICODE)) ?>"
                                data-datasets="<?= e(json_encode($rombelRataDatasets, JSON_UNESCAPED_UNICODE)) ?>"
                                data-horizontal="1"
                                data-x-max="100"
                                data-x-precision="0"
                                data-guides="<?= e(json_encode($rombelRataGuides, JSON_UNESCAPED_UNICODE)) ?>"
                                aria-label="Grafik rata-rata nilai per kelas dengan garis KKM"
                                role="img"></canvas>
                    </div>
                    <div class="report-note mt-2">
                        <i class="bi bi-bullseye"></i>
                        <span>Garis putus-putus menunjukkan KKM <?= e(format_nilai($kkm)) ?>. Kelas di bawah garis tersebut memerlukan perhatian khusus.</span>
                    </div>
                </div>
            </div>

            <div class="report-section-title">
                <div>
                    <h3><i class="bi bi-table me-1 text-primary"></i> Rekap Rombel</h3>
                    <p>Ringkasan capaian setiap kelas beserta rentang nilai yang paling banyak muncul.</p>
                </div>
                <a href="<?= e(url('admin/laporan/index.php?' . http_build_query(['ujian_id' => $examId, 'kelas_id' => 0]))) ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-clipboard-data me-1"></i> Buka Laporan
                </a>
            </div>
            <div class="report-table-wrap">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Kelas</th>
                                <th class="text-center">Peserta</th>
                                <th class="text-center">Rata-rata</th>
                                <th class="text-center">Tertinggi</th>
                                <th class="text-center">Terendah</th>
                                <th class="text-center">Tuntas / Belum</th>
                                <th style="min-width:150px;">Ketuntasan</th>
                                <th class="text-center">Rentang Dominan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classSummary as $index => $class): ?>
                                <?php $mode = $rombelMode[(int) $class['kelas_id']] ?? null; ?>
                                <tr>
                                    <td><?= e($index + 1) ?></td>
                                    <td>
                                        <span class="report-name"><?= e($class['kelas_nama']) ?></span>
                                        <span class="report-sub"><?= e($class['tingkat']) ?> &middot; <?= e($class['tahun_ajaran']) ?></span>
                                    </td>
                                    <td class="text-center"><?= e((int) $class['jumlah']) ?></td>
                                    <td class="text-center fw-semibold"><?= e(format_nilai($class['rata'])) ?></td>
                                    <td class="text-center text-success"><?= e(format_nilai($class['tertinggi'])) ?></td>
                                    <td class="text-center text-danger"><?= e(format_nilai($class['terendah'])) ?></td>
                                    <td class="text-center"><?= e((int) $class['tuntas']) ?> / <?= e((int) $class['belum']) ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1" style="height:6px;" role="progressbar"
                                                 aria-label="Ketuntasan <?= e($class['kelas_nama']) ?>"
                                                 aria-valuenow="<?= e((int) round((float) $class['persen_tuntas'])) ?>"
                                                 aria-valuemin="0" aria-valuemax="100">
                                                <div class="progress-bar bg-success" style="width: <?= e((float) $class['persen_tuntas']) ?>%"></div>
                                            </div>
                                            <span class="small text-nowrap"><?= e(laporan_percent((float) $class['persen_tuntas'])) ?></span>
                                        </div>
                                    </td>
                                    <td class="text-center small">
                                        <?php if ($mode !== null): ?>
                                            <span class="badge text-bg-light border fw-normal"><?= e($mode['label']) ?></span>
                                            <span class="d-block text-secondary"><?= e(laporan_percent((float) $mode['persen'])) ?></span>
                                        <?php else: ?>
                                            <span class="text-secondary">&ndash;</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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

        <div class="report-insight mt-3">
            <div class="report-insight-head">
                <span class="report-insight-icon"><i class="bi bi-clipboard-pulse"></i></span>
                <div>
                    <h3>Interpretasi Analisis Soal</h3>
                    <p>Penjelasan otomatis dari sebaran tingkat kesukaran, daya pembeda, dan efektivitas pengecoh.</p>
                </div>
            </div>

            <?php foreach ($itemNarrative['ringkasan'] as $paragraph): ?>
                <p><?= e($paragraph) ?></p>
            <?php endforeach; ?>

            <?php if ($itemNarrative['rekomendasi'] !== []): ?>
                <div class="report-insight-label">Tindak lanjut</div>
                <ul class="report-insight-list report-insight-list-action">
                    <?php foreach ($itemNarrative['rekomendasi'] as $rekomendasi): ?>
                        <li><?= e($rekomendasi) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
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

            $packageNarrative = laporan_package_narrative($package);
            $hasDiscrimination = (bool) $package['daya_pembeda_tersedia'];
            $itemMapDatasets = [];
            $itemMapGuides = [];

            if ($hasDiscrimination) {
                $itemMapPoints = [];
                $itemMapPointColors = [];

                foreach ($package['items'] as $item) {
                    if ($item['daya_pembeda'] === null) {
                        continue;
                    }

                    $warnaTitik = match ($item['tingkat_kesukaran']) {
                        'Mudah' => '#20c997',
                        'Sedang' => '#ffc107',
                        default => '#dc3545',
                    };

                    $itemMapPoints[] = [
                        'x' => (float) $item['persen_benar'],
                        'y' => (float) $item['daya_pembeda'],
                        'label' => 'No. ' . (int) $item['nomor'] . ' (' . $item['tingkat_kesukaran'] . ')',
                    ];
                    $itemMapPointColors[] = $warnaTitik;
                }

                $itemMapDatasets[] = [
                    'label'  => 'Butir soal',
                    'points' => $itemMapPoints,
                    'pointColors' => $itemMapPointColors,
                    'color'  => '#0d6efd',
                    'pointRadius' => 5,
                ];

                $itemMapGuides = [
                    ['axis' => 'x', 'value' => 30, 'label' => '30%', 'color' => '#dc3545'],
                    ['axis' => 'x', 'value' => 70, 'label' => '70%', 'color' => '#20c997'],
                    ['axis' => 'y', 'value' => 0, 'label' => 'DP 0', 'color' => '#98a2b3'],
                ];
            }

            $difficultyGuides = [
                ['axis' => 'y', 'value' => 30, 'label' => '30%', 'color' => '#dc3545'],
                ['axis' => 'y', 'value' => 70, 'label' => '70%', 'color' => '#20c997'],
            ];
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

                    <div class="row g-3 mb-2">
                        <div class="<?= $hasDiscrimination ? 'col-lg-6' : 'col-12' ?>">
                            <div class="report-chart-label">
                                Kurva Tingkat Kesukaran
                                <span>Persentase jawaban benar per nomor</span>
                            </div>
                            <div class="report-mini-chart">
                                <canvas data-report-chart="line"
                                        data-labels="<?= e(json_encode($packageLabels)) ?>"
                                        data-values="<?= e(json_encode($packageValues)) ?>"
                                        data-colors="<?= e(json_encode($packageColors)) ?>"
                                        data-label="Jawaban benar"
                                        data-y-min="0"
                                        data-y-max="100"
                                        data-y-suffix="%"
                                        data-y-precision="0"
                                        data-guides="<?= e(json_encode($difficultyGuides)) ?>"
                                        aria-label="Kurva tingkat kesukaran Paket <?= e($package['kode_paket']) ?>"
                                        role="img"></canvas>
                            </div>
                            <div class="report-legend">
                                <span><span class="report-legend-dot" style="background:#20c997;"></span> Mudah &ge; 70%</span>
                                <span><span class="report-legend-dot" style="background:#ffc107;"></span> Sedang 30&ndash;69%</span>
                                <span><span class="report-legend-dot" style="background:#dc3545;"></span> Sukar &lt; 30%</span>
                            </div>
                        </div>
                        <?php if ($hasDiscrimination): ?>
                            <div class="col-lg-6">
                                <div class="report-chart-label">
                                    Peta Butir
                                    <span>Tingkat kesukaran &times; daya pembeda</span>
                                </div>
                                <div class="report-mini-chart">
                                    <canvas data-report-chart="scatter"
                                            data-datasets="<?= e(json_encode($itemMapDatasets)) ?>"
                                            data-x-min="0"
                                            data-x-max="100"
                                            data-y-min="-1"
                                            data-y-max="1"
                                            data-x-title="Kesukaran (%)"
                                            data-y-title="Daya pembeda"
                                            data-x-suffix="%"
                                            data-guides="<?= e(json_encode($itemMapGuides)) ?>"
                                            aria-label="Peta sebaran butir Paket <?= e($package['kode_paket']) ?>"
                                            role="img"></canvas>
                                </div>
                                <div class="report-legend">
                                    <span><span class="report-legend-dot" style="background:#20c997;"></span> Mudah</span>
                                    <span><span class="report-legend-dot" style="background:#ffc107;"></span> Sedang</span>
                                    <span><span class="report-legend-dot" style="background:#dc3545;"></span> Sukar</span>
                                    <span><i class="bi bi-info-circle"></i> Kuadran kanan-atas paling ideal</span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="report-insight report-insight-compact mb-2">
                        <div class="report-insight-head">
                            <span class="report-insight-icon"><i class="bi bi-clipboard-data"></i></span>
                            <div>
                                <h3>Catatan Paket <?= e($package['kode_paket']) ?></h3>
                                <p>Interpretasi karakteristik butir dan saran tindak lanjut.</p>
                            </div>
                        </div>

                        <?php foreach ($packageNarrative['paragraf'] as $paragraph): ?>
                            <p><?= e($paragraph) ?></p>
                        <?php endforeach; ?>

                        <?php if ($packageNarrative['rekomendasi'] !== []): ?>
                            <div class="report-insight-label">Tindak lanjut</div>
                            <ul class="report-insight-list report-insight-list-action">
                                <?php foreach ($packageNarrative['rekomendasi'] as $rekomendasi): ?>
                                    <li><?= e($rekomendasi) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
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
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
