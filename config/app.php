<?php
declare(strict_types=1);

/**
 * SIKILAT - Sistem Koreksi Nilai Cepat
 * ---------------------------------------------------------------------
 * Bootstrap aplikasi:
 *  - konstanta aplikasi & path
 *  - deteksi base URL (mendukung subfolder XAMPP maupun root cPanel)
 *  - penanganan error tanpa membocorkan detail database
 *  - session aman (HttpOnly, SameSite, strict mode, idle timeout)
 *  - security header
 *  - pemuatan konfigurasi database, helper, CSRF, dan autentikasi
 */

define('SIKILAT_APP', true);
define('APP_NAME', 'SIKILAT');
define('APP_DESCRIPTION', 'Sistem Koreksi Nilai Cepat');
define('APP_VERSION', '1.0.0');

$env = getenv('SIKILAT_ENV');
if ($env === false || $env === '') {
    $hostOnly = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]);
    $isLocalHost = PHP_SAPI === 'cli'
        || in_array($hostOnly, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    $env = $isLocalHost ? 'local' : 'production';
}
define('APP_ENV', $env);

$debugEnv = getenv('SIKILAT_DEBUG');
define(
    'APP_DEBUG',
    $debugEnv !== false ? filter_var($debugEnv, FILTER_VALIDATE_BOOLEAN) : (APP_ENV === 'local')
);

define('ROOT_PATH', dirname(__DIR__));
define('CONFIG_PATH', ROOT_PATH . '/config');
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('DATABASE_PATH', ROOT_PATH . '/database');
define('UPLOADS_PATH', ROOT_PATH . '/uploads');
define('LOG_PATH', ROOT_PATH . '/logs');

define('APP_TIMEZONE', getenv('SIKILAT_TIMEZONE') ?: 'Asia/Jakarta');
date_default_timezone_set(APP_TIMEZONE);

/* ---------------------------------------------------------------------
 * Deteksi base path aplikasi.
 * Contoh: C:/xampp/htdocs/ujian -> /ujian
 * Di cPanel dengan document root langsung ke folder aplikasi -> ''
 * ------------------------------------------------------------------ */
$sikilatBasePath = '';
$docRootReal = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
$appRootReal = realpath(ROOT_PATH);

if ($docRootReal !== false && $appRootReal !== false) {
    $docRootNorm = rtrim(str_replace('\\', '/', $docRootReal), '/');
    $appRootNorm = rtrim(str_replace('\\', '/', $appRootReal), '/');

    if ($docRootNorm !== '' && stripos($appRootNorm, $docRootNorm) === 0) {
        $sikilatBasePath = substr($appRootNorm, strlen($docRootNorm));
    }
}

$sikilatBasePath = '/' . trim(str_replace('\\', '/', $sikilatBasePath), '/');
if ($sikilatBasePath === '/') {
    $sikilatBasePath = '';
}
define('BASE_PATH', $sikilatBasePath);

/**
 * Origin (skema + host) aplikasi, dipakai untuk URL absolut.
 */
function app_origin(): string
{
    if (PHP_SAPI === 'cli') {
        return 'http://localhost';
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host) ?: 'localhost';

    return ($https ? 'https' : 'http') . '://' . $host;
}

$appUrlEnv = getenv('SIKILAT_APP_URL');
define(
    'APP_URL',
    $appUrlEnv !== false && $appUrlEnv !== '' ? rtrim($appUrlEnv, '/') : app_origin() . BASE_PATH
);

/* ---------------------------------------------------------------------
 * Direktori runtime
 * ------------------------------------------------------------------ */
foreach ([UPLOADS_PATH, LOG_PATH, UPLOADS_PATH . '/logo'] as $sikilatDir) {
    if (!is_dir($sikilatDir)) {
        @mkdir($sikilatDir, 0755, true);
    }
}

/* ---------------------------------------------------------------------
 * Penanganan error
 * ------------------------------------------------------------------ */
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', LOG_PATH . '/php-error.log');

/**
 * Menampilkan halaman error yang ramah pengguna.
 */
function render_error_page(int $status, string $title, string $message, ?string $detail = null): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, '[' . $status . '] ' . $title . ': ' . $message . PHP_EOL);
        exit(1);
    }

    $wantsJson = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

    if ($wantsJson) {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!headers_sent()) {
        http_response_code($status);
    }

    $errorCode = $status;
    $errorTitle = $title;
    $errorMessage = $message;
    $errorDetail = APP_DEBUG ? $detail : null;

    include ROOT_PATH . '/includes/error-page.php';
    exit;
}

set_exception_handler(static function (Throwable $exception): void {
    error_log(sprintf(
        '[SIKILAT] %s: %s di %s:%d',
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    render_error_page(
        500,
        'Terjadi Kesalahan',
        'Maaf, terjadi kesalahan pada server. Silakan coba lagi atau hubungi administrator.',
        $exception::class . ': ' . $exception->getMessage()
            . ' (' . $exception->getFile() . ':' . $exception->getLine() . ')'
    );
});

register_shutdown_function(static function (): void {
    $lastError = error_get_last();
    if ($lastError === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($lastError['type'], $fatalTypes, true)) {
        return;
    }

    error_log(sprintf(
        '[SIKILAT] Fatal error: %s di %s:%d',
        $lastError['message'],
        $lastError['file'],
        $lastError['line']
    ));

    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        render_error_page(
            500,
            'Kesalahan Server',
            'Aplikasi tidak dapat memproses permintaan Anda. Silakan hubungi administrator.',
            $lastError['message'] . ' (' . $lastError['file'] . ':' . $lastError['line'] . ')'
        );
    }
});

/* ---------------------------------------------------------------------
 * Security header
 * ------------------------------------------------------------------ */
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "img-src 'self' data:; "
        . "style-src 'self' 'unsafe-inline'; "
        . "script-src 'self'; "
        . "font-src 'self'; "
        . "connect-src 'self'; "
        . "frame-ancestors 'self'; "
        . "base-uri 'self'; "
        . "form-action 'self'"
    );
}

/* ---------------------------------------------------------------------
 * Session
 * ------------------------------------------------------------------ */
define('SESSION_NAME', 'SIKILAT_SESSID');
define('SESSION_IDLE_TIMEOUT', 7200);      // 2 jam tanpa aktivitas
define('LOGIN_MAX_ATTEMPTS', 5);           // maksimal percobaan login
define('LOGIN_LOCKOUT_SECONDS', 300);      // jeda 5 menit setelah batas

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $isSecureRequest = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string) SESSION_IDLE_TIMEOUT);

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_PATH === '' ? '/' : BASE_PATH . '/',
        'domain'   => '',
        'secure'   => $isSecureRequest,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ---------------------------------------------------------------------
 * Komponen aplikasi
 * ------------------------------------------------------------------ */
require_once CONFIG_PATH . '/database.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/csrf.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/crud.php';
require_once INCLUDES_PATH . '/soal.php';

$sikilatAutoload = ROOT_PATH . '/vendor/autoload.php';
if (is_file($sikilatAutoload)) {
    require_once $sikilatAutoload;
}
