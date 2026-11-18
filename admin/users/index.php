<?php
declare(strict_types=1);

/**
 * SIKILAT - Manajemen pengguna admin/guru.
 * Hanya administrator yang dapat mengakses modul ini.
 */

require_once __DIR__ . '/../../config/app.php';

require_role(['admin']);

$currentUser = auth_user();
$currentId = (int) ($currentUser['id'] ?? 0);

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
        $nama = crud_text(post('nama'), 100);
        $username = crud_text(post('username'), 50);
        $email = mb_strtolower(crud_text(post('email'), 150));
        $role = (string) post('role', 'guru');
        $isActive = post('is_active') === '1' ? 1 : 0;
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $passwordConfirm = isset($_POST['password_confirm']) && is_string($_POST['password_confirm'])
            ? $_POST['password_confirm']
            : '';

        $formData = [
            'id'        => $id,
            'nama'      => $nama,
            'username'  => $username,
            'email'     => $email,
            'role'      => $role,
            'is_active' => $isActive,
        ];

        if (mb_strlen($nama) < 3 || mb_strlen($nama) > 100) {
            $errors[] = 'Nama lengkap harus 3 sampai 100 karakter.';
        }

        if (preg_match('/^[A-Za-z0-9._-]{4,50}$/', $username) !== 1) {
            $errors[] = 'Username harus 4-50 karakter dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.';
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Format email tidak valid.';
        }

        if (!in_array($role, ['admin', 'guru'], true)) {
            $errors[] = 'Role pengguna tidak valid.';
        }

        $existingUser = null;
        if ($id > 0) {
            $existing = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $existing->execute(['id' => $id]);
            $existingUser = $existing->fetch();
            if ($existingUser === false) {
                $errors[] = 'Data pengguna tidak ditemukan.';
                $existingUser = null;
            }
        }

        if ($id === $currentId) {
            if ($role !== 'admin') {
                $errors[] = 'Anda tidak dapat mengubah role akun sendiri.';
            }
            if ($isActive !== 1) {
                $errors[] = 'Anda tidak dapat menonaktifkan akun sendiri.';
            }
        }

        if (
            $existingUser !== null
            && $existingUser['role'] === 'admin'
            && (int) $existingUser['is_active'] === 1
            && ($role !== 'admin' || $isActive !== 1)
            && !crud_other_active_admin_exists($id)
        ) {
            $errors[] = 'Minimal harus ada satu administrator aktif. Akun ini tidak dapat dinonaktifkan atau diturunkan role-nya.';
        }

        if ($password !== '') {
            if (mb_strlen($password) < 8) {
                $errors[] = 'Password minimal 8 karakter.';
            } elseif (preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/[0-9]/', $password) !== 1) {
                $errors[] = 'Password harus mengandung huruf dan angka.';
            }

            if ($password !== $passwordConfirm) {
                $errors[] = 'Konfirmasi password tidak sama.';
            }
        } elseif ($id === 0) {
            $errors[] = 'Password wajib diisi untuk pengguna baru.';
        }

        if ($errors === []) {
            $duplicate = db()->prepare(
                'SELECT id, username, email FROM users
                 WHERE (username = :username OR email = :email) AND id <> :id
                 LIMIT 1'
            );
            $duplicate->execute([
                'username' => $username,
                'email'    => $email,
                'id'       => $id,
            ]);
            $existing = $duplicate->fetch();

            if ($existing !== false) {
                if (strcasecmp((string) $existing['username'], $username) === 0) {
                    $errors[] = 'Username sudah digunakan.';
                }
                if ($email !== '' && strcasecmp((string) ($existing['email'] ?? ''), $email) === 0) {
                    $errors[] = 'Email sudah digunakan.';
                }
                if ($errors === []) {
                    $errors[] = 'Username atau email sudah digunakan.';
                }
            }
        }

        if ($errors === []) {
            try {
                if ($id > 0) {
                    if ($password !== '') {
                        $stmt = db()->prepare(
                            'UPDATE users
                             SET nama = :nama, username = :username, email = :email, role = :role,
                                 is_active = :is_active, password = :password
                             WHERE id = :id'
                        );
                        $stmt->execute([
                            'nama'      => $nama,
                            'username'  => $username,
                            'email'     => $email !== '' ? $email : null,
                            'role'      => $role,
                            'is_active' => $isActive,
                            'password'  => password_hash($password, PASSWORD_DEFAULT),
                            'id'        => $id,
                        ]);
                    } else {
                        $stmt = db()->prepare(
                            'UPDATE users
                             SET nama = :nama, username = :username, email = :email, role = :role,
                                 is_active = :is_active
                             WHERE id = :id'
                        );
                        $stmt->execute([
                            'nama'      => $nama,
                            'username'  => $username,
                            'email'     => $email !== '' ? $email : null,
                            'role'      => $role,
                            'is_active' => $isActive,
                            'id'        => $id,
                        ]);
                    }

                    log_aktivitas('users', 'ubah', 'Mengubah pengguna: ' . $username);
                    flash('success', 'Data pengguna berhasil diperbarui.');
                } else {
                    $stmt = db()->prepare(
                        'INSERT INTO users (nama, username, email, password, role, is_active)
                         VALUES (:nama, :username, :email, :password, :role, :is_active)'
                    );
                    $stmt->execute([
                        'nama'      => $nama,
                        'username'  => $username,
                        'email'     => $email !== '' ? $email : null,
                        'password'  => password_hash($password, PASSWORD_DEFAULT),
                        'role'      => $role,
                        'is_active' => $isActive,
                    ]);
                    log_aktivitas('users', 'tambah', 'Menambah pengguna: ' . $username);
                    flash('success', 'Pengguna baru berhasil ditambahkan.');
                }

                redirect('admin/users/index.php');
            } catch (PDOException $exception) {
                error_log('[SIKILAT] Simpan pengguna gagal: ' . $exception->getMessage());
                $errors[] = $exception->getCode() === '23000'
                    ? 'Username atau email sudah digunakan.'
                    : 'Data pengguna gagal disimpan. Silakan coba lagi.';
            }
        }

        if ($errors !== []) {
            $openModal = $id > 0 ? 'edit' : 'create';
        }
    } elseif ($action === 'delete') {
        $id = (int) post('id', 0);

        $stmt = db()->prepare('SELECT id, nama, username, role, is_active FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $target = $stmt->fetch();

        if ($target === false) {
            flash('error', 'Pengguna tidak ditemukan.');
            redirect('admin/users/index.php');
        }

        if ($id === $currentId) {
            flash('error', 'Anda tidak dapat menghapus akun yang sedang digunakan.');
            redirect('admin/users/index.php');
        }

        if (
            $target['role'] === 'admin'
            && (int) $target['is_active'] === 1
            && !crud_other_active_admin_exists($id)
        ) {
            flash('error', 'Akun administrator aktif terakhir tidak dapat dihapus.');
            redirect('admin/users/index.php');
        }

        $jumlahUjian = crud_user_has_exams($id);
        if ($jumlahUjian > 0) {
            flash(
                'error',
                'Pengguna "' . $target['nama'] . '" tidak dapat dihapus karena masih memiliki '
                . $jumlahUjian . ' ujian. Nonaktifkan akun atau pindahkan kepemilikan ujian terlebih dahulu.'
            );
            redirect('admin/users/index.php');
        }

        try {
            $delete = db()->prepare('DELETE FROM users WHERE id = :id');
            $delete->execute(['id' => $id]);
            log_aktivitas('users', 'hapus', 'Menghapus pengguna: ' . $target['username']);
            flash('success', 'Pengguna berhasil dihapus.');
        } catch (PDOException $exception) {
            error_log('[SIKILAT] Hapus pengguna gagal: ' . $exception->getMessage());
            flash('error', 'Pengguna tidak dapat dihapus karena masih memiliki relasi data.');
        }

        redirect('admin/users/index.php');
    }
}

