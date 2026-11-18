<?php
declare(strict_types=1);

/**
 * SIKILAT - Paket soal per ujian.
 * Admin dapat mengelola semua paket. Guru hanya paket milik ujian yang
 * ia buat sendiri.
 */

require_once __DIR__ . '/../../config/app.php';

require_auth();

$currentUser = auth_user();
$currentId = (int) $currentUser['id'];
$isAdmin = $currentUser['role'] === 'admin';

$errors = [];
$formData = [];
$openModal = '';

/**
 * Ambil paket beserta data ujian dan pemeriksaan hak akses.
 *
 * @return array<string, mixed>|null
 */
function paket_fetch_accessible(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT ps.*, u.created_by, u.nama AS ujian_nama, u.status AS ujian_status
         FROM paket_soal ps
         INNER JOIN ujian u ON u.id = ps.ujian_id
         WHERE ps.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    crud_require_manage_ujian($row);

    return $row;
}

/* ---------------------------------------------------------------------
 * Proses POST
 * ------------------------------------------------------------------ */
if (is_post()) {
    require_csrf();
    $action = (string) post('action', '');

    if ($action === 'save') {
        $id = (int) post('id', 0);
        $ujianId = (int) post('ujian_id', 0);
        $kodePaket = strtoupper(crud_text(post('kode_paket'), 10));
        $keterangan = crud_text(post('keterangan'), 100);

        $formData = [
            'id'         => $id,
            'ujian_id'   => $ujianId,
            'kode_paket' => $kodePaket,
            'keterangan' => $keterangan,
        ];

        if ($id > 0) {
            $existing = paket_fetch_accessible($id);
            if ($existing === null) {
                $errors[] = 'Data paket soal tidak ditemukan.';
            }
        }

        $ujian = null;
        if ($ujianId <= 0) {
            $errors[] = 'Ujian wajib dipilih.';
        } else {
            $ujianStmt = db()->prepare('SELECT * FROM ujian WHERE id = :id LIMIT 1');
            $ujianStmt->execute(['id' => $ujianId]);
            $ujian = $ujianStmt->fetch();

            if ($ujian === false) {
                $errors[] = 'Ujian yang dipilih tidak ditemukan.';
            } else {
                crud_require_manage_ujian($ujian);
            }
        }

        if ($kodePaket === '') {
            $errors[] = 'Kode paket wajib diisi.';
        } elseif (preg_match('/^[A-Z0-9._-]{1,10}$/', $kodePaket) !== 1) {
            $errors[] = 'Kode paket hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung (maksimal 10 karakter).';
        }

        if ($errors === []) {
            $duplicate = db()->prepare(
                'SELECT id FROM paket_soal
                 WHERE ujian_id = :ujian_id AND kode_paket = :kode_paket AND id <> :id
                 LIMIT 1'
            );
            $duplicate->execute(['ujian_id' => $ujianId, 'kode_paket' => $kodePaket, 'id' => $id]);
            if ($duplicate->fetch() !== false) {
                $errors[] = 'Kode paket tersebut sudah digunakan pada ujian ini.';
            }
        }

        if ($errors === []) {
            try {
                if ($id > 0) {
                    $stmt = db()->prepare(
                        'UPDATE paket_soal
                         SET ujian_id = :ujian_id, kode_paket = :kode_paket, keterangan = :keterangan
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        'ujian_id'   => $ujianId,
                        'kode_paket' => $kodePaket,
                        'keterangan' => $keterangan !== '' ? $keterangan : null,
                        'id'         => $id,
                    ]);
                    log_aktivitas('paket_soal', 'ubah', 'Mengubah paket soal ' . $kodePaket . ' pada ujian: ' . $ujian['nama']);
                    flash('success', 'Paket soal berhasil diperbarui.');
                } else {
                    $stmt = db()->prepare(
                        'INSERT INTO paket_soal (ujian_id, kode_paket, keterangan)
                         VALUES (:ujian_id, :kode_paket, :keterangan)'
                    );
                    $stmt->execute([
                        'ujian_id'   => $ujianId,
                        'kode_paket' => $kodePaket,
                        'keterangan' => $keterangan !== '' ? $keterangan : null,
                    ]);
                    log_aktivitas('paket_soal', 'tambah', 'Menambah paket soal ' . $kodePaket . ' pada ujian: ' . $ujian['nama']);
                    flash('success', 'Paket soal berhasil ditambahkan.');
                }

                redirect('admin/paket-soal/index.php' . ($ujianId > 0 ? '?ujian_id=' . $ujianId : ''));
            } catch (PDOException $exception) {
                error_log('[SIKILAT] Simpan paket soal gagal: ' . $exception->getMessage());
                $errors[] = $exception->getCode() === '23000'
                    ? 'Kode paket tersebut sudah digunakan pada ujian ini.'
                    : 'Paket soal gagal disimpan. Silakan coba lagi.';
            }
        }

        if ($errors !== []) {
            $openModal = $id > 0 ? 'edit' : 'create';
        }
    } elseif ($action === 'delete') {
        $id = (int) post('id', 0);
        $paket = paket_fetch_accessible($id);

        if ($paket === null) {
            flash('error', 'Paket soal tidak ditemukan.');
            redirect('admin/paket-soal/index.php');
        }

        $hasil = db()->prepare('SELECT COUNT(*) FROM hasil_ujian WHERE paket_soal_id = :id');
        $hasil->execute(['id' => $id]);
        $jumlahHasil = (int) $hasil->fetchColumn();

        if ($jumlahHasil > 0) {
            flash(
                'error',
                'Paket "' . $paket['kode_paket'] . '" tidak dapat dihapus karena sudah memiliki '
                . $jumlahHasil . ' hasil ujian.'
            );
            redirect('admin/paket-soal/index.php?ujian_id=' . (int) $paket['ujian_id']);
        }

        try {
            $delete = db()->prepare('DELETE FROM paket_soal WHERE id = :id');
            $delete->execute(['id' => $id]);
            log_aktivitas(
                'paket_soal',
                'hapus',
                'Menghapus paket soal ' . $paket['kode_paket'] . ' dari ujian: ' . $paket['ujian_nama']
            );
            flash('success', 'Paket soal berhasil dihapus beserta kunci jawabannya.');
        } catch (PDOException $exception) {
            error_log('[SIKILAT] Hapus paket soal gagal: ' . $exception->getMessage());
            flash('error', 'Paket soal tidak dapat dihapus karena masih memiliki relasi data.');
        }

        redirect('admin/paket-soal/index.php?ujian_id=' . (int) $paket['ujian_id']);
    }
}

