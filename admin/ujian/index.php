<?php
declare(strict_types=1);

/**
 * SIKILAT - Data ujian dan relasi kelas peserta.
 * Admin dapat mengelola semua ujian. Guru hanya melihat dan mengelola
 * ujian yang ia buat sendiri.
 */

require_once __DIR__ . '/../../config/app.php';

require_auth();

$currentUser = auth_user();
$currentId = (int) $currentUser['id'];
$isAdmin = $currentUser['role'] === 'admin';
$statusOptions = crud_status_options();

$errors = [];
$formData = [];
$openModal = '';

/**
 * Ambil satu ujian beserta pemeriksaan hak akses.
 *
 * @return array<string, mixed>|null
 */
function ujian_fetch_accessible(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM ujian WHERE id = :id LIMIT 1');
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
        $nama = crud_text(post('nama'), 150);
        $mapelId = (int) post('mata_pelajaran_id', 0);
        $jumlahSoal = (int) post('jumlah_soal', 0);
        $kkmRaw = str_replace(',', '.', (string) post('kkm', ''));
        $tanggal = crud_text(post('tanggal_ujian'), 10);
        $waktuMulaiRaw = crud_text(post('waktu_mulai'), 8);
        $waktuSelesaiRaw = crud_text(post('waktu_selesai'), 8);
        $status = (string) post('status', 'draft');
        $deskripsi = crud_text(post('deskripsi'), 2000);
        $kelasIds = crud_int_list(post('kelas_ids'));

        $waktuMulai = crud_valid_time($waktuMulaiRaw) ? crud_normalize_time($waktuMulaiRaw) : $waktuMulaiRaw;
        $waktuSelesai = crud_valid_time($waktuSelesaiRaw) ? crud_normalize_time($waktuSelesaiRaw) : $waktuSelesaiRaw;

        $formData = [
            'id'                => $id,
            'nama'              => $nama,
            'mata_pelajaran_id' => $mapelId,
            'jumlah_soal'       => $jumlahSoal,
            'kkm'               => $kkmRaw,
            'tanggal_ujian'     => $tanggal,
            'waktu_mulai'       => $waktuMulai,
            'waktu_selesai'     => $waktuSelesai,
            'status'            => $status,
            'deskripsi'         => $deskripsi,
            'kelas_ids'         => $kelasIds,
        ];

        if ($id > 0) {
            $existing = ujian_fetch_accessible($id);
            if ($existing === null) {
                $errors[] = 'Data ujian tidak ditemukan.';
            }
        }

        if (mb_strlen($nama) < 3) {
            $errors[] = 'Nama ujian minimal 3 karakter.';
        }

        $mapel = null;
        if ($mapelId <= 0) {
            $errors[] = 'Mata pelajaran wajib dipilih.';
        } else {
            $mapelStmt = db()->prepare('SELECT id, nama FROM mata_pelajaran WHERE id = :id LIMIT 1');
            $mapelStmt->execute(['id' => $mapelId]);
            $mapel = $mapelStmt->fetch();
            if ($mapel === false) {
                $errors[] = 'Mata pelajaran yang dipilih tidak ditemukan.';
            }
        }

        if ($jumlahSoal < 1 || $jumlahSoal > 200) {
            $errors[] = 'Jumlah soal harus antara 1 dan 200.';
        }

        if (!is_numeric($kkmRaw) || (float) $kkmRaw < 0 || (float) $kkmRaw > 100) {
            $errors[] = 'KKM harus berupa angka antara 0 dan 100.';
        }

        if (!crud_valid_date($tanggal)) {
            $errors[] = 'Tanggal ujian tidak valid.';
        }

        if (!crud_valid_time($waktuMulaiRaw)) {
            $errors[] = 'Waktu mulai tidak valid.';
        }

        if (!crud_valid_time($waktuSelesaiRaw)) {
            $errors[] = 'Waktu selesai tidak valid.';
        }

        if (
            crud_valid_time($waktuMulaiRaw)
            && crud_valid_time($waktuSelesaiRaw)
            && strcmp($waktuSelesai, $waktuMulai) <= 0
        ) {
            $errors[] = 'Waktu selesai harus lebih besar dari waktu mulai.';
        }

        if (!array_key_exists($status, $statusOptions)) {
            $errors[] = 'Status ujian tidak valid.';
        }

        if ($kelasIds === []) {
            $errors[] = 'Pilih minimal satu kelas peserta.';
        } else {
            $validKelas = [];
            $kelasStmt = db()->prepare('SELECT id FROM kelas WHERE id = :id LIMIT 1');
            foreach ($kelasIds as $kelasId) {
                $kelasStmt->execute(['id' => $kelasId]);
                if ($kelasStmt->fetch() !== false) {
                    $validKelas[] = $kelasId;
                }
            }

            if (count($validKelas) !== count($kelasIds)) {
                $errors[] = 'Ada kelas peserta yang tidak ditemukan.';
            }
        }

        // Aktivasi hanya boleh bila setiap paket memiliki kunci lengkap.
        if ($errors === [] && $status === 'aktif') {
            $wasActive = $id > 0
                && isset($existing)
                && is_array($existing)
                && ($existing['status'] ?? '') === 'aktif';

            if (!$wasActive) {
                if ($id === 0) {
                    $errors[] = 'Ujian baru belum memiliki paket soal dan kunci jawaban. '
                        . 'Simpan sebagai draft, tambahkan paket, isi kunci jawaban, lalu aktifkan ujian.';
                } else {
                    $keyStatus = crud_exam_key_status($id, $jumlahSoal);
                    $problem = crud_exam_key_problem($keyStatus);
                    if ($problem !== '') {
                        $hint = empty($keyStatus['has_package'])
                            ? ' Tambahkan paket soal melalui menu Paket Soal.'
                            : ' Lengkapi melalui menu Kunci Jawaban.';
                        $errors[] = 'Ujian belum dapat diaktifkan. ' . $problem . $hint;
                    }
                }
            }
        }

        if ($errors === []) {
            $pdo = db();
            try {
                $pdo->beginTransaction();

                if ($id > 0) {
                    $stmt = $pdo->prepare(
                        'UPDATE ujian
                         SET mata_pelajaran_id = :mapel_id, nama = :nama, deskripsi = :deskripsi,
                             jumlah_soal = :jumlah_soal, kkm = :kkm, tanggal_ujian = :tanggal,
                             waktu_mulai = :waktu_mulai, waktu_selesai = :waktu_selesai, status = :status
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        'mapel_id'       => $mapelId,
                        'nama'           => $nama,
                        'deskripsi'      => $deskripsi !== '' ? $deskripsi : null,
                        'jumlah_soal'    => $jumlahSoal,
                        'kkm'            => round((float) $kkmRaw, 2),
                        'tanggal'        => $tanggal,
                        'waktu_mulai'    => $waktuMulai,
                        'waktu_selesai'  => $waktuSelesai,
                        'status'         => $status,
                        'id'             => $id,
                    ]);
                    $ujianId = $id;
                    $pesan = 'Data ujian berhasil diperbarui.';
                    $aksi = 'ubah';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO ujian
                            (mata_pelajaran_id, created_by, nama, deskripsi, jumlah_soal, kkm,
                             tanggal_ujian, waktu_mulai, waktu_selesai, status)
                         VALUES
                            (:mapel_id, :created_by, :nama, :deskripsi, :jumlah_soal, :kkm,
                             :tanggal, :waktu_mulai, :waktu_selesai, :status)'
                    );
                    $stmt->execute([
                        'mapel_id'      => $mapelId,
                        'created_by'    => $currentId,
                        'nama'          => $nama,
                        'deskripsi'     => $deskripsi !== '' ? $deskripsi : null,
                        'jumlah_soal'   => $jumlahSoal,
                        'kkm'           => round((float) $kkmRaw, 2),
                        'tanggal'       => $tanggal,
                        'waktu_mulai'   => $waktuMulai,
                        'waktu_selesai' => $waktuSelesai,
                        'status'        => $status,
                    ]);
                    $ujianId = (int) $pdo->lastInsertId();
                    $pesan = 'Ujian baru berhasil ditambahkan.';
                    $aksi = 'tambah';
                }

                $deleteKelas = $pdo->prepare('DELETE FROM ujian_kelas WHERE ujian_id = :ujian_id');
                $deleteKelas->execute(['ujian_id' => $ujianId]);

                $insertKelas = $pdo->prepare(
                    'INSERT INTO ujian_kelas (ujian_id, kelas_id) VALUES (:ujian_id, :kelas_id)'
                );
                foreach ($kelasIds as $kelasId) {
                    $insertKelas->execute(['ujian_id' => $ujianId, 'kelas_id' => $kelasId]);
                }

                $pdo->commit();

                log_aktivitas('ujian', $aksi, $pesan . ' ' . $nama);
                flash('success', $pesan);

                redirect('admin/ujian/index.php');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[SIKILAT] Simpan ujian gagal: ' . $exception->getMessage());
                $errors[] = 'Data ujian gagal disimpan. Silakan coba lagi.';
            }
        }

        if ($errors !== []) {
            $openModal = $id > 0 ? 'edit' : 'create';
        }
    } elseif ($action === 'delete') {
        $id = (int) post('id', 0);
        $ujian = ujian_fetch_accessible($id);

        if ($ujian === null) {
            flash('error', 'Data ujian tidak ditemukan.');
            redirect('admin/ujian/index.php');
        }

        $hasil = db()->prepare('SELECT COUNT(*) FROM hasil_ujian WHERE ujian_id = :id');
        $hasil->execute(['id' => $id]);
        $jumlahHasil = (int) $hasil->fetchColumn();

        if ($jumlahHasil > 0) {
            flash(
                'error',
                'Ujian "' . $ujian['nama'] . '" tidak dapat dihapus karena sudah memiliki '
                . $jumlahHasil . ' hasil ujian.'
            );
            redirect('admin/ujian/index.php');
        }

        try {
            $delete = db()->prepare('DELETE FROM ujian WHERE id = :id');
            $delete->execute(['id' => $id]);
            log_aktivitas('ujian', 'hapus', 'Menghapus ujian: ' . $ujian['nama']);
            flash('success', 'Ujian berhasil dihapus beserta paket soal dan relasi kelasnya.');
        } catch (PDOException $exception) {
            error_log('[SIKILAT] Hapus ujian gagal: ' . $exception->getMessage());
            flash('error', 'Ujian tidak dapat dihapus karena masih memiliki relasi data.');
        }

        redirect('admin/ujian/index.php');
    } elseif ($action === 'status') {
        $id = (int) post('id', 0);
        $status = (string) post('status', '');
        $ujian = ujian_fetch_accessible($id);

        if ($ujian === null) {
            flash('error', 'Data ujian tidak ditemukan.');
            redirect('admin/ujian/index.php');
        }

        if (!array_key_exists($status, $statusOptions)) {
            flash('error', 'Status ujian tidak valid.');
            redirect('admin/ujian/index.php');
        }

        // Cegah aktivasi bila paket belum ada atau kunci jawaban belum lengkap.
        if ($status === 'aktif' && ($ujian['status'] ?? '') !== 'aktif') {
            $keyStatus = crud_exam_key_status($id);
            $problem = crud_exam_key_problem($keyStatus);
            if ($problem !== '') {
                $hint = empty($keyStatus['has_package'])
                    ? ' Tambahkan paket soal melalui menu Paket Soal.'
                    : ' Lengkapi melalui menu Kunci Jawaban terlebih dahulu.';
                flash('error', 'Status ujian tidak diubah. ' . $problem . $hint);
                redirect('admin/ujian/index.php');
            }
        }

        try {
            $update = db()->prepare('UPDATE ujian SET status = :status WHERE id = :id');
            $update->execute(['status' => $status, 'id' => $id]);

            log_aktivitas(
                'ujian',
                'ubah_status',
                'Mengubah status ujian "' . $ujian['nama'] . '" menjadi ' . $status . '.'
            );

            flash('success', 'Status ujian berhasil diubah menjadi ' . $statusOptions[$status] . '.');
        } catch (PDOException $exception) {
            error_log('[SIKILAT] Ubah status ujian gagal: ' . $exception->getMessage());
            flash('error', 'Status ujian gagal diubah. Silakan coba lagi.');
        }

        redirect('admin/ujian/index.php');
    }
}

