<?php
declare(strict_types=1);

/**
 * SIKILAT - Helper alur ujian siswa (Tahap 4).
 *
 * Semua data identitas, paket, dan waktu mulai disimpan di session server.
 * Browser hanya mengirim pilihan jawaban; kunci jawaban tidak pernah
 * dikirim ke halaman siswa.
 */

if (!defined('SIKILAT_APP')) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

/**
 * Cegah halaman ujian disimpan di cache browser/proxy.
 */
function siswa_no_cache(): void
{
    if (headers_sent()) {
        return;
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Robots-Tag: noindex, nofollow');
}

/**
 * Daftar ujian yang sedang aktif.
 *
 * @return array<int, array<string, mixed>>
 */
function siswa_active_exams(): array
{
    return db()->query(
        "SELECT u.id, u.nama, u.deskripsi, u.jumlah_soal, u.kkm,
                u.tanggal_ujian, u.waktu_mulai, u.waktu_selesai,
                mp.nama AS mapel_nama, mp.kode AS mapel_kode,
                (SELECT COUNT(*)
                   FROM ujian_kelas uk
                   INNER JOIN kelas k ON k.id = uk.kelas_id
                  WHERE uk.ujian_id = u.id AND k.is_active = 1) AS kelas_count
         FROM ujian u
         INNER JOIN mata_pelajaran mp ON mp.id = u.mata_pelajaran_id
         WHERE u.status = 'aktif'
         ORDER BY u.tanggal_ujian ASC, u.waktu_mulai ASC, u.id ASC"
    )->fetchAll();
}

/**
 * Data satu ujian beserta mata pelajarannya.
 *
 * @return array<string, mixed>|null
 */
function siswa_exam(int $ujianId): ?array
{
    $stmt = db()->prepare(
        'SELECT u.*, mp.nama AS mapel_nama, mp.kode AS mapel_kode
         FROM ujian u
         INNER JOIN mata_pelajaran mp ON mp.id = u.mata_pelajaran_id
         WHERE u.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $ujianId]);
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

/**
 * Kelas aktif yang terdaftar sebagai peserta ujian.
 *
 * @return array<int, array<string, mixed>>
 */
function siswa_exam_classes(int $ujianId): array
{
    $stmt = db()->prepare(
        'SELECT k.id, k.nama, k.tingkat, k.tahun_ajaran
         FROM ujian_kelas uk
         INNER JOIN kelas k ON k.id = uk.kelas_id
         WHERE uk.ujian_id = :ujian_id AND k.is_active = 1
         ORDER BY k.tingkat ASC, k.nama ASC'
    );
    $stmt->execute(['ujian_id' => $ujianId]);

    return $stmt->fetchAll();
}

/**
 * Paket soal beserta kelengkapan kunci pada rentang 1..jumlah_soal.
 *
 * @return array<int, array<string, mixed>>
 */
function siswa_exam_packages(int $ujianId, int $totalSoal): array
{
    $stmt = db()->prepare(
        'SELECT ps.id, ps.kode_paket, ps.keterangan,
                COUNT(kj.id) AS kunci_terisi
         FROM paket_soal ps
         LEFT JOIN kunci_jawaban kj
                ON kj.paket_soal_id = ps.id
               AND kj.nomor_soal BETWEEN 1 AND :total_soal
         WHERE ps.ujian_id = :ujian_id
         GROUP BY ps.id, ps.kode_paket, ps.keterangan
         ORDER BY ps.kode_paket ASC'
    );
    $stmt->execute(['total_soal' => $totalSoal, 'ujian_id' => $ujianId]);

    $packages = [];
    foreach ($stmt->fetchAll() as $row) {
        $terisi = (int) $row['kunci_terisi'];
        $row['kunci_terisi'] = $terisi;
        $row['kunci_kurang'] = max(0, $totalSoal - $terisi);
        $row['complete'] = $row['kunci_kurang'] === 0;
        $packages[] = $row;
    }

    return $packages;
}

/**
 * Hitung kunci valid yang tersedia untuk satu paket.
 */
function siswa_package_key_count(int $paketId, int $totalSoal): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM kunci_jawaban
         WHERE paket_soal_id = :paket_id
           AND nomor_soal BETWEEN 1 AND :total_soal'
    );
    $stmt->execute(['paket_id' => $paketId, 'total_soal' => $totalSoal]);

    return (int) $stmt->fetchColumn();
}

/**
 * Hapus sesi pengerjaan yang sedang berjalan.
 */