/* ---------------------------------------------------------------------
 * Filter & data
 * ------------------------------------------------------------------ */
$ujianFilter = (int) input('ujian_id', 0);

if ($ujianFilter > 0) {
    $filterStmt = db()->prepare('SELECT * FROM ujian WHERE id = :id LIMIT 1');
    $filterStmt->execute(['id' => $ujianFilter]);
    $filterUjian = $filterStmt->fetch();

    if ($filterUjian === false) {
        $ujianFilter = 0;
    } else {
        crud_require_manage_ujian($filterUjian);
    }
}

$where = [];
$params = [];

if (!$isAdmin) {
    $where[] = 'u.created_by = :scope_user';
    $params['scope_user'] = $currentId;
}

if ($ujianFilter > 0) {
    $where[] = 'ps.ujian_id = :ujian_id';
    $params['ujian_id'] = $ujianFilter;
}

$sql = 'SELECT ps.*, u.nama AS ujian_nama, u.status AS ujian_status, u.tanggal_ujian, u.jumlah_soal,
               mp.nama AS mapel, mp.kode AS mapel_kode,
               (SELECT COUNT(*) FROM kunci_jawaban kj
                 WHERE kj.paket_soal_id = ps.id
                   AND kj.nomor_soal BETWEEN 1 AND u.jumlah_soal) AS jumlah_kunci,
               (SELECT COUNT(*) FROM hasil_ujian h WHERE h.paket_soal_id = ps.id) AS jumlah_hasil
        FROM paket_soal ps
        INNER JOIN ujian u ON u.id = ps.ujian_id
        INNER JOIN mata_pelajaran mp ON mp.id = u.mata_pelajaran_id';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY u.tanggal_ujian DESC, u.nama ASC, ps.kode_paket ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$paketList = $stmt->fetchAll();

