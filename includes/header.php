<?php
declare(strict_types=1);

/**
 * SIKILAT - Layout panel admin: header, navbar, sidebar, pembuka konten.
 *
 * Variabel yang dapat diisi halaman sebelum include:
 * - $pageTitle       (string)  Judul halaman
 * - $pageSubtitle    (string)  Deskripsi singkat
 * - $breadcrumbs     (array)   [['label' => ..., 'url' => ...], ...]
 * - $useDataTables   (bool)
 * - $useChartJs      (bool)
 * - $pageStyles      (array) daftar path aset CSS tambahan
 */

if (!defined('SIKILAT_APP')) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

$user = auth_user();
if ($user === null) {
    redirect('auth/login.php');
}

$pageTitle = $pageTitle ?? 'Dashboard';
$pageSubtitle = $pageSubtitle ?? '';
$breadcrumbs = $breadcrumbs ?? [['label' => $pageTitle]];
$useDataTables = $useDataTables ?? false;
$useChartJs = $useChartJs ?? false;
$pageStyles = $pageStyles ?? [];

$appNameDisplay = setting('nama_aplikasi', APP_NAME);
$schoolName = setting('nama_sekolah', '');
$logoSetting = setting('logo_sekolah', '');
$logoUrl = $logoSetting !== '' && is_file(UPLOADS_PATH . '/logo/' . basename($logoSetting))
    ? url('uploads/logo/' . rawurlencode(basename($logoSetting)))
    : asset('img/logo-sikilat.svg');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> | <?= e($appNameDisplay) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('img/logo-sikilat.svg')) ?>">
    <link rel="stylesheet" href="<?= e(asset('plugins/adminlte/css/adminlte.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('plugins/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('plugins/overlayscrollbars/overlayscrollbars.min.css')) ?>">
    <?php if ($useDataTables): ?>
        <link rel="stylesheet" href="<?= e(asset('plugins/datatables/css/dataTables.bootstrap5.min.css')) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?php foreach ($pageStyles as $stylePath): ?>
        <link rel="stylesheet" href="<?= e(asset((string) $stylePath)) ?>">
    <?php endforeach; ?>
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<div class="app-wrapper">

    <nav class="app-header navbar navbar-expand bg-white border-bottom">
        <div class="container-fluid">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button" aria-label="Buka atau tutup menu">
                        <i class="bi bi-list fs-4"></i>
                    </a>
                </li>
                <li class="nav-item d-none d-md-block">
                    <a href="<?= e(url('admin/dashboard/index.php')) ?>" class="nav-link">Beranda</a>
                </li>
            </ul>

            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item d-none d-md-block me-2">
                    <a href="<?= e(url('siswa/index.php')) ?>" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Halaman Siswa
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="avatar-circle"><?= e(initials($user['nama'])) ?></span>
                        <span class="d-none d-sm-inline">
                            <span class="d-block lh-sm fw-semibold small"><?= e($user['nama']) ?></span>
                            <span class="d-block lh-sm text-secondary" style="font-size:.72rem;"><?= e(role_label($user['role'])) ?></span>
                        </span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li class="px-3 py-2">
                            <div class="fw-semibold"><?= e($user['nama']) ?></div>
                            <div class="small text-secondary">@<?= e($user['username']) ?></div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="<?= e(url('auth/logout.php')) ?>" class="m-0">
                                <?= csrf_field() ?>
                                <button type="submit" class="dropdown-item text-danger">
                                    <i class="bi bi-box-arrow-right me-2"></i> Keluar
                                </button>
                            </form>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </nav>

    <?php require INCLUDES_PATH . '/sidebar.php'; ?>

    <main class="app-main">
        <div class="app-content-header">
            <div class="container-fluid">
                <div class="row align-items-center">
                    <div class="col-sm-6">
                        <h3 class="mb-0 fw-semibold"><?= e($pageTitle) ?></h3>
                        <?php if ($pageSubtitle !== ''): ?>
                            <p class="text-secondary small mb-0 mt-1"><?= e($pageSubtitle) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end mb-0">
                            <?php
                            $totalBreadcrumbs = count($breadcrumbs);
                            foreach ($breadcrumbs as $index => $crumb):
                                $isLast = $index === $totalBreadcrumbs - 1;
                                $crumbLabel = (string) ($crumb['label'] ?? '');
                                $crumbUrl = (string) ($crumb['url'] ?? '');
                                ?>
                                <li class="breadcrumb-item<?= $isLast ? ' active' : '' ?>">
                                    <?php if (!$isLast && $crumbUrl !== ''): ?>
                                        <a href="<?= e($crumbUrl) ?>"><?= e($crumbLabel) ?></a>
                                    <?php else: ?>
                                        <?= e($crumbLabel) ?>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">
