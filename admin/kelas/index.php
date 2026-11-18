<?php
declare(strict_types=1);

/**
 * SIKILAT - Master data kelas.
 * Admin dapat mengelola kelas; guru hanya dapat melihat.
 */

require_once __DIR__ . '/../../config/app.php';

require_auth();

$canManage = is_admin();
if (is_post() && !$canManage) {
    render_error_page(403, 'Akses Ditolak', 'Hanya administrator yang dapat mengubah data kelas.');
}

$errors = [];
$formData = [];
$openModal = '';
$tingkatOptions = crud_tingkat_options();

/* ---------------------------------------------------------------------
 * Proses POST
 * ------------------------------------------------------------------ */
if (is_post()) {
    require_csrf();
    $action = (string) post('action', '');

    if ($action === 'save') {
        $id = (int) post('id', 0);
        $nama = crud_text(post('nama'), 50);
        $tingkat = strtoupper(crud_text(post('tingkat'), 5));
        $tahunAjaran = crud_text(post('tahun_ajaran'), 9);
        $isActive = post('is_active') === '1' ? 1 : 0;

        $formData = [
            'id'           => $id,
            'nama'         => $nama,
            'tingkat'      => $tingkat,
            'tahun_ajaran' => $tahunAjaran,
            'is_active'    => $isActive,
        ];

        if ($nama === '') {
            $errors[] = 'Nama kelas wajib diisi.';
        }

        if (!in_array($tingkat, $tingkatOptions, true)) {
            $errors[] = 'Tingkat kelas tidak valid.';
        }

        if (preg_match('/^(\d{4})\/(\d{4})$/', $tahunAjaran, $tahunMatch) !== 1) {
            $errors[] = 'Tahun ajaran harus berformat YYYY/YYYY, contoh 2026/2027.';
        } elseif ((int) $tahunMatch[2] !== (int) $tahunMatch[1] + 1) {
            $errors[] = 'Tahun ajaran harus berurutan, contoh 2026/2027.';
        }

        if ($id > 0) {
            $check = db()->prepare('SELECT id FROM kelas WHERE id = :id LIMIT 1');
            $check->execute(['id' => $id]);
            if ($check->fetch() === false) {
                $errors[] = 'Data kelas tidak ditemukan.';
            }
        }

        if ($errors === []) {
            $duplicate = db()->prepare(
                'SELECT id FROM kelas
                 WHERE nama = :nama AND tahun_ajaran = :tahun_ajaran AND id <> :id
                 LIMIT 1'
            );
            $duplicate->execute(['nama' => $nama, 'tahun_ajaran' => $tahunAjaran, 'id' => $id]);
            if ($duplicate->fetch() !== false) {
                $errors[] = 'Kelas dengan nama dan tahun ajaran tersebut sudah ada.';
            }
        }

        if ($errors === []) {
            try {
                if ($id > 0) {
                    $stmt = db()->prepare(
                        'UPDATE kelas
                         SET nama = :nama, tingkat = :tingkat, tahun_ajaran = :tahun_ajaran, is_active = :is_active
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        'nama'         => $nama,
                        'tingkat'      => $tingkat,
                        'tahun_ajaran' => $tahunAjaran,
                        'is_active'    => $isActive,
                        'id'           => $id,
                    ]);
                    log_aktivitas('kelas', 'ubah', 'Mengubah kelas: ' . $nama . ' (' . $tahunAjaran . ')');
                    flash('success', 'Data kelas berhasil diperbarui.');
                } else {
                    $stmt = db()->prepare(
                        'INSERT INTO kelas (nama, tingkat, tahun_ajaran, is_active)
                         VALUES (:nama, :tingkat, :tahun_ajaran, :is_active)'
                    );
                    $stmt->execute([
                        'nama'         => $nama,
                        'tingkat'      => $tingkat,
                        'tahun_ajaran' => $tahunAjaran,
                        'is_active'    => $isActive,
                    ]);
                    log_aktivitas('kelas', 'tambah', 'Menambah kelas: ' . $nama . ' (' . $tahunAjaran . ')');
                    flash('success', 'Data kelas berhasil ditambahkan.');
                }

                redirect('admin/kelas/index.php');
            } catch (PDOException $exception) {
                error_log('[SIKILAT] Simpan kelas gagal: ' . $exception->getMessage());
                $errors[] = $exception->getCode() === '23000'
                    ? 'Kelas dengan nama dan tahun ajaran tersebut sudah ada.'
                    : 'Data gagal disimpan. Silakan coba lagi.';
            }
        }

        if ($errors !== []) {
            $openModal = $id > 0 ? 'edit' : 'create';
        }
    } elseif ($action === 'delete') {
        $id = (int) post('id', 0);
        $stmt = db()->prepare('SELECT id, nama, tahun_ajaran FROM kelas WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $kelas = $stmt->fetch();

        if ($kelas === false) {
            flash('error', 'Data kelas tidak ditemukan.');
            redirect('admin/kelas/index.php');
        }

        $relasi = db()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM ujian_kelas WHERE kelas_id = :id_ujian) AS jumlah_ujian,
                (SELECT COUNT(*) FROM hasil_ujian WHERE kelas_id = :id_hasil) AS jumlah_hasil'
        );
        $relasi->execute(['id_ujian' => $id, 'id_hasil' => $id]);
        $jumlah = $relasi->fetch();
        $jumlahUjian = $jumlah !== false ? (int) $jumlah['jumlah_ujian'] : 0;
        $jumlahHasil = $jumlah !== false ? (int) $jumlah['jumlah_hasil'] : 0;

        if ($jumlahUjian > 0 || $jumlahHasil > 0) {
            $alasan = [];
            if ($jumlahUjian > 0) {
                $alasan[] = $jumlahUjian . ' ujian';
            }
            if ($jumlahHasil > 0) {
                $alasan[] = $jumlahHasil . ' hasil ujian';
            }
            flash(
                'error',
                'Kelas "' . $kelas['nama'] . '" tidak dapat dihapus karena masih terhubung dengan '
                . implode(' dan ', $alasan) . '.'
            );
            redirect('admin/kelas/index.php');
        }

        try {
            $delete = db()->prepare('DELETE FROM kelas WHERE id = :id');
            $delete->execute(['id' => $id]);
            log_aktivitas('kelas', 'hapus', 'Menghapus kelas: ' . $kelas['nama'] . ' (' . $kelas['tahun_ajaran'] . ')');
            flash('success', 'Data kelas berhasil dihapus.');
        } catch (PDOException $exception) {
            error_log('[SIKILAT] Hapus kelas gagal: ' . $exception->getMessage());
            flash('error', 'Data kelas tidak dapat dihapus karena masih memiliki relasi.');
        }

        redirect('admin/kelas/index.php');
    }
}

