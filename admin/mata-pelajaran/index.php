<?php
declare(strict_types=1);

/**
 * SIKILAT - Master data mata pelajaran.
 * Admin dapat menambah, mengubah, dan menghapus. Guru hanya dapat melihat.
 */

require_once __DIR__ . '/../../config/app.php';

require_auth();

$canManage = is_admin();
if (is_post() && !$canManage) {
    render_error_page(403, 'Akses Ditolak', 'Hanya administrator yang dapat mengubah mata pelajaran.');
}

$errors = [];
$formData = [];
$openModal = '';

/* ---------------------------------------------------------------------
 * Proses POST
 * ------------------------------------------------------------------ */
if (is_post()) {
    require_csrf();
    $action = (string) post('action', '');

    if ($action === 'save') {
        $id = (int) post('id', 0);
        $kode = strtoupper(crud_text(post('kode'), 20));
        $nama = crud_text(post('nama'), 100);
        $deskripsi = crud_text(post('deskripsi'), 1000);
        $isActive = post('is_active') === '1' ? 1 : 0;

        $formData = [
            'id'         => $id,
            'kode'       => $kode,
            'nama'       => $nama,
            'deskripsi'  => $deskripsi,
            'is_active'  => $isActive,
        ];

        if ($kode === '') {
            $errors[] = 'Kode mata pelajaran wajib diisi.';
        } elseif (preg_match('/^[A-Z0-9._-]{1,20}$/', $kode) !== 1) {
            $errors[] = 'Kode hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung (maksimal 20 karakter).';
        }

        if (mb_strlen($nama) < 2) {
            $errors[] = 'Nama mata pelajaran minimal 2 karakter.';
        }

        if ($id > 0) {
            $check = db()->prepare('SELECT id FROM mata_pelajaran WHERE id = :id LIMIT 1');
            $check->execute(['id' => $id]);
            if ($check->fetch() === false) {
                $errors[] = 'Data mata pelajaran tidak ditemukan.';
            }
        }

        if ($errors === []) {
            $duplicate = db()->prepare(
                'SELECT kode, nama FROM mata_pelajaran
                 WHERE (kode = :kode OR nama = :nama) AND id <> :id
                 LIMIT 1'
            );
            $duplicate->execute(['kode' => $kode, 'nama' => $nama, 'id' => $id]);
            $existing = $duplicate->fetch();

            if ($existing !== false) {
                if (strcasecmp((string) $existing['kode'], $kode) === 0) {
                    $errors[] = 'Kode mata pelajaran sudah digunakan.';
                }
                if (strcasecmp((string) $existing['nama'], $nama) === 0) {
                    $errors[] = 'Nama mata pelajaran sudah digunakan.';
                }
                if ($errors === []) {
                    $errors[] = 'Kode atau nama mata pelajaran sudah digunakan.';
                }
            }
        }

        if ($errors === []) {
            try {
                if ($id > 0) {
                    $stmt = db()->prepare(
                        'UPDATE mata_pelajaran
                         SET kode = :kode, nama = :nama, deskripsi = :deskripsi, is_active = :is_active
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        'kode'      => $kode,
                        'nama'      => $nama,
                        'deskripsi' => $deskripsi !== '' ? $deskripsi : null,
                        'is_active' => $isActive,
                        'id'        => $id,
                    ]);
                    log_aktivitas('mata_pelajaran', 'ubah', 'Mengubah mata pelajaran: ' . $nama);
                    flash('success', 'Mata pelajaran berhasil diperbarui.');
                } else {
                    $stmt = db()->prepare(
                        'INSERT INTO mata_pelajaran (kode, nama, deskripsi, is_active)
                         VALUES (:kode, :nama, :deskripsi, :is_active)'
                    );
                    $stmt->execute([
                        'kode'      => $kode,
                        'nama'      => $nama,
                        'deskripsi' => $deskripsi !== '' ? $deskripsi : null,
                        'is_active' => $isActive,
                    ]);
                    log_aktivitas('mata_pelajaran', 'tambah', 'Menambah mata pelajaran: ' . $nama);
                    flash('success', 'Mata pelajaran berhasil ditambahkan.');
                }

                redirect('admin/mata-pelajaran/index.php');
            } catch (PDOException $exception) {
                error_log('[SIKILAT] Simpan mata pelajaran gagal: ' . $exception->getMessage());
                $errors[] = $exception->getCode() === '23000'
                    ? 'Kode atau nama mata pelajaran sudah digunakan.'
                    : 'Data gagal disimpan. Silakan coba lagi.';
            }
        }

        if ($errors !== []) {
            $openModal = $id > 0 ? 'edit' : 'create';
        }
    } elseif ($action === 'delete') {
        $id = (int) post('id', 0);
        $stmt = db()->prepare('SELECT id, nama FROM mata_pelajaran WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $mapel = $stmt->fetch();

        if ($mapel === false) {
            flash('error', 'Mata pelajaran tidak ditemukan.');
            redirect('admin/mata-pelajaran/index.php');
        }

        $used = db()->prepare('SELECT COUNT(*) FROM ujian WHERE mata_pelajaran_id = :id');
        $used->execute(['id' => $id]);
        $jumlahUjian = (int) $used->fetchColumn();

        if ($jumlahUjian > 0) {
            flash(
                'error',
                'Mata pelajaran "' . $mapel['nama'] . '" tidak dapat dihapus karena masih dipakai oleh '
                . $jumlahUjian . ' ujian.'
            );
            redirect('admin/mata-pelajaran/index.php');
        }

        try {
            $delete = db()->prepare('DELETE FROM mata_pelajaran WHERE id = :id');
            $delete->execute(['id' => $id]);
            log_aktivitas('mata_pelajaran', 'hapus', 'Menghapus mata pelajaran: ' . $mapel['nama']);
            flash('success', 'Mata pelajaran berhasil dihapus.');
        } catch (PDOException $exception) {
            error_log('[SIKILAT] Hapus mata pelajaran gagal: ' . $exception->getMessage());
            flash('error', 'Mata pelajaran tidak dapat dihapus karena masih memiliki relasi data.');
        }

        redirect('admin/mata-pelajaran/index.php');
    }
}

