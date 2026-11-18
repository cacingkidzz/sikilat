<?php
declare(strict_types=1);

/**
 * SIKILAT - Autentikasi dan otorisasi.
 *
 * - Cek login berdasarkan session + data user di database (bukan hanya session).
 * - Idle timeout dan regenerasi session ID saat login.
 * - Pembatasan akses berdasarkan peran admin/guru.
 */

if (!defined('SIKILAT_APP')) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

/**
 * Data pengguna yang sedang login, atau null.
 *
 * @return array{id:int, nama:string, username:string, email:?string, role:string, is_active:int, last_login:?string}|null
 */
function auth_user(): ?array
{
    static $loaded = false;
    static $user = null;

    if ($loaded) {
        return $user;
    }
    $loaded = true;

    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
        return null;
    }

    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) > SESSION_IDLE_TIMEOUT) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION = ['_session_expired' => true];

        return null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, nama, username, email, role, is_active, last_login
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => (int) $userId]);
        $row = $stmt->fetch();
    } catch (Throwable $exception) {
        error_log('[SIKILAT] Gagal memuat data pengguna: ' . $exception->getMessage());

        return null;
    }

    if ($row === false || (int) $row['is_active'] !== 1) {
        unset($_SESSION['user_id'], $_SESSION['last_activity']);

        return null;
    }

    $_SESSION['last_activity'] = time();

    $user = [
        'id'         => (int) $row['id'],
        'nama'       => (string) $row['nama'],
        'username'   => (string) $row['username'],
        'email'      => $row['email'] !== null ? (string) $row['email'] : null,
        'role'       => (string) $row['role'],
        'is_active'  => (int) $row['is_active'],
        'last_login' => $row['last_login'] !== null ? (string) $row['last_login'] : null,
    ];

    return $user;
}

function auth_check(): bool
{
    return auth_user() !== null;
}

function auth_id(): ?int
{
    $user = auth_user();

    return $user !== null ? $user['id'] : null;
}

function auth_role(): ?string
{
    $user = auth_user();

    return $user !== null ? $user['role'] : null;
}

function is_admin(): bool
{
    return auth_role() === 'admin';
}

/**
 * Wajib login. Menyimpan halaman tujuan untuk redirect setelah login.
 */
function require_auth(): void
{
    if (auth_check()) {
        return;
    }

    $expired = !empty($_SESSION['_session_expired']);
    unset($_SESSION['_session_expired']);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $_SESSION['intended_url'] = (string) ($_SERVER['REQUEST_URI'] ?? '');
    }

    flash(
        $expired ? 'warning' : 'info',
        $expired
            ? 'Sesi Anda berakhir karena tidak ada aktivitas. Silakan masuk kembali.'
            : 'Silakan masuk terlebih dahulu untuk mengakses panel.'
    );

    redirect('auth/login.php');
}

/**
 * Halaman login tidak perlu diakses jika sudah login.
 */
function require_guest(): void
{
    if (auth_check()) {
        redirect('admin/dashboard/index.php');
    }
}

/**
 * @param array<int, string> $roles
 */
function require_role(array $roles): void
{
    require_auth();

    $role = auth_role();
    if ($role === null || !in_array($role, $roles, true)) {
        render_error_page(
            403,
            'Akses Ditolak',
            'Anda tidak memiliki hak akses untuk membuka halaman ini.'
        );
    }
}

/* ---------------------------------------------------------------------
 * Pembatasan percobaan login (berbasis session)
 * ------------------------------------------------------------------ */

function login_attempts(): array
{
    $attempts = $_SESSION['_login_attempts'] ?? [];
    if (!is_array($attempts)) {
        return [];
    }

    $now = time();
    $valid = [];
    foreach ($attempts as $timestamp) {
        if (is_int($timestamp) && ($now - $timestamp) < LOGIN_LOCKOUT_SECONDS) {
            $valid[] = $timestamp;
        }
    }

    return array_values($valid);
}

/**
 * Sisa waktu kunci dalam detik, 0 jika tidak terkunci.
 */
function login_lock_remaining(): int
{
    $attempts = login_attempts();

    if (count($attempts) < LOGIN_MAX_ATTEMPTS) {
        return 0;
    }

    $lastAttempt = (int) end($attempts);
    $remaining = LOGIN_LOCKOUT_SECONDS - (time() - $lastAttempt);

    return $remaining > 0 ? $remaining : 1;
}

function login_record_failure(): void
{
    $attempts = login_attempts();
    $attempts[] = time();
    $_SESSION['_login_attempts'] = $attempts;
}

function login_clear_failures(): void
{
    unset($_SESSION['_login_attempts']);
}

/* ---------------------------------------------------------------------
 * Login / logout
 * ------------------------------------------------------------------ */

/**
 * @return array{success:bool, message:string}
 */
function attempt_login(string $identity, string $password): array
{
    $identity = trim($identity);

    if ($identity === '' || $password === '') {
        return ['success' => false, 'message' => 'Username/email dan password wajib diisi.'];
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, nama, username, email, password, role, is_active
             FROM users
             WHERE username = :identity_username OR email = :identity_email
             LIMIT 1'
        );
        $stmt->execute([
            'identity_username' => $identity,
            'identity_email'    => $identity,
        ]);
        $row = $stmt->fetch();
    } catch (Throwable $exception) {
        error_log('[SIKILAT] Login gagal (database): ' . $exception->getMessage());

        return ['success' => false, 'message' => 'Login tidak dapat diproses saat ini. Coba beberapa saat lagi.'];
    }

    // Pesan yang sama untuk akun tidak ada, nonaktif, atau password salah
    // agar tidak membocorkan keberadaan akun.
    $pesanGagal = 'Username/email atau password salah.';

    if ($row === false || (int) $row['is_active'] !== 1) {
        usleep(400_000);

        return ['success' => false, 'message' => $pesanGagal];
    }

    if (!password_verify($password, (string) $row['password'])) {
        usleep(400_000);
        log_aktivitas('auth', 'login_gagal', 'Percobaan login gagal untuk: ' . $identity);

        return ['success' => false, 'message' => $pesanGagal];
    }

    if (password_needs_rehash((string) $row['password'], PASSWORD_DEFAULT)) {
        try {
            $rehash = db()->prepare('UPDATE users SET password = :password WHERE id = :id');
            $rehash->execute([
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'id'       => (int) $row['id'],
            ]);
        } catch (Throwable $exception) {
            error_log('[SIKILAT] Gagal rehash password: ' . $exception->getMessage());
        }
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $_SESSION['user_id'] = (int) $row['id'];
    $_SESSION['last_activity'] = time();
    unset($_SESSION['_session_expired']);
    csrf_rotate();

    try {
        $update = db()->prepare('UPDATE users SET last_login = NOW() WHERE id = :id');
        $update->execute(['id' => (int) $row['id']]);
    } catch (Throwable $exception) {
        error_log('[SIKILAT] Gagal memperbarui last_login: ' . $exception->getMessage());
    }

    log_aktivitas(
        'auth',
        'login',
        'Berhasil masuk ke panel sebagai ' . $row['role'],
        (int) $row['id']
    );

    return ['success' => true, 'message' => 'Selamat datang, ' . $row['nama'] . '!'];
}

/**
 * Keluar dan hancurkan session.
 */
function logout_user(bool $catatLog = true): void
{
    if ($catatLog && auth_check()) {
        log_aktivitas('auth', 'logout', 'Keluar dari panel');
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => 'Lax',
            ]
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
