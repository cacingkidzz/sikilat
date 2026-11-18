<?php
declare(strict_types=1);

/**
 * SIKILAT - Helper tipe soal, normalisasi jawaban, dan skor parsial.
 *
 * Tipe soal berlaku per nomor sehingga satu paket boleh memuat campuran
 * Pilihan Ganda (pg), Benar/Salah (benar_salah), dan Multiple Response
 * (multiple_response).
 *
 * Format penyimpanan jawaban:
 * - pg               : satu huruf A-E
 * - benar_salah      : B (Benar) atau S (Salah)
 * - multiple_response: huruf terurut dipisah koma, contoh A,C
 */

if (!defined('SIKILAT_APP')) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

/**
 * Definisi seluruh tipe soal yang didukung.
 *
 * @return array<string, array{label:string, short:string, options:array<int, string>, multi:bool}>
 */
function soal_tipe_definitions(): array
{
    return [
        'pg' => [
            'label'   => 'Pilihan Ganda',
            'short'   => 'PG',
            'options' => ['A', 'B', 'C', 'D', 'E'],
            'multi'   => false,
        ],
        'benar_salah' => [
            'label'   => 'Benar/Salah',
            'short'   => 'B/S',
            'options' => ['B', 'S'],
            'multi'   => false,
        ],
        'multiple_response' => [
            'label'   => 'Multiple Response',
            'short'   => 'MR',
            'options' => ['A', 'B', 'C', 'D', 'E'],
            'multi'   => true,
        ],
    ];
}

/**
 * Kunci tipe soal yang valid.
 *
 * @return array<int, string>
 */
function soal_tipe_keys(): array
{
    return array_keys(soal_tipe_definitions());
}

/**
 * Normalkan nilai tipe soal; nilai tidak dikenal menjadi `pg`.
 */
function soal_tipe_normalize(mixed $tipe): string
{
    $tipe = is_string($tipe) ? strtolower(trim($tipe)) : '';

    return in_array($tipe, soal_tipe_keys(), true) ? $tipe : 'pg';
}

/**
 * Label panjang tipe soal.
 */
function soal_tipe_label(mixed $tipe): string
{
    $tipe = soal_tipe_normalize($tipe);

    return (string) soal_tipe_definitions()[$tipe]['label'];
}

/**
 * Label pendek untuk badge.
 */
function soal_tipe_short(mixed $tipe): string
{
    $tipe = soal_tipe_normalize($tipe);

    return (string) soal_tipe_definitions()[$tipe]['short'];
}

/**
 * Daftar opsi jawaban untuk satu tipe soal.
 *
 * @return array<int, string>
 */
function soal_tipe_options(mixed $tipe): array
{
    $tipe = soal_tipe_normalize($tipe);

    return soal_tipe_definitions()[$tipe]['options'];
}

/**
 * Apakah tipe soal memakai banyak pilihan sekaligus.
 */
function soal_tipe_is_multi(mixed $tipe): bool
{
    $tipe = soal_tipe_normalize($tipe);

    return (bool) soal_tipe_definitions()[$tipe]['multi'];
}

/**
 * Lengkapi peta tipe soal 1..total dengan default `pg`.
 *
 * @param array<int|string, mixed> $typesByNumber
 * @return array<int, string>
 */
function soal_types_complete(int $totalSoal, array $typesByNumber = []): array
{
    $types = [];
    for ($number = 1; $number <= max(0, $totalSoal); $number++) {
        $types[$number] = soal_tipe_normalize($typesByNumber[$number] ?? 'pg');
    }

    return $types;
}

/**
 * Apakah salah satu nomor memakai Multiple Response.
 *
 * @param array<int|string, mixed> $typesByNumber
 */
function soal_types_have_multi(array $typesByNumber): bool
{
    foreach ($typesByNumber as $tipe) {
        if (soal_tipe_normalize($tipe) === 'multiple_response') {
            return true;
        }
    }

    return false;
}

