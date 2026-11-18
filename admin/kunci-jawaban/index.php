<?php
declare(strict_types=1);

/**
 * SIKILAT - Kunci jawaban per paket soal.
 * Admin dapat mengelola semua paket. Guru hanya paket milik ujian yang ia buat.
 * Kunci terkunci bila ujian sudah memiliki hasil peserta.
 */

require_once __DIR__ . '/../../config/app.php';

require_auth();

$currentUser = auth_user();
$currentId = (int) $currentUser['id'];
$isAdmin = $currentUser['role'] === 'admin';

$ujianId = (int) input('ujian_id', 0);
$paketId = (int) input('paket_id', 0);

if (is_post()) {
    require_csrf();
    $ujianId = (int) post('ujian_id', $ujianId);
    $paketId = (int) post('paket_id', $paketId);
}

/* ---------------------------------------------------------------------
 * Ambil paket & ujian + pemeriksaan hak akses
 * ------------------------------------------------------------------ */
$paket = null;

if ($paketId > 0) {
    $stmt = db()->prepare(
        'SELECT ps.*, u.nama AS ujian_nama, u.status AS ujian_status, u.jumlah_soal,
                u.tanggal_ujian, u.created_by,
                mp.nama AS mapel, mp.kode AS mapel_kode,
                (SELECT COUNT(*) FROM hasil_ujian h WHERE h.ujian_id = u.id) AS jumlah_hasil
         FROM paket_soal ps
         INNER JOIN ujian u ON u.id = ps.ujian_id
         INNER JOIN mata_pelajaran mp ON mp.id = u.mata_pelajaran_id
         WHERE ps.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $paketId]);
    $paket = $stmt->fetch();

    if ($paket === false) {
        $paket = null;
        $paketId = 0;

        if (is_post()) {
            flash('error', 'Paket soal tidak ditemukan.');
            redirect('admin/kunci-jawaban/index.php');
        }
    } else {
        crud_require_manage_ujian($paket);
        $paketUjianId = (int) $paket['ujian_id'];

        if ($ujianId > 0 && $ujianId !== $paketUjianId) {
            render_error_page(
                404,
                'Paket Tidak Sesuai',
                'Paket soal yang dipilih bukan bagian dari ujian tersebut.'
            );
        }

        $ujianId = $paketUjianId;
    }
}

$ujian = null;
if ($ujianId > 0) {
    $stmt = db()->prepare(
        'SELECT u.*, mp.nama AS mapel, mp.kode AS mapel_kode,
                (SELECT COUNT(*) FROM hasil_ujian h WHERE h.ujian_id = u.id) AS jumlah_hasil,
                (SELECT COUNT(*) FROM paket_soal ps WHERE ps.ujian_id = u.id) AS jumlah_paket
         FROM ujian u
         INNER JOIN mata_pelajaran mp ON mp.id = u.mata_pelajaran_id
         WHERE u.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $ujianId]);
    $ujian = $stmt->fetch();

    if ($ujian === false) {
        $ujian = null;
        $ujianId = 0;
        $paket = null;
        $paketId = 0;
    } else {
        crud_require_manage_ujian($ujian);
    }
}

/* ---------------------------------------------------------------------
 * Proses simpan kunci
 * ------------------------------------------------------------------ */
