<?php
declare(strict_types=1);

/**
 * SIKILAT - Fungsi helper yang dipakai lintas modul.
 * Semua fungsi di sini bersifat stateless kecuali cache pengaturan
 * dan old input (session).
 */

if (!defined('SIKILAT_APP')) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

/**
 * Escape output HTML.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * URL internal aplikasi, contoh: url('auth/login.php').
 */
function url(string $path = ''): string
{
    return BASE_PATH . '/' . ltrim($path, '/');
}

/**
 * URL aset dengan cache busting berdasarkan waktu ubah file.
 */
function asset(string $path): string
{
    $relative = 'assets/' . ltrim($path, '/');
    $absolute = ROOT_PATH . '/' . $relative;
    $version = is_file($absolute) ? (string) filemtime($absolute) : APP_VERSION;

    return url($relative) . '?v=' . $version;
}

/**
 * URL absolut (untuk laporan/email di masa mendatang).
 */
function absolute_url(string $path = ''): string
{
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}

/**
 * Redirect ke path internal atau URL absolut.
 */
function redirect(string $location): never
{
    if (preg_match('#^https?://#i', $location) === 1) {
        $target = $location;
    } elseif (str_starts_with($location, '/')) {
        // Path absolut dari root domain (sudah termasuk BASE_PATH bila perlu).
        $target = $location;
    } else {
        $target = url($location);
    }

    if (!headers_sent()) {
        header('Location: ' . $target, true, 302);
    }
    exit;
}

/**
 * Path skrip saat ini relatif terhadap folder aplikasi.
 * Contoh: admin/dashboard/index.php
 */
function current_relative_path(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    if (BASE_PATH !== '' && str_starts_with($script, BASE_PATH . '/')) {
        return substr($script, strlen(BASE_PATH) + 1);
    }

    return ltrim($script, '/');
}

function is_post(): bool
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
}

/**
 * Ambil nilai POST yang sudah di-trim (array dikembalikan apa adanya).
 */
function post(string $key, mixed $default = null): mixed
{
    if (!array_key_exists($key, $_POST)) {
        return $default;
    }

    $value = $_POST[$key];

    return is_string($value) ? trim($value) : $value;
}

/**
 * Ambil nilai GET yang sudah di-trim.
 */
function input(string $key, mixed $default = null): mixed
{
    if (!array_key_exists($key, $_GET)) {
        return $default;
    }

    $value = $_GET[$key];

    return is_string($value) ? trim($value) : $value;
}

/* ---------------------------------------------------------------------
 * Flash message & old input
 * ------------------------------------------------------------------ */

/**
 * @param string $type success|error|warning|info
 */
function flash(string $type, string $message): void
{
    if (!isset($_SESSION['_flash'])) {
        $_SESSION['_flash'] = [];
    }

    $_SESSION['_flash'][] = [
        'type'    => $type,
        'message' => $message,
    ];
}

/**
 * @return array<int, array{type:string, message:string}>
 */
function get_flashes(): array
{
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);

    return is_array($flashes) ? $flashes : [];
}

/**
 * Menyimpan input lama agar form dapat diisi ulang setelah validasi gagal.
 *
 * @param array<string, scalar|null> $data
 */
function set_old_input(array $data): void
{
    $_SESSION['_old_input'] = $data;
}

function old(string $key, string $default = ''): string
{
    $value = $_SESSION['_old_input'][$key] ?? $default;

    return is_scalar($value) ? (string) $value : $default;
}

function clear_old_input(): void
{
    unset($_SESSION['_old_input']);
}

/* ---------------------------------------------------------------------
 * Pengaturan aplikasi
 * ------------------------------------------------------------------ */

/**
 * @return array<string, string>
 */
function settings_all(): array
{
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    $cache = [];

    try {
        $rows = db()->query('SELECT nama_pengaturan, nilai FROM pengaturan')->fetchAll();
        foreach ($rows as $row) {
            $cache[(string) $row['nama_pengaturan']] = (string) ($row['nilai'] ?? '');
        }
    } catch (Throwable $exception) {
        error_log('[SIKILAT] Gagal memuat pengaturan: ' . $exception->getMessage());
    }

    return $cache;
}

function setting(string $key, string $default = ''): string
{
    $settings = settings_all();
    $value = $settings[$key] ?? '';

    return trim($value) === '' ? $default : $value;
}

/* ---------------------------------------------------------------------
 * Utilitas umum
 * ------------------------------------------------------------------ */

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/**
 * Catat aktivitas penting ke tabel log_aktivitas.
 * Kegagalan logging tidak boleh menggagalkan proses utama.
 */