/**
 * Normalisasi satu jawaban sesuai tipe soal.
 *
 * @return array{ok:bool, value:?string, error:string}
 */
function soal_answer_normalize(mixed $tipe, mixed $raw): array
{
    $tipe = soal_tipe_normalize($tipe);
    $failure = static function (string $message): array {
        return ['ok' => false, 'value' => null, 'error' => $message];
    };

    if ($raw === null) {
        return ['ok' => true, 'value' => null, 'error' => ''];
    }

    if (is_array($raw)) {
        if ($tipe !== 'multiple_response') {
            return $failure('Jawaban untuk soal ' . soal_tipe_label($tipe) . ' hanya boleh satu pilihan.');
        }

        $letters = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                return $failure('Pilihan jawaban tidak valid.');
            }

            $item = strtoupper(trim($item));
            if ($item === '') {
                continue;
            }

            if (preg_match('/^[A-E]$/', $item) !== 1) {
                return $failure('Pilihan Multiple Response harus A, B, C, D, atau E.');
            }

            $letters[$item] = true;
        }

        if ($letters === []) {
            return ['ok' => true, 'value' => null, 'error' => ''];
        }

        $ordered = array_keys($letters);
        sort($ordered);

        return ['ok' => true, 'value' => implode(',', $ordered), 'error' => ''];
    }

    if (!is_string($raw)) {
        return $failure('Nilai jawaban tidak valid.');
    }

    $value = strtoupper(trim($raw));
    if ($value === '') {
        return ['ok' => true, 'value' => null, 'error' => ''];
    }

    if ($tipe === 'multiple_response') {
        $clean = preg_replace('/[\s,;]+/u', '', $value) ?? '';
        if ($clean === '' || preg_match('/^[A-E]+$/', $clean) !== 1) {
            return $failure('Pilihan Multiple Response harus berupa huruf A-E, contoh AC atau A,C.');
        }

        $letters = array_values(array_unique(mb_str_split($clean)));
        sort($letters);

        return ['ok' => true, 'value' => implode(',', $letters), 'error' => ''];
    }

    if ($tipe === 'benar_salah') {
        if (in_array($value, ['B', 'BENAR', 'TRUE'], true)) {
            return ['ok' => true, 'value' => 'B', 'error' => ''];
        }

        if (in_array($value, ['S', 'SALAH', 'FALSE'], true)) {
            return ['ok' => true, 'value' => 'S', 'error' => ''];
        }

        return $failure('Jawaban Benar/Salah harus B (Benar) atau S (Salah).');
    }

    if (preg_match('/^[A-E]$/', $value) !== 1) {
        return $failure('Pilihan jawaban Pilihan Ganda harus A, B, C, D, atau E.');
    }

    return ['ok' => true, 'value' => $value, 'error' => ''];
}

/**
 * Pecah jawaban tersimpan menjadi daftar huruf.
 *
 * @return array<int, string>
 */
function soal_answer_letters(?string $answer): array
{
    if ($answer === null || trim($answer) === '') {
        return [];
    }

    $letters = array_values(array_filter(
        array_map('trim', explode(',', strtoupper($answer))),
        static fn (string $letter): bool => $letter !== ''
    ));

    return $letters;
}

/**
 * Tampilan jawaban yang ramah dibaca.
 */
function soal_answer_display(mixed $tipe, ?string $answer, string $empty = '-'): string
{
    $tipe = soal_tipe_normalize($tipe);

    if ($answer === null || trim($answer) === '') {
        return $empty;
    }

    if ($tipe === 'benar_salah') {
        return match (strtoupper(trim($answer))) {
            'B' => 'Benar',
            'S' => 'Salah',
            default => strtoupper(trim($answer)),
        };
    }

    if ($tipe === 'multiple_response') {
        return implode(', ', soal_answer_letters($answer));
    }

    return strtoupper(trim($answer));
}