/* ---------------------------------------------------------------------
 * Data, filter, dan statistik
 * ------------------------------------------------------------------ */
$tingkatFilter = strtoupper((string) input('tingkat', ''));
$statusFilter = (string) input('status', 'semua');
$tahunFilter = (string) input('tahun', '');

$where = [];
$params = [];

if (in_array($tingkatFilter, $tingkatOptions, true)) {
    $where[] = 'k.tingkat = :tingkat';
    $params['tingkat'] = $tingkatFilter;
} else {
    $tingkatFilter = '';
}

if ($statusFilter === 'aktif') {
    $where[] = 'k.is_active = 1';
} elseif ($statusFilter === 'nonaktif') {
    $where[] = 'k.is_active = 0';
} else {
    $statusFilter = 'semua';
}

if ($tahunFilter !== '') {
    $where[] = 'k.tahun_ajaran = :tahun';
    $params['tahun'] = $tahunFilter;
}

$sql = 'SELECT k.*,
               (SELECT COUNT(*) FROM ujian_kelas uk WHERE uk.kelas_id = k.id) AS jumlah_ujian,
               (SELECT COUNT(*) FROM hasil_ujian h WHERE h.kelas_id = k.id) AS jumlah_hasil
        FROM kelas k';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY k.tahun_ajaran DESC, k.tingkat ASC, k.nama ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$kelasList = $stmt->fetchAll();

