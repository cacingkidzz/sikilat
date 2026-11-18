<?php
declare(strict_types=1);

/**
 * SIKILAT - Halaman instalasi.
 *
 * Alur:
 *  1. Cek kebutuhan sistem.
 *  2. Buat database + impor database/db_sikilat.sql.
 *  3. Buat akun administrator pertama (password di-hash oleh PHP).
 *
 * Halaman ini otomatis terkunci setelah ada akun administrator.
 */

require_once __DIR__ . '/config/app.php';
require_once INCLUDES_PATH . '/installer.php';

$checks = installer_checks();
$checksOk = installer_required_ok($checks);

$serverPdo = null;
$serverVersion = null;
$serverError = null;
$dbExists = false;
$dbStatus = null;
$adminCount = 0;

try {
    $serverPdo = db_connect(DB_HOST, DB_PORT, DB_USER, DB_PASS);
    $serverVersion = (string) $serverPdo->query('SELECT VERSION()')->fetchColumn();
    $dbExists = installer_database_exists($serverPdo, DB_NAME);

    if ($dbExists) {
        $pdo = db_connect(DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME);
        $dbStatus = installer_table_status($pdo);
        $adminCount = $dbStatus['admin_count'];
    }
} catch (PDOException $exception) {
    $serverError = $exception->getMessage();
    error_log('[SIKILAT] Instalasi tidak dapat terhubung ke database: ' . $exception->getMessage());
}

$installed = $dbStatus !== null && $dbStatus['installed'];
$errors = [];

/* ---------------------------------------------------------------------
 * Proses form
 * ------------------------------------------------------------------ */
if (is_post()) {
    $action = (string) post('action', '');
    $token = post('_token') !== null ? (string) post('_token') : null;

    if (!csrf_verify($token)) {
        $errors[] = 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.';
    } elseif (!$checksOk) {
        $errors[] = 'Kebutuhan sistem wajib belum terpenuhi. Perbaiki dahulu sebelum melanjutkan.';
    } elseif ($action === 'install_db') {
        try {
            $importResult = installer_import_database();
            log_aktivitas(
                'instalasi',
                'impor_database',
                'Skema database berhasil diimpor (' . $importResult['executed'] . ' perintah, '
                . $importResult['tables'] . ' tabel).'
            );
            flash('success', 'Database ' . DB_NAME . ' berhasil dibuat dan skema berhasil diimpor.');
            redirect('install.php');
        } catch (Throwable $exception) {
            error_log('[SIKILAT] Impor database gagal: ' . $exception->getMessage());
            $errors[] = 'Impor database gagal: ' . $exception->getMessage();
        }
    } elseif ($action === 'create_admin') {
        $nama = (string) post('nama', '');
        $username = (string) post('username', '');
        $email = (string) post('email', '');
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $passwordConfirm = isset($_POST['password_confirm']) && is_string($_POST['password_confirm'])
            ? $_POST['password_confirm']
            : '';

        if (!$installed) {
            $errors[] = 'Skema database belum lengkap. Selesaikan langkah instalasi database terlebih dahulu.';
        } elseif ($adminCount > 0) {
            $errors[] = 'Akun administrator sudah ada. Halaman instalasi telah terkunci.';
        } else {
            $lengthNama = mb_strlen($nama);
            if ($lengthNama < 3 || $lengthNama > 100) {
                $errors[] = 'Nama lengkap harus 3 sampai 100 karakter.';
            }

            if (preg_match('/^[A-Za-z0-9._-]{4,50}$/', $username) !== 1) {
                $errors[] = 'Username harus 4-50 karakter dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.';
            }

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = 'Format email tidak valid.';
            }

            if (mb_strlen($password) < 8) {
                $errors[] = 'Password minimal 8 karakter.';
            } elseif (preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/[0-9]/', $password) !== 1) {
                $errors[] = 'Password harus mengandung huruf dan angka.';
            }

            if ($password !== $passwordConfirm) {
                $errors[] = 'Konfirmasi password tidak sama.';
            }

            if ($errors === []) {
                $pdo = null;
                try {
                    $pdo = db_connect(DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME);
                    $pdo->beginTransaction();

                    $adminCheck = (int) $pdo->query(
                        "SELECT COUNT(*) FROM users WHERE role = 'admin' FOR UPDATE"
                    )->fetchColumn();

                    if ($adminCheck > 0) {
                        $pdo->rollBack();
                        $errors[] = 'Akun administrator sudah ada. Halaman instalasi telah terkunci.';
                    } else {
                        $duplicate = $pdo->prepare(
                            'SELECT username, email FROM users WHERE username = :username OR (email IS NOT NULL AND email = :email) LIMIT 1'
                        );
                        $duplicate->execute([
                            'username' => $username,
                            'email'    => $email !== '' ? $email : null,
                        ]);
                        $existing = $duplicate->fetch();

                        if ($existing !== false) {
                            $pdo->rollBack();
                            $errors[] = 'Username atau email sudah digunakan.';
                        } else {
                            $hash = password_hash($password, PASSWORD_DEFAULT);
                            $insert = $pdo->prepare(
                                'INSERT INTO users (nama, username, email, password, role, is_active)
                                 VALUES (:nama, :username, :email, :password, :role, 1)'
                            );
                            $insert->execute([
                                'nama'     => $nama,
                                'username' => $username,
                                'email'    => $email !== '' ? $email : null,
                                'password' => $hash,
                                'role'     => 'admin',
                            ]);

                            $adminId = (int) $pdo->lastInsertId();
                            $pdo->commit();

                            log_aktivitas(
                                'instalasi',
                                'buat_admin',
                                'Akun administrator pertama dibuat: ' . $username,
                                $adminId
                            );

                            flash('success', 'Akun administrator berhasil dibuat. Silakan masuk menggunakan akun tersebut.');
                            redirect('install.php');
                        }
                    }
                } catch (Throwable $exception) {
                    if ($pdo instanceof PDO && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('[SIKILAT] Gagal membuat admin: ' . $exception->getMessage());
                    $errors[] = 'Akun administrator gagal dibuat. Periksa log aplikasi atau coba lagi.';
                }
            }
        }
    }
}