/**
 * Hitung skor satu butir.
 *
 * Multiple Response memakai skor parsial:
 *   skor = max(0, (jumlah opsi benar yang dipilih - jumlah opsi salah) / jumlah kunci)
 * Skor penuh hanya diberikan bila himpunan jawaban sama persis dengan kunci.
 * Setiap butir memiliki skor maksimum 1.
 *
 * @return array{skor:float, is_benar:?int, benar:int, salah:int, total_kunci:int}
 */
function soal_score(mixed $tipe, string $key, ?string $answer): array
{
    $tipe = soal_tipe_normalize($tipe);
    $empty = ['skor' => 0.0, 'is_benar' => null, 'benar' => 0, 'salah' => 0, 'total_kunci' => 0];

    if ($answer === null || trim($answer) === '') {
        return $empty;
    }

    $key = strtoupper(trim($key));
    $answer = strtoupper(trim($answer));

    if ($tipe !== 'multiple_response') {
        $full = $answer === $key;

        return [
            'skor'        => $full ? 1.0 : 0.0,
            'is_benar'    => $full ? 1 : 0,
            'benar'       => $full ? 1 : 0,
            'salah'       => $full ? 0 : 1,
            'total_kunci' => 1,
        ];
    }

    $keyLetters = array_values(array_unique(soal_answer_letters($key)));
    $answerLetters = array_values(array_unique(soal_answer_letters($answer)));
    $totalKunci = count($keyLetters);

    if ($totalKunci === 0) {
        return $empty;
    }

    $benar = count(array_intersect($answerLetters, $keyLetters));
    $salah = count(array_diff($answerLetters, $keyLetters));
    $ratio = ($benar - $salah) / $totalKunci;
    $ratio = max(0.0, min(1.0, $ratio));

    sort($keyLetters);
    sort($answerLetters);
    $full = $keyLetters === $answerLetters;

    if ($full) {
        $ratio = 1.0;
    }

    return [
        'skor'        => round($ratio, 4),
        'is_benar'    => $full ? 1 : 0,
        'benar'       => $benar,
        'salah'       => $salah,
        'total_kunci' => $totalKunci,
    ];
}

/**
 * Parse input cepat menjadi peta nomor => jawaban ternormalisasi atau null.
 *
 * Bila paket tidak memuat Multiple Response, mode ringkas lama dipertahankan:
 * satu karakter per nomor (A-E, B/S, atau - untuk kosong). Bila paket memuat
 * Multiple Response, dipakai satu token per nomor yang dipisah spasi/koma/
 * titik koma; jawaban multi ditulis menyambung, mis. AC atau (AC).
 *
 * @param array<int|string, mixed> $typesByNumber
 * @return array{ok:bool, answers:array<int, ?string>, error:string}
 */
