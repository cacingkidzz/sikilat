<?php
declare(strict_types=1);

/**
 * SIKILAT - Laporan hasil ujian (Tahap 5).
 *
 * Halaman ini menampilkan rekap nilai, distribusi, rekap kelas/paket, dan
 * rincian nilai siswa untuk satu ujian. Filter kelas bersifat opsional.
 * Seluruh data difilter berdasarkan ujian agar hasil ujian berbeda tidak
 * pernah tercampur.
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
$classSummary = laporan_class_summary($results, $kkm);
$packageSummary = laporan_package_summary($results, $kkm);
$packages = $exam !== null ? laporan_packages($examId) : [];
$totalSoal = $exam !== null ? (int) $exam['jumlah_soal'] : 0;

// Peringkat sederhana berdasarkan nilai (competition ranking).
$rankMap = [];
$rankRows = $results;
usort($rankRows, static function (array $a, array $b): int {
    $compare = (float) $b['nilai'] <=> (float) $a['nilai'];

    return $compare !== 0
        ? $compare
        : strcmp((string) $a['nomor_absen'], (string) $b['nomor_absen']);
});
$lastValue = null;
$lastRank = 0;
foreach ($rankRows as $index => $rankRow) {
    $value = (float) $rankRow['nilai'];
    if ($lastValue === null || abs($value - $lastValue) > 0.00001) {
        $lastRank = $index + 1;
        $lastValue = $value;
    }
    $rankMap[(int) $rankRow['id']] = $lastRank;
}

$filterQuery = http_build_query([
    'ujian_id' => $examId,
    'kelas_id' => $classId,
]);

$exportBase = 'admin/laporan/';
$distributionLabels = array_map(static fn (array $bin): string => (string) $bin['label'], $distribution);
$distributionValues = array_map(static fn (array $bin): int => (int) $bin['jumlah'], $distribution);
$distributionColors = ['#dc3545', '#fd7e14', '#ffc107', '#20c997', '#0d6efd', '#0a58ca'];

$classChartLabels = array_map(
    static fn (array $class): string => (string) $class['kelas_nama'],
    $classSummary
);
$classChartValues = array_map(
    static fn (array $class): float => (float) ($class['rata'] ?? 0),
    $classSummary
);

$packageChartLabels = array_map(
    static fn (array $package): string => 'Paket ' . (string) $package['kode_paket'],
    $packageSummary
);
$packageChartValues = array_map(
    static fn (array $package): float => (float) ($package['rata'] ?? 0),
    $packageSummary
);

$pageTitle = 'Laporan Hasil Ujian';
$pageSubtitle = 'Rekap nilai, distribusi, rincian siswa, serta ekspor Excel dan PDF.';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url('admin/dashboard/index.php')],
    ['label' => 'Laporan Hasil Ujian'],
];
$pageStyles = ['css/laporan.css'];
$useChartJs = true;
$useDataTables = true;
$pageScripts = ['js/laporan.js'];

require INCLUDES_PATH . '/header.php';
?>

<?php if ($examList === []): ?>
    <div class="card">
        <div class="card-body report-empty">
            <span class="report-empty-icon"><i class="bi bi-journal-x"></i></span>
            <h3>Belum ada ujian</h3>
            <p>Laporan dapat dibuat setelah ada ujian dan siswa yang mengirim jawaban.</p>
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
                        &middot; <?= e((int) $exam['jumlah_soal']) ?> soal
                        &middot; KKM <?= e(format_nilai($kkm)) ?>
                    </p>
                </div>
                <?= status_badge((string) $exam['status']) ?>
            </div>
            <div class="report-hero-meta">
                <span><i class="bi bi-people"></i><?= e((int) $summary['jumlah']) ?> peserta</span>
                <span><i class="bi bi-collection"></i><?= e(count($packages)) ?> paket soal</span>
                <span><i class="bi bi-mortarboard"></i><?= e($selectedClass !== null ? $selectedClass['nama'] : 'Semua kelas') ?></span>
                <span><i class="bi bi-clock"></i><?= e(substr((string) $exam['waktu_mulai'], 0, 5)) ?>&ndash;<?= e(substr((string) $exam['waktu_selesai'], 0, 5)) ?> WIB</span>
            </div>
        </div>

        <div class="report-filter-card mb-3">
            <div class="card-body">
                <form method="get" action="<?= e(url('admin/laporan/index.php')) ?>" data-auto-submit>
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-5">
                            <label for="filterUjian">Ujian</label>
                            <select class="form-select" id="filterUjian" name="ujian_id">
                                <?php foreach ($examList as $examOption): ?>
                                    <option value="<?= (int) $examOption['id'] ?>"<?= crud_selected($examId, (int) $examOption['id']) ?>>
                                        <?= e($examOption['nama']) ?> &middot; <?= e($examOption['mapel_kode']) ?>
                                        (<?= e((int) $examOption['peserta']) ?> peserta)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="filterKelas">Kelas</label>
                            <select class="form-select" id="filterKelas" name="kelas_id">
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
                                <a href="<?= e(url('admin/laporan/index.php?ujian_id=' . $examId)) ?>" class="btn btn-light border">
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
                <h3>Belum ada hasil ujian</h3>
                <p>
                    Ujian ini belum memiliki jawaban siswa<?= $selectedClass !== null ? ' pada kelas ' . e($selectedClass['nama']) : '' ?>.
                    Laporan akan tampil otomatis setelah siswa mengirim jawaban.
                </p>
                <a href="<?= e(url('admin/analisis/index.php?' . $filterQuery)) ?>" class="btn btn-outline-primary">
                    <i class="bi bi-graph-up-arrow me-1"></i> Buka Analisis
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
                        <div class="report-metric-meta"><?= e(count($classSummary)) ?> kelas &middot; <?= e(count($packageSummary)) ?> paket</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl">
                <div class="report-metric">
                    <span class="report-metric-icon bg-info-subtle text-info"><i class="bi bi-graph-up"></i></span>
                    <div class="min-w-0">
                        <div class="report-metric-label">Rata-rata</div>
                        <div class="report-metric-value"><?= e(format_nilai($summary['rata'])) ?></div>
                        <div class="report-metric-meta">Skala 0&ndash;100</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl">
                <div class="report-metric">
                    <span class="report-metric-icon bg-success-subtle text-success"><i class="bi bi-arrow-up-circle"></i></span>
                    <div class="min-w-0">
                        <div class="report-metric-label">Tertinggi</div>
                        <div class="report-metric-value"><?= e(format_nilai($summary['tertinggi'])) ?></div>
                        <div class="report-metric-meta">Nilai maksimum</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl">
                <div class="report-metric">
                    <span class="report-metric-icon bg-danger-subtle text-danger"><i class="bi bi-arrow-down-circle"></i></span>
                    <div class="min-w-0">
                        <div class="report-metric-label">Terendah</div>
                        <div class="report-metric-value"><?= e(format_nilai($summary['terendah'])) ?></div>
                        <div class="report-metric-meta">Nilai minimum</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl">
                <div class="report-metric">
                    <span class="report-metric-icon bg-warning-subtle text-warning"><i class="bi bi-patch-check"></i></span>
                    <div class="min-w-0">
                        <div class="report-metric-label">Ketuntasan</div>
                        <div class="report-metric-value"><?= e(laporan_percent((float) $summary['persen_tuntas'])) ?></div>
                        <div class="report-metric-meta"><?= e((int) $summary['tuntas']) ?> tuntas &middot; <?= e((int) $summary['belum']) ?> belum</div>
                    </div>
                </div>
            </div>
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
                                    (<?= e(laporan_percent((float) $bin['persen'])) ?>)
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="card h-100">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span><i class="bi bi-collection me-1 text-primary"></i> Rata-rata per Paket</span>
                        <span class="small text-secondary fw-normal">Nilai ternormalisasi</span>
                    </div>
                    <div class="card-body">
                        <div class="report-chart">
                            <canvas data-report-chart="bar"
                                    data-labels="<?= e(json_encode($packageChartLabels, JSON_UNESCAPED_UNICODE)) ?>"
                                    data-values="<?= e(json_encode($packageChartValues)) ?>"
                                    data-label="Rata-rata nilai"
                                    aria-label="Grafik rata-rata nilai per paket"
                                    role="img"></canvas>
                        </div>
                        <div class="report-note mt-2">
                            <i class="bi bi-info-circle"></i>
                            <span>Nilai antar paket dapat dibandingkan karena sudah dinormalisasi. Analisis butir tetap dipisah per paket.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($selectedClass === null && count($classSummary) > 1): ?>
            <div class="card mt-3">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span><i class="bi bi-mortarboard me-1 text-primary"></i> Rata-rata Nilai per Kelas</span>
                    <span class="small text-secondary fw-normal">Klik filter kelas untuk melihat rincian</span>
                </div>
                <div class="card-body">
                    <div class="report-chart">
                        <canvas data-report-chart="bar"
                                data-labels="<?= e(json_encode($classChartLabels, JSON_UNESCAPED_UNICODE)) ?>"
                                data-values="<?= e(json_encode($classChartValues)) ?>"
                                data-label="Rata-rata nilai"
                                data-horizontal="1"
                                aria-label="Grafik rata-rata nilai per kelas"
                                role="img"></canvas>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="report-section-title">
            <div>
                <h3><i class="bi bi-table me-1 text-primary"></i> Rekap Nilai per Kelas</h3>
                <p>Ringkasan jumlah peserta, rata-rata, dan ketuntasan setiap kelas pada ujian ini.</p>
            </div>
            <a href="<?= e(url($exportBase . 'pdf.php?jenis=kelas&' . $filterQuery)) ?>" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-file-earmark-pdf me-1"></i> PDF Hasil per Kelas
            </a>
        </div>
        <div class="report-table-wrap mb-3">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
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
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classSummary as $index => $class): ?>
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
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-xl-7">
                <div class="report-section-title mt-0">
                    <div>
                        <h3><i class="bi bi-collection me-1 text-primary"></i> Rekap per Paket</h3>
                        <p>Statistik nilai tiap paket. Butir soal tidak digabung antar paket.</p>
                    </div>
                </div>
                <div class="report-table-wrap">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Paket</th>
                                    <th class="text-center">Peserta</th>
                                    <th class="text-center">Rata-rata</th>
                                    <th class="text-center">Tertinggi</th>
                                    <th class="text-center">Terendah</th>
                                    <th class="text-center">Tuntas</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($packageSummary as $package): ?>
                                    <tr>
                                        <td>
                                            <span class="report-name">Paket <?= e($package['kode_paket']) ?></span>
                                            <span class="report-sub"><?= e($package['keterangan'] !== '' ? $package['keterangan'] : 'Tanpa keterangan') ?></span>
                                        </td>
                                        <td class="text-center"><?= e((int) $package['jumlah']) ?></td>
                                        <td class="text-center fw-semibold"><?= e(format_nilai($package['rata'])) ?></td>
                                        <td class="text-center"><?= e(format_nilai($package['tertinggi'])) ?></td>
                                        <td class="text-center"><?= e(format_nilai($package['terendah'])) ?></td>
                                        <td class="text-center"><?= e((int) $package['tuntas']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="report-section-title mt-0">
                    <div>
                        <h3><i class="bi bi-download me-1 text-primary"></i> Ekspor Laporan</h3>
                        <p>Berkas dibuat dari data aktual sesuai filter aktif.</p>
                    </div>
                </div>
                <div class="report-export-grid">
                    <a class="report-export-card" href="<?= e(url($exportBase . 'excel.php?' . $filterQuery)) ?>">
                        <span class="report-export-icon excel"><i class="bi bi-file-earmark-excel"></i></span>
                        <span class="report-export-text">
                            <strong>Excel Lengkap</strong>
                            <small>Rekap nilai, kelas, paket, rincian jawaban, analisis butir</small>
                        </span>
                    </a>
                    <a class="report-export-card" href="<?= e(url($exportBase . 'pdf.php?jenis=kelas&' . $filterQuery)) ?>">
                        <span class="report-export-icon pdf"><i class="bi bi-file-earmark-pdf"></i></span>
                        <span class="report-export-text">
                            <strong>PDF Hasil Ujian</strong>
                            <small>Rekap dan daftar nilai per kelas</small>
                        </span>
                    </a>
                    <a class="report-export-card" href="<?= e(url($exportBase . 'pdf.php?jenis=analisis&' . $filterQuery)) ?>">
                        <span class="report-export-icon pdf"><i class="bi bi-file-earmark-bar-graph"></i></span>
                        <span class="report-export-text">
                            <strong>PDF Analisis</strong>
                            <small>Narasi, kurva butir, peta butir, dan analisis rombel</small>
                        </span>
                    </a>
                    <a class="report-export-card" href="<?= e(url($exportBase . 'pdf.php?jenis=distribusi&' . $filterQuery)) ?>">
                        <span class="report-export-icon pdf"><i class="bi bi-bar-chart-steps"></i></span>
                        <span class="report-export-text">
                            <strong>PDF Distribusi</strong>
                            <small>Sebaran nilai, kurva rombel, dan ketuntasan kelas</small>
                        </span>
                    </a>
                </div>
                <div class="report-note mt-3">
                    <i class="bi bi-shield-check"></i>
                    <span>Semua ekspor hanya memuat hasil dari ujian yang dipilih. Laporan analisis butir selalu dipisah per paket.</span>
                </div>
            </div>
        </div>

        <div class="report-section-title">
            <div>
                <h3><i class="bi bi-clipboard-data me-1 text-primary"></i> Daftar Nilai Siswa</h3>
                <p>Benar, salah, kosong, paket, dan nilai akhir setiap peserta.</p>
            </div>
            <div class="report-actions">
                <a href="<?= e(url($exportBase . 'excel.php?' . $filterQuery)) ?>" class="btn btn-sm btn-outline-success">
                    <i class="bi bi-file-earmark-excel me-1"></i> Excel
                </a>
                <a href="<?= e(url($exportBase . 'pdf.php?jenis=kelas&' . $filterQuery)) ?>" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-file-earmark-pdf me-1"></i> PDF
                </a>
            </div>
        </div>
        <div class="report-table-wrap">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" data-datatable>
                    <thead>
                        <tr>
                            <th class="text-center">Rank</th>
                            <th class="text-center">Absen</th>
                            <th>Nama Siswa</th>
                            <th>Kelas</th>
                            <th class="text-center">Paket</th>
                            <th class="text-center">Benar</th>
                            <th class="text-center">Salah</th>
                            <th class="text-center">Kosong</th>
                            <th class="text-center">Nilai</th>
                            <th>Status</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php /* Urutkan tampilan mengikuti peringkat agar kolom Rank tetap berurutan. */ ?>
                        <?php foreach ($rankRows as $result): ?>
                            <?php
                            $nilai = (float) $result['nilai'];
                            $lulus = $nilai + 0.00001 >= $kkm;
                            $studentQuery = http_build_query([
                                'jenis'     => 'siswa',
                                'ujian_id'  => $examId,
                                'kelas_id'  => (int) $result['kelas_id'],
                                'hasil_id'  => (int) $result['id'],
                            ]);
                            ?>
                            <tr>
                                <td class="text-center fw-semibold text-secondary"><?= e($rankMap[(int) $result['id']] ?? '-') ?></td>
                                <td class="text-center"><?= e($result['nomor_absen']) ?></td>
                                <td>
                                    <span class="report-name"><?= e($result['nama_siswa']) ?></span>
                                    <span class="report-sub"><?= e($result['total_soal']) ?> soal &middot; dikumpulkan <?= e(format_tanggal((string) $result['submitted_at'], true)) ?></span>
                                </td>
                                <td class="small"><?= e($result['kelas_nama']) ?></td>
                                <td class="text-center"><span class="badge text-bg-light border"><?= e($result['kode_paket']) ?></span></td>
                                <td class="text-center text-success fw-semibold"><?= e((int) $result['jumlah_benar']) ?></td>
                                <td class="text-center text-danger fw-semibold"><?= e((int) $result['jumlah_salah']) ?></td>
                                <td class="text-center text-secondary"><?= e((int) $result['jumlah_kosong']) ?></td>
                                <td class="text-center report-metric-value" style="font-size:1rem;"><?= e(format_nilai($nilai)) ?></td>
                                <td>
                                    <span class="report-badge <?= $lulus ? 'report-badge-pass' : 'report-badge-fail' ?>">
                                        <i class="bi <?= $lulus ? 'bi-check2-circle' : 'bi-exclamation-circle' ?>"></i>
                                        <?= $lulus ? 'Tuntas' : 'Belum' ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="<?= e(url($exportBase . 'pdf.php?' . $studentQuery)) ?>"
                                       class="btn btn-sm btn-outline-danger" title="Laporan individu <?= e($result['nama_siswa']) ?>">
                                        <i class="bi bi-file-earmark-person"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="report-note mt-3">
            <i class="bi bi-info-circle"></i>
            <span>
                Total jawaban benar: <strong><?= e((int) $summary['benar']) ?></strong>,
                salah/parsial: <strong><?= e((int) $summary['salah']) ?></strong>,
                kosong: <strong><?= e((int) $summary['kosong']) ?></strong>.
                Nilai akhir dihitung dari <strong>total skor butir</strong> dibagi jumlah soal, bukan dari jumlah benar saja.
                Soal Multiple Response memakai skor parsial, sehingga jawaban yang belum tepat seluruhnya
                tetap masuk kolom salah/parsial namun menyumbang sebagian skor.
            </span>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