/* ---------------------------------------------------------------------
 * Filter & data
 * ------------------------------------------------------------------ */
$roleFilter = (string) input('role', 'semua');
$statusFilter = (string) input('status', 'semua');

$where = [];
$params = [];

if (in_array($roleFilter, ['admin', 'guru'], true)) {
    $where[] = 'u.role = :role';
    $params['role'] = $roleFilter;
} else {
    $roleFilter = 'semua';
}

if ($statusFilter === 'aktif') {
    $where[] = 'u.is_active = 1';
} elseif ($statusFilter === 'nonaktif') {
    $where[] = 'u.is_active = 0';
} else {
    $statusFilter = 'semua';
}

$sql = 'SELECT u.*,
               (SELECT COUNT(*) FROM ujian j WHERE j.created_by = u.id) AS jumlah_ujian
        FROM users u';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY u.role ASC, u.nama ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$totalUsers = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalAdmin = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
$totalGuru = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'guru' AND is_active = 1")->fetchColumn();
$totalNonaktif = (int) db()->query('SELECT COUNT(*) FROM users WHERE is_active = 0')->fetchColumn();

$pageTitle = 'Manajemen Pengguna';
$pageSubtitle = 'Kelola akun administrator dan guru yang dapat mengakses panel.';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url('admin/dashboard/index.php')],
    ['label' => 'Manajemen Pengguna'],
];
$useDataTables = true;
$pageScripts = ['js/crud.js'];

