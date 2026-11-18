<?php
declare(strict_types=1);

/**
 * SIKILAT - Halaman login admin/guru.
 */

require_once __DIR__ . '/../config/app.php';

require_guest();

$appNameDisplay = setting('nama_aplikasi', APP_NAME);
$schoolName = setting('nama_sekolah', '');
$identityOld = old('identity');
clear_old_input();

$flashes = get_flashes();
$baruKeluar = isset($_GET['logout']);

$belumInstalasi = false;
try {
    db()->query('SELECT 1 FROM users LIMIT 1');
} catch (Throwable $exception) {
    $belumInstalasi = true;
}

$alertMap = [
    'success' => 'success',
    'error'   => 'danger',
    'warning' => 'warning',
    'info'    => 'info',
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Masuk | <?= e($appNameDisplay) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('img/logo-sikilat.svg')) ?>">
    <link rel="stylesheet" href="<?= e(asset('plugins/adminlte/css/adminlte.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('plugins/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="auth-body">
<div class="auth-wrapper">

    <section class="auth-hero">
        <div class="auth-hero-content">
            <a href="<?= e(url()) ?>" class="auth-brand">
                <img src="<?= e(asset('img/logo-sikilat.svg')) ?>" alt="Logo <?= e($appNameDisplay) ?>">
                <span>
                    <span class="auth-brand-name d-block"><?= e($appNameDisplay) ?></span>
                    <span class="auth-brand-sub"><?= e(APP_DESCRIPTION) ?></span>
                </span>
            </a>

            <h1 class="auth-headline">Koreksi ujian lebih cepat, akurat, dan terukur.</h1>
            <p class="auth-description">
                Kelola ujian pilihan ganda, koreksi otomatis berdasarkan paket soal, dan
                hasilkan laporan nilai dalam satu panel yang terintegrasi.
            </p>

            <ul class="auth-features">
                <li>
                    <span class="feature-icon"><i class="bi bi-lightning-charge"></i></span>
                    <span>Koreksi otomatis dari kunci jawaban tiap paket soal.</span>
                </li>
                <li>
                    <span class="feature-icon"><i class="bi bi-bar-chart-line"></i></span>
                    <span>Analisis butir soal, pengecoh, dan ketuntasan siswa.</span>
                </li>
                <li>
                    <span class="feature-icon"><i class="bi bi-file-earmark-text"></i></span>
                    <span>Rekap nilai siap diekspor ke Excel dan PDF.</span>
                </li>
            </ul>
        </div>

        <div class="auth-hero-footer">
            <?= e($appNameDisplay) ?> &middot; <?= e(APP_DESCRIPTION) ?> &middot; v<?= e(APP_VERSION) ?>
        </div>
    </section>

    <section class="auth-form-side">
        <div class="auth-card">
            <a href="<?= e(url()) ?>" class="auth-mobile-brand">
                <img src="<?= e(asset('img/logo-sikilat.svg')) ?>" alt="Logo <?= e($appNameDisplay) ?>">
                <span><?= e($appNameDisplay) ?></span>
            </a>

            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="badge text-bg-primary-subtle text-primary-emphasis">
                            <i class="bi bi-shield-lock me-1"></i> Panel Admin &amp; Guru
                        </span>
                        <?php if ($schoolName !== ''): ?>
                            <span class="small text-secondary text-truncate ms-2" title="<?= e($schoolName) ?>" style="max-width: 190px;">
                                <?= e($schoolName) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <h1 class="auth-title">Masuk ke panel</h1>
                    <p class="auth-subtitle">Masukkan akun yang telah terdaftar untuk melanjutkan.</p>

                    <?php if ($belumInstalasi): ?>
                        <div class="alert alert-warning d-flex gap-2 align-items-start" role="alert">
                            <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                            <div>
                                <strong>Database belum siap.</strong>
                                <div class="small">Jalankan instalasi terlebih dahulu melalui
                                    <a href="<?= e(url('install.php')) ?>" class="alert-link">halaman instalasi</a>.
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($baruKeluar): ?>
                        <div class="alert alert-info d-flex gap-2 align-items-start" role="alert">
                            <i class="bi bi-info-circle-fill mt-1"></i>
                            <div>Anda telah keluar dari panel. Sesi Anda sudah berakhir.</div>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($flashes as $flash): ?>
                        <?php $alertClass = $alertMap[$flash['type']] ?? 'info'; ?>
                        <div class="alert alert-<?= e($alertClass) ?> d-flex gap-2 align-items-start" role="alert">
                            <i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill' ?> mt-1"></i>
                            <div><?= e($flash['message']) ?></div>
                        </div>
                    <?php endforeach; ?>

                    <form method="post" action="<?= e(url('auth/proses-login.php')) ?>" autocomplete="on" class="mt-4">
                        <?= csrf_field() ?>

                        <div class="mb-3">
                            <label for="identity" class="form-label">Username atau Email</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text"
                                       class="form-control"
                                       id="identity"
                                       name="identity"
                                       value="<?= e($identityOld) ?>"
                                       placeholder="Masukkan username atau email"
                                       autocomplete="username"
                                       maxlength="150"
                                       required
                                       autofocus>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key"></i></span>
                                <input type="password"
                                       class="form-control"
                                       id="password"
                                       name="password"
                                       placeholder="Masukkan password"
                                       autocomplete="current-password"
                                       required>
                                <button class="btn password-toggle"
                                        type="button"
                                        data-toggle-password
                                        data-target="#password"
                                        aria-label="Tampilkan password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Masuk
                        </button>
                    </form>

                    <div class="d-flex justify-content-between align-items-center mt-4 pt-1 small">
                        <span class="text-secondary">
                            <i class="bi bi-info-circle me-1"></i>Lupa password? Hubungi administrator.
                        </span>
                        <a href="<?= e(url('siswa/index.php')) ?>" class="auth-link">Halaman siswa</a>
                    </div>
                </div>
            </div>

            <div class="auth-footer">
                &copy; <?= date('Y') ?> <?= e($appNameDisplay) ?> &middot; <?= e(APP_DESCRIPTION) ?>
            </div>
        </div>
    </section>
</div>

<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