$flashes = get_flashes();

$step1Class = $checksOk ? 'is-done' : 'is-active';
$step2Class = $installed ? 'is-done' : ($checksOk ? 'is-active' : '');
$step3Class = $adminCount > 0 ? 'is-done' : ($installed ? 'is-active' : '');
$step4Class = $adminCount > 0 ? 'is-active' : '';

$formNama = (string) post('nama', '');
$formUsername = (string) post('username', '');
$formEmail = (string) post('email', '');
$appNameDisplay = setting('nama_aplikasi', APP_NAME);
$totalTabel = $dbStatus !== null ? count($dbStatus['tables']) : 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Instalasi | <?= e($appNameDisplay) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('img/logo-sikilat.svg')) ?>">
    <link rel="stylesheet" href="<?= e(asset('plugins/adminlte/css/adminlte.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('plugins/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="install-body">
<div class="install-wrapper">

    <header class="install-header">
        <img src="<?= e(asset('img/logo-sikilat.svg')) ?>" alt="Logo <?= e($appNameDisplay) ?>">
        <div>
            <h1 class="install-title">Instalasi <?= e($appNameDisplay) ?></h1>
            <p class="install-subtitle"><?= e(APP_DESCRIPTION) ?> &middot; v<?= e(APP_VERSION) ?></p>
        </div>
        <span class="badge text-bg-primary ms-auto d-none d-sm-inline">Panduan Awal</span>
    </header>

    <ol class="install-steps">
        <li class="<?= e($step1Class) ?>"><span class="step-number">1</span> Cek Sistem</li>
        <li class="<?= e($step2Class) ?>"><span class="step-number">2</span> Database</li>
        <li class="<?= e($step3Class) ?>"><span class="step-number">3</span> Akun Admin</li>
        <li class="<?= e($step4Class) ?>"><span class="step-number">4</span> Selesai</li>
    </ol>

    <?php foreach ($flashes as $flash): ?>
        <?php
        $alertMap = ['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'];
        $alertClass = $alertMap[$flash['type']] ?? 'info';
        ?>
        <div class="alert alert-<?= e($alertClass) ?> d-flex gap-2 align-items-start" role="alert">
            <i class="bi bi-check-circle-fill mt-1"></i>
            <div><?= e($flash['message']) ?></div>
        </div>
    <?php endforeach; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger" role="alert">
            <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Periksa kembali:</div>
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($adminCount > 0): ?>
        <div class="card mb-3">
            <div class="card-body p-4">
                <div class="d-flex gap-3 align-items-start">
                    <span class="stat-icon stat-icon-success"><i class="bi bi-shield-check"></i></span>
                    <div>
                        <h2 class="h5 fw-semibold mb-1">Instalasi selesai</h2>
                        <p class="text-secondary mb-3">
                            Aplikasi sudah terpasang dan memiliki akun administrator. Halaman instalasi
                            terkunci otomatis untuk mencegah pembuatan akun tambahan tanpa izin.
                        </p>
                        <div class="d-flex flex-column flex-sm-row gap-2">
                            <a href="<?= e(url('auth/login.php')) ?>" class="btn btn-primary">
                                <i class="bi bi-box-arrow-in-right me-1"></i> Masuk ke Panel
                            </a>
                            <a href="<?= e(url('siswa/index.php')) ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-people me-1"></i> Halaman Siswa
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-cpu me-1"></i> Kebutuhan Sistem</span>
                    <span class="badge text-bg-<?= $checksOk ? 'success' : 'danger' ?>">
                        <?= $checksOk ? 'Siap' : 'Perlu perbaikan' ?>
                    </span>
                </div>
                <div class="card-body">
                    <ul class="check-list">
                        <?php foreach ($checks as $check): ?>
                            <?php
                            if ($check['ok']) {
                                $iconClass = 'check-ok bi-check-circle-fill';
                                $statusText = 'OK';
                            } elseif ($check['wajib']) {
                                $iconClass = 'check-fail bi-x-circle-fill';
                                $statusText = 'Wajib';
                            } else {
                                $iconClass = 'check-warn bi-exclamation-circle-fill';
                                $statusText = 'Opsional';
                            }
                            ?>
                            <li>
                                <i class="bi <?= e($iconClass) ?> check-icon"></i>
                                <div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span><?= e($check['label']) ?></span>
                                        <span class="badge text-bg-light fw-normal"><?= e($statusText) ?></span>
                                    </div>
                                    <span class="check-info"><?= e($check['info']) ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-database me-1"></i> Status Database</span>
                    <?php if ($serverError !== null): ?>
                        <span class="badge text-bg-danger">Gagal</span>
                    <?php elseif ($installed): ?>
                        <span class="badge text-bg-success">Siap</span>
                    <?php else: ?>
                        <span class="badge text-bg-warning">Belum selesai</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($serverError !== null): ?>
                        <div class="alert alert-danger small" role="alert">
                            <div class="fw-semibold mb-1">Tidak dapat terhubung ke server MySQL.</div>
                            <div class="mb-2">
                                Pastikan MySQL sudah berjalan (XAMPP Control Panel &rarr; Start MySQL) dan
                                kredensial pada <code>config/database.php</code> sudah benar.
                            </div>
                            <details>
                                <summary class="small">Detail teknis</summary>
                                <code class="d-block text-break mt-1"><?= e($serverError) ?></code>
                            </details>
                        </div>
                    <?php else: ?>
                        <dl class="row small mb-3">
                            <dt class="col-4 text-secondary fw-normal">Host</dt>
                            <dd class="col-8 mb-1"><?= e(DB_HOST) ?>:<?= e(DB_PORT) ?></dd>
                            <dt class="col-4 text-secondary fw-normal">Database</dt>
                            <dd class="col-8 mb-1"><?= e(DB_NAME) ?></dd>
                            <dt class="col-4 text-secondary fw-normal">Pengguna</dt>
                            <dd class="col-8 mb-1"><?= e(DB_USER) ?></dd>
                            <dt class="col-4 text-secondary fw-normal">Server</dt>
                            <dd class="col-8 mb-0">MariaDB/MySQL <?= e($serverVersion ?? '-') ?></dd>
                        </dl>

                        <?php if (!$dbExists): ?>
                            <div class="alert alert-info small mb-3">
                                Database <strong><?= e(DB_NAME) ?></strong> belum ada. Klik tombol di bawah
                                untuk membuat database dan mengimpor seluruh tabel.
                            </div>
                        <?php elseif (!$installed): ?>
                            <div class="alert alert-warning small mb-3">
                                Database sudah ada, tetapi tabel belum lengkap (<?= e($totalTabel) ?> tabel terdeteksi).
                                Impor ulang akan <strong>menghapus dan membuat ulang</strong> seluruh tabel.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success small mb-3">
                                Seluruh <?= e(count(installer_required_tables())) ?> tabel tersedia dan siap digunakan.
                            </div>
                        <?php endif; ?>

                        <?php if (!$installed): ?>
                            <form method="post" action="<?= e(url('install.php')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="install_db">
                                <button type="submit" class="btn btn-primary w-100" <?= $checksOk ? '' : 'disabled' ?>>
                                    <i class="bi bi-database-add me-1"></i>
                                    <?= $dbExists ? 'Impor Ulang Skema Database' : 'Buat Database & Impor Skema' ?>
                                </button>
                            </form>
                            <?php if (!$checksOk): ?>
                                <p class="small text-danger mt-2 mb-0">
                                    Tombol dinonaktifkan sampai kebutuhan sistem wajib terpenuhi.
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>

                        <details class="mt-3">
                            <summary class="small text-secondary">Panduan impor manual (phpMyAdmin)</summary>
                            <ol class="small text-secondary mt-2 mb-0 ps-3">
                                <li>Buka <code>http://localhost/phpmyadmin</code>.</li>
                                <li>Pilih menu <strong>Impor</strong>, unggah <code>database/db_sikilat.sql</code>.</li>
                                <li>Klik <strong>Kirim</strong>, lalu muat ulang halaman ini.</li>
                                <li>Akun admin tetap dibuat melalui formulir di halaman ini.</li>
                            </ol>
                        </details>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($installed && $adminCount === 0): ?>
        <div class="card mt-3">
            <div class="card-header">
                <i class="bi bi-person-plus me-1"></i> Buat Akun Administrator Pertama
            </div>
            <div class="card-body">
                <p class="text-secondary small mb-3">
                    Password akan disimpan sebagai hash menggunakan <code>password_hash()</code>.
                    Gunakan kombinasi huruf dan angka minimal 8 karakter.
                </p>

                <form method="post" action="<?= e(url('install.php')) ?>" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_admin">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="nama" class="form-label">Nama Lengkap</label>
                            <input type="text" class="form-control" id="nama" name="nama"
                                   value="<?= e($formNama) ?>" maxlength="100" required>
                        </div>
                        <div class="col-md-6">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username"
                                   value="<?= e($formUsername) ?>" maxlength="50"
                                   pattern="[A-Za-z0-9._-]{4,50}"
                                   title="4-50 karakter: huruf, angka, titik, garis bawah, atau tanda hubung"
                                   autocomplete="username" required>
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email <span class="text-secondary fw-normal">(opsional)</span></label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?= e($formEmail) ?>" maxlength="150" autocomplete="email">
                        </div>
                        <div class="col-md-6">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="password" name="password"
                                       minlength="8" autocomplete="new-password" required>
                                <button class="btn password-toggle" type="button" data-toggle-password
                                        data-target="#password" aria-label="Tampilkan password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="password_confirm" class="form-label">Ulangi Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                                       minlength="8" autocomplete="new-password" required>
                                <button class="btn password-toggle" type="button" data-toggle-password
                                        data-target="#password_confirm" aria-label="Tampilkan password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-column flex-sm-row gap-2 align-items-sm-center mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-person-check me-1"></i> Simpan Akun Administrator
                        </button>
                        <span class="small text-secondary">
                            Setelah akun dibuat, halaman instalasi terkunci otomatis.
                        </span>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="card mt-3">
        <div class="card-body small text-secondary">
            <div class="fw-semibold text-body mb-1"><i class="bi bi-info-circle me-1"></i>Catatan keamanan</div>
            <ul class="mb-0 ps-3">
                <li>Halaman instalasi hanya dapat membuat akun saat belum ada administrator.</li>
                <li>Setelah selesai, disarankan menghapus atau mengganti nama <code>install.php</code>.</li>
                <li>Kunci jawaban tidak pernah ditampilkan pada halaman siswa.</li>
                <li>Password tidak pernah disimpan dalam bentuk teks biasa.</li>
            </ul>
        </div>
    </div>

    <p class="text-center small text-secondary mt-4 mb-0">
        <?= e($appNameDisplay) ?> &middot; <?= e(APP_DESCRIPTION) ?> &middot; v<?= e(APP_VERSION) ?>
    </p>
</div>

<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