if (is_post()) {
    if ($paket === null) {
        flash('error', 'Paket soal tidak ditemukan atau tidak dapat diakses.');
        redirect('admin/kunci-jawaban/index.php');
    }

    $action = (string) post('action', '');
    $totalSoal = (int) $paket['jumlah_soal'];
    $redirectUrl = 'admin/kunci-jawaban/index.php?ujian_id=' . (int) $paket['ujian_id']
        . '&paket_id=' . (int) $paket['id'];

    if ((int) $paket['jumlah_hasil'] > 0) {
        flash(
            'error',
            'Kunci jawaban paket "' . $paket['kode_paket'] . '" terkunci karena ujian ini sudah memiliki '
            . (int) $paket['jumlah_hasil'] . ' hasil peserta. Hapus hasil ujian terlebih dahulu jika kunci '
            . 'benar-benar harus diperbaiki.'
        );
        redirect($redirectUrl);
    }

    if ($action === 'save') {
        $rawPg = post('kunci_pg', []);
        $rawBs = post('kunci_bs', []);
        $rawMulti = post('kunci_multi', []);
        $rawTypes = post('tipe', []);

        $rawPg = is_array($rawPg) ? $rawPg : [];
        $rawBs = is_array($rawBs) ? $rawBs : [];
        $rawMulti = is_array($rawMulti) ? $rawMulti : [];
        $rawTypes = is_array($rawTypes) ? $rawTypes : [];

        $keys = [];
        $keyTypes = [];
        $invalid = '';

        for ($number = 1; $number <= $totalSoal; $number++) {
            $tipe = soal_tipe_normalize($rawTypes[$number] ?? 'pg');
            $keyTypes[$number] = $tipe;

            $raw = match ($tipe) {
                'benar_salah' => $rawBs[$number] ?? null,
                'multiple_response' => $rawMulti[$number] ?? [],
                default => $rawPg[$number] ?? null,
            };

            if ($tipe === 'multiple_response' && !is_array($raw)) {
                $raw = $raw === null || $raw === '' ? [] : [$raw];
            }

            if ($raw === null || $raw === '' || $raw === []) {
                continue;
            }

            $normalized = soal_answer_normalize($tipe, $raw);
            if (!$normalized['ok']) {
                $invalid = 'Nomor ' . $number . ': ' . $normalized['error'];
                break;
            }

            if ($normalized['value'] !== null) {
                $keys[$number] = $normalized['value'];
            }
        }

        if ($invalid !== '') {
            flash('error', 'Kunci tidak disimpan. ' . $invalid);
            redirect($redirectUrl);
        }

        if ($paket['ujian_status'] === 'aktif' && count($keys) < $totalSoal) {
            flash(
                'error',
                'Kunci tidak disimpan. Ujian ini sedang aktif sehingga kuncinya harus tetap lengkap ('
                . $totalSoal . ' soal). Ubah status ujian menjadi draft bila ingin mengosongkan sebagian kunci.'
            );
            redirect($redirectUrl);
        }

        $pdo = db();
        try {
            $pdo->beginTransaction();

            $lockCheck = $pdo->prepare('SELECT COUNT(*) FROM hasil_ujian WHERE ujian_id = :ujian_id');
            $lockCheck->execute(['ujian_id' => (int) $paket['ujian_id']]);
            if ((int) $lockCheck->fetchColumn() > 0) {
                $pdo->rollBack();
                flash('error', 'Kunci jawaban terkunci karena sudah ada hasil peserta.');
                redirect($redirectUrl);
            }

            $delete = $pdo->prepare('DELETE FROM kunci_jawaban WHERE paket_soal_id = :paket_id');
            $delete->execute(['paket_id' => (int) $paket['id']]);

            if ($keys !== []) {
                ksort($keys);
                $insert = $pdo->prepare(
                    'INSERT INTO kunci_jawaban (paket_soal_id, nomor_soal, tipe_soal, jawaban)
                     VALUES (:paket_id, :nomor_soal, :tipe_soal, :jawaban)'
                );
                foreach ($keys as $number => $value) {
                    $insert->execute([
                        'paket_id'   => (int) $paket['id'],
                        'nomor_soal' => $number,
                        'tipe_soal'  => $keyTypes[$number] ?? 'pg',
                        'jawaban'    => $value,
                    ]);
                }
            }

            $pdo->commit();

            $filled = count($keys);
            $missing = max(0, $totalSoal - $filled);

            log_aktivitas(
                'kunci_jawaban',
                'simpan',
                'Menyimpan ' . $filled . ' kunci jawaban paket ' . $paket['kode_paket']
                . ' pada ujian: ' . $paket['ujian_nama']
            );

            if ($missing === 0) {
                flash('success', 'Kunci jawaban paket ' . $paket['kode_paket'] . ' lengkap: '
                    . $filled . ' dari ' . $totalSoal . ' soal.');
            } else {
                flash('info', 'Kunci jawaban paket ' . $paket['kode_paket'] . ' disimpan: '
                    . $filled . ' dari ' . $totalSoal . ' terisi, kurang ' . $missing . ' kunci.');
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[SIKILAT] Simpan kunci jawaban gagal: ' . $exception->getMessage());
            flash('error', 'Kunci jawaban gagal disimpan. Silakan coba lagi.');
        }

        redirect($redirectUrl);
    }

    if ($action === 'bulk') {
        $bulkText = post('bulk_text', '');
        $rawTypes = post('tipe', []);
        $bulkTypes = soal_types_complete($totalSoal, is_array($rawTypes) ? $rawTypes : []);
        $parsed = soal_parse_bulk(is_string($bulkText) ? $bulkText : '', $totalSoal, $bulkTypes);

        if (!$parsed['ok']) {
            flash('error', $parsed['error']);
            redirect($redirectUrl);
        }

        $keys = [];
        foreach ($parsed['answers'] as $number => $value) {
            if ($value !== null && $value !== '') {
                $keys[(int) $number] = $value;
            }
        }

        if ($keys === []) {
            flash('error', 'Teks kunci tidak berisi jawaban yang dapat disimpan.');
            redirect($redirectUrl);
        }

        $pdo = db();
        try {
            $pdo->beginTransaction();

            $lockCheck = $pdo->prepare('SELECT COUNT(*) FROM hasil_ujian WHERE ujian_id = :ujian_id');
            $lockCheck->execute(['ujian_id' => (int) $paket['ujian_id']]);
            if ((int) $lockCheck->fetchColumn() > 0) {
                $pdo->rollBack();
                flash('error', 'Kunci jawaban terkunci karena sudah ada hasil peserta.');
                redirect($redirectUrl);
            }

            $upsert = $pdo->prepare(
                'INSERT INTO kunci_jawaban (paket_soal_id, nomor_soal, tipe_soal, jawaban)
                 VALUES (:paket_id, :nomor_soal, :tipe_soal, :jawaban)
                 ON DUPLICATE KEY UPDATE tipe_soal = VALUES(tipe_soal), jawaban = VALUES(jawaban)'
            );
            foreach ($keys as $number => $value) {
                $upsert->execute([
                    'paket_id'   => (int) $paket['id'],
                    'nomor_soal' => $number,
                    'tipe_soal'  => $bulkTypes[$number] ?? 'pg',
                    'jawaban'    => $value,
                ]);
            }

            $countStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM kunci_jawaban
                 WHERE paket_soal_id = :paket_id AND nomor_soal BETWEEN 1 AND :total_soal'
            );
            $countStmt->execute(['paket_id' => (int) $paket['id'], 'total_soal' => $totalSoal]);
            $filled = (int) $countStmt->fetchColumn();

            $pdo->commit();

            log_aktivitas(
                'kunci_jawaban',
                'input_massal',
                'Input massal ' . count($keys) . ' kunci paket ' . $paket['kode_paket']
                . ' pada ujian: ' . $paket['ujian_nama']
            );

            $missing = max(0, $totalSoal - $filled);
            flash(
                $missing === 0 ? 'success' : 'info',
                'Input massal diterapkan. Paket ' . $paket['kode_paket'] . ': '
                . $filled . ' dari ' . $totalSoal . ' terisi'
                . ($missing > 0 ? ', kurang ' . $missing . ' kunci.' : '.')
            );
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[SIKILAT] Input massal kunci gagal: ' . $exception->getMessage());
            flash('error', 'Input massal gagal disimpan. Silakan coba lagi.');
        }

        redirect($redirectUrl);
    }

    flash('error', 'Aksi kunci jawaban tidak dikenal.');
    redirect($redirectUrl);
}