require INCLUDES_PATH . '/header.php';
?>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-primary"><i class="bi bi-people"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Total Pengguna</div>
                    <div class="stat-value"><?= e($totalUsers) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-success"><i class="bi bi-shield-check"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Admin Aktif</div>
                    <div class="stat-value"><?= e($totalAdmin) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-info"><i class="bi bi-person-workspace"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Guru Aktif</div>
                    <div class="stat-value"><?= e($totalGuru) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <span class="stat-icon stat-icon-warning"><i class="bi bi-person-dash"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Nonaktif</div>
                    <div class="stat-value"><?= e($totalNonaktif) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span><i class="bi bi-person-badge me-1 text-primary"></i> Daftar Pengguna</span>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalUser"
                data-modal-title="Tambah Pengguna">
            <i class="bi bi-person-plus me-1"></i> Tambah Pengguna
        </button>
    </div>

    <div class="card-body border-bottom">
        <form method="get" action="<?= e(url('admin/users/index.php')) ?>" class="filter-bar" data-auto-submit>
            <div>
                <label class="form-label" for="filterRoleUser">Role</label>
                <select class="form-select form-select-sm" id="filterRoleUser" name="role">
                    <option value="semua"<?= crud_selected($roleFilter, 'semua') ?>>Semua role</option>
                    <option value="admin"<?= crud_selected($roleFilter, 'admin') ?>>Administrator</option>
                    <option value="guru"<?= crud_selected($roleFilter, 'guru') ?>>Guru</option>
                </select>
            </div>
            <div>
                <label class="form-label" for="filterStatusUser">Status</label>
                <select class="form-select form-select-sm" id="filterStatusUser" name="status">
                    <option value="semua"<?= crud_selected($statusFilter, 'semua') ?>>Semua status</option>
                    <option value="aktif"<?= crud_selected($statusFilter, 'aktif') ?>>Aktif</option>
                    <option value="nonaktif"<?= crud_selected($statusFilter, 'nonaktif') ?>>Nonaktif</option>
                </select>
            </div>
            <div class="pb-1">
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <a href="<?= e(url('admin/users/index.php')) ?>" class="btn btn-sm btn-light border">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                </a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle" data-datatable>
            <thead>
                <tr>
                    <th>Pengguna</th>
                    <th>Kontak</th>
                    <th style="width:130px;">Role</th>
                    <th class="text-center" style="width:80px;">Ujian</th>
                    <th style="width:105px;">Status</th>
                    <th style="width:140px;">Login Terakhir</th>
                    <th class="text-end" style="width:110px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $row): ?>
                    <?php
                    $isSelf = (int) $row['id'] === $currentId;
                    $jumlahUjian = (int) $row['jumlah_ujian'];
                    $record = [
                        'id'        => (int) $row['id'],
                        'nama'      => (string) $row['nama'],
                        'username'  => (string) $row['username'],
                        'email'     => (string) ($row['email'] ?? ''),
                        'role'      => (string) $row['role'],
                        'is_active' => (int) $row['is_active'],
                    ];
                    ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="avatar-circle"><?= e(initials((string) $row['nama'])) ?></span>
                                <div class="min-w-0">
                                    <div class="table-title">
                                        <?= e($row['nama']) ?>
                                        <?php if ($isSelf): ?>
                                            <span class="badge text-bg-light border ms-1">Anda</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="table-subtext">@<?= e($row['username']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="small">
                            <?= $row['email'] !== null && $row['email'] !== ''
                                ? e($row['email'])
                                : '<span class="text-secondary">Belum diisi</span>' ?>
                        </td>
                        <td><?= crud_role_badge((string) $row['role']) ?></td>
                        <td class="text-center">
                            <?php if ($jumlahUjian > 0): ?>
                                <span class="badge text-bg-light border" title="Ujian yang dibuat pengguna ini">
                                    <?= e($jumlahUjian) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-secondary">0</span>
                            <?php endif; ?>
                        </td>
                        <td><?= crud_active_badge((int) $row['is_active']) ?></td>
                        <td class="small text-secondary">
                            <?= $row['last_login'] !== null
                                ? e(format_tanggal((string) $row['last_login'], true))
                                : 'Belum pernah' ?>
                        </td>
                        <td class="text-end">
                            <div class="table-actions">
                                <button type="button" class="btn btn-outline-primary"
                                        data-bs-toggle="modal" data-bs-target="#modalUser"
                                        data-record="<?= e(json_encode($record, JSON_UNESCAPED_UNICODE)) ?>"
                                        data-modal-title="Ubah Pengguna" title="Ubah">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="post" action="<?= e(url('admin/users/index.php')) ?>"
                                      data-confirm="Hapus pengguna &quot;<?= e($row['nama']) ?>&quot;? Tindakan ini tidak dapat dibatalkan."
                                      data-confirm-title="Hapus Pengguna">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger"
                                            <?= $isSelf || $jumlahUjian > 0 ? 'disabled' : '' ?>
                                            title="<?= $isSelf
                                                ? 'Tidak dapat menghapus akun sendiri'
                                                : ($jumlahUjian > 0 ? 'Tidak dapat dihapus karena memiliki ujian' : 'Hapus') ?>">
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
    <div data-auto-modal="#modalUser" class="d-none" aria-hidden="true"></div>
<?php endif; ?>

<div class="modal fade" id="modalUser" tabindex="-1" aria-labelledby="modalUserLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="post" action="<?= e(url('admin/users/index.php')) ?>" data-crud-form>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= e(crud_value($formData, 'id')) ?>">

                <div class="modal-header">
                    <h5 class="modal-title" id="modalUserLabel" data-modal-title>
                        <?= $openModal === 'edit' ? 'Ubah Pengguna' : 'Tambah Pengguna' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>

                <div class="modal-body">
                    <div data-server-error><?= crud_errors_html($errors) ?></div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="userNama" class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="userNama" name="nama" maxlength="100"
                                   value="<?= e(crud_value($formData, 'nama')) ?>" required
                                   placeholder="Nama lengkap pengguna">
                        </div>
                        <div class="col-md-6">
                            <label for="userUsername" class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="userUsername" name="username" maxlength="50"
                                   value="<?= e(crud_value($formData, 'username')) ?>" required
                                   placeholder="Contoh: guru.math" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label for="userEmail" class="form-label">Email</label>
                            <input type="email" class="form-control" id="userEmail" name="email" maxlength="150"
                                   value="<?= e(crud_value($formData, 'email')) ?>"
                                   placeholder="nama@sekolah.sch.id" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label for="userRole" class="form-label">Role <span class="text-danger">*</span></label>
                            <select class="form-select" id="userRole" name="role" required>
                                <option value="guru"<?= crud_selected($formData === [] ? 'guru' : crud_value($formData, 'role', 'guru'), 'guru') ?>>Guru</option>
                                <option value="admin"<?= crud_selected(crud_value($formData, 'role'), 'admin') ?>>Administrator</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="userPassword" class="form-label">
                                Password <span class="text-danger" data-password-required><?= $openModal === 'edit' ? '' : '*' ?></span>
                            </label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="userPassword" name="password"
                                       autocomplete="new-password" placeholder="<?= $openModal === 'edit' ? 'Kosongkan bila tidak diubah' : 'Minimal 8 karakter' ?>">
                                <button type="button" class="btn btn-outline-secondary password-toggle"
                                        data-toggle-password data-target="#userPassword" aria-label="Tampilkan password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="form-text">Minimal 8 karakter, mengandung huruf dan angka.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="userPasswordConfirm" class="form-label">Konfirmasi Password</label>
                            <input type="password" class="form-control" id="userPasswordConfirm" name="password_confirm"
                                   autocomplete="new-password" placeholder="Ulangi password baru">
                        </div>
                        <div class="col-md-6">
                            <label for="userStatus" class="form-label">Status</label>
                            <select class="form-select" id="userStatus" name="is_active">
                                <option value="1"<?= crud_selected($formData === [] ? '1' : crud_value($formData, 'is_active', '1'), '1') ?>>Aktif</option>
                                <option value="0"<?= crud_selected($formData === [] ? '1' : crud_value($formData, 'is_active', '1'), '0') ?>>Nonaktif</option>
                            </select>
                        </div>
                    </div>

                    <div class="alert alert-light border small mt-3 mb-0">
                        <i class="bi bi-info-circle me-1 text-primary"></i>
                        Akun administrator terakhir tidak dapat dinonaktifkan atau dihapus. Akun yang memiliki ujian
                        juga tidak dapat dihapus agar relasi data tetap aman.
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
