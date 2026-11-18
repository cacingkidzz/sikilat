<?php
declare(strict_types=1);

/**
 * SIKILAT - Dashboard panel admin/guru.
 * Seluruh angka diambil langsung dari database (tanpa data dummy).
 */

require_once __DIR__ . '/../../config/app.php';

require_auth();
$user = auth_user();

$pdo = db();
$isAdminDashboard = $user['role'] === 'admin';
$scopeUserId = (int) $user['id'];

$totalMapel = (int) $pdo->query('SELECT COUNT(*) FROM mata_pelajaran WHERE is_active = 1')->fetchColumn();
$totalKelas = (int) $pdo->query('SELECT COUNT(*) FROM kelas WHERE is_active = 1')->fetchColumn();

if ($isAdminDashboard) {
    $totalUjian = (int) $pdo->query('SELECT COUNT(*) FROM ujian')->fetchColumn();
    $ujianAktif = (int) $pdo->query("SELECT COUNT(*) FROM ujian WHERE status = 'aktif'")->fetchColumn();
    $totalPeserta = (int) $pdo->query('SELECT COUNT(*) FROM hasil_ujian')->fetchColumn();

    $nilaiStats = $pdo->query(
        'SELECT COUNT(*) AS jumlah, AVG(nilai) AS rata, MAX(nilai) AS maks, MIN(nilai) AS min
         FROM hasil_ujian'
    )->fetch();
} else {
    $scopeExam = $pdo->prepare('SELECT COUNT(*) FROM ujian WHERE created_by = :uid');
    $scopeExam->execute(['uid' => $scopeUserId]);
    $totalUjian = (int) $scopeExam->fetchColumn();

    $scopeActive = $pdo->prepare("SELECT COUNT(*) FROM ujian WHERE status = 'aktif' AND created_by = :uid");
    $scopeActive->execute(['uid' => $scopeUserId]);
    $ujianAktif = (int) $scopeActive->fetchColumn();

    $scopeHasil = $pdo->prepare(
        'SELECT COUNT(*) AS jumlah, AVG(h.nilai) AS rata, MAX(h.nilai) AS maks, MIN(h.nilai) AS min
         FROM hasil_ujian h
         INNER JOIN ujian u ON u.id = h.ujian_id
         WHERE u.created_by = :uid'
    );
    $scopeHasil->execute(['uid' => $scopeUserId]);
    $nilaiStats = $scopeHasil->fetch();
    $totalPeserta = $nilaiStats !== false ? (int) $nilaiStats['jumlah'] : 0;
}

$rataNilai = $nilaiStats !== false && $nilaiStats['rata'] !== null ? round((float) $nilaiStats['rata'], 2) : null;
$nilaiTertinggi = $nilaiStats !== false && $nilaiStats['maks'] !== null ? (float) $nilaiStats['maks'] : null;
$nilaiTerendah = $nilaiStats !== false && $nilaiStats['min'] !== null ? (float) $nilaiStats['min'] : null;

$statusCounts = ['draft' => 0, 'aktif' => 0, 'selesai' => 0];
$statusSql = 'SELECT status, COUNT(*) AS total FROM ujian';
if (!$isAdminDashboard) {
    $statusSql .= ' WHERE created_by = :uid';
}
$statusSql .= ' GROUP BY status';
$statusStmt = $pdo->prepare($statusSql);
$statusStmt->execute($isAdminDashboard ? [] : ['uid' => $scopeUserId]);
$statusRows = $statusStmt->fetchAll();
foreach ($statusRows as $statusRow) {
    $statusKey = (string) $statusRow['status'];
    if (array_key_exists($statusKey, $statusCounts)) {
        $statusCounts[$statusKey] = (int) $statusRow['total'];
    }
}

$chartSql = "SELECT * FROM (
        SELECT u.id, u.nama, u.tanggal_ujian,
               COUNT(h.id) AS peserta,
               ROUND(AVG(h.nilai), 2) AS rata
        FROM ujian u
        INNER JOIN hasil_ujian h ON h.ujian_id = u.id
        " . ($isAdminDashboard ? '' : 'WHERE u.created_by = :uid') . "
        GROUP BY u.id, u.nama, u.tanggal_ujian
        ORDER BY u.tanggal_ujian DESC, u.id DESC
        LIMIT 8
    ) AS terbaru
    ORDER BY terbaru.tanggal_ujian ASC, terbaru.id ASC";
