<?php
declare(strict_types=1);

/**
 * Halaman error mandiri. Tidak bergantung pada layout admin agar tetap
 * dapat ditampilkan meskipun terjadi kegagalan saat bootstrap.
 */

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$errorCode = isset($errorCode) ? (int) $errorCode : 500;
$errorTitle = isset($errorTitle) ? (string) $errorTitle : 'Terjadi Kesalahan';
$errorMessage = isset($errorMessage) ? (string) $errorMessage : 'Permintaan Anda tidak dapat diproses.';
$errorDetail = $errorDetail ?? null;

$assetBase = defined('BASE_PATH') ? BASE_PATH . '/assets' : '/assets';
$version = defined('APP_VERSION') ? APP_VERSION : '1.0.0';
$logoUrl = $assetBase . '/img/logo-sikilat.svg';
$loginUrl = defined('BASE_PATH') ? BASE_PATH . '/auth/login.php' : '/auth/login.php';
$homeUrl = defined('BASE_PATH') ? (BASE_PATH === '' ? '/' : BASE_PATH . '/') : '/';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($errorCode) ?> - <?= e($errorTitle) ?> | SIKILAT</title>
    <link rel="icon" type="image/svg+xml" href="<?= e($logoUrl) ?>">
    <link rel="stylesheet" href="<?= e($assetBase . '/plugins/adminlte/css/adminlte.min.css') ?>?v=<?= e($version) ?>">
    <link rel="stylesheet" href="<?= e($assetBase . '/plugins/bootstrap-icons/bootstrap-icons.min.css') ?>?v=<?= e($version) ?>">
    <link rel="stylesheet" href="<?= e($assetBase . '/css/app.css') ?>?v=<?= e($version) ?>">
</head>
<body class="error-body">
    <main class="error-wrapper">
        <div class="card error-card border-0 shadow-lg">
            <div class="card-body p-4 p-md-5 text-center">
                <img src="<?= e($logoUrl) ?>" alt="Logo SIKILAT" width="56" height="56" class="mb-3">
                <div class="error-code"><?= e($errorCode) ?></div>
                <h1 class="h4 fw-semibold mb-2"><?= e($errorTitle) ?></h1>
                <p class="text-secondary mb-4"><?= e($errorMessage) ?></p>

                <?php if ($errorDetail !== null && $errorDetail !== ''): ?>
                    <div class="alert alert-warning text-start small mb-4" role="alert">
                        <div class="fw-semibold mb-1">Detail teknis (mode pengembangan):</div>
                        <code class="text-break"><?= e($errorDetail) ?></code>
                    </div>
                <?php endif; ?>

                <div class="d-flex flex-column flex-sm-row gap-2 justify-content-center">
                    <a href="<?= e($homeUrl) ?>" class="btn btn-primary">
                        <i class="bi bi-house-door me-1"></i> Ke Beranda
                    </a>
                    <a href="<?= e($loginUrl) ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Halaman Login
                    </a>
                </div>
            </div>
        </div>
        <p class="text-center text-secondary small mt-4 mb-0">
            SIKILAT &middot; Sistem Koreksi Nilai Cepat
        </p>
    </main>
</body>
</html>