/* ---------------------------------------------------------------------
 * Data & filter
 * ------------------------------------------------------------------ */
$statusFilter = (string) input('status', 'semua');
$where = [];
$params = [];

if ($statusFilter === 'aktif') {
    $where[] = 'mp.is_active = 1';
} elseif ($statusFilter === 'nonaktif') {
    $where[] = 'mp.is_active = 0';
} else {
    $statusFilter = 'semua';
}

$sql = 'SELECT mp.*,
               (SELECT COUNT(*) FROM ujian u WHERE u.mata_pelajaran_id = mp.id) AS jumlah_ujian
        FROM mata_pelajaran mp';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY mp.nama ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$mapelList = $stmt->fetchAll();

$totalMapel = (int) db()->query('SELECT COUNT(*) FROM mata_pelajaran')->fetchColumn();
$totalAktif = (int) db()->query('SELECT COUNT(*) FROM mata_pelajaran WHERE is_active = 1')->fetchColumn();
$totalDipakai = (int) db()->query('SELECT COUNT(DISTINCT mata_pelajaran_id) FROM ujian')->fetchColumn();

$pageTitle = 'Mata Pelajaran';
$pageSubtitle = 'Kelola daftar mata pelajaran yang digunakan pada ujian.';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url('admin/dashboard/index.php')],
    ['label' => 'Mata Pelajaran'],
];
$useDataTables = true;
$pageScripts = ['js/crud.js'];