$chartStmt = $pdo->prepare($chartSql);
$chartStmt->execute($isAdminDashboard ? [] : ['uid' => $scopeUserId]);
$chartRows = $chartStmt->fetchAll();

$chartLabels = array_map(static fn (array $row): string => (string) $row['nama'], $chartRows);
$chartRata = array_map(static fn (array $row): float => round((float) $row['rata'], 2), $chartRows);
$chartPeserta = array_map(static fn (array $row): int => (int) $row['peserta'], $chartRows);

$ujianTerbaruSql = "SELECT u.id, u.nama, u.status, u.tanggal_ujian, u.waktu_mulai, u.waktu_selesai, u.jumlah_soal,
            mp.nama AS mapel,
            (SELECT COUNT(*) FROM hasil_ujian h WHERE h.ujian_id = u.id) AS peserta,
            (SELECT ROUND(AVG(h.nilai), 2) FROM hasil_ujian h WHERE h.ujian_id = u.id) AS rata
     FROM ujian u
     INNER JOIN mata_pelajaran mp ON mp.id = u.mata_pelajaran_id"
    . ($isAdminDashboard ? '' : ' WHERE u.created_by = :uid')
    . ' ORDER BY u.tanggal_ujian DESC, u.id DESC
     LIMIT 5';
$ujianTerbaruStmt = $pdo->prepare($ujianTerbaruSql);
$ujianTerbaruStmt->execute($isAdminDashboard ? [] : ['uid' => $scopeUserId]);
$ujianTerbaru = $ujianTerbaruStmt->fetchAll();

$pageTitle = 'Dashboard';
$pageSubtitle = 'Selamat datang, ' . $user['nama'] . '. Berikut ringkasan data ujian '
    . ($isAdminDashboard ? 'sekolah.' : 'yang Anda buat.');
$breadcrumbs = [['label' => 'Dashboard']];
$useChartJs = true;
$pageScripts = ['js/dashboard.js'];

require INCLUDES_PATH . '/header.php';
?>

