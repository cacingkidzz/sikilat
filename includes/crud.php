<?php
declare(strict_types=1);

/**
 * SIKILAT - Helper modul CRUD tahap 2.
 * Berisi utilitas validasi, akses kepemilikan ujian, dan render kecil
 * yang dipakai oleh modul master data, pengguna, ujian, dan paket soal.
 */

if (!defined('SIKILAT_APP')) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

/**
 * Ubah array POST/GET menjadi daftar ID integer unik yang valid.
 *
 * @return array<int, int>
 */
function crud_int_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $ids = [];
    foreach ($value as $item) {
        if (is_int($item) || (is_string($item) && ctype_digit($item))) {
            $id = (int) $item;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
    }

    return array_values($ids);
}

/**
 * Batasi teks sesuai panjang kolom dan buang karakter kontrol.
 */
function crud_text(mixed $value, int $maxLength = 0): string
{
    if (!is_string($value)) {
        return '';
    }

    $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');

    if ($maxLength > 0) {
        $text = mb_substr($text, 0, $maxLength);
    }

    return $text;
}

/**
 * Nilai field dari array data untuk mengisi ulang form.
 */
function crud_value(array $data, string $key, string $default = ''): string
{
    $value = $data[$key] ?? $default;

    return is_scalar($value) ? (string) $value : $default;
}

/**
 * Atribut checked untuk checkbox.
 */
function crud_checked(bool $condition): string
{
    return $condition ? ' checked' : '';
}

/**
 * Atribut selected untuk option.
 */
function crud_selected(mixed $value, mixed $expected): string
{
    return (string) $value === (string) $expected ? ' selected' : '';
}

/**
 * Daftar pilihan status ujian.
 *
 * @return array<string, string>
 */
function crud_status_options(): array
{
    return [
        'draft'   => 'Draft',
        'aktif'   => 'Aktif',
        'selesai' => 'Selesai',
    ];
}

/**
 * Daftar pilihan tingkat kelas.
 *
 * @return array<int, string>
 */
function crud_tingkat_options(): array
{
    return ['VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
}

/**
 * Validasi tanggal format Y-m-d.
 */
function crud_valid_date(string $date): bool
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches) !== 1) {
        return false;
    }

    return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
}

/**
 * Validasi jam format H:i atau H:i:s.
 */
function crud_valid_time(string $time): bool
{
    return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time) === 1;
}

/**
 * Normalisasi jam ke H:i:s agar konsisten dengan kolom TIME.
 */
function crud_normalize_time(string $time): string
{
    return strlen($time) === 5 ? $time . ':00' : $time;
}

/**
 * Apakah pengguna aktif boleh mengelola ujian tertentu.
 * Admin boleh semua; guru hanya ujian yang ia buat.
 */
function crud_can_manage_ujian(array $ujian): bool
{
    $user = auth_user();
    if ($user === null) {
        return false;
    }

    if ($user['role'] === 'admin') {
        return true;
    }

    return $user['role'] === 'guru'
        && isset($ujian['created_by'])
        && (int) $ujian['created_by'] === $user['id'];
}

/**
 * Hentikan permintaan jika pengguna tidak berhak mengelola ujian.
 */
function crud_require_manage_ujian(array $ujian): void
{
    if (crud_can_manage_ujian($ujian)) {
        return;
    }

    render_error_page(
        403,
        'Akses Ditolak',
        'Anda tidak memiliki hak untuk mengubah ujian ini.'
    );
}

/**
 * Render daftar error validasi sebagai alert Bootstrap.
 *
 * @param array<int, string> $errors
 */
function crud_errors_html(array $errors): string
{
    if ($errors === []) {
        return '';
    }

    $items = '';
    foreach ($errors as $error) {
        $items .= '<li>' . e($error) . '</li>';
    }

    return '<div class="alert alert-danger d-flex gap-2 align-items-start mb-3" role="alert">'
        . '<i class="bi bi-exclamation-triangle-fill mt-1"></i>'
        . '<div><div class="fw-semibold mb-1">Periksa kembali data berikut:</div>'
        . '<ul class="mb-0 ps-3 small">' . $items . '</ul></div></div>';
}

/**
 * Badge status aktif/nonaktif.
 */
function crud_active_badge(int|bool $active): string
{
    return $active
        ? '<span class="badge text-bg-success-subtle text-success-emphasis border border-success-subtle">Aktif</span>'
        : '<span class="badge text-bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">Nonaktif</span>';
}

/**
 * Nama role dalam bentuk badge.
 */
function crud_role_badge(string $role): string
{
    return $role === 'admin'
        ? '<span class="badge text-bg-primary-subtle text-primary-emphasis border border-primary-subtle"><i class="bi bi-shield-check me-1"></i>Administrator</span>'
        : '<span class="badge text-bg-info-subtle text-info-emphasis border border-info-subtle"><i class="bi bi-person-workspace me-1"></i>Guru</span>';
}

/**
 * Cek apakah masih ada minimal satu administrator aktif selain ID tertentu.
 */
function crud_other_active_admin_exists(int $excludeId): bool
{
    $stmt = db()->prepare(
        "SELECT id FROM users
         WHERE role = 'admin' AND is_active = 1 AND id <> :id
         LIMIT 1"
    );
    $stmt->execute(['id' => $excludeId]);

    return $stmt->fetch() !== false;
}

/**
 * Cek apakah pengguna memiliki ujian yang dibuat.
 */
