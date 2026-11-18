<?php
declare(strict_types=1);

/**
 * SIKILAT - Logout (POST + CSRF).
 * Logout melalui GET ditolak agar tidak dapat dipicu oleh tautan/gambar.
 */

require_once __DIR__ . '/../config/app.php';

require_csrf();

logout_user();

redirect('auth/login.php?logout=1');
