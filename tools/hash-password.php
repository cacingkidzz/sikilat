<?php
declare(strict_types=1);

/**
 * SIKILAT - Membuat hash password untuk keperluan manual/SQL.
 *
 * Penggunaan:
 *   php tools/hash-password.php "<password>"
 *
 * Hasil hash dapat dipakai pada query UPDATE users SET password = '...'
 * atau untuk membuat akun admin darurat.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya dapat dijalankan dari terminal (CLI).');
}

$password = $argv[1] ?? '';

if ($password === '') {
    fwrite(STDERR, "Penggunaan: php tools/hash-password.php \"<password>\"" . PHP_EOL);
    exit(1);
}

if (mb_strlen($password) < 8) {
    fwrite(STDERR, 'Password minimal 8 karakter.' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, password_hash($password, PASSWORD_DEFAULT) . PHP_EOL);
