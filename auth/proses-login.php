<?php
declare(strict_types=1);

/**
 * SIKILAT - Proses autentikasi login (POST).
 */

require_once __DIR__ . '/../config/app.php';

require_post();

if (!csrf_verify(post('_token') !== null ? (string) post('_token') : null)) {
    flash('error', 'Token keamanan tidak valid. Silakan coba lagi.');
    redirect('auth/login.php');
}

if (auth_check()) {
    redirect('admin/dashboard/index.php');
}

$identity = (string) post('identity', '');
// Password tidak di-trim karena spasi di awal/akhir bisa merupakan bagian password.
$password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';

if ($identity === '' || $password === '') {
    set_old_input(['identity' => $identity]);
    flash('error', 'Username/email dan password wajib diisi.');
    redirect('auth/login.php');
}

$lockRemaining = login_lock_remaining();
if ($lockRemaining > 0) {
    set_old_input(['identity' => $identity]);
    flash(
        'warning',
        'Terlalu banyak percobaan login. Silakan tunggu ' . $lockRemaining . ' detik lagi.'
    );
    redirect('auth/login.php');
}

$result = attempt_login($identity, $password);

if ($result['success']) {
    login_clear_failures();
    clear_old_input();
    flash('success', $result['message']);
    redirect(take_intended_url());
}

login_record_failure();
set_old_input(['identity' => $identity]);
flash('error', $result['message']);
redirect('auth/login.php');