/* ---------------------------------------------------------------------
 * Filter, data, dan statistik
 * ------------------------------------------------------------------ */
$statusFilter = (string) input('status', 'semua');
$mapelFilter = (int) input('mapel_id', 0);

$where = [];
$params = [];

if (!$isAdmin) {
    $where[] = 'u.created_by = :scope_user';
    $params['scope_user'] = $currentId;
}

if (array_key_exists($statusFilter, $statusOptions)) {
    $where[] = 'u.status = :status';
    $params['status'] = $statusFilter;
} else {
    $statusFilter = 'semua';
}

if ($mapelFilter > 0) {
    $where[] = 'u.mata_pelajaran_id = :mapel_id';
    $params['mapel_id'] = $mapelFilter;
}

$sql = 'SELECT u.*, mp.nama AS mapel, mp.kode AS mapel_kode,
               creator.nama AS pembuat,
               (SELECT COUNT(*) FROM ujian_kelas uk WHERE uk.ujian_id = u.id) AS jumlah_kelas,
               (SELECT COUNT(*) FROM paket_soal ps WHERE ps.ujian_id = u.id) AS jumlah_paket,
               (SELECT COUNT(*) FROM hasil_ujian h WHERE h.ujian_id = u.id) AS jumlah_hasil,
               (SELECT GROUP_CONCAT(k.nama ORDER BY k.nama SEPARATOR ", ")
                  FROM ujian_kelas uk
                  INNER JOIN kelas k ON k.id = uk.kelas_id
                 WHERE uk.ujian_id = u.id) AS kelas_nama
        FROM ujian u
        INNER JOIN mata_pelajaran mp ON mp.id = u.mata_pelajaran_id
        LEFT JOIN users creator ON creator.id = u.created_by';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY u.tanggal_ujian DESC, u.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$ujianList = $stmt->fetchAll();