function log_aktivitas(string $modul, string $aksi, string $deskripsi = '', ?int $userId = null): void
{
    try {
        if ($userId === null && function_exists('auth_id')) {
            $userId = auth_id();
        }

        $stmt = db()->prepare(
            'INSERT INTO log_aktivitas (user_id, modul, aksi, deskripsi, ip_address, user_agent)
             VALUES (:user_id, :modul, :aksi, :deskripsi, :ip, :user_agent)'
        );
        $stmt->execute([
            'user_id'    => $userId,
            'modul'      => mb_substr($modul, 0, 50),
            'aksi'       => mb_substr($aksi, 0, 50),
            'deskripsi'  => $deskripsi !== '' ? $deskripsi : null,
            'ip'         => client_ip() !== '' ? client_ip() : null,
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $exception) {
        error_log('[SIKILAT] Gagal menulis log aktivitas: ' . $exception->getMessage());
    }
}

/**
 * Format tanggal Indonesia, contoh: 16 November 2026.
 */
function format_tanggal(?string $date, bool $denganWaktu = false): string
{
    if ($date === null || trim($date) === '') {
        return '-';
    }

    try {
        $dateTime = new DateTimeImmutable($date);
    } catch (Exception) {
        return '-';
    }

    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    $hasil = $dateTime->format('j') . ' ' . $bulan[(int) $dateTime->format('n')] . ' ' . $dateTime->format('Y');

    if ($denganWaktu) {
        $hasil .= ', ' . $dateTime->format('H:i') . ' WIB';
    }

    return $hasil;
}

function format_nilai(mixed $nilai): string
{
    if ($nilai === null || $nilai === '') {
        return '-';
    }

    return number_format((float) $nilai, 2, ',', '.');
}

function status_badge(string $status): string
{
    $map = [
        'draft'   => ['secondary', 'Draft'],
        'aktif'   => ['success', 'Aktif'],
        'selesai' => ['primary', 'Selesai'],
    ];

    [$warna, $label] = $map[$status] ?? ['secondary', ucfirst($status)];

    return '<span class="badge text-bg-' . $warna . '">' . e($label) . '</span>';
}

function role_label(string $role): string
{
    return match ($role) {
        'admin' => 'Administrator',
        'guru'  => 'Guru',
        default => ucfirst($role),
    };
}

/**
 * Inisial nama untuk avatar, maksimal 2 karakter.
 */
function initials(string $nama): string
{
    $nama = trim($nama);
    if ($nama === '') {
        return '?';
    }

    $bagian = preg_split('/\s+/', $nama) ?: [];
    $inisial = '';

    foreach ($bagian as $kata) {
        if ($kata === '') {
            continue;
        }
        $inisial .= mb_strtoupper(mb_substr($kata, 0, 1));
        if (mb_strlen($inisial) >= 2) {
            break;
        }
    }

    return $inisial !== '' ? $inisial : mb_strtoupper(mb_substr($nama, 0, 1));
}

function str_limit(string $text, int $limit = 100, string $end = '…'): string
{
    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, max(0, $limit - 1))) . $end;
}

/**
 * Ambil URL tujuan sebelum login, hanya izinkan path internal aplikasi.
 */
function take_intended_url(string $default = 'admin/dashboard/index.php'): string
{
    $raw = $_SESSION['intended_url'] ?? '';
    unset($_SESSION['intended_url']);

    if (!is_string($raw) || $raw === '') {
        return $default;
    }

    $parts = parse_url($raw);
    if ($parts === false) {
        return $default;
    }

    $path = (string) ($parts['path'] ?? '');
    $query = (string) ($parts['query'] ?? '');

    if ($path === '' || str_contains($path, "\r") || str_contains($path, "\n") || str_starts_with($path, '//')) {
        return $default;
    }

    if (BASE_PATH !== '') {
        if (!str_starts_with($path, BASE_PATH . '/')) {
            return $default;
        }
        $path = substr($path, strlen(BASE_PATH) + 1);
    } else {
        $path = ltrim($path, '/');
    }

    if ($path === '') {
        return $default;
    }

    return $path . ($query !== '' ? '?' . $query : '');
}

/**
 * Kirim respons JSON lalu hentikan eksekusi.
 *
 * @param array<string, mixed> $payload
 */
function json_response(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Validasi apakah sebuah string berupa URL http/https yang wajar.
 */
function is_valid_url(string $url): bool
{
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && preg_match('#^https?://#i', $url) === 1;
}