$paketLengkap = 0;
$paketBelum = 0;
foreach ($paketList as $paketRow) {
    $butuh = (int) $paketRow['jumlah_soal'];
    $terisi = (int) $paketRow['jumlah_kunci'];

    if ($butuh > 0 && $terisi >= $butuh) {
        $paketLengkap++;
    } else {
        $paketBelum++;
    }
}

$scopeSql = $isAdmin ? '' : ' WHERE u.created_by = :scope_user';
$scopeCondition = $isAdmin ? '' : ' AND u.created_by = :scope_user';
$scopeParams = $isAdmin ? [] : ['scope_user' => $currentId];

$countPaketStmt = db()->prepare(
    'SELECT COUNT(*) FROM paket_soal ps INNER JOIN ujian u ON u.id = ps.ujian_id' . $scopeSql
);
$countPaketStmt->execute($scopeParams);
$totalPaket = (int) $countPaketStmt->fetchColumn();

$countKunciStmt = db()->prepare(
    'SELECT COUNT(*) FROM kunci_jawaban kj
     INNER JOIN paket_soal ps ON ps.id = kj.paket_soal_id
     INNER JOIN ujian u ON u.id = ps.ujian_id
     WHERE kj.nomor_soal BETWEEN 1 AND u.jumlah_soal' . $scopeCondition
);
$countKunciStmt->execute($scopeParams);
$totalKunci = (int) $countKunciStmt->fetchColumn();

$countUjianStmt = db()->prepare(
    'SELECT COUNT(DISTINCT ps.ujian_id) FROM paket_soal ps INNER JOIN ujian u ON u.id = ps.ujian_id' . $scopeSql
);
$countUjianStmt->execute($scopeParams);
$totalUjianBerpaket = (int) $countUjianStmt->fetchColumn();

$exams = crud_accessible_exams();
$defaultUjian = $ujianFilter;
if ($defaultUjian === 0 && $formData !== []) {
    $defaultUjian = (int) ($formData['ujian_id'] ?? 0);
}
if ($defaultUjian === 0 && count($exams) === 1) {
    $defaultUjian = (int) $exams[0]['id'];
}

$pageTitle = 'Paket Soal';
$pageSubtitle = $isAdmin
    ? 'Kelola paket soal A, B, C, dan seterusnya untuk setiap ujian.'
    : 'Kelola paket soal pada ujian yang Anda buat.';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url('admin/dashboard/index.php')],
    ['label' => 'Data Ujian', 'url' => url('admin/ujian/index.php')],
    ['label' => 'Paket Soal'],
];
$useDataTables = true;
$pageScripts = ['js/crud.js'];