function siswa_clear_attempt(): void
{
    unset($_SESSION['siswa_attempt']);
}

/**
 * Context pengerjaan aktif yang sudah diverifikasi ke database.
 *
 * @return array<string, mixed>|null
 */
function siswa_attempt_context(): ?array
{
    $attempt = $_SESSION['siswa_attempt'] ?? null;
    if (!is_array($attempt)) {
        return null;
    }

    $ujianId = filter_var($attempt['ujian_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $kelasId = filter_var($attempt['kelas_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $paketId = filter_var($attempt['paket_soal_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $nama = is_string($attempt['nama'] ?? null) ? trim((string) $attempt['nama']) : '';
    $absen = is_string($attempt['nomor_absen'] ?? null) ? trim((string) $attempt['nomor_absen']) : '';

    if ($ujianId === false || $kelasId === false || $paketId === false || $nama === '' || $absen === '') {
        siswa_clear_attempt();

        return null;
    }

    $stmt = db()->prepare(
        "SELECT u.id AS ujian_id, u.nama AS ujian_nama, u.status, u.jumlah_soal, u.kkm,
                u.tanggal_ujian, u.waktu_mulai, u.waktu_selesai, u.deskripsi,
                mp.nama AS mapel_nama, mp.kode AS mapel_kode,
                k.id AS kelas_id, k.nama AS kelas_nama, k.tingkat, k.tahun_ajaran,
                ps.id AS paket_soal_id, ps.kode_paket, ps.keterangan AS paket_keterangan
         FROM ujian u
         INNER JOIN mata_pelajaran mp ON mp.id = u.mata_pelajaran_id
         INNER JOIN ujian_kelas uk ON uk.ujian_id = u.id AND uk.kelas_id = :kelas_id
         INNER JOIN kelas k ON k.id = uk.kelas_id
         INNER JOIN paket_soal ps ON ps.id = :paket_id AND ps.ujian_id = u.id
         WHERE u.id = :ujian_id
         LIMIT 1"
    );
    $stmt->execute([
        'kelas_id' => (int) $kelasId,
        'paket_id' => (int) $paketId,
        'ujian_id' => (int) $ujianId,
    ]);
    $row = $stmt->fetch();

    if ($row === false || !in_array((string) $row['status'], ['aktif', 'selesai'], true)) {
        siswa_clear_attempt();

        return null;
    }

    $totalSoal = (int) $row['jumlah_soal'];
    $startedTs = (int) ($attempt['started_ts'] ?? 0);
    if ($startedTs <= 0 || $startedTs > time() + 300) {
        $startedTs = time();
        $_SESSION['siswa_attempt']['started_ts'] = $startedTs;
    }

    $token = is_string($attempt['token'] ?? null) ? (string) $attempt['token'] : '';
    if (preg_match('/^[a-f0-9]{32,64}$/', $token) !== 1) {
        $token = bin2hex(random_bytes(16));
        $_SESSION['siswa_attempt']['token'] = $token;
    }

    $row['nama_siswa'] = $nama;
    $row['nomor_absen'] = $absen;
    $row['started_ts'] = $startedTs;
    $row['attempt_token'] = $token;
    $row['jumlah_soal'] = $totalSoal;
    $row['kunci_terisi'] = siswa_package_key_count((int) $paketId, $totalSoal);
    $row['keys_complete'] = $row['kunci_terisi'] >= $totalSoal;

    $typeStmt = db()->prepare(
        'SELECT nomor_soal, tipe_soal
         FROM kunci_jawaban
         WHERE paket_soal_id = :paket_id
           AND nomor_soal BETWEEN 1 AND :total_soal
         ORDER BY nomor_soal ASC'
    );
    $typeStmt->execute(['paket_id' => (int) $paketId, 'total_soal' => $totalSoal]);

    $types = [];
    foreach ($typeStmt->fetchAll() as $typeRow) {
        $number = (int) $typeRow['nomor_soal'];
        if ($number >= 1 && $number <= $totalSoal) {
            $types[$number] = soal_tipe_normalize($typeRow['tipe_soal'] ?? 'pg');
        }
    }

    $row['tipe_soal'] = soal_types_complete($totalSoal, $types);
    $row['has_multi'] = soal_types_have_multi($row['tipe_soal']);

    return $row;
}

/**
 * Mulai sesi pengerjaan baru di session server.
 *
 * @param array<string, mixed> $exam
 */
function siswa_start_attempt(array $exam, int $kelasId, int $paketId, string $nama, string $absen): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    unset($_SESSION['siswa_result']);

    $_SESSION['siswa_attempt'] = [
        'ujian_id'     => (int) $exam['id'],
        'kelas_id'     => $kelasId,
        'paket_soal_id' => $paketId,
        'nama'         => $nama,
        'nomor_absen'  => $absen,
        'started_ts'   => time(),
        'token'        => bin2hex(random_bytes(16)),
    ];
}

/**
 * Hapus konfirmasi hasil yang sedang ditampilkan.
 */
function siswa_clear_result(): void
{
    unset($_SESSION['siswa_result']);
}

/**
 * Hasil ujian milik session ini, tanpa menerima ID dari URL.
 *
 * @return array<string, mixed>|null
 */
function siswa_result_context(): ?array
{
    $result = $_SESSION['siswa_result'] ?? null;
    if (!is_array($result)) {
        return null;
    }

    $hasilId = filter_var($result['hasil_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($hasilId === false) {
        siswa_clear_result();

        return null;
    }

    $stmt = db()->prepare(
        'SELECT h.*, u.nama AS ujian_nama, u.kkm, u.tanggal_ujian, u.waktu_mulai, u.waktu_selesai,
                mp.nama AS mapel_nama, mp.kode AS mapel_kode,
                k.nama AS kelas_nama, k.tingkat, k.tahun_ajaran,
                ps.kode_paket, ps.keterangan AS paket_keterangan
         FROM hasil_ujian h
         INNER JOIN ujian u ON u.id = h.ujian_id
         INNER JOIN mata_pelajaran mp ON mp.id = u.mata_pelajaran_id
         INNER JOIN kelas k ON k.id = h.kelas_id
         INNER JOIN paket_soal ps ON ps.id = h.paket_soal_id
         WHERE h.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => (int) $hasilId]);
    $row = $stmt->fetch();

    if ($row === false) {
        siswa_clear_result();

        return null;
    }

    $expected = [
        'ujian_id'    => (int) ($result['ujian_id'] ?? 0),
        'kelas_id'    => (int) ($result['kelas_id'] ?? 0),
        'nama_siswa'  => (string) ($result['nama_siswa'] ?? ''),
        'nomor_absen' => (string) ($result['nomor_absen'] ?? ''),
    ];

    if ((int) $row['ujian_id'] !== $expected['ujian_id']
        || (int) $row['kelas_id'] !== $expected['kelas_id']
        || (string) $row['nama_siswa'] !== $expected['nama_siswa']
        || (string) $row['nomor_absen'] !== $expected['nomor_absen']
    ) {
        siswa_clear_result();

        return null;
    }

    $row['hasil_id'] = (int) $row['id'];
    $row['attempt_token'] = is_string($result['attempt_token'] ?? null) ? $result['attempt_token'] : '';

    $typeStmt = db()->prepare(
        "SELECT COUNT(*) FROM jawaban_siswa
         WHERE hasil_ujian_id = :hasil_id AND tipe_soal = 'multiple_response'"
    );
    $typeStmt->execute(['hasil_id' => (int) $row['id']]);
    $row['has_multi'] = (int) $typeStmt->fetchColumn() > 0;

    return $row;
}

/**
 * Parse input cepat berurutan menjadi peta nomor => jawaban atau null.
 *
 * Aturan lengkap mengikuti soal_parse_bulk(): mode ringkas satu karakter per
 * nomor bila tidak ada Multiple Response, atau satu token per nomor bila ada.
 *
 * @param array<int|string, mixed> $typesByNumber
 * @return array{ok: bool, tokens: array<int, ?string>, error: string}
 */
function siswa_parse_bulk_answers(string $text, int $totalSoal, array $typesByNumber = []): array
{
    $parsed = soal_parse_bulk($text, $totalSoal, $typesByNumber);

    return [
        'ok'     => $parsed['ok'],
        'tokens' => $parsed['answers'],
        'error'  => $parsed['error'],
    ];
}

/**
 * Susun teks input cepat dari peta jawaban.
 *
 * @param array<int, ?string>      $answers
 * @param array<int|string, mixed> $typesByNumber
 */
function siswa_bulk_text(array $answers, int $totalSoal, array $typesByNumber = []): string
{
    return soal_bulk_text($answers, $totalSoal, $typesByNumber);
}

/**
 * Gabungkan pilihan per nomor dan input cepat menjadi jawaban final.
 *
 * Bila keduanya terisi, keduanya wajib sama agar sinkronisasi UI tidak
 * dapat dilewati. Bila pilihan per nomor kosong (mode tanpa JavaScript),
 * input cepat dipakai sebagai sumber jawaban.
 *
 * @param array<int|string, mixed> $typesByNumber
 * @return array{ok: bool, answers: array<int, ?string>, error: string}
 */
function siswa_collect_answers(
    mixed $postedAnswers,
    string $bulkText,
    int $totalSoal,
    array $typesByNumber = []
): array {
    $types = soal_types_complete($totalSoal, $typesByNumber);
    $answers = array_fill(1, $totalSoal, null);
    $hasRadioAnswer = false;

    if ($postedAnswers !== null) {
        if (!is_array($postedAnswers)) {
            return ['ok' => false, 'answers' => [], 'error' => 'Format jawaban tidak valid.'];
        }

        foreach ($postedAnswers as $key => $value) {
            if (!is_int($key) && !(is_string($key) && ctype_digit($key))) {
                return ['ok' => false, 'answers' => [], 'error' => 'Format nomor jawaban tidak valid.'];
            }

            $number = (int) $key;
            if ($number < 1 || $number > $totalSoal) {
                return ['ok' => false, 'answers' => [], 'error' => 'Nomor jawaban di luar rentang soal.'];
            }

            $tipe = $types[$number] ?? 'pg';
            $normalized = soal_answer_normalize($tipe, $value);

            if (!$normalized['ok']) {
                return [
                    'ok'      => false,
                    'answers' => [],
                    'error'   => 'Nomor ' . $number . ': ' . $normalized['error'],
                ];
            }

            if ($normalized['value'] === null) {
                $answers[$number] = null;
                continue;
            }

            $answers[$number] = $normalized['value'];
            $hasRadioAnswer = true;
        }
    }

    $bulk = trim($bulkText);
    if ($bulk !== '') {
        $parsed = siswa_parse_bulk_answers($bulk, $totalSoal, $types);
        if (!$parsed['ok']) {
            return ['ok' => false, 'answers' => [], 'error' => $parsed['error']];
        }

        if ($parsed['tokens'] !== []) {
            $bulkAnswers = array_fill(1, $totalSoal, null);
            foreach ($parsed['tokens'] as $number => $answer) {
                if ($number >= 1 && $number <= $totalSoal) {
                    $bulkAnswers[$number] = $answer;
                }
            }

            if ($hasRadioAnswer && $bulkAnswers !== $answers) {
                return [
                    'ok'    => false,
                    'answers' => [],
                    'error' => 'Input cepat dan pilihan per nomor tidak sama. Periksa kembali jawaban Anda.',
                ];
            }

            if (!$hasRadioAnswer) {
                $answers = $bulkAnswers;
            }
        }
    }

    return ['ok' => true, 'answers' => $answers, 'error' => ''];
}

/**
 * Deteksi pelanggaran unique constraint (pengiriman ganda).
 */
function siswa_is_duplicate_error(PDOException $exception): bool
{
    $sqlState = (string) $exception->getCode();
    $driverCode = (int) ($exception->errorInfo[1] ?? 0);

    return $sqlState === '23000'
        || $driverCode === 1062
        || str_contains($exception->getMessage(), 'Duplicate entry');
}

/**
 * Koreksi jawaban dan simpan hasil dalam satu transaksi.
 *
 * @param array<string, mixed> $context
 * @param array<int, ?string>  $answers
 * @return array{ok: bool, duplicate: bool, message: string, hasil_id: int, nilai: float}
 */
function siswa_submit_attempt(array $context, array $answers): array
{
    $totalSoal = (int) ($context['jumlah_soal'] ?? 0);
    $ujianId = (int) ($context['ujian_id'] ?? 0);
    $kelasId = (int) ($context['kelas_id'] ?? 0);
    $paketId = (int) ($context['paket_soal_id'] ?? 0);
    $nama = trim((string) ($context['nama_siswa'] ?? ''));
    $absen = trim((string) ($context['nomor_absen'] ?? ''));

    $failure = static function (string $message): array {
        return [
            'ok'        => false,
            'duplicate' => false,
            'message'   => $message,
            'hasil_id'  => 0,
            'nilai'     => 0.0,
        ];
    };

    if ($totalSoal < 1 || $ujianId < 1 || $kelasId < 1 || $paketId < 1 || $nama === '' || $absen === '') {
        return $failure('Sesi ujian tidak valid. Silakan mulai kembali dari halaman awal.');
    }

    if (count($answers) !== $totalSoal) {
        return $failure('Jumlah jawaban tidak sesuai dengan jumlah soal.');
    }

    $pdo = db();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT id, status, jumlah_soal FROM ujian WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => $ujianId]);
        $exam = $stmt->fetch();

        if ($exam === false) {
            $pdo->rollBack();

            return $failure('Ujian tidak ditemukan.');
        }

        if (!in_array((string) $exam['status'], ['aktif', 'selesai'], true)) {
            $pdo->rollBack();

            return $failure('Ujian sudah tidak menerima jawaban.');
        }

        if ((int) $exam['jumlah_soal'] !== $totalSoal) {
            $pdo->rollBack();

            return $failure('Konfigurasi jumlah soal ujian berubah. Hubungi pengawas ujian.');
        }

        $stmt = $pdo->prepare(
            'SELECT id FROM hasil_ujian
             WHERE ujian_id = :ujian_id AND kelas_id = :kelas_id AND nomor_absen = :nomor_absen
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([
            'ujian_id'    => $ujianId,
            'kelas_id'    => $kelasId,
            'nomor_absen' => $absen,
        ]);

        if ($stmt->fetch() !== false) {
            $pdo->rollBack();

            return [
                'ok'        => false,
                'duplicate' => true,
                'message'   => 'Jawaban untuk ujian, kelas, dan nomor absen ini sudah pernah dikirim.',
                'hasil_id'  => 0,
                'nilai'     => 0.0,
            ];
        }

        $stmt = $pdo->prepare(
            'SELECT nomor_soal, jawaban, tipe_soal
             FROM kunci_jawaban
             WHERE paket_soal_id = :paket_id
               AND nomor_soal BETWEEN 1 AND :total_soal
             ORDER BY nomor_soal ASC
             FOR UPDATE'
        );
        $stmt->execute(['paket_id' => $paketId, 'total_soal' => $totalSoal]);

        $keys = [];
        $types = [];
        foreach ($stmt->fetchAll() as $row) {
            $number = (int) $row['nomor_soal'];
            $tipe = soal_tipe_normalize($row['tipe_soal'] ?? 'pg');
            $normalized = soal_answer_normalize($tipe, $row['jawaban']);

            if ($number >= 1 && $number <= $totalSoal && $normalized['ok'] && $normalized['value'] !== null) {
                $keys[$number] = $normalized['value'];
                $types[$number] = $tipe;
            }
        }

        if (count($keys) !== $totalSoal) {
            $pdo->rollBack();

            return $failure('Kunci jawaban paket ini belum lengkap. Hubungi pengawas ujian.');
        }

        $types = soal_types_complete($totalSoal, $types);
        $benar = 0;
        $salah = 0;
        $kosong = 0;
        $skorTotal = 0.0;
        $detail = [];

        for ($number = 1; $number <= $totalSoal; $number++) {
            $answer = $answers[$number] ?? null;
            $tipe = $types[$number] ?? 'pg';

            if ($answer !== null) {
                $normalized = soal_answer_normalize($tipe, $answer);
                if (!$normalized['ok'] || $normalized['value'] === null) {
                    $pdo->rollBack();

                    return $failure('Ada pilihan jawaban yang tidak valid.');
                }
                $answer = $normalized['value'];
            }

            $key = $keys[$number];
            $score = soal_score($tipe, $key, $answer);
            $skorTotal += $score['skor'];

            if ($answer === null) {
                $kosong++;
            } elseif ($score['is_benar'] === 1) {
                $benar++;
            } else {
                $salah++;
            }

            $detail[] = [
                'nomor_soal' => $number,
                'tipe_soal'  => $tipe,
                'jawaban'    => $answer,
                'kunci'      => $key,
                'skor'       => $score['skor'],
                'is_benar'   => $score['is_benar'],
            ];
        }

        $skorTotal = round($skorTotal, 4);
        $nilai = round($skorTotal / $totalSoal * 100, 2);
        $startedTs = (int) ($context['started_ts'] ?? time());
        $startedTs = min($startedTs, time());
        $startedAt = date('Y-m-d H:i:s', $startedTs);
        $durasi = max(0, time() - $startedTs);

        $snapshot = json_encode($keys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($snapshot === false) {
            $pdo->rollBack();

            return $failure('Hasil tidak dapat disimpan. Silakan coba lagi.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO hasil_ujian
                (ujian_id, kelas_id, paket_soal_id, nama_siswa, nomor_absen,
                 total_soal, jumlah_benar, jumlah_salah, jumlah_kosong, skor_total, nilai,
                 kunci_snapshot, started_at, durasi_detik, submitted_at, ip_address)
             VALUES
                (:ujian_id, :kelas_id, :paket_soal_id, :nama_siswa, :nomor_absen,
                 :total_soal, :jumlah_benar, :jumlah_salah, :jumlah_kosong, :skor_total, :nilai,
                 :kunci_snapshot, :started_at, :durasi_detik, NOW(), :ip_address)'
        );
        $stmt->execute([
            'ujian_id'      => $ujianId,
            'kelas_id'      => $kelasId,
            'paket_soal_id' => $paketId,
            'nama_siswa'    => $nama,
            'nomor_absen'   => $absen,
            'total_soal'    => $totalSoal,
            'jumlah_benar'  => $benar,
            'jumlah_salah'  => $salah,
            'jumlah_kosong' => $kosong,
            'skor_total'    => number_format($skorTotal, 4, '.', ''),
            'nilai'         => number_format($nilai, 2, '.', ''),
            'kunci_snapshot' => $snapshot,
            'started_at'    => $startedAt,
            'durasi_detik'  => $durasi,
            'ip_address'    => client_ip() !== '' ? client_ip() : null,
        ]);

        $hasilId = (int) $pdo->lastInsertId();

        $stmtDetail = $pdo->prepare(
            'INSERT INTO jawaban_siswa
                (hasil_ujian_id, nomor_soal, tipe_soal, jawaban, kunci, skor, is_benar)
             VALUES
                (:hasil_ujian_id, :nomor_soal, :tipe_soal, :jawaban, :kunci, :skor, :is_benar)'
        );

        foreach ($detail as $row) {
            $stmtDetail->execute([
                'hasil_ujian_id' => $hasilId,
                'nomor_soal'     => $row['nomor_soal'],
                'tipe_soal'      => $row['tipe_soal'],
                'jawaban'        => $row['jawaban'],
                'kunci'          => $row['kunci'],
                'skor'           => number_format((float) $row['skor'], 4, '.', ''),
                'is_benar'       => $row['is_benar'],
            ]);
        }

        $pdo->commit();

        $_SESSION['siswa_result'] = [
            'hasil_id'     => $hasilId,
            'ujian_id'     => $ujianId,
            'kelas_id'     => $kelasId,
            'nama_siswa'   => $nama,
            'nomor_absen'  => $absen,
            'attempt_token' => (string) ($context['attempt_token'] ?? ''),
            'created_ts'   => time(),
        ];
        siswa_clear_attempt();

        log_aktivitas(
            'siswa',
            'kirim_jawaban',
            'Hasil ujian "' . (string) ($context['ujian_nama'] ?? '') . '" dikirim untuk '
                . $nama . ' (' . $absen . ') dengan nilai ' . format_nilai($nilai) . '.'
        );

        return [
            'ok'        => true,
            'duplicate' => false,
            'message'   => 'Jawaban berhasil dikirim.',
            'hasil_id'  => $hasilId,
            'nilai'     => $nilai,
        ];
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (siswa_is_duplicate_error($exception)) {
            return [
                'ok'        => false,
                'duplicate' => true,
                'message'   => 'Jawaban untuk ujian, kelas, dan nomor absen ini sudah pernah dikirim.',
                'hasil_id'  => 0,
                'nilai'     => 0.0,
            ];
        }

        error_log('[SIKILAT] Gagal menyimpan hasil ujian: ' . $exception->getMessage());

        return $failure('Hasil ujian tidak dapat disimpan saat ini. Silakan coba lagi.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('[SIKILAT] Gagal menyimpan hasil ujian: ' . $exception->getMessage());

        return $failure('Hasil ujian tidak dapat disimpan saat ini. Silakan coba lagi.');
    }
}

/**
 * Format durasi pengerjaan dalam bahasa Indonesia.
 */
function siswa_format_duration(?int $seconds): string
{
    if ($seconds === null || $seconds < 0) {
        return '-';
    }

    if ($seconds < 60) {
        return $seconds . ' detik';
    }

    $minutes = intdiv($seconds, 60);
    $remainingSeconds = $seconds % 60;

    if ($minutes < 60) {
        return $minutes . ' menit' . ($remainingSeconds > 0 ? ' ' . $remainingSeconds . ' detik' : '');
    }

    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;

    return $hours . ' jam' . ($remainingMinutes > 0 ? ' ' . $remainingMinutes . ' menit' : '');
}
