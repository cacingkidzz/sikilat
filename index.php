<?php
declare(strict_types=1);

/**
 * SIKILAT - Titik masuk aplikasi.
 * Mengarahkan pengguna ke dashboard (jika sudah login) atau halaman login.
 */

require_once __DIR__ . '/config/app.php';

redirect(auth_check() ? 'admin/dashboard/index.php' : 'auth/login.php');