$statsWhere = $isAdmin ? '' : ' WHERE created_by = :user_id';
$statsStmt = db()->prepare(
    'SELECT status, COUNT(*) AS total FROM ujian' . $statsWhere . ' GROUP BY status'
);
$statsStmt->execute($isAdmin ? [] : ['user_id' => $currentId]);
$statusTotals = ['draft' => 0, 'aktif' => 0, 'selesai' => 0];
foreach ($statsStmt->fetchAll() as $row) {
    $key = (string) $row['status'];
    if (array_key_exists($key, $statusTotals)) {
        $statusTotals[$key] = (int) $row['total'];
    }
}
$totalUjian = array_sum($statusTotals);

$mapelOptions = db()->query(
    'SELECT id, kode, nama, is_active FROM mata_pelajaran ORDER BY is_active DESC, nama ASC'
)->fetchAll();
$kelasOptions = db()->query(
    'SELECT id, nama, tingkat, tahun_ajaran, is_active FROM kelas ORDER BY tahun_ajaran DESC, tingkat ASC, nama ASC'
)->fetchAll();

$defaultMapel = $mapelFilter > 0 ? $mapelFilter : 0;
foreach ($mapelOptions as $mapelOption) {
    if ((int) $mapelOption['id'] === $defaultMapel) {
        $defaultMapel = (int) $mapelOption['id'];
        break;
    }
}

