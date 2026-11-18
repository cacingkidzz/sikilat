<?php
declare(strict_types=1);

/**
 * SIKILAT - Helper instalasi.
 * Hanya dimuat oleh install.php.
 */

if (!defined('SIKILAT_APP')) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

/**
 * Daftar tabel yang harus ada setelah impor skema.
 *
 * @return array<int, string>
 */
function installer_required_tables(): array
{
    return [
        'users',
        'mata_pelajaran',
        'kelas',
        'ujian',
        'ujian_kelas',
        'paket_soal',
        'kunci_jawaban',
        'hasil_ujian',
        'jawaban_siswa',
        'pengaturan',
        'log_aktivitas',
    ];
}

/**
 * Pemeriksaan kebutuhan sistem.
 *
 * @return array<int, array{label:string, ok:bool, info:string, wajib:bool}>
 */
function installer_checks(): array
{
    $checks = [
        [
            'label' => 'PHP 8.2 atau lebih baru',
            'ok'    => PHP_VERSION_ID >= 80200,
            'info'  => 'Versi terpasang: ' . PHP_VERSION,
            'wajib' => true,
        ],
        [
            'label' => 'Ekstensi PDO MySQL',
            'ok'    => extension_loaded('pdo_mysql'),
            'info'  => 'Dibutuhkan untuk koneksi database.',
            'wajib' => true,
        ],
        [
            'label' => 'Ekstensi mbstring',
            'ok'    => extension_loaded('mbstring'),
            'info'  => 'Dibutuhkan untuk pengolahan teks UTF-8.',
            'wajib' => true,
        ],
        [
            'label' => 'Ekstensi GD',
            'ok'    => extension_loaded('gd'),
            'info'  => 'Dibutuhkan untuk grafik dan dokumen PDF.',
            'wajib' => false,
        ],
        [
            'label' => 'Ekstensi ZIP',
            'ok'    => extension_loaded('zip'),
            'info'  => 'Dibutuhkan untuk ekspor Excel (PhpSpreadsheet).',
            'wajib' => false,
        ],
        [
            'label' => 'Ekstensi Fileinfo',
            'ok'    => extension_loaded('fileinfo'),
            'info'  => 'Dibutuhkan untuk validasi file unggahan.',
            'wajib' => true,
        ],
        [
            'label' => 'Folder uploads/ dapat ditulis',
            'ok'    => is_dir(UPLOADS_PATH) && is_writable(UPLOADS_PATH),
            'info'  => 'Dipakai untuk logo sekolah.',
            'wajib' => true,
        ],
        [
            'label' => 'Folder logs/ dapat ditulis',
            'ok'    => is_dir(LOG_PATH) && is_writable(LOG_PATH),
            'info'  => 'Dipakai untuk log error PHP.',
            'wajib' => true,
        ],
        [
            'label' => 'File skema database tersedia',
            'ok'    => is_readable(DATABASE_PATH . '/db_sikilat.sql'),
            'info'  => 'database/db_sikilat.sql',
            'wajib' => true,
        ],
        [
            'label' => 'Composer vendor terpasang',
            'ok'    => is_file(ROOT_PATH . '/vendor/autoload.php'),
            'info'  => 'Opsional pada tahap 1; dibutuhkan untuk ekspor Excel/PDF.',
            'wajib' => false,
        ],
    ];

    return $checks;
}

/**
 * @param array<int, array{label:string, ok:bool, info:string, wajib:bool}> $checks
 */
function installer_required_ok(array $checks): bool
{
    foreach ($checks as $check) {
        if ($check['wajib'] && !$check['ok']) {
            return false;
        }
    }

    return true;
}

/**
 * Memeriksa keberadaan database pada server MySQL.
 */
function installer_database_exists(PDO $serverPdo, string $database): bool
{
    $stmt = $serverPdo->prepare(
        'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :nama LIMIT 1'
    );
    $stmt->execute(['nama' => $database]);

    return $stmt->fetchColumn() !== false;
}

/**
 * Status tabel pada database yang sudah dipilih.
 *
 * @return array{tables:array<int,string>, missing:array<int,string>, installed:bool, admin_count:int}
 */
function installer_table_status(PDO $pdo): array
{
    /** @var array<int, string> $tables */
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $required = installer_required_tables();
    $missing = array_values(array_diff($required, $tables));
    $installed = $missing === [];

    $adminCount = 0;
    if (in_array('users', $tables, true)) {
        $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    }

    return [
        'tables'      => $tables,
        'missing'     => $missing,
        'installed'   => $installed,
        'admin_count' => $adminCount,
    ];
}

/**
 * Memecah file SQL menjadi statement individual.
 * Mendukung komentar --, #, /* *\/, serta quote ' " ` dan escape backslash.
 *
 * @return array<int, string>
 */
function installer_split_sql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($char === "\n") {
                $inLineComment = false;
                $buffer .= $char;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($char === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if (!$inSingle && !$inDouble && !$inBacktick) {
            if ($char === '-' && $next === '-') {
                $inLineComment = true;
                $i++;
                continue;
            }
            if ($char === '#') {
                $inLineComment = true;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
            if ($char === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }
        }

        if ($inSingle) {
            if ($char === '\\' && $next !== '') {
                $buffer .= $char . $next;
                $i++;
                continue;
            }
            if ($char === "'") {
                if ($next === "'") {
                    $buffer .= "''";
                    $i++;
                    continue;
                }
                $inSingle = false;
            }
        } elseif ($inDouble) {
            if ($char === '\\' && $next !== '') {
                $buffer .= $char . $next;
                $i++;
                continue;
            }
            if ($char === '"') {
                if ($next === '"') {
                    $buffer .= '""';
                    $i++;
                    continue;
                }
                $inDouble = false;
            }
        } elseif ($inBacktick) {
            if ($char === '`') {
                if ($next === '`') {
                    $buffer .= '``';
                    $i++;
                    continue;
                }
                $inBacktick = false;
            }
        } else {
            if ($char === "'") {
                $inSingle = true;
            } elseif ($char === '"') {
                $inDouble = true;
            } elseif ($char === '`') {
                $inBacktick = true;
            }
        }

        $buffer .= $char;
    }

    $statement = trim($buffer);
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}

/**
 * Membuat database (jika belum ada) dan mengimpor skema SIKILAT.
 *
 * @return array{executed:int, tables:int}
 * @throws RuntimeException
 */
function installer_import_database(): array
{
    if (preg_match('/^[A-Za-z0-9_]+$/', DB_NAME) !== 1) {
        throw new RuntimeException('Nama database pada config/database.php tidak valid.');
    }

    $sqlFile = DATABASE_PATH . '/db_sikilat.sql';
    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        throw new RuntimeException('File database/db_sikilat.sql tidak dapat dibaca.');
    }

    $serverPdo = db_connect(DB_HOST, DB_PORT, DB_USER, DB_PASS);
    $serverPdo->exec(
        'CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` '
        . 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $pdo = db_connect(DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME);
    $statements = installer_split_sql($sql);
    $executed = 0;

    foreach ($statements as $statement) {
        if (preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $statement) === 1) {
            continue;
        }

        try {
            $pdo->exec($statement);
            $executed++;
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'Gagal menjalankan perintah SQL: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /** @var array<int, string> $tables */
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff(installer_required_tables(), $tables);

    if ($missing !== []) {
        throw new RuntimeException('Impor belum lengkap. Tabel belum ada: ' . implode(', ', $missing));
    }

    return [
        'executed' => $executed,
        'tables'   => count($tables),
    ];
}
