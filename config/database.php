<?php
declare(strict_types=1);

/**
 * SIKILAT - Konfigurasi Database
 * ---------------------------------------------------------------------
 * Koneksi menggunakan PDO (prepared statements, error mode exception).
 *
 * Kredensial bawaan mengikuti XAMPP: user "root" tanpa password.
 * Untuk hosting cPanel, sesuaikan nilai di bawah atau buat file
 * config/database.local.php yang mengembalikan array berikut:
 *
 *   <?php
 *   return [
 *       'host' => 'localhost',
 *       'port' => 22,
 *       'name' => 'sikilat',
 *       'user' => 'sikilat',
 *       'pass' => '8aXHtvoT3uLBN9YZupY0',
 *   ];
 *
 * File database.local.php tidak ikut terunggah ke repositori dan
 * diblokir dari akses web melalui .htaccess.
 */

if (!defined('SIKILAT_APP')) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

$dbConfig = [
    'host' => getenv('SIKILAT_DB_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('SIKILAT_DB_PORT') ?: 3306),
    'name' => getenv('SIKILAT_DB_NAME') ?: 'db_sikilat',
    'user' => getenv('SIKILAT_DB_USER') ?: 'root',
    'pass' => getenv('SIKILAT_DB_PASS') !== false ? (string) getenv('SIKILAT_DB_PASS') : '',
];

$localConfigFile = __DIR__ . '/database.local.php';
if (is_file($localConfigFile)) {
    $localConfig = require $localConfigFile;
    if (is_array($localConfig)) {
        /** @var array<string, mixed> $dbConfig */
        $dbConfig = array_merge($dbConfig, array_intersect_key($localConfig, $dbConfig));
    }
}

define('DB_HOST', (string) $dbConfig['host']);
define('DB_PORT', (int) $dbConfig['port']);
define('DB_NAME', (string) $dbConfig['name']);
define('DB_USER', (string) $dbConfig['user']);
define('DB_PASS', (string) $dbConfig['pass']);
define('DB_CHARSET', 'utf8mb4');
define('DB_TIMEZONE', '+07:00');

/**
 * Membuat koneksi PDO baru.
 *
 * @param string|null $database Null untuk koneksi ke server tanpa memilih database
 *                              (dipakai installer saat membuat database).
 * @throws PDOException
 */
function db_connect(
    string $host,
    int $port,
    string $user,
    string $pass,
    ?string $database = null,
    string $charset = 'utf8mb4'
): PDO {
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=' . $charset;
    if ($database !== null && $database !== '') {
        $dsn .= ';dbname=' . $database;
    }

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
        PDO::ATTR_PERSISTENT         => false,
    ]);

    // Memastikan charset dan zona waktu konsisten untuk setiap koneksi.
    $pdo->exec('SET NAMES ' . $charset . ' COLLATE ' . $charset . '_unicode_ci');
    $pdo->exec("SET time_zone = '" . DB_TIMEZONE . "'");

    return $pdo;
}

/**
 * Koneksi database utama aplikasi (singleton per request).
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        $pdo = db_connect(DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME);
    } catch (PDOException $exception) {
        error_log('[SIKILAT] Koneksi database gagal: ' . $exception->getMessage());
        throw new RuntimeException(
            'Koneksi database gagal. Periksa konfigurasi database dan pastikan server MySQL berjalan.',
            0,
            $exception
        );
    }

    return $pdo;
}