<div class="row g-3">
    <div class="col-6 col-md-4 col-xl">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-primary"><i class="bi bi-book"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Mata Pelajaran</div>
                    <div class="stat-value"><?= e($totalMapel) ?></div>
                    <div class="stat-meta">Mata pelajaran aktif</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-info"><i class="bi bi-people"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Kelas</div>
                    <div class="stat-value"><?= e($totalKelas) ?></div>
                    <div class="stat-meta">Kelas aktif</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-warning"><i class="bi bi-journal-check"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Ujian</div>
                    <div class="stat-value"><?= e($totalUjian) ?></div>
                    <div class="stat-meta"><?= e($ujianAktif) ?> ujian sedang aktif</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-success"><i class="bi bi-clipboard2-check"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Peserta</div>
                    <div class="stat-value"><?= e($totalPeserta) ?></div>
                    <div class="stat-meta">Jawaban terkumpul</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-purple"><i class="bi bi-graph-up-arrow"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Rata-rata Nilai</div>
                    <div class="stat-value"><?= $rataNilai !== null ? e(format_nilai($rataNilai)) : '&mdash;' ?></div>
                    <div class="stat-meta">Skala 0&ndash;100</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="bi bi-bar-chart-line me-1 text-primary"></i> Grafik Hasil Ujian</span>
                <span class="small text-secondary fw-normal">Rata-rata nilai per ujian &middot; maks. 8 ujian terakhir</span>
            </div>
            <div class="card-body">
                <?php if ($chartRows === []): ?>
                    <div class="chart-empty">
                        <i class="bi bi-bar-chart"></i>
                        <div class="fw-semibold text-body">Belum ada data hasil ujian</div>
                        <div class="small">Grafik akan tampil setelah siswa mengirim jawaban dan sistem melakukan koreksi.</div>
                    </div>
                <?php else: ?>
                    <div class="chart-container">
                        <canvas id="chart-hasil"
                                data-labels="<?= e(json_encode($chartLabels, JSON_UNESCAPED_UNICODE)) ?>"
                                data-rata="<?= e(json_encode($chartRata)) ?>"
                                data-peserta="<?= e(json_encode($chartPeserta)) ?>"
                                aria-label="Grafik rata-rata nilai per ujian"
                                role="img"></canvas>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-pie-chart me-1 text-primary"></i> Status Ujian</div>
            <div class="card-body">
                <?php
                $statusMeta = [
                    'aktif'   => ['Aktif', 'success', 'bi-broadcast'],
                    'draft'   => ['Draft', 'secondary', 'bi-pencil-square'],
                    'selesai' => ['Selesai', 'primary', 'bi-check2-circle'],
                ];
                foreach ($statusMeta as $key => [$label, $warna, $ikon]):
                    $jumlah = $statusCounts[$key];
                    $persen = $totalUjian > 0 ? (int) round(($jumlah / $totalUjian) * 100) : 0;
                    ?>
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="stat-icon stat-icon-<?= $key === 'aktif' ? 'success' : ($key === 'draft' ? 'warning' : 'primary') ?>"
                              style="width:38px;height:38px;font-size:1rem;border-radius:11px;">
                            <i class="bi <?= e($ikon) ?>"></i>
                        </span>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-center small">
                                <span class="fw-semibold"><?= e($label) ?></span>
                                <span class="text-secondary"><?= e($jumlah) ?> ujian</span>
                            </div>
                            <div class="progress mt-1" style="height:5px;" role="progressbar"
                                 aria-label="Persentase ujian <?= e(strtolower($label)) ?>"
                                 aria-valuenow="<?= e($persen) ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="progress-bar bg-<?= e($warna) ?>" style="width: <?= e($persen) ?>%"></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="small text-secondary border-top pt-2 mt-1">
                    Total <?= e($totalUjian) ?> ujian terdaftar.
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="bi bi-clipboard-data me-1 text-primary"></i> Ringkasan Nilai</div>
            <div class="card-body">
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="small text-secondary">Rata-rata</span>
                    <span class="fw-semibold"><?= $rataNilai !== null ? e(format_nilai($rataNilai)) : '&mdash;' ?></span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="small text-secondary">Nilai tertinggi</span>
                    <span class="fw-semibold text-success"><?= $nilaiTertinggi !== null ? e(format_nilai($nilaiTertinggi)) : '&mdash;' ?></span>
                </div>
                <div class="d-flex justify-content-between py-2">
                    <span class="small text-secondary">Nilai terendah</span>
                    <span class="fw-semibold text-danger"><?= $nilaiTerendah !== null ? e(format_nilai($nilaiTerendah)) : '&mdash;' ?></span>
                </div>
                <div class="small text-secondary border-top pt-2 mt-1">
                    Dihitung dari <?= e($totalPeserta) ?> hasil ujian.
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-1 text-primary"></i> Ujian Terbaru</span>
        <span class="small text-secondary fw-normal">5 ujian terakhir</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th scope="col">Ujian</th>
                    <th scope="col">Mata Pelajaran</th>
                    <th scope="col">Jadwal</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-center">Peserta</th>
                    <th scope="col" class="text-end">Rata-rata</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($ujianTerbaru === []): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4">
                            <div class="text-secondary mb-1">Belum ada ujian yang dibuat.</div>
                            <div class="small text-secondary">
                                Gunakan menu <strong>Data Ujian</strong> untuk membuat ujian baru.
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($ujianTerbaru as $ujian): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e($ujian['nama']) ?></div>
                                <div class="small text-secondary"><?= e($ujian['jumlah_soal']) ?> soal</div>
                            </td>
                            <td><?= e($ujian['mapel']) ?></td>
                            <td>
                                <div><?= e(format_tanggal($ujian['tanggal_ujian'])) ?></div>
                                <div class="small text-secondary">
                                    <?= e(substr((string) $ujian['waktu_mulai'], 0, 5)) ?>&ndash;<?= e(substr((string) $ujian['waktu_selesai'], 0, 5)) ?> WIB
                                </div>
                            </td>
                            <td><?= status_badge((string) $ujian['status']) ?></td>
                            <td class="text-center"><?= e((int) $ujian['peserta']) ?></td>
                            <td class="text-end">
                                <?= $ujian['rata'] !== null ? e(format_nilai($ujian['rata'])) : '&mdash;' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="alert alert-primary d-flex gap-2 align-items-start mt-3 mb-0" role="alert">
    <i class="bi bi-info-circle-fill mt-1"></i>
    <div class="small">
        <strong>Tahap 6 selesai.</strong> Halaman <strong>Pengaturan</strong> sudah aktif untuk administrator.
        Atur identitas aplikasi, profil sekolah, logo, KKM default, tahun ajaran, dan semester;
        perubahan langsung dipakai pada panel, halaman siswa, serta header dan footer laporan PDF.
    </div>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