$tahunList = db()->query('SELECT DISTINCT tahun_ajaran FROM kelas ORDER BY tahun_ajaran DESC')->fetchAll();
$totalKelas = (int) db()->query('SELECT COUNT(*) FROM kelas')->fetchColumn();
$totalAktif = (int) db()->query('SELECT COUNT(*) FROM kelas WHERE is_active = 1')->fetchColumn();
$totalRelasi = (int) db()->query('SELECT COUNT(DISTINCT kelas_id) FROM ujian_kelas')->fetchColumn();

$pageTitle = 'Kelas';
$pageSubtitle = 'Kelola kelas peserta ujian per tahun ajaran.';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url('admin/dashboard/index.php')],
    ['label' => 'Kelas'],
];
$useDataTables = true;
$pageScripts = ['js/crud.js'];

require INCLUDES_PATH . '/header.php';
?>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-primary"><i class="bi bi-people"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Total Kelas</div>
                    <div class="stat-value"><?= e($totalKelas) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-success"><i class="bi bi-check2-circle"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Kelas Aktif</div>
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
                    <div class="stat-label">Terdaftar di Ujian</div>
                    <div class="stat-value"><?= e($totalRelasi) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span><i class="bi bi-people me-1 text-primary"></i> Daftar Kelas</span>
        <?php if ($canManage): ?>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalKelas"
                    data-modal-title="Tambah Kelas">
                <i class="bi bi-plus-lg me-1"></i> Tambah Kelas
            </button>
        <?php else: ?>
            <span class="badge text-bg-light border fw-normal"><i class="bi bi-eye me-1"></i>Hanya lihat</span>
        <?php endif; ?>
    </div>

    <div class="card-body border-bottom">
        <form method="get" action="<?= e(url('admin/kelas/index.php')) ?>" class="filter-bar" data-auto-submit>
            <div>
                <label class="form-label" for="filterTingkatKelas">Tingkat</label>
                <select class="form-select form-select-sm" id="filterTingkatKelas" name="tingkat">
                    <option value="">Semua tingkat</option>
                    <?php foreach ($tingkatOptions as $tingkat): ?>
                        <option value="<?= e($tingkat) ?>"<?= crud_selected($tingkatFilter, $tingkat) ?>><?= e($tingkat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="filterTahunKelas">Tahun Ajaran</label>
                <select class="form-select form-select-sm" id="filterTahunKelas" name="tahun">
                    <option value="">Semua tahun</option>
                    <?php foreach ($tahunList as $tahun): ?>
                        <option value="<?= e($tahun['tahun_ajaran']) ?>"<?= crud_selected($tahunFilter, $tahun['tahun_ajaran']) ?>>
                            <?= e($tahun['tahun_ajaran']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="filterStatusKelas">Status</label>
                <select class="form-select form-select-sm" id="filterStatusKelas" name="status">
                    <option value="semua"<?= crud_selected($statusFilter, 'semua') ?>>Semua status</option>
                    <option value="aktif"<?= crud_selected($statusFilter, 'aktif') ?>>Aktif</option>
                    <option value="nonaktif"<?= crud_selected($statusFilter, 'nonaktif') ?>>Nonaktif</option>
                </select>
            </div>
            <div class="pb-1">
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <a href="<?= e(url('admin/kelas/index.php')) ?>" class="btn btn-sm btn-light border">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                </a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle" data-datatable>
            <thead>
                <tr>
                    <th>Nama Kelas</th>
                    <th style="width:90px;">Tingkat</th>
                    <th style="width:130px;">Tahun Ajaran</th>
                    <th class="text-center" style="width:80px;">Ujian</th>
                    <th style="width:110px;">Status</th>
                    <th class="text-end" style="width:110px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($kelasList as $row): ?>
                    <?php
                    $record = [
                        'id'           => (int) $row['id'],
                        'nama'         => (string) $row['nama'],
                        'tingkat'      => (string) $row['tingkat'],
                        'tahun_ajaran' => (string) $row['tahun_ajaran'],
                        'is_active'    => (int) $row['is_active'],
                    ];
                    $jumlahUjian = (int) $row['jumlah_ujian'];
                    $jumlahHasil = (int) $row['jumlah_hasil'];
                    $punyaRelasi = $jumlahUjian > 0 || $jumlahHasil > 0;
                    ?>
                    <tr>
                        <td>
                            <div class="table-title"><?= e($row['nama']) ?></div>
                            <div class="table-subtext">
                                <?= $jumlahHasil > 0
                                    ? e($jumlahHasil) . ' hasil ujian'
                                    : 'Belum ada hasil ujian' ?>
                            </div>
                        </td>
                        <td><span class="badge badge-soft-primary"><?= e($row['tingkat']) ?></span></td>
                        <td><?= e($row['tahun_ajaran']) ?></td>
                        <td class="text-center">
                            <?php if ($jumlahUjian > 0): ?>
                                <span class="badge text-bg-light border" title="Jumlah ujian yang memakai kelas ini">
                                    <?= e($jumlahUjian) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-secondary">0</span>
                            <?php endif; ?>
                        </td>
                        <td><?= crud_active_badge((int) $row['is_active']) ?></td>
                        <td class="text-end">
                            <?php if ($canManage): ?>
                                <div class="table-actions">
                                    <button type="button" class="btn btn-outline-primary"
                                            data-bs-toggle="modal" data-bs-target="#modalKelas"
                                            data-record="<?= e(json_encode($record, JSON_UNESCAPED_UNICODE)) ?>"
                                            data-modal-title="Ubah Kelas" title="Ubah">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="post" action="<?= e(url('admin/kelas/index.php')) ?>"
                                          data-confirm="Hapus kelas &quot;<?= e($row['nama']) ?>&quot; tahun <?= e($row['tahun_ajaran']) ?>? Tindakan ini tidak dapat dibatalkan."
                                          data-confirm-title="Hapus Kelas">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger"
                                                <?= $punyaRelasi ? 'disabled' : '' ?>
                                                title="<?= $punyaRelasi ? 'Tidak dapat dihapus karena masih terhubung dengan ujian atau hasil' : 'Hapus' ?>">
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
    <div data-auto-modal="#modalKelas" class="d-none" aria-hidden="true"></div>
<?php endif; ?>

<div class="modal fade" id="modalKelas" tabindex="-1" aria-labelledby="modalKelasLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= e(url('admin/kelas/index.php')) ?>" data-crud-form>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= e(crud_value($formData, 'id')) ?>">

                <div class="modal-header">
                    <h5 class="modal-title" id="modalKelasLabel" data-modal-title>
                        <?= $openModal === 'edit' ? 'Ubah Kelas' : 'Tambah Kelas' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>

                <div class="modal-body">
                    <div data-server-error><?= crud_errors_html($errors) ?></div>

                    <div class="mb-3">
                        <label for="kelasNama" class="form-label">Nama Kelas <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="kelasNama" name="nama" maxlength="50"
                               value="<?= e(crud_value($formData, 'nama')) ?>" required
                               placeholder="Contoh: X IPA 1">
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-5">
                            <label for="kelasTingkat" class="form-label">Tingkat <span class="text-danger">*</span></label>
                            <select class="form-select" id="kelasTingkat" name="tingkat" required>
                                <option value="">Pilih tingkat</option>
                                <?php foreach ($tingkatOptions as $tingkat): ?>
                                    <option value="<?= e($tingkat) ?>"<?= crud_selected(crud_value($formData, 'tingkat'), $tingkat) ?>><?= e($tingkat) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-7">
                            <label for="kelasTahun" class="form-label">Tahun Ajaran <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="kelasTahun" name="tahun_ajaran" maxlength="9"
                                   value="<?= e(crud_value($formData, 'tahun_ajaran')) ?>" required
                                   placeholder="2026/2027" pattern="\d{4}/\d{4}" inputmode="numeric">
                            <div class="form-text">Format YYYY/YYYY dan berurutan.</div>
                        </div>
                    </div>

                    <div>
                        <label for="kelasStatus" class="form-label">Status</label>
                        <select class="form-select" id="kelasStatus" name="is_active">
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