/* ---------------------------------------------------------------------
 * Data pilihan ujian & daftar paket
 * ------------------------------------------------------------------ */
$examWhere = $isAdmin ? '' : ' WHERE u.created_by = :user_id';
$examStmt = db()->prepare(
    'SELECT u.id, u.nama, u.status, u.jumlah_soal, u.tanggal_ujian,
            mp.nama AS mapel, mp.kode AS mapel_kode,
            (SELECT COUNT(*) FROM paket_soal ps WHERE ps.ujian_id = u.id) AS jumlah_paket
     FROM ujian u
     INNER JOIN mata_pelajaran mp ON mp.id = u.mata_pelajaran_id'
    . $examWhere
    . ' ORDER BY u.tanggal_ujian DESC, u.id DESC'
);
$examStmt->execute($isAdmin ? [] : ['user_id' => $currentId]);
$examOptions = $examStmt->fetchAll();

$where = [];
$params = [];

if (!$isAdmin) {
    $where[] = 'u.created_by = :user_id';
    $params['user_id'] = $currentId;
}

if ($ujianId > 0) {
    $where[] = 'ps.ujian_id = :ujian_id';
    $params['ujian_id'] = $ujianId;
}

$packageSql = 'SELECT ps.id, ps.ujian_id, ps.kode_paket, ps.keterangan,
                      u.nama AS ujian_nama, u.status AS ujian_status, u.jumlah_soal,
                      u.tanggal_ujian,
                      mp.nama AS mapel, mp.kode AS mapel_kode,
                      (SELECT COUNT(*) FROM kunci_jawaban kj
                        WHERE kj.paket_soal_id = ps.id
                          AND kj.nomor_soal BETWEEN 1 AND u.jumlah_soal) AS jumlah_kunci,
                      (SELECT COUNT(*) FROM hasil_ujian h WHERE h.ujian_id = u.id) AS jumlah_hasil
               FROM paket_soal ps
               INNER JOIN ujian u ON u.id = ps.ujian_id
               INNER JOIN mata_pelajaran mp ON mp.id = u.mata_pelajaran_id';
if ($where !== []) {
    $packageSql .= ' WHERE ' . implode(' AND ', $where);
}
$packageSql .= ' ORDER BY u.tanggal_ujian DESC, u.nama ASC, ps.kode_paket ASC';

$packageStmt = db()->prepare($packageSql);
$packageStmt->execute($params);
$packageList = $packageStmt->fetchAll();

$totalPaket = count($packageList);
$paketLengkap = 0;
$paketBelum = 0;
$paketTerkunci = 0;
$totalKunci = 0;
$totalKebutuhan = 0;