function crud_user_has_exams(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM ujian WHERE created_by = :id');
    $stmt->execute(['id' => $userId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Query pilihan ujian yang boleh dikelola pengguna saat ini.
 *
 * @return array<int, array<string, mixed>>
 */
function crud_accessible_exams(): array
{
    $user = auth_user();
    if ($user === null) {
        return [];
    }

    if ($user['role'] === 'admin') {
        return db()->query(
            'SELECT u.id, u.nama, u.status, mp.nama AS mapel
             FROM ujian u
             INNER JOIN mata_pelajaran mp ON mp.id = u.mata_pelajaran_id
             ORDER BY u.tanggal_ujian DESC, u.id DESC'
        )->fetchAll();
    }

    $stmt = db()->prepare(
        'SELECT u.id, u.nama, u.status, mp.nama AS mapel
         FROM ujian u
         INNER JOIN mata_pelajaran mp ON mp.id = u.mata_pelajaran_id
         WHERE u.created_by = :user_id
         ORDER BY u.tanggal_ujian DESC, u.id DESC'
    );
    $stmt->execute(['user_id' => $user['id']]);

    return $stmt->fetchAll();
}

/**
 * Ringkasan kelengkapan kunci jawaban seluruh paket pada satu ujian.
 *
 * @return array{
 *   exists: bool,
 *   jumlah_soal: int,
 *   packages: array<int, array<string, mixed>>,
 *   has_package: bool,
 *   complete: bool,
 *   incomplete: array<int, array<string, mixed>>,
 *   missing_total: int
 * }
 */
function crud_exam_key_status(int $ujianId, ?int $totalSoalOverride = null): array
{
    $status = [
        'exists'        => false,
        'jumlah_soal'   => 0,
        'packages'      => [],
        'has_package'   => false,
        'complete'      => false,
        'incomplete'    => [],
        'missing_total' => 0,
    ];

    $stmt = db()->prepare('SELECT id, jumlah_soal FROM ujian WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $ujianId]);
    $ujian = $stmt->fetch();

    if ($ujian === false) {
        return $status;
    }

    $totalSoal = $totalSoalOverride !== null
        ? max(1, $totalSoalOverride)
        : (int) $ujian['jumlah_soal'];

    $stmt = db()->prepare(
        'SELECT ps.id, ps.kode_paket, COUNT(kj.id) AS terisi
         FROM paket_soal ps
         LEFT JOIN kunci_jawaban kj
                ON kj.paket_soal_id = ps.id
               AND kj.nomor_soal BETWEEN 1 AND :total_soal
         WHERE ps.ujian_id = :ujian_id
         GROUP BY ps.id, ps.kode_paket
         ORDER BY ps.kode_paket ASC'
    );
    $stmt->execute(['total_soal' => $totalSoal, 'ujian_id' => $ujianId]);

    $packages = [];
    foreach ($stmt->fetchAll() as $row) {
        $terisi = (int) $row['terisi'];
        $missing = max(0, $totalSoal - $terisi);
        $package = [
            'id'         => (int) $row['id'],
            'kode_paket' => (string) $row['kode_paket'],
            'terisi'     => $terisi,
            'missing'    => $missing,
            'complete'   => $missing === 0,
        ];

        $packages[] = $package;

        if ($missing > 0) {
            $status['incomplete'][] = $package;
            $status['missing_total'] += $missing;
        }
    }

    $status['exists'] = true;
    $status['jumlah_soal'] = $totalSoal;
    $status['packages'] = $packages;
    $status['has_package'] = $packages !== [];
    $status['complete'] = $packages !== [] && $status['incomplete'] === [];

    return $status;
}

/**
 * Pesan alasan kunci jawaban belum siap untuk aktivasi.
 *
 * @param array<string, mixed> $status Hasil crud_exam_key_status().
 */
function crud_exam_key_problem(array $status): string
{
    if (empty($status['exists'])) {
        return 'Ujian tidak ditemukan.';
    }

    if (empty($status['has_package'])) {
        return 'Ujian belum memiliki paket soal.';
    }

    if (!empty($status['complete'])) {
        return '';
    }

    $parts = [];
    $incomplete = is_array($status['incomplete'] ?? null) ? $status['incomplete'] : [];
    foreach (array_slice($incomplete, 0, 5) as $package) {
        $parts[] = 'Paket ' . (string) $package['kode_paket']
            . ' kurang ' . (int) $package['missing'] . ' kunci';
    }

    if (count($incomplete) > 5) {
        $parts[] = 'dan ' . (count($incomplete) - 5) . ' paket lain';
    }

    return 'Kunci jawaban belum lengkap: ' . implode(', ', $parts) . '.';
}

/**
 * Normalisasi input kunci massal (contoh: ABCDEABCDE).
 *
 * Bila paket memuat Multiple Response, format token dipakai: satu token per
 * nomor dengan jawaban multi ditulis menyambung (AC) atau (AC).
 *
 * @param array<int|string, mixed> $typesByNumber
 * @return array{ok: bool, keys: array<int, string>, error: string}
 */
function crud_key_bulk_normalize(string $text, array $typesByNumber = []): array
{
    $result = ['ok' => false, 'keys' => [], 'error' => ''];

    if (trim($text) === '') {
        $result['error'] = 'Teks kunci wajib diisi.';

        return $result;
    }

    $totalSoal = max(200, count($typesByNumber));
    $parsed = soal_parse_bulk($text, $totalSoal, $typesByNumber);

    if (!$parsed['ok']) {
        $result['error'] = $parsed['error'];

        return $result;
    }

    $keys = [];
    foreach ($parsed['answers'] as $number => $value) {
        if (is_string($value) && $value !== '') {
            $keys[(int) $number] = $value;
        }
    }

    if ($keys === []) {
        $result['error'] = 'Teks kunci tidak berisi jawaban yang dapat disimpan.';

        return $result;
    }

    return ['ok' => true, 'keys' => $keys, 'error' => ''];
}