require INCLUDES_PATH . '/header.php';
?>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-primary"><i class="bi bi-book"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Total Mata Pelajaran</div>
                    <div class="stat-value"><?= e($totalMapel) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-success"><i class="bi bi-check2-circle"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Aktif</div>
                    <div class="stat-value"><?= e($totalAktif) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-info"><i class="bi bi-journal-check"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Dipakai Ujian</div>
                    <div class="stat-value"><?= e($totalDipakai) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span><i class="bi bi-book me-1 text-primary"></i> Daftar Mata Pelajaran</span>
        <?php if ($canManage): ?>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalMapel"
                    data-modal-title="Tambah Mata Pelajaran">
                <i class="bi bi-plus-lg me-1"></i> Tambah Mata Pelajaran
            </button>
        <?php else: ?>
            <span class="badge text-bg-light border fw-normal"><i class="bi bi-eye me-1"></i>Hanya lihat</span>
        <?php endif; ?>
    </div>

    <div class="card-body border-bottom">
        <form method="get" action="<?= e(url('admin/mata-pelajaran/index.php')) ?>" class="filter-bar" data-auto-submit>
            <div>
                <label class="form-label" for="filterStatusMapel">Status</label>
                <select class="form-select form-select-sm" id="filterStatusMapel" name="status">
                    <option value="semua"<?= crud_selected($statusFilter, 'semua') ?>>Semua status</option>
                    <option value="aktif"<?= crud_selected($statusFilter, 'aktif') ?>>Aktif</option>
                    <option value="nonaktif"<?= crud_selected($statusFilter, 'nonaktif') ?>>Nonaktif</option>
                </select>
            </div>
            <div class="pb-1">
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <a href="<?= e(url('admin/mata-pelajaran/index.php')) ?>" class="btn btn-sm btn-light border">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                </a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle" data-datatable>
            <thead>
                <tr>
                    <th style="width:90px;">Kode</th>
                    <th>Nama Mata Pelajaran</th>
                    <th>Deskripsi</th>
                    <th class="text-center" style="width:80px;">Ujian</th>
                    <th style="width:110px;">Status</th>
                    <th class="text-end" style="width:110px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($mapelList as $row): ?>
                    <?php
                    $record = [
                        'id'        => (int) $row['id'],
                        'kode'      => (string) $row['kode'],
                        'nama'      => (string) $row['nama'],
                        'deskripsi' => (string) ($row['deskripsi'] ?? ''),
                        'is_active' => (int) $row['is_active'],
                    ];
                    $jumlahUjian = (int) $row['jumlah_ujian'];
                    ?>
                    <tr>
                        <td><span class="badge badge-soft-primary"><?= e($row['kode']) ?></span></td>
                        <td>
                            <div class="table-title"><?= e($row['nama']) ?></div>
                            <div class="table-subtext">Diperbarui <?= e(format_tanggal($row['updated_at'], true)) ?></div>
                        </td>
                        <td class="small text-secondary">
                            <?= $row['deskripsi'] !== null && $row['deskripsi'] !== ''
                                ? e(str_limit((string) $row['deskripsi'], 80))
                                : '<span class="text-secondary">-</span>' ?>
                        </td>
                        <td class="text-center">
                            <?php if ($jumlahUjian > 0): ?>
                                <a href="<?= e(url('admin/ujian/index.php?mapel_id=' . (int) $row['id'])) ?>"
                                   class="badge text-bg-light border text-decoration-none"
                                   title="Lihat ujian dengan mata pelajaran ini">
                                    <?= e($jumlahUjian) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-secondary">0</span>
                            <?php endif; ?>
                        </td>
                        <td><?= crud_active_badge((int) $row['is_active']) ?></td>
                        <td class="text-end">
                            <?php if ($canManage): ?>
                                <div class="table-actions">
                                    <button type="button" class="btn btn-outline-primary"
                                            data-bs-toggle="modal" data-bs-target="#modalMapel"
                                            data-record="<?= e(json_encode($record, JSON_UNESCAPED_UNICODE)) ?>"
                                            data-modal-title="Ubah Mata Pelajaran" title="Ubah">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="post" action="<?= e(url('admin/mata-pelajaran/index.php')) ?>"
                                          data-confirm="Hapus mata pelajaran &quot;<?= e($row['nama']) ?>&quot;? Tindakan ini tidak dapat dibatalkan."
                                          data-confirm-title="Hapus Mata Pelajaran">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger"
                                                <?= $jumlahUjian > 0 ? 'disabled' : '' ?>
                                                title="<?= $jumlahUjian > 0 ? 'Tidak dapat dihapus karena masih dipakai ujian' : 'Hapus' ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <span class="text-secondary small">Hanya lihat</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($openModal !== ''): ?>
    <div data-auto-modal="#modalMapel" class="d-none" aria-hidden="true"></div>
<?php endif; ?>

<div class="modal fade" id="modalMapel" tabindex="-1" aria-labelledby="modalMapelLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= e(url('admin/mata-pelajaran/index.php')) ?>" data-crud-form>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= e(crud_value($formData, 'id')) ?>">

                <div class="modal-header">
                    <h5 class="modal-title" id="modalMapelLabel" data-modal-title>
                        <?= $openModal === 'edit' ? 'Ubah Mata Pelajaran' : 'Tambah Mata Pelajaran' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>

                <div class="modal-body">
                    <div data-server-error><?= crud_errors_html($errors) ?></div>

                    <div class="mb-3">
                        <label for="mapelKode" class="form-label">Kode <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="mapelKode" name="kode" maxlength="20"
                               value="<?= e(crud_value($formData, 'kode')) ?>" required
                               placeholder="Contoh: MTK" autocomplete="off">
                        <div class="form-text">Huruf, angka, titik, garis bawah, atau tanda hubung.</div>
                    </div>

                    <div class="mb-3">
                        <label for="mapelNama" class="form-label">Nama Mata Pelajaran <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="mapelNama" name="nama" maxlength="100"
                               value="<?= e(crud_value($formData, 'nama')) ?>" required
                               placeholder="Contoh: Matematika">
                    </div>

                    <div class="mb-3">
                        <label for="mapelDeskripsi" class="form-label">Deskripsi</label>
                        <textarea class="form-control" id="mapelDeskripsi" name="deskripsi" rows="3" maxlength="1000"
                                  placeholder="Keterangan singkat (opsional)"><?= e(crud_value($formData, 'deskripsi')) ?></textarea>
                    </div>

                    <div>
                        <label for="mapelStatus" class="form-label">Status</label>
                        <select class="form-select" id="mapelStatus" name="is_active">
                            <option value="1"<?= crud_selected($formData === [] ? '1' : crud_value($formData, 'is_active', '1'), '1') ?>>Aktif</option>
                            <option value="0"<?= crud_selected($formData === [] ? '1' : crud_value($formData, 'is_active', '1'), '0') ?>>Nonaktif</option>
                        </select>
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
