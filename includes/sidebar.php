<?php
declare(strict_types=1);

/**
 * SIKILAT - Sidebar navigasi panel admin.
 * Menu disesuaikan dengan role. Modul yang belum tersedia ditampilkan
 * nonaktif dengan label "Segera" agar tidak menghasilkan tautan rusak.
 */

if (!defined('SIKILAT_APP')) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

$currentPath = current_relative_path();
$brandLogo = $logoUrl ?? asset('img/logo-sikilat.svg');
$isAdminMenu = is_admin();

$menu = [
    ['type' => 'header', 'label' => 'Menu Utama'],
    [
        'type'   => 'link',
        'label'  => 'Dashboard',
        'icon'   => 'bi-speedometer2',
        'path'   => 'admin/dashboard/index.php',
        'prefix' => 'admin/dashboard/',
    ],
    ['type' => 'header', 'label' => 'Master Data'],
    [
        'type'   => 'link',
        'label'  => 'Mata Pelajaran',
        'icon'   => 'bi-book',
        'path'   => 'admin/mata-pelajaran/index.php',
        'prefix' => 'admin/mata-pelajaran/',
    ],
    [
        'type'   => 'link',
        'label'  => 'Kelas',
        'icon'   => 'bi-people',
        'path'   => 'admin/kelas/index.php',
        'prefix' => 'admin/kelas/',
    ],
];

if ($isAdminMenu) {
    $menu[] = [
        'type'   => 'link',
        'label'  => 'Manajemen Pengguna',
        'icon'   => 'bi-person-badge',
        'path'   => 'admin/users/index.php',
        'prefix' => 'admin/users/',
    ];
}

$menu = array_merge($menu, [
    ['type' => 'header', 'label' => 'Pelaksanaan Ujian'],
    [
        'type'   => 'link',
        'label'  => 'Data Ujian',
        'icon'   => 'bi-journal-check',
        'path'   => 'admin/ujian/index.php',
        'prefix' => 'admin/ujian/',
    ],
    [
        'type'   => 'link',
        'label'  => 'Paket Soal',
        'icon'   => 'bi-collection',
        'path'   => 'admin/paket-soal/index.php',
        'prefix' => 'admin/paket-soal/',
    ],
    [
        'type'   => 'link',
        'label'  => 'Kunci Jawaban',
        'icon'   => 'bi-key',
        'path'   => 'admin/kunci-jawaban/index.php',
        'prefix' => 'admin/kunci-jawaban/',
    ],
    ['type' => 'header', 'label' => 'Hasil & Laporan'],
    [
        'type'   => 'link',
        'label'  => 'Hasil Ujian',
        'icon'   => 'bi-clipboard-data',
        'path'   => 'admin/laporan/index.php',
        'prefix' => 'admin/laporan/',
    ],
    [
        'type'   => 'link',
        'label'  => 'Analisis Ujian',
        'icon'   => 'bi-graph-up-arrow',
        'path'   => 'admin/analisis/index.php',
        'prefix' => 'admin/analisis/',
    ],
    ['type' => 'header', 'label' => 'Sistem'],
]);

if ($isAdminMenu) {
    $menu[] = [
        'type'   => 'link',
        'label'  => 'Pengaturan',
        'icon'   => 'bi-gear',
        'path'   => 'admin/pengaturan/index.php',
        'prefix' => 'admin/pengaturan/',
    ];
}

$menu[] = [
    'type'   => 'link',
    'label'  => 'Halaman Siswa',
    'icon'   => 'bi-box-arrow-up-right',
    'target' => '_blank',
];
?>
<aside class="app-sidebar bg-white border-end shadow-sm">
    <div class="sidebar-brand">
        <a href="<?= e(url('admin/dashboard/index.php')) ?>" class="brand-link">
            <img src="<?= e($brandLogo) ?>" alt="Logo <?= e($appNameDisplay ?? APP_NAME) ?>" class="brand-image">
            <span class="brand-text fw-semibold text-primary"><?= e($appNameDisplay ?? APP_NAME) ?></span>
        </a>
    </div>

    <div class="sidebar-wrapper">
        <nav class="mt-2" aria-label="Navigasi utama">
            <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="menu" data-accordion="false">
                <?php foreach ($menu as $item): ?>
                    <?php if ($item['type'] === 'header'): ?>
                        <li class="nav-header"><?= e($item['label']) ?></li>
                    <?php elseif ($item['type'] === 'disabled'): ?>
                        <li class="nav-item">
                            <span class="nav-link text-secondary opacity-75 pe-none" aria-disabled="true">
                                <i class="nav-icon bi <?= e($item['icon']) ?>"></i>
                                <p>
                                    <span class="nav-label"><?= e($item['label']) ?></span>
                                    <span class="badge rounded-pill text-bg-light ms-1 fw-normal">Segera</span>
                                </p>
                            </span>
                        </li>
                    <?php else: ?>
                        <?php
                        $path = (string) ($item['path'] ?? '');
                        $prefix = (string) ($item['prefix'] ?? $path);
                        $isActive = $path === ''
                            ? $currentPath === 'siswa/index.php'
                            : ($currentPath === $path || ($prefix !== '' && str_starts_with($currentPath, $prefix)));
                        $target = (string) ($item['target'] ?? '');
                        $href = $path !== '' ? url($path) : url('siswa/index.php');
                        ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $isActive ? ' active' : '' ?>"
                               href="<?= e($href) ?>"
                               <?= $target !== '' ? 'target="' . e($target) . '" rel="noopener"' : '' ?>>
                                <i class="nav-icon bi <?= e($item['icon']) ?>"></i>
                                <p><span class="nav-label"><?= e($item['label']) ?></span></p>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </nav>
    </div>

    <div class="sidebar-footer border-top p-3">
        <div class="small text-secondary d-flex justify-content-between align-items-center">
            <span>Versi <?= e(APP_VERSION) ?></span>
            <span class="badge text-bg-light fw-normal">Tahap 6</span>
        </div>
        <?php if (($schoolName ?? '') !== ''): ?>
            <div class="small text-secondary mt-2 text-truncate" title="<?= e($schoolName) ?>">
                <i class="bi bi-building me-1"></i><?= e($schoolName) ?>
            </div>
        <?php endif; ?>
    </div>
</aside>