$pageTitle = 'Data Ujian';
$pageSubtitle = $isAdmin
    ? 'Kelola ujian, jadwal, kelas peserta, dan status pelaksanaan.'
    : 'Kelola ujian yang Anda buat.';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url('admin/dashboard/index.php')],
    ['label' => 'Data Ujian'],
];
$useDataTables = true;
$pageScripts = ['js/crud.js'];

require INCLUDES_PATH . '/header.php';
?>

<?php if (!$isAdmin): ?>
    <div class="alert alert-info d-flex gap-2 align-items-start mb-3">
        <i class="bi bi-info-circle-fill mt-1"></i>
        <div>
            Sebagai guru, Anda hanya melihat ujian yang Anda buat. Hubungi administrator untuk perubahan
            data master mata pelajaran atau kelas.
        </div>
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-primary"><i class="bi bi-journal-check"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Total Ujian</div>
                    <div class="stat-value"><?= e($totalUjian) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-warning"><i class="bi bi-pencil-square"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Draft</div>
                    <div class="stat-value"><?= e($statusTotals['draft']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-success"><i class="bi bi-broadcast"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Aktif</div>
                    <div class="stat-value"><?= e($statusTotals['aktif']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-info"><i class="bi bi-check2-all"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Selesai</div>
                    <div class="stat-value"><?= e($statusTotals['selesai']) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span><i class="bi bi-journal-check me-1 text-primary"></i> Daftar Ujian</span>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalUjian"
                data-modal-title="Tambah Ujian">
            <i class="bi bi-plus-lg me-1"></i> Tambah Ujian
        </button>
    </div>

    <div class="card-body border-bottom">
        <form method="get" action="<?= e(url('admin/ujian/index.php')) ?>" class="filter-bar" data-auto-submit>
            <div>
                <label class="form-label" for="filterStatusUjian">Status</label>
                <select class="form-select form-select-sm" id="filterStatusUjian" name="status">
                    <option value="semua"<?= crud_selected($statusFilter, 'semua') ?>>Semua status</option>
                    <?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= crud_selected($statusFilter, $value) ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="filterMapelUjian">Mata Pelajaran</label>
                <select class="form-select form-select-sm" id="filterMapelUjian" name="mapel_id">
                    <option value="0">Semua mata pelajaran</option>
                    <?php foreach ($mapelOptions as $mapelOption): ?>
                        <option value="<?= (int) $mapelOption['id'] ?>"<?= crud_selected($mapelFilter, (int) $mapelOption['id']) ?>>
                            <?= e($mapelOption['nama']) ?><?= (int) $mapelOption['is_active'] === 1 ? '' : ' (Nonaktif)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="pb-1">
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <a href="<?= e(url('admin/ujian/index.php')) ?>" class="btn btn-sm btn-light border">
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
                    <th>Kelas Peserta</th>
                    <th>Jadwal</th>
                    <th class="text-center" style="width:95px;">Soal / KKM</th>
                    <th class="text-center" style="width:70px;">Paket</th>
                    <th class="text-center" style="width:75px;">Peserta</th>
                    <th style="width:180px;">Status</th>
                    <th class="text-end" style="width:140px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ujianList as $row): ?>
                    <?php
                    $record = [
                        'id'                => (int) $row['id'],
                        'nama'              => (string) $row['nama'],
                        'mata_pelajaran_id' => (int) $row['mata_pelajaran_id'],
                        'jumlah_soal'       => (int) $row['jumlah_soal'],
                        'kkm'               => (string) $row['kkm'],
                        'tanggal_ujian'     => (string) $row['tanggal_ujian'],
                        'waktu_mulai'       => substr((string) $row['waktu_mulai'], 0, 5),
                        'waktu_selesai'     => substr((string) $row['waktu_selesai'], 0, 5),
                        'status'            => (string) $row['status'],
                        'deskripsi'         => (string) ($row['deskripsi'] ?? ''),
                    ];
                    $kelasIdsStmt = db()->prepare('SELECT kelas_id FROM ujian_kelas WHERE ujian_id = :id ORDER BY kelas_id');
                    $kelasIdsStmt->execute(['id' => (int) $row['id']]);
                    $record['kelas_ids'] = array_map(
                        static fn (array $item): int => (int) $item['kelas_id'],
                        $kelasIdsStmt->fetchAll()
                    );
                    $jumlahHasil = (int) $row['jumlah_hasil'];
                    $jumlahPaket = (int) $row['jumlah_paket'];
                    ?>
                    <tr>
                        <td style="min-width:220px;">
                            <div class="table-title"><?= e($row['nama']) ?></div>
                            <div class="table-subtext">
                                <span class="badge badge-soft-primary me-1"><?= e($row['mapel_kode']) ?></span>
                                <?= e($row['mapel']) ?>
                                <?php if ($isAdmin && $row['pembuat'] !== null): ?>
                                    &middot; oleh <?= e($row['pembuat']) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="small" style="min-width:170px;">
                            <?= $row['kelas_nama'] !== null && $row['kelas_nama'] !== ''
                                ? e(str_limit((string) $row['kelas_nama'], 60))
                                : '<span class="text-secondary">Belum ada kelas</span>' ?>
                            <div class="table-subtext"><?= e($row['jumlah_kelas']) ?> kelas</div>
                        </td>
                        <td class="small text-nowrap">
                            <div class="fw-semibold"><?= e(format_tanggal((string) $row['tanggal_ujian'])) ?></div>
                            <div class="table-subtext">
                                <?= e(substr((string) $row['waktu_mulai'], 0, 5)) ?>–<?= e(substr((string) $row['waktu_selesai'], 0, 5)) ?> WIB
                            </div>
                        </td>
                        <td class="text-center small">
                            <div class="fw-semibold"><?= e($row['jumlah_soal']) ?></div>
                            <div class="table-subtext">KKM <?= e(format_nilai($row['kkm'])) ?></div>
                        </td>
                        <td class="text-center">
                            <a href="<?= e(url('admin/paket-soal/index.php?ujian_id=' . (int) $row['id'])) ?>"
                               class="badge <?= $jumlahPaket > 0 ? 'text-bg-primary' : 'text-bg-light border text-secondary' ?> text-decoration-none"
                               title="Kelola paket soal">
                                <?= e($jumlahPaket) ?>
                            </a>
                        </td>
                        <td class="text-center">
                            <?php if ($jumlahHasil > 0): ?>
                                <span class="badge text-bg-success" title="Jumlah hasil ujian"><?= e($jumlahHasil) ?></span>
                            <?php else: ?>
                                <span class="text-secondary">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= status_badge((string) $row['status']) ?>
                            <form method="post" action="<?= e(url('admin/ujian/index.php')) ?>"
                                  class="status-form mt-1"
                                  data-confirm="Ubah status ujian &quot;<?= e($row['nama']) ?>&quot;? Pastikan paket soal sudah siap."
                                  data-confirm-title="Ubah Status Ujian">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="status">
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <select class="form-select form-select-sm" name="status" aria-label="Status ujian">
                                    <?php foreach ($statusOptions as $value => $label): ?>
                                        <option value="<?= e($value) ?>"<?= crud_selected($row['status'], $value) ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-outline-primary" title="Simpan status">
                                    <i class="bi bi-check2"></i>
                                </button>
                            </form>
                        </td>
                        <td class="text-end">
                            <div class="table-actions">
                                <button type="button" class="btn btn-outline-primary"
                                        data-bs-toggle="modal" data-bs-target="#modalUjian"
                                        data-record="<?= e(json_encode($record, JSON_UNESCAPED_UNICODE)) ?>"
                                        data-modal-title="Ubah Ujian" title="Ubah">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <a href="<?= e(url('admin/paket-soal/index.php?ujian_id=' . (int) $row['id'])) ?>"
                                   class="btn btn-outline-secondary" title="Paket soal">
                                    <i class="bi bi-collection"></i>
                                </a>
                                <form method="post" action="<?= e(url('admin/ujian/index.php')) ?>"
                                      data-confirm="Hapus ujian &quot;<?= e($row['nama']) ?>&quot;? Paket soal dan relasi kelas akan ikut terhapus."
                                      data-confirm-title="Hapus Ujian">
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
    <div data-auto-modal="#modalUjian" class="d-none" aria-hidden="true"></div>
<?php endif; ?>

<div class="modal fade" id="modalUjian" tabindex="-1" aria-labelledby="modalUjianLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <form method="post" action="<?= e(url('admin/ujian/index.php')) ?>" data-crud-form>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= e(crud_value($formData, 'id')) ?>">

                <div class="modal-header">
                    <h5 class="modal-title" id="modalUjianLabel" data-modal-title>
                        <?= $openModal === 'edit' ? 'Ubah Ujian' : 'Tambah Ujian' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>

                <div class="modal-body">
                    <div data-server-error><?= crud_errors_html($errors) ?></div>

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label for="ujianNama" class="form-label">Nama Ujian <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="ujianNama" name="nama" maxlength="150"
                                   value="<?= e(crud_value($formData, 'nama')) ?>" required
                                   placeholder="Contoh: Penilaian Tengah Semester Ganjil">
                        </div>
                        <div class="col-md-4">
                            <label for="ujianMapel" class="form-label">Mata Pelajaran <span class="text-danger">*</span></label>
                            <select class="form-select" id="ujianMapel" name="mata_pelajaran_id" required>
                                <option value="">Pilih mata pelajaran</option>
                                <?php foreach ($mapelOptions as $mapelOption): ?>
                                    <option value="<?= (int) $mapelOption['id'] ?>"
                                        <?= crud_selected(
                                            $formData !== [] ? crud_value($formData, 'mata_pelajaran_id') : $defaultMapel,
                                            (int) $mapelOption['id']
                                        ) ?>>
                                        <?= e($mapelOption['nama']) ?><?= (int) $mapelOption['is_active'] === 1 ? '' : ' (Nonaktif)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="ujianJumlahSoal" class="form-label">Jumlah Soal <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="ujianJumlahSoal" name="jumlah_soal"
                                   min="1" max="200" required
                                   value="<?= e($formData !== [] ? crud_value($formData, 'jumlah_soal') : '30') ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="ujianKkm" class="form-label">KKM <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="ujianKkm" name="kkm"
                                   min="0" max="100" step="0.01" required
                                   value="<?= e($formData !== [] ? crud_value($formData, 'kkm') : setting('kkm_default', '75')) ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="ujianTanggal" class="form-label">Tanggal <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="ujianTanggal" name="tanggal_ujian" required
                                   value="<?= e($formData !== [] ? crud_value($formData, 'tanggal_ujian') : date('Y-m-d')) ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="ujianStatus" class="form-label">Status</label>
                            <select class="form-select" id="ujianStatus" name="status">
                                <?php foreach ($statusOptions as $value => $label): ?>
                                    <option value="<?= e($value) ?>"<?= crud_selected(
                                        $formData !== [] ? crud_value($formData, 'status', 'draft') : 'draft',
                                        $value
                                    ) ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="ujianMulai" class="form-label">Waktu Mulai <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="ujianMulai" name="waktu_mulai" required
                                   value="<?= e($formData !== [] ? substr(crud_value($formData, 'waktu_mulai'), 0, 5) : '08:00') ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="ujianSelesai" class="form-label">Waktu Selesai <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="ujianSelesai" name="waktu_selesai" required
                                   value="<?= e($formData !== [] ? substr(crud_value($formData, 'waktu_selesai'), 0, 5) : '10:00') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="ujianDeskripsi" class="form-label">Deskripsi</label>
                            <input type="text" class="form-control" id="ujianDeskripsi" name="deskripsi" maxlength="2000"
                                   value="<?= e(crud_value($formData, 'deskripsi')) ?>"
                                   placeholder="Keterangan singkat (opsional)">
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <div>
                            <div class="form-label mb-0">Kelas Peserta <span class="text-danger">*</span></div>
                            <div class="form-text mt-0">Pilih satu atau beberapa kelas yang mengikuti ujian ini.</div>
                        </div>
                        <button type="button" class="btn btn-sm btn-light border" data-check-all="#kelasUjianGrid">
                            <i class="bi bi-check2-square me-1"></i>Pilih semua
                        </button>
                    </div>

                    <?php if ($kelasOptions === []): ?>
                        <div class="alert alert-warning mb-0">
                            Belum ada kelas. Administrator harus menambahkan kelas terlebih dahulu.
                        </div>
                    <?php else: ?>
                        <?php
                        $selectedKelas = $formData !== []
                            ? crud_int_list($formData['kelas_ids'] ?? [])
                            : [];
                        ?>
                        <div class="class-check-grid" id="kelasUjianGrid">
                            <?php foreach ($kelasOptions as $kelasOption): ?>
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="kelas_ids[]"
                                           value="<?= (int) $kelasOption['id'] ?>"
                                           <?= crud_checked(in_array((int) $kelasOption['id'], $selectedKelas, true)) ?>>
                                    <span class="form-check-label small">
                                        <?= e($kelasOption['nama']) ?>
                                        <span class="d-block text-secondary" style="font-size:.72rem;">
                                            <?= e($kelasOption['tahun_ajaran']) ?><?= (int) $kelasOption['is_active'] === 1 ? '' : ' · nonaktif' ?>
                                        </span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="alert alert-light border small mt-3 mb-0">
                        <i class="bi bi-info-circle me-1 text-primary"></i>
                        Status <strong>Aktif</strong> sebaiknya diatur setelah paket soal tersedia. Ujian yang sudah
                        memiliki hasil tidak dapat dihapus.
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i> Simpan Ujian
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