require INCLUDES_PATH . '/header.php';
?>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-primary"><i class="bi bi-collection"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Total Paket</div>
                    <div class="stat-value"><?= e($totalPaket) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-info"><i class="bi bi-journal-check"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Ujian Berpaket</div>
                    <div class="stat-value"><?= e($totalUjianBerpaket) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-success"><i class="bi bi-key"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Kunci Tersimpan</div>
                    <div class="stat-value"><?= e($totalKunci) ?></div>
                    <div class="stat-meta">
                        <?= e($paketLengkap) ?> paket lengkap
                        &middot; <?= e($paketBelum) ?> perlu dilengkapi
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span><i class="bi bi-collection me-1 text-primary"></i> Daftar Paket Soal</span>
        <?php if ($exams === []): ?>
            <span class="badge text-bg-light border fw-normal">Belum ada ujian yang dapat dikelola</span>
        <?php else: ?>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalPaket"
                    data-modal-title="Tambah Paket Soal">
                <i class="bi bi-plus-lg me-1"></i> Tambah Paket Soal
            </button>
        <?php endif; ?>
    </div>

    <div class="card-body border-bottom">
        <form method="get" action="<?= e(url('admin/paket-soal/index.php')) ?>" class="filter-bar" data-auto-submit>
            <div>
                <label class="form-label" for="filterUjianPaket">Ujian</label>
                <select class="form-select form-select-sm" id="filterUjianPaket" name="ujian_id">
                    <option value="0">Semua ujian</option>
                    <?php foreach ($exams as $exam): ?>
                        <option value="<?= (int) $exam['id'] ?>"<?= crud_selected($ujianFilter, (int) $exam['id']) ?>>
                            <?= e(str_limit((string) $exam['nama'], 60)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="pb-1">
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <a href="<?= e(url('admin/paket-soal/index.php')) ?>" class="btn btn-sm btn-light border">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                </a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle" data-datatable>
            <thead>
                <tr>
                    <th>Ujian</th>
                    <th style="width:95px;">Kode</th>
                    <th>Keterangan</th>
                    <th style="width:190px;">Progres Kunci</th>
                    <th class="text-center" style="width:70px;">Hasil</th>
                    <th style="width:105px;">Status Ujian</th>
                    <th class="text-end" style="width:170px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($paketList as $row): ?>
                    <?php
                    $jumlahHasil = (int) $row['jumlah_hasil'];
                    $jumlahKunci = (int) $row['jumlah_kunci'];
                    $butuhKunci = (int) $row['jumlah_soal'];
                    $kurangKunci = max(0, $butuhKunci - $jumlahKunci);
                    $persenKunci = $butuhKunci > 0 ? (int) round(($jumlahKunci / $butuhKunci) * 100) : 0;
                    $warnaKunci = $kurangKunci === 0 ? 'success' : ($jumlahKunci > 0 ? 'warning' : 'secondary');
                    $kunciTerkunci = $jumlahHasil > 0;
                    $record = [
                        'id'         => (int) $row['id'],
                        'ujian_id'   => (int) $row['ujian_id'],
                        'kode_paket' => (string) $row['kode_paket'],
                        'keterangan' => (string) ($row['keterangan'] ?? ''),
                    ];
                    ?>
                    <tr>
                        <td style="min-width:220px;">
                            <div class="table-title"><?= e($row['ujian_nama']) ?></div>
                            <div class="table-subtext">
                                <span class="badge badge-soft-primary me-1"><?= e($row['mapel_kode']) ?></span>
                                <?= e($row['mapel']) ?> &middot; <?= e(format_tanggal((string) $row['tanggal_ujian'])) ?>
                            </div>
                        </td>
                        <td><span class="badge badge-soft-primary fs-6"><?= e($row['kode_paket']) ?></span></td>
                        <td class="small text-secondary" style="min-width:180px;">
                            <?= $row['keterangan'] !== null && $row['keterangan'] !== ''
                                ? e(str_limit((string) $row['keterangan'], 80))
                                : '<span class="text-secondary">-</span>' ?>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height:6px; min-width:70px;"
                                     role="progressbar" aria-valuenow="<?= $persenKunci ?>"
                                     aria-valuemin="0" aria-valuemax="100"
                                     aria-label="Progres kunci paket <?= e($row['kode_paket']) ?>">
                                    <div class="progress-bar bg-<?= e($warnaKunci) ?>" style="width: <?= $persenKunci ?>%"></div>
                                </div>
                                <span class="small fw-semibold text-nowrap"><?= e($jumlahKunci) ?>/<?= e($butuhKunci) ?></span>
                            </div>
                            <div class="table-subtext">
                                <?= $kurangKunci === 0
                                    ? '<span class="text-success"><i class="bi bi-check2 me-1"></i>Lengkap</span>'
                                    : '<span class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>Kurang ' . e($kurangKunci) . ' kunci</span>' ?>
                            </div>
                        </td>
                        <td class="text-center">
                            <?php if ($jumlahHasil > 0): ?>
                                <span class="badge text-bg-info" title="Jumlah hasil ujian"><?= e($jumlahHasil) ?></span>
                            <?php else: ?>
                                <span class="text-secondary">0</span>
                            <?php endif; ?>
                        </td>
                        <td><?= status_badge((string) $row['ujian_status']) ?></td>
                        <td class="text-end">
                            <div class="table-actions">
                                <a href="<?= e(url('admin/kunci-jawaban/index.php?ujian_id=' . (int) $row['ujian_id'] . '&paket_id=' . (int) $row['id'])) ?>"
                                   class="btn <?= $kurangKunci > 0 && !$kunciTerkunci ? 'btn-primary' : 'btn-outline-primary' ?>"
                                   title="<?= $kunciTerkunci ? 'Lihat kunci (terkunci)' : 'Isi kunci jawaban' ?>">
                                    <i class="bi <?= $kunciTerkunci ? 'bi-lock' : 'bi-key' ?>"></i>
                                    Kunci
                                </a>
                                <button type="button" class="btn btn-outline-primary"
                                        data-bs-toggle="modal" data-bs-target="#modalPaket"
                                        data-record="<?= e(json_encode($record, JSON_UNESCAPED_UNICODE)) ?>"
                                        data-modal-title="Ubah Paket Soal" title="Ubah">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="post" action="<?= e(url('admin/paket-soal/index.php')) ?>"
                                      data-confirm="Hapus paket &quot;<?= e($row['kode_paket']) ?>&quot; dari ujian &quot;<?= e($row['ujian_nama']) ?>&quot;? Kunci jawaban paket ini ikut terhapus."
                                      data-confirm-title="Hapus Paket Soal">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger"
                                            <?= $jumlahHasil > 0 ? 'disabled' : '' ?>
                                            title="<?= $jumlahHasil > 0 ? 'Tidak dapat dihapus karena sudah memiliki hasil ujian' : 'Hapus' ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($openModal !== ''): ?>
    <div data-auto-modal="#modalPaket" class="d-none" aria-hidden="true"></div>
<?php endif; ?>

<div class="modal fade" id="modalPaket" tabindex="-1" aria-labelledby="modalPaketLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= e(url('admin/paket-soal/index.php')) ?>" data-crud-form>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= e(crud_value($formData, 'id')) ?>">

                <div class="modal-header">
                    <h5 class="modal-title" id="modalPaketLabel" data-modal-title>
                        <?= $openModal === 'edit' ? 'Ubah Paket Soal' : 'Tambah Paket Soal' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>

                <div class="modal-body">
                    <div data-server-error><?= crud_errors_html($errors) ?></div>

                    <div class="mb-3">
                        <label for="paketUjian" class="form-label">Ujian <span class="text-danger">*</span></label>
                        <select class="form-select" id="paketUjian" name="ujian_id" required>
                            <option value="">Pilih ujian</option>
                            <?php foreach ($exams as $exam): ?>
                                <option value="<?= (int) $exam['id'] ?>"
                                    <?= crud_selected(
                                        $formData !== [] ? crud_value($formData, 'ujian_id') : $defaultUjian,
                                        (int) $exam['id']
                                    ) ?>>
                                    <?= e($exam['nama']) ?> — <?= e($exam['mapel']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="paketKode" class="form-label">Kode Paket <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="paketKode" name="kode_paket" maxlength="10"
                               value="<?= e(crud_value($formData, 'kode_paket')) ?>" required
                               placeholder="Contoh: A" autocomplete="off" style="text-transform:uppercase;">
                        <div class="form-text">Huruf, angka, titik, garis bawah, atau tanda hubung. Unik per ujian.</div>
                    </div>

                    <div>
                        <label for="paketKeterangan" class="form-label">Keterangan</label>
                        <input type="text" class="form-control" id="paketKeterangan" name="keterangan" maxlength="100"
                               value="<?= e(crud_value($formData, 'keterangan')) ?>"
                               placeholder="Contoh: Paket soal cadangan (opsional)">
                    </div>

                    <div class="alert alert-light border small mt-3 mb-0">
                        <i class="bi bi-info-circle me-1 text-primary"></i>
                        Setiap paket harus memiliki kunci jawaban lengkap sebelum ujian dapat diaktifkan.
                        Gunakan tombol <strong>Kunci</strong> pada daftar paket untuk mengisi kunci.
                        Paket yang sudah memiliki hasil ujian tidak dapat dihapus.
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