foreach ($packageList as $packageRow) {
    $jumlahSoal = (int) $packageRow['jumlah_soal'];
    $jumlahKunci = (int) $packageRow['jumlah_kunci'];
    $totalKunci += $jumlahKunci;
    $totalKebutuhan += $jumlahSoal;

    if ($jumlahSoal > 0 && $jumlahKunci >= $jumlahSoal) {
        $paketLengkap++;
    } else {
        $paketBelum++;
    }

    if ((int) $packageRow['jumlah_hasil'] > 0) {
        $paketTerkunci++;
    }
}

/* ---------------------------------------------------------------------
 * Data editor paket terpilih
 * ------------------------------------------------------------------ */
$existingKeys = [];
$existingTypes = [];
$hasMulti = false;
$filledCount = 0;
$missingCount = 0;
$progressPercent = 0;
$locked = false;
$examPackages = [];

if ($paket !== null) {
    $totalSoal = (int) $paket['jumlah_soal'];
    $locked = (int) $paket['jumlah_hasil'] > 0;

    $keyStmt = db()->prepare(
        'SELECT nomor_soal, jawaban, tipe_soal FROM kunci_jawaban
         WHERE paket_soal_id = :paket_id
         ORDER BY nomor_soal ASC'
    );
    $keyStmt->execute(['paket_id' => (int) $paket['id']]);
    foreach ($keyStmt->fetchAll() as $keyRow) {
        $number = (int) $keyRow['nomor_soal'];
        if ($number >= 1 && $number <= $totalSoal) {
            $existingKeys[$number] = (string) $keyRow['jawaban'];
            $existingTypes[$number] = soal_tipe_normalize($keyRow['tipe_soal'] ?? 'pg');
        }
    }

    $existingTypes = soal_types_complete($totalSoal, $existingTypes);
    $hasMulti = soal_types_have_multi($existingTypes);

    $filledCount = count($existingKeys);
    $missingCount = max(0, $totalSoal - $filledCount);
    $progressPercent = $totalSoal > 0 ? (int) round(($filledCount / $totalSoal) * 100) : 0;

    $switchStmt = db()->prepare(
        'SELECT ps.id, ps.kode_paket,
                (SELECT COUNT(*) FROM kunci_jawaban kj
                  WHERE kj.paket_soal_id = ps.id
                    AND kj.nomor_soal BETWEEN 1 AND :total_soal) AS terisi
         FROM paket_soal ps
         WHERE ps.ujian_id = :ujian_id
         ORDER BY ps.kode_paket ASC'
    );
    $switchStmt->execute(['total_soal' => $totalSoal, 'ujian_id' => (int) $paket['ujian_id']]);
    $examPackages = $switchStmt->fetchAll();
}

$pageTitle = 'Kunci Jawaban';
$pageSubtitle = 'Isi dan periksa kunci jawaban setiap paket soal dengan cepat.';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url('admin/dashboard/index.php')],
    ['label' => 'Data Ujian', 'url' => url('admin/ujian/index.php')],
    ['label' => 'Paket Soal', 'url' => url('admin/paket-soal/index.php')],
    ['label' => 'Kunci Jawaban'],
];
$useDataTables = true;
$pageScripts = ['js/crud.js', 'js/kunci-jawaban.js'];

require INCLUDES_PATH . '/header.php';
?>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-primary"><i class="bi bi-collection"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Total Paket</div>
                    <div class="stat-value"><?= e($totalPaket) ?></div>
                    <div class="stat-meta">Sesuai filter ujian</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-success"><i class="bi bi-check2-circle"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Kunci Lengkap</div>
                    <div class="stat-value"><?= e($paketLengkap) ?></div>
                    <div class="stat-meta">Siap diaktifkan</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-warning"><i class="bi bi-exclamation-circle"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Belum Lengkap</div>
                    <div class="stat-value"><?= e($paketBelum) ?></div>
                    <div class="stat-meta">Perlu dilengkapi</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-purple"><i class="bi bi-lock"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Terkunci</div>
                    <div class="stat-value"><?= e($paketTerkunci) ?></div>
                    <div class="stat-meta">Ujian sudah punya hasil</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($examOptions === []): ?>
    <div class="alert alert-info d-flex gap-2 align-items-start">
        <i class="bi bi-info-circle-fill mt-1"></i>
        <div>
            Belum ada ujian yang dapat dikelola. Buat ujian terlebih dahulu melalui menu
            <a href="<?= e(url('admin/ujian/index.php')) ?>" class="alert-link">Data Ujian</a>.
        </div>
    </div>