function soal_parse_bulk(string $text, int $totalSoal, array $typesByNumber = []): array
{
    $result = ['ok' => false, 'answers' => [], 'error' => ''];
    $totalSoal = max(0, $totalSoal);
    $types = soal_types_complete($totalSoal, $typesByNumber);

    if ($totalSoal === 0) {
        $result['error'] = 'Jumlah soal belum ditentukan.';

        return $result;
    }

    if (mb_strlen($text) > 5000) {
        $result['error'] = 'Teks jawaban terlalu panjang.';

        return $result;
    }

    $hasMulti = soal_types_have_multi($types);

    if (!$hasMulti) {
        $position = 0;

        foreach (mb_str_split($text) as $character) {
            if (preg_match('/^[\s,;]$/u', $character) === 1) {
                continue;
            }

            if ($character === '-' || $character === '_' || $character === '.') {
                $position++;
                $result['answers'][$position] = null;
            } else {
                $position++;
                $tipe = $types[$position] ?? 'pg';
                $normalized = soal_answer_normalize($tipe, $character);

                if (!$normalized['ok'] || $normalized['value'] === null) {
                    $allowed = $tipe === 'benar_salah' ? 'B/S' : 'A–E';
                    $result['error'] = 'Karakter "' . $character . '" tidak valid pada posisi '
                        . $position . '. Gunakan ' . $allowed . ', atau - untuk nomor kosong.';

                    return $result;
                }

                $result['answers'][$position] = $normalized['value'];
            }

            if ($position > $totalSoal) {
                $result['error'] = 'Jumlah jawaban melebihi ' . $totalSoal . ' soal.';

                return $result;
            }
        }

        $result['ok'] = true;

        return $result;
    }

    $tokens = preg_split('/[\s,;]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    if ($tokens === false) {
        $tokens = [];
    }

    $position = 0;
    foreach ($tokens as $token) {
        $position++;
        if ($position > $totalSoal) {
            $result['error'] = 'Jumlah jawaban melebihi ' . $totalSoal . ' soal.';

            return $result;
        }

        if (in_array($token, ['-', '_', '.'], true)) {
            $result['answers'][$position] = null;
            continue;
        }

        if (preg_match('/^\((.*)\)$/u', $token, $matches) === 1) {
            $token = trim($matches[1]);
        }

        $tipe = $types[$position] ?? 'pg';
        $normalized = soal_answer_normalize($tipe, $token);

        if (!$normalized['ok'] || $normalized['value'] === null) {
            $example = match ($tipe) {
                'benar_salah' => 'B atau S',
                'multiple_response' => 'gabungan huruf seperti AC',
                default => 'A–E',
            };
            $result['error'] = 'Token "' . $token . '" tidak valid untuk nomor ' . $position
                . ' (' . soal_tipe_label($tipe) . '). Gunakan ' . $example . ', atau - untuk kosong.';

            return $result;
        }

        $result['answers'][$position] = $normalized['value'];
    }

    $result['ok'] = true;

    return $result;
}

/**
 * Susun teks input cepat dari peta jawaban.
 *
 * @param array<int, ?string>      $answers
 * @param array<int|string, mixed> $typesByNumber
 */
function soal_bulk_text(array $answers, int $totalSoal, array $typesByNumber = []): string
{
    $types = soal_types_complete($totalSoal, $typesByNumber);

    if (soal_types_have_multi($types)) {
        $tokens = [];
        for ($number = 1; $number <= $totalSoal; $number++) {
            $answer = $answers[$number] ?? null;
            if ($answer === null || $answer === '') {
                $tokens[] = '-';
            } elseif (($types[$number] ?? 'pg') === 'multiple_response') {
                $tokens[] = implode('', soal_answer_letters($answer));
            } else {
                $tokens[] = $answer;
            }
        }

        return implode(' ', $tokens);
    }

    $text = '';
    for ($number = 1; $number <= $totalSoal; $number++) {
        $text .= $answers[$number] ?? '-';
    }

    return $text;
}

/**
 * Urutan kolom opsi untuk laporan: A-E lalu S (Benar/Salah).
 *
 * @return array<int, string>
 */
function soal_option_letter_order(): array
{
    return ['A', 'B', 'C', 'D', 'E', 'S'];
}

/**
 * Kumpulkan kolom opsi yang perlu ditampilkan dari hasil analisis butir.
 *
 * @param array<int, array<string, mixed>> $items Seluruh item lintas paket.
 * @return array<int, string>
 */
function soal_option_letters_from_items(array $items): array
{
    $used = [];
    $queue = array_values($items);

    while ($queue !== []) {
        $entry = array_shift($queue);
        if (!is_array($entry)) {
            continue;
        }

        if (isset($entry['items']) && is_array($entry['items'])) {
            foreach ($entry['items'] as $child) {
                $queue[] = $child;
            }
        }

        $options = $entry['opsi'] ?? null;
        if (!is_array($options)) {
            continue;
        }

        foreach (array_keys($options) as $letter) {
            $used[(string) $letter] = true;
        }
    }

    $ordered = [];
    foreach (soal_option_letter_order() as $letter) {
        if (isset($used[$letter])) {
            $ordered[] = $letter;
        }
    }

    return $ordered;
}
