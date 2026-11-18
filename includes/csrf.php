<?php
declare(strict_types=1);

/**
 * SIKILAT - Proteksi CSRF.
 *
 * Satu token per session, dibandingkan dengan hash_equals untuk
 * mencegah timing attack, dan dirotasi setelah login/logout.
 */

if (!defined('SIKILAT_APP')) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

function csrf_token(): string
{
    if (!isset($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token']) || $_SESSION['_csrf_token'] === '') {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token): bool
{
    $sessionToken = $_SESSION['_csrf_token'] ?? '';

    return is_string($token)
        && $token !== ''
        && is_string($sessionToken)
        && $sessionToken !== ''
        && hash_equals($sessionToken, $token);
}

/**
 * Membuang token lama, token baru dibuat saat dibutuhkan.
 */
function csrf_rotate(): void
{
    unset($_SESSION['_csrf_token']);
}

/**
 * Wajib metode POST.
 */
function require_post(): void
{
    if (!is_post()) {
        render_error_page(405, 'Metode Tidak Diizinkan', 'Permintaan ini harus dikirim menggunakan metode POST.');
    }
}

/**
 * Wajib token CSRF valid, jika tidak tampilkan halaman 403.
 */
function require_csrf(): void
{
    require_post();

    if (!csrf_verify(post('_token') !== null ? (string) post('_token') : null)) {
        render_error_page(
            403,
            'Sesi Kedaluwarsa',
            'Token keamanan tidak valid atau halaman terlalu lama terbuka. Muat ulang halaman dan coba lagi.'
        );
    }
}