<?php else: ?>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" action="<?= e(url('admin/kunci-jawaban/index.php')) ?>" class="filter-bar" data-auto-submit>
            <div>
                <label class="form-label" for="filterUjianKunci">Ujian</label>
                <select class="form-select form-select-sm key-exam-filter" id="filterUjianKunci" name="ujian_id">
                    <option value="0">Semua ujian</option>
                    <?php foreach ($examOptions as $examOption): ?>
                        <option value="<?= (int) $examOption['id'] ?>"<?= crud_selected($ujianId, (int) $examOption['id']) ?>>
                            <?= e(str_limit((string) $examOption['nama'], 70)) ?>
                            (<?= e($examOption['jumlah_paket']) ?> paket)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="pb-1">
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <a href="<?= e(url('admin/kunci-jawaban/index.php')) ?>" class="btn btn-sm btn-light border">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                </a>
                <?php if ($ujianId > 0): ?>
                    <a href="<?= e(url('admin/paket-soal/index.php?ujian_id=' . $ujianId)) ?>"
                       class="btn btn-sm btn-light border">
                        <i class="bi bi-collection me-1"></i> Kelola Paket
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($paket !== null): ?>
    <?php
    $totalSoal = (int) $paket['jumlah_soal'];
    $progressColor = $missingCount === 0 ? 'success' : ($filledCount > 0 ? 'warning' : 'secondary');
    ?>
    <div class="card mb-3" id="editorKunci">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <span class="fw-semibold">
                    <i class="bi bi-key me-1 text-primary"></i>
                    Kunci Paket <?= e($paket['kode_paket']) ?> — <?= e($paket['ujian_nama']) ?>
                </span>
                <div class="table-subtext mt-1">
                    <span class="badge badge-soft-primary me-1"><?= e($paket['mapel_kode']) ?></span>
                    <?= e($paket['mapel']) ?>
                    &middot; <?= e(format_tanggal((string) $paket['tanggal_ujian'])) ?>
                    &middot; <?= e($paket['jumlah_soal']) ?> soal
                    &middot; <?= status_badge((string) $paket['ujian_status']) ?>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($examPackages as $switchPackage): ?>
                    <a href="<?= e(url('admin/kunci-jawaban/index.php?ujian_id=' . (int) $paket['ujian_id'] . '&paket_id=' . (int) $switchPackage['id'])) ?>"
                       class="btn btn-sm <?= (int) $switchPackage['id'] === (int) $paket['id'] ? 'btn-primary' : 'btn-light border' ?>">
                        Paket <?= e($switchPackage['kode_paket']) ?>
                        <span class="badge <?= (int) $switchPackage['id'] === (int) $paket['id'] ? 'text-bg-light' : 'text-bg-secondary' ?> ms-1">
                            <?= e((int) $switchPackage['terisi']) ?>/<?= e($totalSoal) ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card-body">
            <?php if ($locked): ?>
                <div class="alert alert-warning d-flex gap-2 align-items-start">
                    <i class="bi bi-lock-fill mt-1"></i>
                    <div>
                        <div class="fw-semibold">Kunci jawaban terkunci.</div>
                        <div class="small">
                            Ujian ini sudah memiliki <strong><?= e((int) $paket['jumlah_hasil']) ?> hasil peserta</strong>.
                            Kunci tidak dapat diubah agar hasil koreksi lama tetap konsisten.
                            Hapus hasil ujian terlebih dahulu jika kunci benar-benar harus diperbaiki.
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="key-progress-panel mb-3">
                <div class="row g-3 align-items-center">
                    <div class="col-6 col-md-3">
                        <div class="key-metric">
                            <span class="key-metric-label">Terisi</span>
                            <span class="key-metric-value text-success" data-filled-count><?= e($filledCount) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="key-metric">
                            <span class="key-metric-label">Kosong</span>
                            <span class="key-metric-value <?= $missingCount > 0 ? 'text-danger' : 'text-secondary' ?>" data-missing-count><?= e($missingCount) ?></span>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="fw-semibold" data-progress-text><?= e($filledCount) ?> / <?= e($totalSoal) ?> terisi</span>
                            <span class="text-secondary" data-progress-percent><?= e($progressPercent) ?>%</span>
                        </div>
                        <div class="progress key-progress" role="progressbar" data-progress-wrap
                             aria-label="Progres pengisian kunci"
                             aria-valuenow="<?= e($progressPercent) ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar bg-<?= e($progressColor) ?>" data-progress-bar
                                 style="width: <?= e($progressPercent) ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$locked): ?>
                <div class="card bg-light-subtle border mb-3">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                            <label for="bulkKunci" class="form-label mb-0">
                                <i class="bi bi-lightning-charge me-1 text-warning"></i>
                                Input massal
                            </label>
                            <span class="small text-secondary" data-bulk-count></span>
                        </div>
                        <div class="row g-2 align-items-end">
                            <div class="col-12 col-lg-9">
                                <textarea class="form-control bulk-textarea" id="bulkKunci" name="bulk_text"
                                          form="formKunci" rows="2" data-bulk-input
                                          data-max="<?= (int) $paket['jumlah_soal'] ?>"
                                          data-has-multi="<?= $hasMulti ? '1' : '0' ?>"
                                          placeholder="<?= $hasMulti ? 'Contoh: A AC B S - D' : 'Contoh: ABCDEABCDEABCDE...' ?>"
                                          autocapitalize="characters" autocomplete="off"
                                          spellcheck="false"></textarea>
                                <div class="form-text">
                                    <?php if ($hasMulti): ?>
                                        Tulis satu token per nomor, dipisah spasi atau koma.
                                        Multiple Response ditulis menyambung (mis. <code>AC</code> atau <code>(AC)</code>),
                                        Benar/Salah memakai <code>B</code>/<code>S</code>, PG memakai <code>A</code>–<code>E</code>.
                                        Gunakan <code>-</code> untuk nomor kosong.
                                    <?php else: ?>
                                        Urut dari nomor 1. Spasi, enter, koma, titik, dan tanda hubung diabaikan.
                                        PG memakai <code>A</code>–<code>E</code>, Benar/Salah memakai <code>B</code>/<code>S</code>.
                                        Kunci nomor lain yang sudah ada tidak dihapus.
                                    <?php endif; ?>
                                </div>
                                <div class="form-text text-danger d-none" data-bulk-hint>
                                    Jumlah jawaban melebihi jumlah soal paket ini.
                                </div>
                            </div>
                            <div class="col-12 col-lg-3 d-grid">
                                <button type="submit" class="btn btn-outline-primary" form="formKunci"
                                        name="action" value="bulk">
                                    <i class="bi bi-input-cursor-text me-1"></i> Terapkan Massal
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <form method="post" action="<?= e(url('admin/kunci-jawaban/index.php')) ?>"
                      id="formKunci" data-key-form data-total="<?= (int) $paket['jumlah_soal'] ?>"
                      data-has-multi="<?= $hasMulti ? '1' : '0' ?>" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="ujian_id" value="<?= (int) $paket['ujian_id'] ?>">
                    <input type="hidden" name="paket_id" value="<?= (int) $paket['id'] ?>">

                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <div class="small text-secondary">
                            <i class="bi bi-keyboard me-1"></i>
                            Pilih tipe soal per nomor. PG memakai <strong>A</strong>–<strong>E</strong>,
                            Benar/Salah memakai <strong>B</strong>/<strong>S</strong>, Multiple Response
                            dapat mencentang beberapa opsi. Panah atas/bawah berpindah nomor.
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-light border" data-focus-first-empty>
                                <i class="bi bi-arrow-down-circle me-1"></i> Nomor Kosong
                            </button>
                            <div class="key-legend">
                                <span class="key-legend-item" title="Pilihan Ganda">A–E</span>
                                <span class="key-legend-item" title="Benar/Salah">B/S</span>
                                <span class="key-legend-item" title="Multiple Response">MR</span>
                            </div>
                        </div>
                    </div>

                    <div class="key-table-wrap">
                        <table class="table table-hover key-table">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width:56px;">No</th>
                                    <th style="width:150px;">Tipe Soal</th>
                                    <th>Kunci Jawaban</th>
                                    <th class="text-center" style="width:60px;">Status</th>
                                    <th class="text-center" style="width:54px;">Hapus</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($number = 1; $number <= (int) $paket['jumlah_soal']; $number++): ?>
                                    <?php
                                    $selected = $existingKeys[$number] ?? '';
                                    $tipe = $existingTypes[$number] ?? 'pg';
                                    $selectedLetters = soal_answer_letters($selected);
                                    $rowFilled = $selected !== '';
                                    ?>
                                    <tr data-key-row="<?= $number ?>" data-tipe="<?= e($tipe) ?>"
                                        class="<?= $rowFilled ? 'key-row-filled' : '' ?>">
                                        <td class="text-center key-number"><?= $number ?></td>
                                        <td>
                                            <select class="form-select form-select-sm key-type-select"
                                                    name="tipe[<?= $number ?>]" data-key-type-select
                                                    aria-label="Tipe soal nomor <?= $number ?>">
                                                <?php foreach (soal_tipe_definitions() as $typeKey => $definition): ?>
                                                    <option value="<?= e($typeKey) ?>"<?= $tipe === $typeKey ? ' selected' : '' ?>>
                                                        <?= e($definition['label']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <div class="key-controls" data-key-controls data-active="<?= e($tipe) ?>">
                                                <div class="btn-group key-options" data-key-type="pg" role="group"
                                                     aria-label="Kunci PG nomor <?= $number ?>">
                                                    <?php foreach (['A', 'B', 'C', 'D', 'E'] as $option): ?>
                                                        <?php $inputId = 'kunci_pg_' . $number . '_' . $option; ?>
                                                        <input type="radio" class="btn-check"
                                                               name="kunci_pg[<?= $number ?>]"
                                                               id="<?= e($inputId) ?>"
                                                               value="<?= e($option) ?>"
                                                               data-key-input
                                                               <?= $tipe === 'pg' && $selected === $option ? 'checked' : '' ?>>
                                                        <label class="btn btn-outline-primary" for="<?= e($inputId) ?>">
                                                            <?= e($option) ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>

                                                <div class="btn-group key-options" data-key-type="benar_salah" role="group"
                                                     aria-label="Kunci Benar/Salah nomor <?= $number ?>">
                                                    <?php foreach (['B' => 'Benar', 'S' => 'Salah'] as $option => $label): ?>
                                                        <?php $inputId = 'kunci_bs_' . $number . '_' . $option; ?>
                                                        <input type="radio" class="btn-check"
                                                               name="kunci_bs[<?= $number ?>]"
                                                               id="<?= e($inputId) ?>"
                                                               value="<?= e($option) ?>"
                                                               data-key-input
                                                               <?= $tipe === 'benar_salah' && $selected === $option ? 'checked' : '' ?>>
                                                        <label class="btn btn-outline-primary" for="<?= e($inputId) ?>">
                                                            <?= e($label) ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>

                                                <div class="btn-group key-options" data-key-type="multiple_response" role="group"
                                                     aria-label="Kunci Multiple Response nomor <?= $number ?>">
                                                    <?php foreach (['A', 'B', 'C', 'D', 'E'] as $option): ?>
                                                        <?php $inputId = 'kunci_mr_' . $number . '_' . $option; ?>
                                                        <input type="checkbox" class="btn-check"
                                                               name="kunci_multi[<?= $number ?>][]"
                                                               id="<?= e($inputId) ?>"
                                                               value="<?= e($option) ?>"
                                                               data-key-input
                                                               <?= $tipe === 'multiple_response' && in_array($option, $selectedLetters, true) ? 'checked' : '' ?>>
                                                        <label class="btn btn-outline-primary" for="<?= e($inputId) ?>">
                                                            <?= e($option) ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center key-state">
                                            <span class="key-state-empty text-secondary"><i class="bi bi-dash-circle"></i></span>
                                            <span class="key-state-filled"><i class="bi bi-check-circle-fill"></i></span>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-link text-secondary p-0"
                                                    data-clear-row title="Kosongkan nomor <?= $number ?>"
                                                    aria-label="Kosongkan nomor <?= $number ?>">
                                                <i class="bi bi-x-circle"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="key-editor-footer">
                        <div class="small text-secondary">
                            <i class="bi bi-info-circle me-1 text-primary"></i>
                            Kunci disimpan hanya setelah tombol simpan ditekan.
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-light border" data-clear-all>
                                <i class="bi bi-eraser me-1"></i> Kosongkan Semua
                            </button>
                            <button type="submit" class="btn btn-primary" name="action" value="save">
                                <i class="bi bi-save me-1"></i>
                                <span data-save-label>Simpan Kunci (<?= e($filledCount) ?>/<?= e($totalSoal) ?>)</span>
                            </button>
                        </div>
                    </div>
                </form>
            <?php else: ?>
                <div class="key-table-wrap">
                    <table class="table table-sm key-table key-table-locked">
                        <thead>
                            <tr>
                                <th class="text-center" style="width:70px;">No</th>
                                <th style="width:170px;">Tipe</th>
                                <th>Kunci Tersimpan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($number = 1; $number <= (int) $paket['jumlah_soal']; $number++): ?>
                                <?php
                                $saved = $existingKeys[$number] ?? '';
                                $savedType = $existingTypes[$number] ?? 'pg';
                                ?>
                                <tr>
                                    <td class="text-center key-number"><?= $number ?></td>
                                    <td>
                                        <span class="badge text-bg-light border text-secondary">
                                            <?= e(soal_tipe_label($savedType)) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($saved !== ''): ?>
                                            <span class="badge text-bg-success fs-6"><?= e(soal_answer_display($savedType, $saved)) ?></span>
                                        <?php else: ?>
                                            <span class="badge text-bg-light border text-secondary">Kosong</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span>
            <i class="bi bi-list-check me-1 text-primary"></i>
            Daftar Paket <?= $ujian !== null ? '— ' . e($ujian['nama']) : 'Soal' ?>
        </span>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="small text-secondary">
                Total kunci tersimpan: <strong><?= e($totalKunci) ?></strong>
                dari <strong><?= e($totalKebutuhan) ?></strong> kebutuhan
            </span>
            <?php if ($ujian !== null): ?>
                <a href="<?= e(url('admin/paket-soal/index.php?ujian_id=' . $ujianId)) ?>"
                   class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-plus-lg me-1"></i> Tambah Paket
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($packageList === []): ?>
        <div class="card-body text-center py-5">
            <i class="bi bi-collection fs-1 text-secondary"></i>
            <div class="fw-semibold mt-2">Belum ada paket soal</div>
            <div class="small text-secondary mb-3">
                <?= $ujian !== null
                    ? 'Ujian ini belum memiliki paket soal. Tambahkan paket A, B, C, dan seterusnya.'
                    : 'Belum ada paket soal yang dapat ditampilkan.' ?>
            </div>
            <?php if ($ujian !== null): ?>
                <a href="<?= e(url('admin/paket-soal/index.php?ujian_id=' . $ujianId)) ?>"
                   class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i> Tambah Paket Soal
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle" data-datatable>
                <thead>
                    <tr>
                        <th>Ujian</th>
                        <th style="width:85px;">Paket</th>
                        <th>Keterangan</th>
                        <th style="width:210px;">Progres Kunci</th>
                        <th class="text-center" style="width:80px;">Hasil</th>
                        <th style="width:105px;">Status Ujian</th>
                        <th class="text-end" style="width:160px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($packageList as $packageRow): ?>
                        <?php
                        $jumlahSoal = (int) $packageRow['jumlah_soal'];
                        $jumlahKunci = (int) $packageRow['jumlah_kunci'];
                        $kurang = max(0, $jumlahSoal - $jumlahKunci);
                        $pct = $jumlahSoal > 0 ? (int) round(($jumlahKunci / $jumlahSoal) * 100) : 0;
                        $isLocked = (int) $packageRow['jumlah_hasil'] > 0;
                        $isSelected = $paket !== null && (int) $paket['id'] === (int) $packageRow['id'];
                        $barColor = $kurang === 0 ? 'success' : ($jumlahKunci > 0 ? 'warning' : 'secondary');
                        ?>
                        <tr>
                            <td style="min-width:220px;">
                                <div class="table-title"><?= e($packageRow['ujian_nama']) ?></div>
                                <div class="table-subtext">
                                    <span class="badge badge-soft-primary me-1"><?= e($packageRow['mapel_kode']) ?></span>
                                    <?= e($packageRow['mapel']) ?>
                                    &middot; <?= e(format_tanggal((string) $packageRow['tanggal_ujian'])) ?>
                                </div>
                            </td>
                            <td><span class="badge badge-soft-primary fs-6"><?= e($packageRow['kode_paket']) ?></span></td>
                            <td class="small text-secondary">
                                <?= $packageRow['keterangan'] !== null && $packageRow['keterangan'] !== ''
                                    ? e(str_limit((string) $packageRow['keterangan'], 60))
                                    : '<span class="text-secondary">-</span>' ?>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height:6px; min-width:80px;"
                                         role="progressbar" aria-valuenow="<?= $pct ?>"
                                         aria-valuemin="0" aria-valuemax="100"
                                         aria-label="Progres kunci paket <?= e($packageRow['kode_paket']) ?>">
                                        <div class="progress-bar bg-<?= e($barColor) ?>" style="width: <?= $pct ?>%"></div>
                                    </div>
                                    <span class="small fw-semibold text-nowrap"><?= e($jumlahKunci) ?>/<?= e($jumlahSoal) ?></span>
                                </div>
                                <div class="table-subtext">
                                    <?= $kurang === 0
                                        ? '<span class="text-success"><i class="bi bi-check2 me-1"></i>Lengkap</span>'
                                        : '<span class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>Kurang ' . e($kurang) . ' kunci</span>' ?>
                                </div>
                            </td>
                            <td class="text-center">
                                <?php if ((int) $packageRow['jumlah_hasil'] > 0): ?>
                                    <span class="badge text-bg-info" title="Jumlah hasil peserta">
                                        <?= e((int) $packageRow['jumlah_hasil']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-secondary">0</span>
                                <?php endif; ?>
                            </td>
                            <td><?= status_badge((string) $packageRow['ujian_status']) ?></td>
                            <td class="text-end">
                                <div class="table-actions justify-content-end">
                                    <a href="<?= e(url('admin/kunci-jawaban/index.php?ujian_id=' . (int) $packageRow['ujian_id'] . '&paket_id=' . (int) $packageRow['id'])) ?>"
                                       class="btn <?= $isSelected ? 'btn-primary' : 'btn-outline-primary' ?>"
                                       title="<?= $isLocked ? 'Lihat kunci (terkunci)' : 'Isi kunci jawaban' ?>">
                                        <i class="bi <?= $isLocked ? 'bi-lock' : 'bi-key' ?>"></i>
                                        <?= $isLocked ? 'Lihat' : 'Isi Kunci' ?>
                                    </a>
                                    <?php if ($isLocked): ?>
                                        <span class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                            Terkunci
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
