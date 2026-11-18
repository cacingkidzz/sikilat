<?php
declare(strict_types=1);

/**
 * SIKILAT - Ubah password akun melalui CLI.
 *
 * Penggunaan:
 *   php tools/set-password.php <username> "<password-baru>"
 *
 * Berguna sebelum modul Manajemen Pengguna tersedia pada tahap berikutnya.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya dapat dijalankan dari terminal (CLI).');
}

require_once __DIR__ . '/../config/app.php';

$username = $argv[1] ?? '';
$password = $argv[2] ?? '';

if ($username === '' || $password === '') {
    fwrite(STDERR, "Penggunaan: php tools/set-password.php <username> \"<password-baru>\"" . PHP_EOL);
    fwrite(STDERR, "Contoh    : php tools/set-password.php admin \"Rahasia#2026\"" . PHP_EOL);
    exit(1);
}

if (mb_strlen($password) < 8) {
    fwrite(STDERR, 'Password minimal 8 karakter.' . PHP_EOL);
    exit(1);
}

try {
    $stmt = db()->prepare('SELECT id, nama FROM users WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if ($user === false) {
        fwrite(STDERR, 'Akun dengan username "' . $username . '" tidak ditemukan.' . PHP_EOL);
        exit(1);
    }

    $update = db()->prepare('UPDATE users SET password = :password, is_active = 1 WHERE id = :id');
    $update->execute([
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'id'       => (int) $user['id'],
    ]);

    log_aktivitas('users', 'reset_password_cli', 'Password diubah melalui CLI untuk akun: ' . $username, (int) $user['id']);

    fwrite(STDOUT, 'Password untuk akun "' . $username . '" (' . $user['nama'] . ') berhasil diubah.' . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Gagal mengubah password: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
