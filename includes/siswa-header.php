<?php
declare(strict_types=1);

/**
 * SIKILAT - Header layout khusus halaman siswa.
 *
 * Variabel opsional sebelum include:
 * - $pageTitle    (string)
 * - $pageSubtitle (string)
 * - $activeStep   (int) 0 = tanpa langkah, 1..4 = langkah aktif
 * - $studentWide  (bool) memakai container lebih lebar untuk halaman jawaban
 */

if (!defined('SIKILAT_APP')) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

$pageTitle = $pageTitle ?? 'Ujian Siswa';
$pageSubtitle = $pageSubtitle ?? '';
$activeStep = $activeStep ?? 1;
$studentWide = $studentWide ?? false;

$appNameDisplay = setting('nama_aplikasi', APP_NAME);
$schoolName = setting('nama_sekolah', '');
$tahunAjaran = setting('tahun_ajaran_aktif', '');
$semesterAktif = setting('semester_aktif', '');
$periodeAkademik = trim($tahunAjaran . ($tahunAjaran !== '' && $semesterAktif !== '' ? ' - Semester ' : '') . $semesterAktif);
$logoSetting = setting('logo_sekolah', '');
$logoFile = $logoSetting !== '' ? basename($logoSetting) : '';
$logoUrl = $logoFile !== '' && is_file(UPLOADS_PATH . '/logo/' . $logoFile)
    ? url('uploads/logo/' . rawurlencode($logoFile))
    : asset('img/logo-sikilat.svg');

$steps = [
    1 => 'Pilih Ujian',
    2 => 'Identitas',
    3 => 'Jawaban',
    4 => 'Selesai',
];

$flashes = get_flashes();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0f5bd7">
    <title><?= e($pageTitle) ?> | <?= e($appNameDisplay) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('img/logo-sikilat.svg')) ?>">
    <link rel="stylesheet" href="<?= e(asset('plugins/adminlte/css/adminlte.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('plugins/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/siswa.css')) ?>">
</head>
<body class="siswa-body">
<a class="siswa-skip-link" href="#siswa-content">Lewati ke konten</a>

<header class="siswa-topbar">
    <div class="container<?= $studentWide ? '-fluid px-3 px-lg-4' : '' ?>">
        <div class="siswa-topbar-inner">
            <a href="<?= e(url('siswa/index.php')) ?>" class="siswa-brand">
                <img src="<?= e($logoUrl) ?>" alt="Logo <?= e($appNameDisplay) ?>" width="40" height="40">
                <span class="siswa-brand-text">
                    <strong><?= e($appNameDisplay) ?></strong>
                    <small><?= e($schoolName !== '' ? $schoolName : APP_DESCRIPTION) ?></small>
                </span>
            </a>
            <div class="siswa-topbar-actions">
                <?php if ($periodeAkademik !== ''): ?>
                    <span class="siswa-year d-none d-md-inline-flex">
                        <i class="bi bi-calendar3"></i> <?= e($periodeAkademik) ?>
                    </span>
                <?php endif; ?>
                <a href="<?= e(url('auth/login.php')) ?>" class="siswa-admin-link" title="Panel Admin">
                    <i class="bi bi-shield-lock"></i>
                    <span class="d-none d-sm-inline">Panel Admin</span>
                </a>
            </div>
        </div>
    </div>
</header>

<?php if ($activeStep >= 1 && $activeStep <= 4): ?>
    <div class="siswa-steps-wrap">
        <div class="container<?= $studentWide ? '-fluid px-3 px-lg-4' : '' ?>">
            <ol class="siswa-steps" aria-label="Langkah pengerjaan ujian">
                <?php foreach ($steps as $number => $label): ?>
                    <?php
                    $stateClass = $number === $activeStep ? ' is-active' : ($number < $activeStep ? ' is-done' : '');
                    ?>
                    <li class="siswa-step<?= $stateClass ?>"<?= $number === $activeStep ? ' aria-current="step"' : '' ?>>
                        <span class="siswa-step-dot">
                            <?php if ($number < $activeStep): ?>
                                <i class="bi bi-check-lg"></i>
                            <?php else: ?>
                                <?= e((string) $number) ?>
                            <?php endif; ?>
                        </span>
                        <span class="siswa-step-label"><?= e($label) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>
<?php endif; ?>

<main class="siswa-main" id="siswa-content">
    <div class="container<?= $studentWide ? '-fluid px-3 px-lg-4' : '' ?>">
        <?php if ($pageSubtitle !== ''): ?>
            <div class="siswa-page-heading">
                <h1><?= e($pageTitle) ?></h1>
                <p><?= e($pageSubtitle) ?></p>
            </div>
        <?php endif; ?>

        <?php foreach ($flashes as $flash): ?>
            <?php
            $type = in_array($flash['type'] ?? '', ['success', 'danger', 'warning', 'info'], true)
                ? (string) $flash['type']
                : 'info';
            $icon = [
                'success' => 'check-circle-fill',
                'danger'  => 'exclamation-octagon-fill',
                'warning' => 'exclamation-triangle-fill',
                'info'    => 'info-circle-fill',
            ][$type];
            ?>
            <div class="alert siswa-alert alert-<?= e($type) ?> d-flex align-items-start gap-2" role="alert">
                <i class="bi bi-<?= e($icon) ?> siswa-alert-icon"></i>
                <div><?= e((string) ($flash['message'] ?? '')) ?></div>
            </div>
        <?php endforeach; ?>
