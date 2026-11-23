<?php
declare(strict_types=1);

/**
 * SIKILAT - Helper laporan dan analisis hasil ujian (Tahap 5).
 *
 * Prinsip utama:
 * - Semua query selalu dibatasi pada satu ujian (dan opsional satu kelas).
 * - Statistik nilai boleh menggabungkan paket karena nilai sudah dinormalisasi.
 * - Analisis butir TIDAK PERNAH menggabungkan nomor lintas paket, karena isi
 *   soal dapat berbeda meskipun nomornya sama.
 */

if (!defined('SIKILAT_APP')) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

/**
 * Batas distribusi nilai yang dipakai halaman dan ekspor.
 *
 * @return array<int, array{label:string, min:float, max:float}>
 */
function laporan_distribution_bins(): array
{
    return [
        ['label' => '0-49', 'min' => 0.0, 'max' => 49.9999],
        ['label' => '50-59', 'min' => 50.0, 'max' => 59.9999],
        ['label' => '60-69', 'min' => 60.0, 'max' => 69.9999],
        ['label' => '70-79', 'min' => 70.0, 'max' => 79.9999],
        ['label' => '80-89', 'min' => 80.0, 'max' => 89.9999],
        ['label' => '90-100', 'min' => 90.0, 'max' => 100.0],
    ];
}

/**
 * Daftar ujian yang boleh diakses pengguna, lengkap dengan jumlah peserta.
 *
 * @return array<int, array<string, mixed>>
 */
function laporan_exams(): array
{
    $user = auth_user();
    if ($user === null) {
        return [];
    }

    $sql = "SELECT u.id, u.nama, u.status, u.kkm, u.jumlah_soal,
                   u.tanggal_ujian, u.waktu_mulai, u.waktu_selesai, u.created_by,
                   mp.nama AS mapel_nama, mp.kode AS mapel_kode,
                   (SELECT COUNT(*) FROM hasil_ujian h WHERE h.ujian_id = u.id) AS peserta,
                   (SELECT COUNT(DISTINCT h.kelas_id) FROM hasil_ujian h WHERE h.ujian_id = u.id) AS kelas_terisi,
                   (SELECT COUNT(*) FROM paket_soal ps WHERE ps.ujian_id = u.id) AS paket_count
            FROM ujian u
            INNER JOIN mata_pelajaran mp ON mp.id = u.mata_pelajaran_id";

    $params = [];
    if ($user['role'] !== 'admin') {
        $sql .= ' WHERE u.created_by = :user_id';
        $params['user_id'] = (int) $user['id'];
    }

    $sql .= ' ORDER BY u.tanggal_ujian DESC, u.id DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Satu ujian yang boleh diakses, atau null.
 *
 * @return array<string, mixed>|null
 */
function laporan_exam(int $ujianId): ?array
{
    if ($ujianId < 1) {
        return null;
    }

    foreach (laporan_exams() as $exam) {
        if ((int) $exam['id'] === $ujianId) {
            return $exam;
        }
    }

    return null;
}

/**
 * Pemeriksaan ringan apakah pengguna boleh mengakses satu ujian.
 * Admin boleh mengakses semua ujian; guru hanya ujian yang ia buat.
 * Hasil disimpan sementara agar pemanggilan helper berulang tidak menambah query.
 */
function laporan_exam_accessible(int $ujianId): bool
{
    static $cache = [];

    $user = auth_user();
    if ($user === null || $ujianId < 1) {
        return false;
    }

    $cacheKey = $ujianId . ':' . (int) $user['id'];
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $sql = 'SELECT COUNT(*) FROM ujian WHERE id = :id';
    $params = ['id' => $ujianId];

    if ($user['role'] !== 'admin') {
        $sql .= ' AND created_by = :user_id';
        $params['user_id'] = (int) $user['id'];
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $cache[$cacheKey] = (int) $stmt->fetchColumn() > 0;

    return $cache[$cacheKey];
}

/**
 * Kelas yang terkait ujian, baik dari relasi peserta maupun hasil historis.
 *
 * @return array<int, array<string, mixed>>
 */
function laporan_classes(int $ujianId): array
{
    if (!laporan_exam_accessible($ujianId)) {
        return [];
    }

    $stmt = db()->prepare(
        'SELECT k.id, k.nama, k.tingkat, k.tahun_ajaran, k.is_active,
                COUNT(h.id) AS peserta,
                COALESCE(ROUND(AVG(h.nilai), 2), 0) AS rata
         FROM kelas k
         INNER JOIN (
             SELECT kelas_id FROM ujian_kelas WHERE ujian_id = :id_ujian_kelas
             UNION
             SELECT kelas_id FROM hasil_ujian WHERE ujian_id = :id_hasil
         ) daftar ON daftar.kelas_id = k.id
         LEFT JOIN hasil_ujian h
                ON h.ujian_id = :id_ujian_hasil AND h.kelas_id = k.id
         GROUP BY k.id, k.nama, k.tingkat, k.tahun_ajaran, k.is_active
         ORDER BY k.tingkat ASC, k.nama ASC'
    );
    $stmt->execute([
        'id_ujian_kelas' => $ujianId,
        'id_hasil'       => $ujianId,
        'id_ujian_hasil' => $ujianId,
    ]);

    return $stmt->fetchAll();
}

/**
 * Seluruh paket soal milik ujian, termasuk paket yang belum memiliki peserta.
 *
 * @return array<int, array<string, mixed>>
 */
function laporan_packages(int $ujianId): array
{
    if (!laporan_exam_accessible($ujianId)) {
        return [];
    }

    $stmt = db()->prepare(
        'SELECT ps.id, ps.kode_paket, ps.keterangan,
                (SELECT COUNT(*) FROM kunci_jawaban kj WHERE kj.paket_soal_id = ps.id) AS jumlah_kunci,
                (SELECT COUNT(*) FROM hasil_ujian h WHERE h.paket_soal_id = ps.id) AS peserta
         FROM paket_soal ps
         WHERE ps.ujian_id = :ujian_id
         ORDER BY ps.kode_paket ASC'
    );
    $stmt->execute(['ujian_id' => $ujianId]);

    return $stmt->fetchAll();
}

/**
 * Apakah kelas termasuk peserta ujian atau memiliki hasil historis.
 */
function laporan_class_valid(int $ujianId, int $kelasId): bool
{
    if ($kelasId < 1) {
        return false;
    }

    foreach (laporan_classes($ujianId) as $class) {
        if ((int) $class['id'] === $kelasId) {
            return true;
        }
    }

    return false;
}

/**
 * Hasil peserta untuk satu ujian dan opsional satu kelas.
 *
 * @return array<int, array<string, mixed>>
 */
function laporan_results(int $ujianId, ?int $kelasId = null): array
{
    if (!laporan_exam_accessible($ujianId)) {
        return [];
    }

    $sql = 'SELECT h.*, k.nama AS kelas_nama, k.tingkat, k.tahun_ajaran,
                   ps.kode_paket, ps.keterangan AS paket_keterangan,
                   u.nama AS ujian_nama, u.kkm, u.jumlah_soal,
                   u.tanggal_ujian, u.waktu_mulai, u.waktu_selesai,
                   mp.nama AS mapel_nama, mp.kode AS mapel_kode
            FROM hasil_ujian h
            INNER JOIN kelas k ON k.id = h.kelas_id
            INNER JOIN paket_soal ps ON ps.id = h.paket_soal_id
            INNER JOIN ujian u ON u.id = h.ujian_id
            INNER JOIN mata_pelajaran mp ON mp.id = u.mata_pelajaran_id
            WHERE h.ujian_id = :ujian_id';

    $params = ['ujian_id' => $ujianId];
    if ($kelasId !== null && $kelasId > 0) {
        $sql .= ' AND h.kelas_id = :kelas_id';
        $params['kelas_id'] = $kelasId;
    }

    $sql .= ' ORDER BY k.tingkat ASC, k.nama ASC,
                      CAST(h.nomor_absen AS UNSIGNED) ASC, h.nomor_absen ASC, h.nama_siswa ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Ringkasan statistik nilai.
 *
 * @param array<int, array<string, mixed>> $results
 * @return array{jumlah:int, rata:?float, tertinggi:?float, terendah:?float,
 *               tuntas:int, belum:int, persen_tuntas:float, kkm:float,
 *               benar:int, salah:int, kosong:int}
 */
function laporan_summary(array $results, float $kkm): array
{
    $jumlah = count($results);
    $nilai = [];
    $benar = 0;
    $salah = 0;
    $kosong = 0;
    $tuntas = 0;

    foreach ($results as $row) {
        $value = (float) $row['nilai'];
        $nilai[] = $value;
        $benar += (int) $row['jumlah_benar'];
        $salah += (int) $row['jumlah_salah'];
        $kosong += (int) $row['jumlah_kosong'];

        if ($value + 0.00001 >= $kkm) {
            $tuntas++;
        }
    }

    return [
        'jumlah'        => $jumlah,
        'rata'          => $jumlah > 0 ? round(array_sum($nilai) / $jumlah, 2) : null,
        'tertinggi'     => $jumlah > 0 ? max($nilai) : null,
        'terendah'      => $jumlah > 0 ? min($nilai) : null,
        'tuntas'        => $tuntas,
        'belum'         => $jumlah - $tuntas,
        'persen_tuntas' => $jumlah > 0 ? round($tuntas / $jumlah * 100, 2) : 0.0,
        'kkm'           => $kkm,
        'benar'         => $benar,
        'salah'         => $salah,
        'kosong'        => $kosong,
    ];
}

/**
 * Distribusi nilai berdasarkan batas tetap.
 *
 * @param array<int, array<string, mixed>> $results
 * @return array<int, array{label:string, min:float, max:float, jumlah:int, persen:float}>
 */
function laporan_distribution(array $results): array
{
    $bins = laporan_distribution_bins();
    foreach ($bins as $index => $bin) {
        $bins[$index]['jumlah'] = 0;
    }

    foreach ($results as $row) {
        $value = (float) $row['nilai'];
        foreach ($bins as $index => $bin) {
            $isLast = $index === count($bins) - 1;
            if ($value >= $bin['min'] && ($isLast ? $value <= $bin['max'] : $value <= $bin['max'])) {
                $bins[$index]['jumlah']++;
                break;
            }
        }
    }

    $total = count($results);
    foreach ($bins as $index => $bin) {
        $bins[$index]['persen'] = $total > 0 ? round($bin['jumlah'] / $total * 100, 2) : 0.0;
    }

    return $bins;
}

/**
 * Rekap nilai per kelas.
 *
 * @param array<int, array<string, mixed>> $results
 * @return array<int, array<string, mixed>>
 */
function laporan_class_summary(array $results, float $kkm): array
{
    $groups = [];
    foreach ($results as $row) {
        $key = (int) $row['kelas_id'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'kelas_id'     => $key,
                'kelas_nama'   => (string) $row['kelas_nama'],
                'tingkat'      => (string) $row['tingkat'],
                'tahun_ajaran' => (string) $row['tahun_ajaran'],
                'rows'         => [],
            ];
        }
        $groups[$key]['rows'][] = $row;
    }

    $summary = [];
    foreach ($groups as $group) {
        $stats = laporan_summary($group['rows'], $kkm);
        $stats['kelas_id'] = $group['kelas_id'];
        $stats['kelas_nama'] = $group['kelas_nama'];
        $stats['tingkat'] = $group['tingkat'];
        $stats['tahun_ajaran'] = $group['tahun_ajaran'];
        $summary[] = $stats;
    }

    return $summary;
}

/**
 * Distribusi nilai per kelas untuk kurva rombel.
 *
 * @param array<int, array<string, mixed>> $results
 * @return array<int, array{kelas_id:int, kelas_nama:string, jumlah:int,
 *                          counts:array<int, int>, persen:array<int, float>}>
 */
function laporan_class_distribution(array $results): array
{
    $bins = laporan_distribution_bins();
    $binCount = count($bins);
    $groups = [];

    foreach ($results as $row) {
        $key = (int) $row['kelas_id'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'kelas_id'   => $key,
                'kelas_nama' => (string) $row['kelas_nama'],
                'jumlah'     => 0,
                'counts'     => array_fill(0, $binCount, 0),
            ];
        }

        $groups[$key]['jumlah']++;
        $value = (float) $row['nilai'];

        for ($index = 0; $index < $binCount; $index++) {
            if ($value >= (float) $bins[$index]['min'] && $value <= (float) $bins[$index]['max']) {
                $groups[$key]['counts'][$index]++;
                break;
            }
        }
    }

    $output = [];
    foreach ($groups as $group) {
        $persen = [];
        foreach ($group['counts'] as $count) {
            $persen[] = $group['jumlah'] > 0
                ? round($count / $group['jumlah'] * 100, 2)
                : 0.0;
        }

        $output[] = [
            'kelas_id'   => $group['kelas_id'],
            'kelas_nama' => $group['kelas_nama'],
            'jumlah'     => $group['jumlah'],
            'counts'     => $group['counts'],
            'persen'     => $persen,
        ];
    }

    return $output;
}

/**
 * Rekap nilai per paket. Nilai antar paket boleh dibandingkan karena sudah
 * dinormalisasi ke skala 0-100; analisis butir tetap dipisah.
 *
 * @param array<int, array<string, mixed>> $results
 * @return array<int, array<string, mixed>>
 */
function laporan_package_summary(array $results, float $kkm): array
{
    $groups = [];
    foreach ($results as $row) {
        $key = (int) $row['paket_soal_id'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'paket_soal_id' => $key,
                'kode_paket'    => (string) $row['kode_paket'],
                'keterangan'    => (string) ($row['paket_keterangan'] ?? ''),
                'rows'          => [],
            ];
        }
        $groups[$key]['rows'][] = $row;
    }

    ksort($groups);

    $summary = [];
    foreach ($groups as $group) {
        $stats = laporan_summary($group['rows'], $kkm);
        $stats['paket_soal_id'] = $group['paket_soal_id'];
        $stats['kode_paket'] = $group['kode_paket'];
        $stats['keterangan'] = $group['keterangan'];
        $summary[] = $stats;
    }

    return $summary;
}

/**
 * Analisis butir per paket: tingkat kesukaran, daya pembeda, dan pengecoh.
 *
 * Kelompok atas/bawah memakai 27% peserta berdasarkan nilai total (upper-lower
 * 27%). Daya pembeda hanya dihitung bila jumlah peserta minimal 4 dan kedua
 * kelompok tidak tumpang tindih.
 *
 * @param array<int, array<string, mixed>> $results
 * @return array<int, array<string, mixed>>
 */
function laporan_item_analysis(int $ujianId, ?int $kelasId, array $results, int $totalSoal): array
{
    if (!laporan_exam_accessible($ujianId) || $results === [] || $totalSoal < 1) {
        return [];
    }

    $packages = [];

    foreach ($results as $row) {
        $paketId = (int) $row['paket_soal_id'];
        if (!isset($packages[$paketId])) {
            $packages[$paketId] = [
                'paket_soal_id' => $paketId,
                'kode_paket'    => (string) $row['kode_paket'],
                'peserta'       => 0,
                'nilai'         => [],
                'items'         => [],
            ];
        }

        $packages[$paketId]['peserta']++;
        $packages[$paketId]['nilai'][(int) $row['id']] = (float) $row['nilai'];

        for ($number = 1; $number <= $totalSoal; $number++) {
            if (!isset($packages[$paketId]['items'][$number])) {
                $packages[$paketId]['items'][$number] = [
                    'nomor'        => $number,
                    'tipe_soal'    => 'pg',
                    'kunci'        => null,
                    'benar'        => 0,
                    'opsi'         => array_fill_keys(['A', 'B', 'C', 'D', 'E'], 0),
                    'kosong'       => 0,
                    'upper_benar'  => 0,
                    'lower_benar'  => 0,
                ];
            }
        }
    }

    if ($packages === []) {
        return [];
    }

    $paketIds = array_keys($packages);
    $placeholders = implode(',', array_fill(0, count($paketIds), '?'));

    $keyStmt = db()->prepare(
        "SELECT paket_soal_id, nomor_soal, tipe_soal, jawaban
         FROM kunci_jawaban
         WHERE paket_soal_id IN ($placeholders)
           AND nomor_soal BETWEEN 1 AND ?
         ORDER BY paket_soal_id ASC, nomor_soal ASC"
    );
    $keyStmt->execute([...array_values($paketIds), $totalSoal]);
    foreach ($keyStmt->fetchAll() as $keyRow) {
        $paketId = (int) $keyRow['paket_soal_id'];
        $number = (int) $keyRow['nomor_soal'];
        if (isset($packages[$paketId]['items'][$number])) {
            $tipe = soal_tipe_normalize($keyRow['tipe_soal'] ?? 'pg');
            $packages[$paketId]['items'][$number]['tipe_soal'] = $tipe;
            $packages[$paketId]['items'][$number]['kunci'] = (string) $keyRow['jawaban'];

            if ($tipe === 'benar_salah') {
                $packages[$paketId]['items'][$number]['opsi'] = array_fill_keys(['B', 'S'], 0);
            } else {
                $packages[$paketId]['items'][$number]['opsi'] = array_fill_keys(['A', 'B', 'C', 'D', 'E'], 0);
            }
        }
    }

    $sql = 'SELECT j.hasil_ujian_id, h.paket_soal_id, j.nomor_soal,
                   j.tipe_soal, j.jawaban, j.kunci, j.is_benar
            FROM jawaban_siswa j
            INNER JOIN hasil_ujian h ON h.id = j.hasil_ujian_id
            WHERE h.ujian_id = :ujian_id';
    $params = ['ujian_id' => $ujianId];
    if ($kelasId !== null && $kelasId > 0) {
        $sql .= ' AND h.kelas_id = :kelas_id';
        $params['kelas_id'] = $kelasId;
    }
    $sql .= ' ORDER BY h.paket_soal_id ASC, j.nomor_soal ASC, j.hasil_ujian_id ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $answerRows = $stmt->fetchAll();

    // Tentukan kelompok atas/bawah 27% per paket.
    $upperSets = [];
    $lowerSets = [];
    $groupSizes = [];
    $discriminationAvailable = [];

    foreach ($packages as $paketId => $package) {
        $scores = $package['nilai'];
        $ids = array_keys($scores);
        usort($ids, static function (int $a, int $b) use ($scores): int {
            $compare = $scores[$b] <=> $scores[$a];

            return $compare !== 0 ? $compare : $a <=> $b;
        });

        $count = count($ids);
        $groupSize = (int) ceil($count * 0.27);
        $available = $count >= 4 && $groupSize >= 1 && ($groupSize * 2) <= $count;

        $discriminationAvailable[$paketId] = $available;
        $groupSizes[$paketId] = $groupSize;

        if ($available) {
            $upperSets[$paketId] = array_fill_keys(array_slice($ids, 0, $groupSize), true);
            $lowerSets[$paketId] = array_fill_keys(array_slice($ids, -$groupSize), true);
        } else {
            $upperSets[$paketId] = [];
            $lowerSets[$paketId] = [];
        }
    }

    foreach ($answerRows as $row) {
        $paketId = (int) $row['paket_soal_id'];
        $number = (int) $row['nomor_soal'];
        $hasilId = (int) $row['hasil_ujian_id'];

        if (!isset($packages[$paketId]['items'][$number])) {
            continue;
        }

        $item = &$packages[$paketId]['items'][$number];

        if ($item['kunci'] === null && $row['kunci'] !== null) {
            $tipe = soal_tipe_normalize($row['tipe_soal'] ?? $item['tipe_soal']);
            $item['tipe_soal'] = $tipe;
            $item['kunci'] = (string) $row['kunci'];
            $item['opsi'] = $tipe === 'benar_salah'
                ? array_fill_keys(['B', 'S'], 0)
                : array_fill_keys(['A', 'B', 'C', 'D', 'E'], 0);
        }

        if ($row['jawaban'] === null || $row['jawaban'] === '') {
            $item['kosong']++;
        } elseif ($item['tipe_soal'] === 'multiple_response') {
            foreach (soal_answer_letters((string) $row['jawaban']) as $letter) {
                if (isset($item['opsi'][$letter])) {
                    $item['opsi'][$letter]++;
                }
            }
        } else {
            $letter = strtoupper(trim((string) $row['jawaban']));
            if (isset($item['opsi'][$letter])) {
                $item['opsi'][$letter]++;
            }
        }

        if ((int) $row['is_benar'] === 1) {
            $item['benar']++;
            if (isset($upperSets[$paketId][$hasilId])) {
                $item['upper_benar']++;
            }
            if (isset($lowerSets[$paketId][$hasilId])) {
                $item['lower_benar']++;
            }
        }

        unset($item);
    }

    $output = [];
    foreach ($packages as $paketId => $package) {
        $peserta = (int) $package['peserta'];
        $groupSize = (int) ($groupSizes[$paketId] ?? 0);
        $available = (bool) ($discriminationAvailable[$paketId] ?? false);

        $items = [];
        $difficultyCounts = ['Mudah' => 0, 'Sedang' => 0, 'Sukar' => 0];
        $effectiveTotal = 0;
        $distractorTotal = 0;

        $letterUsage = [];
        foreach ($package['items'] as $packageItem) {
            foreach (array_keys($packageItem['opsi']) as $letter) {
                $letterUsage[(string) $letter] = true;
            }
        }
        $packageLetters = array_values(array_filter(
            soal_option_letter_order(),
            static fn (string $letter): bool => isset($letterUsage[$letter])
        ));

        for ($number = 1; $number <= $totalSoal; $number++) {
            $item = $package['items'][$number];
            $correct = (int) $item['benar'];
            $proportion = $peserta > 0 ? $correct / $peserta : 0.0;

            if ($proportion >= 0.70) {
                $difficulty = 'Mudah';
            } elseif ($proportion >= 0.30) {
                $difficulty = 'Sedang';
            } else {
                $difficulty = 'Sukar';
            }
            $difficultyCounts[$difficulty]++;

            $discrimination = null;
            $discriminationLabel = 'Data kurang';
            if ($available && $groupSize > 0) {
                $discrimination = round(
                    ($item['upper_benar'] / $groupSize) - ($item['lower_benar'] / $groupSize),
                    2
                );

                if ($discrimination >= 0.40) {
                    $discriminationLabel = 'Baik';
                } elseif ($discrimination >= 0.30) {
                    $discriminationLabel = 'Cukup';
                } elseif ($discrimination >= 0.20) {
                    $discriminationLabel = 'Rendah';
                } else {
                    $discriminationLabel = 'Jelek';
                }
            }

            $options = [];
            $effectiveDistractors = [];
            $weakDistractors = [];
            $keyLetters = soal_answer_letters((string) ($item['kunci'] ?? ''));
            foreach ($packageLetters as $letter) {
                $hasOption = array_key_exists($letter, $item['opsi']);
                $count = (int) ($item['opsi'][$letter] ?? 0);
                $percent = $peserta > 0 ? round($count / $peserta * 100, 2) : 0.0;
                $isKey = in_array($letter, $keyLetters, true);
                $effective = false;

                if (!$isKey && $hasOption) {
                    $distractorTotal++;
                    $effective = $percent >= 5.0;
                    if ($effective) {
                        $effectiveDistractors[] = $letter;
                        $effectiveTotal++;
                    } else {
                        $weakDistractors[] = $letter;
                    }
                }

                $options[$letter] = [
                    'count'     => $count,
                    'persen'    => $percent,
                    'is_key'    => $isKey,
                    'effective' => $effective,
                ];
            }

            $items[] = [
                'nomor'                   => $number,
                'tipe_soal'               => soal_tipe_normalize($item['tipe_soal']),
                'tipe_label'              => soal_tipe_label($item['tipe_soal']),
                'kunci'                   => $item['kunci'] ?? '-',
                'kunci_tampil'            => soal_answer_display($item['tipe_soal'], $item['kunci']),
                'benar'                   => $correct,
                'persen_benar'            => round($proportion * 100, 2),
                'tingkat_kesukaran'       => $difficulty,
                'daya_pembeda'            => $discrimination,
                'daya_pembeda_label'      => $discriminationLabel,
                'opsi'                    => $options,
                'kosong'                  => (int) $item['kosong'],
                'kosong_persen'           => $peserta > 0 ? round($item['kosong'] / $peserta * 100, 2) : 0.0,
                'pengecoh_efektif'        => $effectiveDistractors,
                'pengecoh_kurang'         => $weakDistractors,
                'upper_benar'             => (int) $item['upper_benar'],
                'lower_benar'             => (int) $item['lower_benar'],
            ];
        }

        $stats = laporan_summary(array_values(array_filter(
            $results,
            static fn (array $row): bool => (int) $row['paket_soal_id'] === (int) $paketId
        )), 0.0);

        $output[] = [
            'paket_soal_id'        => (int) $paketId,
            'kode_paket'           => (string) $package['kode_paket'],
            'peserta'              => $peserta,
            'items'                => $items,
            'opsi_letters'         => $packageLetters,
            'jumlah_soal'          => $totalSoal,
            'kelompok_atas'        => $groupSize,
            'daya_pembeda_tersedia' => $available,
            'tingkat_counts'       => $difficultyCounts,
            'pengecoh_efektif'     => $effectiveTotal,
            'pengecoh_total'       => $distractorTotal,
            'rata_nilai'           => $stats['rata'],
            'nilai_tertinggi'      => $stats['tertinggi'],
            'nilai_terendah'       => $stats['terendah'],
        ];
    }

    usort($output, static fn (array $a, array $b): int => strcmp($a['kode_paket'], $b['kode_paket']));

    return $output;
}

/**
 * Merangkum karakteristik seluruh butir dari beberapa paket.
 *
 * @param array<int, array<string, mixed>> $packages
 * @return array<string, mixed>
 */
function laporan_item_overview(array $packages): array
{
    $overview = [
        'paket'                 => count($packages),
        'paket_kecil'           => 0,
        'butir'                 => 0,
        'mudah'                 => 0,
        'sedang'                => 0,
        'sukar'                 => 0,
        'rata_benar'            => null,
        'benar_total'           => 0.0,
        'dp_butir'              => 0,
        'dp_rata'               => null,
        'dp_total'              => 0.0,
        'dp_baik'               => 0,
        'dp_cukup'              => 0,
        'dp_rendah'             => 0,
        'dp_jelek'              => 0,
        'dp_negatif'            => [],
        'pengecoh_efektif'      => 0,
        'pengecoh_total'        => 0,
        'sukar_dp_jelek'        => [],
        'kunci_kalah_pengecoh'  => [],
    ];

    foreach ($packages as $package) {
        $namaPaket = 'Paket ' . (string) $package['kode_paket'];
        $pesertaPaket = (int) $package['peserta'];

        if ($pesertaPaket < 4) {
            $overview['paket_kecil']++;
        }

        foreach ($package['items'] as $item) {
            $overview['butir']++;
            $overview['benar_total'] += (float) $item['persen_benar'];

            $tingkat = (string) $item['tingkat_kesukaran'];
            if ($tingkat === 'Mudah') {
                $overview['mudah']++;
            } elseif ($tingkat === 'Sedang') {
                $overview['sedang']++;
            } else {
                $overview['sukar']++;
            }

            $diskriminasi = $item['daya_pembeda'] !== null
                ? (float) $item['daya_pembeda']
                : null;

            if ($diskriminasi !== null) {
                $overview['dp_butir']++;
                $overview['dp_total'] += $diskriminasi;

                $label = (string) $item['daya_pembeda_label'];
                if ($label === 'Baik') {
                    $overview['dp_baik']++;
                } elseif ($label === 'Cukup') {
                    $overview['dp_cukup']++;
                } elseif ($label === 'Rendah') {
                    $overview['dp_rendah']++;
                } else {
                    $overview['dp_jelek']++;
                }

                if ($diskriminasi < 0.0) {
                    $overview['dp_negatif'][] = $namaPaket . ' no. ' . (int) $item['nomor'];
                }

                if ($tingkat === 'Sukar' && $diskriminasi < 0.20) {
                    $overview['sukar_dp_jelek'][] = $namaPaket . ' no. ' . (int) $item['nomor'];
                }
            }

            $overview['pengecoh_efektif'] += count($item['pengecoh_efektif']);
            $overview['pengecoh_total'] += count($item['pengecoh_efektif'])
                + count($item['pengecoh_kurang']);

            // Pengecoh yang lebih sering dipilih daripada kunci menandakan
            // kemungkinan kunci tertukar atau pengecoh sangat menarik. Pemeriksaan
            // ini baru bermakna bila paket diikuti cukup banyak peserta.
            if ($pesertaPaket >= 4 && (string) ($item['tipe_soal'] ?? 'pg') !== 'multiple_response') {
                $kunciCount = 0;
                $maxPengecoh = 0;
                $maxHuruf = '';

                foreach ($item['opsi'] as $huruf => $opsi) {
                    $jumlah = (int) $opsi['count'];
                    if (!empty($opsi['is_key'])) {
                        $kunciCount = max($kunciCount, $jumlah);
                    } elseif ($jumlah > $maxPengecoh) {
                        $maxPengecoh = $jumlah;
                        $maxHuruf = (string) $huruf;
                    }
                }

                if ($maxPengecoh > $kunciCount) {
                    $overview['kunci_kalah_pengecoh'][] = $namaPaket . ' no. ' . (int) $item['nomor']
                        . ' (pilihan ' . $maxHuruf . ' ' . $maxPengecoh
                        . ' vs kunci ' . $kunciCount . ')';
                }
            }
        }
    }

    if ($overview['butir'] > 0) {
        $overview['rata_benar'] = round($overview['benar_total'] / $overview['butir'], 2);
    }

    if ($overview['dp_butir'] > 0) {
        $overview['dp_rata'] = round($overview['dp_total'] / $overview['dp_butir'], 2);
    }

    return $overview;
}

/**
 * Menggabungkan daftar singkat menjadi satu kalimat.
 *
 * @param array<int, string> $items
 */
function laporan_join_list(array $items, int $max = 6): string
{
    $items = array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    $total = count($items);

    if ($total === 0) {
        return '';
    }

    if ($total <= $max) {
        return implode(', ', $items);
    }

    return implode(', ', array_slice($items, 0, $max)) . ', dan ' . ($total - $max) . ' lainnya';
}

/**
 * Label kualitatif daya pembeda dari nilai indeks.
 */
function laporan_discrimination_text(float $value): string
{
    if ($value >= 0.40) {
        return 'baik';
    }

    if ($value >= 0.30) {
        return 'cukup';
    }

    if ($value >= 0.20) {
        return 'rendah';
    }

    return 'jelek';
}

/**
 * Narasi ringkas untuk seluruh butir yang dianalisis.
 *
 * @param array<int, array<string, mixed>> $packages
 * @param array<string, mixed> $overview
 * @return array{ringkasan: array<int, string>, rekomendasi: array<int, string>}
 */
function laporan_item_narrative(array $packages, array $overview, int $peserta): array
{
    $ringkasan = [];
    $rekomendasi = [];
    $butir = (int) $overview['butir'];

    if ($butir === 0) {
        return ['ringkasan' => [], 'rekomendasi' => []];
    }

    $persen = static fn (int $jumlah): string => laporan_percent(
        $butir > 0 ? round($jumlah / $butir * 100, 2) : 0.0
    );

    $ringkasan[] = 'Analisis mencakup ' . $peserta . ' peserta pada '
        . (int) $overview['paket'] . ' paket dan ' . $butir . ' butir soal. '
        . 'Komposisi tingkat kesukaran: ' . (int) $overview['mudah'] . ' butir mudah ('
        . $persen((int) $overview['mudah']) . '), ' . (int) $overview['sedang']
        . ' butir sedang (' . $persen((int) $overview['sedang']) . '), dan '
        . (int) $overview['sukar'] . ' butir sukar (' . $persen((int) $overview['sukar']) . '). '
        . 'Rata-rata jawaban benar per butir ' . laporan_percent((float) $overview['rata_benar']) . '.';

    if ((int) $overview['paket_kecil'] > 0) {
        $ringkasan[] = 'Catatan kelayakan data: ' . (int) $overview['paket_kecil'] . ' dari '
            . (int) $overview['paket'] . ' paket baru diikuti kurang dari empat peserta. '
            . 'Persentase jawaban benar dan pola pengecoh pada paket tersebut belum stabil, '
            . 'sehingga hasilnya perlu dibaca sebagai indikasi awal dan dikonfirmasi setelah '
            . 'jumlah peserta bertambah.';
    }

    if ((int) $overview['dp_butir'] > 0) {
        $ringkasan[] = 'Daya pembeda dapat dihitung pada ' . (int) $overview['dp_butir']
            . ' butir dengan rata-rata ' . number_format((float) $overview['dp_rata'], 2, ',', '.')
            . ' (' . laporan_discrimination_text((float) $overview['dp_rata']) . '). '
            . 'Rinciannya: ' . (int) $overview['dp_baik'] . ' butir baik, '
            . (int) $overview['dp_cukup'] . ' cukup, ' . (int) $overview['dp_rendah']
            . ' rendah, dan ' . (int) $overview['dp_jelek'] . ' jelek. '
            . 'Butir dengan daya pembeda negatif: '
            . (count($overview['dp_negatif']) > 0
                ? laporan_join_list($overview['dp_negatif']) . '.'
                : 'tidak ada.');
    } else {
        $ringkasan[] = 'Daya pembeda butir belum dapat dihitung karena jumlah peserta pada tiap paket '
            . 'masih di bawah empat orang. Analisis saat ini terbatas pada tingkat kesukaran dan pola '
            . 'pilihan pengecoh.';
    }

    $efektif = (int) $overview['pengecoh_efektif'];
    $totalPengecoh = (int) $overview['pengecoh_total'];
    $persenEfektif = $totalPengecoh > 0
        ? round($efektif / $totalPengecoh * 100, 2)
        : 0.0;

    $ringkasan[] = 'Pengecoh yang berfungsi (dipilih minimal 5% peserta) sebanyak ' . $efektif
        . ' dari ' . $totalPengecoh . ' pengecoh (' . laporan_percent($persenEfektif) . '). '
        . (count($overview['kunci_kalah_pengecoh']) > 0
            ? 'Terdapat ' . count($overview['kunci_kalah_pengecoh'])
              . ' butir yang pengecohnya lebih sering dipilih daripada kunci jawaban.'
            : ((int) $overview['paket_kecil'] === (int) $overview['paket']
                ? 'Perbandingan frekuensi kunci dan pengecoh belum dinilai karena semua paket '
                    . 'diikuti kurang dari empat peserta.'
                : 'Tidak ada pengecoh yang mengalahkan frekuensi pilihan kunci jawaban.'));

    // Rekomendasi berbasis data hanya disusun bila ada paket dengan peserta
    // memadai. Paket berpeserta sangat sedikit dapat membuat persentase
    // pengecoh tampak buruk padahal hanya efek ukuran sampel.
    $cukupData = (int) $overview['paket_kecil'] < (int) $overview['paket'];

    if ($cukupData && count($overview['dp_negatif']) > 0) {
        $rekomendasi[] = 'Periksa kembali kunci jawaban atau rumusan butir '
            . laporan_join_list($overview['dp_negatif'])
            . ' karena kelompok bawah lebih banyak menjawab benar daripada kelompok atas.';
    }

    if ($cukupData && count($overview['sukar_dp_jelek']) > 0) {
        $rekomendasi[] = 'Tinjau ulang butir sukar dengan daya pembeda rendah: '
            . laporan_join_list($overview['sukar_dp_jelek'])
            . '. Butir seperti ini cenderung tidak membedakan kemampuan siswa.';
    }

    if ($cukupData && count($overview['kunci_kalah_pengecoh']) > 0) {
        $rekomendasi[] = 'Validasi kunci pada ' . laporan_join_list($overview['kunci_kalah_pengecoh']) . '.';
    }

    if ($cukupData && $totalPengecoh > 0 && $persenEfektif < 50.0) {
        $rekomendasi[] = 'Kurang dari separuh pengecoh berfungsi. Ganti pengecoh yang jarang dipilih '
            . 'agar tiap butir memiliki pengecoh yang benar-benar menarik.';
    }

    if ($cukupData && $butir > 0 && (int) $overview['mudah'] / $butir >= 0.6) {
        $rekomendasi[] = 'Proporsi butir mudah cukup besar. Pertimbangkan menambah butir berpikir '
            . 'tingkat tinggi (HOTS) pada paket berikutnya.';
    }

    if ($rekomendasi === []) {
        $rekomendasi[] = $cukupData
            ? 'Karakteristik butir relatif seimbang. Pertahankan sebaran tingkat kesukaran '
                . 'dan pastikan pengecoh tetap berfungsi pada pelaksanaan berikutnya.'
            : 'Jumlah peserta pada setiap paket masih di bawah empat orang, sehingga kualitas butir '
                . 'belum dapat disimpulkan. Kumpulkan respons tambahan sebelum merevisi soal.';
    }

    return ['ringkasan' => $ringkasan, 'rekomendasi' => $rekomendasi];
}

/**
 * Narasi analisis untuk satu paket soal.
 *
 * @param array<string, mixed> $package
 * @return array{paragraf: array<int, string>, rekomendasi: array<int, string>}
 */
function laporan_package_narrative(array $package): array
{
    $items = $package['items'];
    $total = count($items);

    if ($total === 0) {
        return ['paragraf' => [], 'rekomendasi' => []];
    }

    $peserta = (int) $package['peserta'];
    $kode = (string) $package['kode_paket'];
    $jumlahBenar = 0.0;
    $jumlahDp = 0.0;
    $jumlahDpButir = 0;
    $negatif = [];
    $sukarJelek = [];
    $kunciLemah = [];
    $butirPengecohLemah = [];
    $efektif = 0;
    $totalPengecoh = 0;

    foreach ($items as $item) {
        $jumlahBenar += (float) $item['persen_benar'];

        if ($item['daya_pembeda'] !== null) {
            $nilaiDp = (float) $item['daya_pembeda'];
            $jumlahDp += $nilaiDp;
            $jumlahDpButir++;

            if ($nilaiDp < 0.0) {
                $negatif[] = 'no. ' . (int) $item['nomor'];
            }

            if ((string) $item['tingkat_kesukaran'] === 'Sukar' && $nilaiDp < 0.20) {
                $sukarJelek[] = 'no. ' . (int) $item['nomor'];
            }
        }

        $efektif += count($item['pengecoh_efektif']);
        $totalPengecoh += count($item['pengecoh_efektif']) + count($item['pengecoh_kurang']);

        if (count($item['pengecoh_kurang']) > 0) {
            $butirPengecohLemah[] = 'no. ' . (int) $item['nomor'];
        }

        if ($peserta >= 4 && (string) ($item['tipe_soal'] ?? 'pg') !== 'multiple_response') {
            $kunciCount = 0;
            $maxPengecoh = 0;
            $maxHuruf = '';

            foreach ($item['opsi'] as $huruf => $opsi) {
                $jumlah = (int) $opsi['count'];
                if (!empty($opsi['is_key'])) {
                    $kunciCount = max($kunciCount, $jumlah);
                } elseif ($jumlah > $maxPengecoh) {
                    $maxPengecoh = $jumlah;
                    $maxHuruf = (string) $huruf;
                }
            }

            if ($maxPengecoh > $kunciCount) {
                $kunciLemah[] = 'no. ' . (int) $item['nomor']
                    . ' (pilihan ' . $maxHuruf . ' ' . $maxPengecoh
                    . ' vs kunci ' . $kunciCount . ')';
            }
        }
    }

    $rataBenar = round($jumlahBenar / $total, 2);
    $counts = $package['tingkat_counts'];
    $paragraf = [];

    $paragraf[] = 'Paket ' . $kode . ' diikuti ' . $peserta . ' peserta dengan rata-rata nilai '
        . format_nilai($package['rata_nilai']) . ' (tertinggi ' . format_nilai($package['nilai_tertinggi'])
        . ', terendah ' . format_nilai($package['nilai_terendah']) . '). Dari ' . $total
        . ' butir, ' . (int) $counts['Mudah'] . ' butir mudah, ' . (int) $counts['Sedang']
        . ' butir sedang, dan ' . (int) $counts['Sukar'] . ' butir sukar; rata-rata jawaban benar '
        . laporan_percent($rataBenar) . '.';

    if ($jumlahDpButir > 0) {
        $rataDp = round($jumlahDp / $jumlahDpButir, 2);
        $paragraf[] = 'Daya pembeda rata-rata ' . number_format($rataDp, 2, ',', '.')
            . ' (' . laporan_discrimination_text($rataDp) . ') pada ' . $jumlahDpButir
            . ' butir yang memenuhi syarat. '
            . (count($negatif) > 0
                ? 'Butir dengan daya pembeda negatif: ' . laporan_join_list($negatif) . '. '
                : 'Tidak ditemukan butir dengan daya pembeda negatif. ')
            . (count($sukarJelek) > 0
                ? 'Butir sukar sekaligus berdaya pembeda rendah: ' . laporan_join_list($sukarJelek) . '.'
                : 'Tidak ada butir sukar yang sekaligus berdaya pembeda rendah.');
    } else {
        $paragraf[] = 'Daya pembeda belum dapat dihitung pada paket ini karena jumlah peserta belum '
            . 'mencapai empat orang, sehingga kelompok atas dan bawah tidak dapat dibentuk tanpa '
            . 'tumpang tindih.';
    }

    $persenEfektif = $totalPengecoh > 0 ? round($efektif / $totalPengecoh * 100, 2) : 0.0;
    $paragraf[] = 'Pengecoh efektif ' . $efektif . ' dari ' . $totalPengecoh
        . ' (' . laporan_percent($persenEfektif) . '). '
        . ($butirPengecohLemah === []
            ? 'Seluruh butir memiliki setidaknya satu pengecoh yang berfungsi.'
            : 'Butir yang masih memiliki pengecoh tidak berfungsi: '
                . laporan_join_list($butirPengecohLemah, 8) . '.')
        . ($peserta < 4
            ? ' Persentase ini belum stabil karena paket baru diikuti ' . $peserta
                . ' peserta, sehingga penilaian pengecoh perlu diulang setelah peserta bertambah.'
            : '');

    $rekomendasi = [];

    if (count($negatif) > 0) {
        $rekomendasi[] = 'Periksa kunci jawaban ' . laporan_join_list($negatif)
            . ' karena pola jawabannya berlawanan dengan kemampuan siswa.';
    }

    if (count($sukarJelek) > 0) {
        $rekomendasi[] = 'Revisi atau ganti butir ' . laporan_join_list($sukarJelek)
            . ' yang sukar sekaligus tidak membedakan kelompok atas dan bawah.';
    }

    if (count($kunciLemah) > 0) {
        $rekomendasi[] = 'Validasi kunci pada ' . laporan_join_list($kunciLemah, 4)
            . ' karena pengecoh lebih sering dipilih daripada kunci.';
    }

    if ($peserta >= 4 && $totalPengecoh > 0 && $persenEfektif < 50.0) {
        $rekomendasi[] = 'Perbaiki pengecoh pada ' . laporan_join_list($butirPengecohLemah, 6)
            . ' agar pilihan salah tetap menarik bagi siswa.';
    }

    if ($peserta >= 4 && $rataBenar >= 80.0) {
        $rekomendasi[] = 'Butir paket ini cenderung mudah. Tambahkan butir dengan tingkat kognitif '
            . 'lebih tinggi agar daya beda paket meningkat.';
    } elseif ($peserta >= 4 && $rataBenar < 40.0) {
        $rekomendasi[] = 'Secara umum butir terlalu sukar. Tinjau kembali cakupan materi dan '
            . 'tingkat kesulitan soal sebelum dipakai lagi.';
    }

    if ($rekomendasi === []) {
        $rekomendasi[] = $peserta < 4
            ? 'Jumlah peserta paket ini masih terlalu kecil untuk menyimpulkan kualitas butir. '
                . 'Tambahkan data peserta sebelum memutuskan revisi soal.'
            : 'Kualitas butir paket ini relatif baik. Pertahankan komposisi kesukaran '
                . 'dan pengecoh yang sudah berfungsi.';
    }

    return ['paragraf' => $paragraf, 'rekomendasi' => $rekomendasi];
}

/**
 * Narasi analisis rombel (perbandingan antarkelas).
 *
 * @param array<int, array<string, mixed>> $classSummary
 * @param array<int, array<string, mixed>> $classDistribution
 * @param array<string, mixed> $summary
 * @return array{ringkasan: array<int, string>, sorotan: array<int, string>,
 *               rekomendasi: array<int, string>}
 */
function laporan_rombel_narrative(
    array $classSummary,
    array $classDistribution,
    array $summary,
    float $kkm
): array {
    $ringkasan = [];
    $sorotan = [];
    $rekomendasi = [];
    $peserta = (int) $summary['jumlah'];
    $jumlahKelas = count($classSummary);

    if ($peserta === 0 || $jumlahKelas === 0) {
        return ['ringkasan' => [], 'sorotan' => [], 'rekomendasi' => []];
    }

    $ringkasan[] = 'Analisis rombel mencakup ' . $peserta . ' peserta dari ' . $jumlahKelas
        . ' kelas. Rata-rata nilai ' . format_nilai($summary['rata']) . ' dengan KKM '
        . format_nilai($kkm) . ', sehingga ketuntasan mencapai '
        . laporan_percent((float) $summary['persen_tuntas']) . ' ('
        . (int) $summary['tuntas'] . ' tuntas dan ' . (int) $summary['belum'] . ' belum tuntas).';

    $berisi = array_values(array_filter(
        $classSummary,
        static fn (array $class): bool => (int) $class['jumlah'] > 0
    ));

    $kelasKecil = array_values(array_filter(
        $berisi,
        static fn (array $class): bool => (int) $class['jumlah'] < 5
    ));

    if ($kelasKecil !== []) {
        $ringkasan[] = 'Catatan kelayakan data: ' . count($kelasKecil) . ' dari ' . count($berisi)
            . ' kelas diikuti kurang dari lima peserta. Persentase sebaran dan ketuntasan pada '
            . 'kelas tersebut belum stabil, sehingga perbandingan rombel perlu dibaca sebagai '
            . 'gambaran awal.';
    }

    if (count($berisi) > 1) {
        $urutRata = $berisi;
        usort($urutRata, static function (array $a, array $b): int {
            return ((float) $b['rata'] <=> (float) $a['rata'])
                ?: strcmp((string) $a['kelas_nama'], (string) $b['kelas_nama']);
        });

        $tertinggi = $urutRata[0];
        $terendah = $urutRata[count($urutRata) - 1];
        $selisih = round((float) $tertinggi['rata'] - (float) $terendah['rata'], 2);

        $ringkasan[] = 'Rata-rata tertinggi diraih kelas ' . $tertinggi['kelas_nama'] . ' ('
            . format_nilai($tertinggi['rata']) . '), sedangkan kelas ' . $terendah['kelas_nama']
            . ' memiliki rata-rata terendah (' . format_nilai($terendah['rata']) . '). Selisih '
            . 'antarkeduanya ' . number_format($selisih, 2, ',', '.')
            . ' poin' . ($selisih >= 10.0 ? ' dan tergolong lebar' : ' dan masih wajar') . '.';

        $urutTuntas = $berisi;
        usort($urutTuntas, static function (array $a, array $b): int {
            return ((float) $b['persen_tuntas'] <=> (float) $a['persen_tuntas'])
                ?: strcmp((string) $a['kelas_nama'], (string) $b['kelas_nama']);
        });

        $tuntasTertinggi = $urutTuntas[0];
        $tuntasTerendah = $urutTuntas[count($urutTuntas) - 1];
        $ringkasan[] = 'Ketuntasan tertinggi berada pada kelas ' . $tuntasTertinggi['kelas_nama']
            . ' (' . laporan_percent((float) $tuntasTertinggi['persen_tuntas']) . ') dan terendah '
            . 'pada kelas ' . $tuntasTerendah['kelas_nama'] . ' ('
            . laporan_percent((float) $tuntasTerendah['persen_tuntas']) . ').';
    } else {
        $ringkasan[] = 'Data yang ditampilkan hanya mencakup satu kelas, sehingga perbandingan '
            . 'antarkelas belum dapat dilakukan. Tambahkan hasil ujian kelas lain atau ubah filter '
            . 'kelas untuk melihat posisi rombel secara keseluruhan.';
    }

    $bins = laporan_distribution_bins();
    $distMap = [];
    foreach ($classDistribution as $dist) {
        $distMap[(int) $dist['kelas_id']] = $dist;
    }

    $sorotanKelas = $berisi;
    usort($sorotanKelas, static fn (array $a, array $b): int => (int) $b['jumlah'] <=> (int) $a['jumlah']);

    foreach ($sorotanKelas as $index => $class) {
        if ($index >= 8) {
            $sorotan[] = 'Masih ada ' . (count($sorotanKelas) - 8)
                . ' kelas lain; rincian lengkap tersedia pada tabel di bawah.';
            break;
        }

        $dist = $distMap[(int) $class['kelas_id']] ?? null;
        $kalimat = 'Kelas ' . $class['kelas_nama'] . ': ' . (int) $class['jumlah']
            . ' peserta, rata-rata ' . format_nilai($class['rata']) . ', ketuntasan '
            . laporan_percent((float) $class['persen_tuntas']) . '.';

        if ($dist !== null && $dist['jumlah'] > 0) {
            $maxIndex = 0;
            foreach ($dist['counts'] as $binIndex => $jumlah) {
                if ($jumlah > $dist['counts'][$maxIndex]) {
                    $maxIndex = $binIndex;
                }
            }

            $kalimat .= ' Sebanyak ' . laporan_percent((float) $dist['persen'][$maxIndex])
                . ' pesertanya berada pada rentang nilai ' . $bins[$maxIndex]['label'] . '.';
        }

        $sorotan[] = $kalimat;
    }

    $diBawahKkm = array_values(array_filter(
        $berisi,
        static fn (array $class): bool => (float) $class['rata'] + 0.00001 < $kkm
    ));

    if ($diBawahKkm !== []) {
        $nama = array_map(
            static fn (array $class): string => (string) $class['kelas_nama']
                . ' (' . format_nilai($class['rata']) . ')',
            $diBawahKkm
        );
        $rekomendasi[] = 'Kelas dengan rata-rata di bawah KKM: ' . laporan_join_list($nama, 6)
            . '. Prioritaskan program remedial dan pendampingan di kelas tersebut.';
    }

    if (count($berisi) > 1) {
        $selisih = round(
            (float) max(array_column($berisi, 'rata')) - (float) min(array_column($berisi, 'rata')),
            2
        );

        if ($selisih >= 10.0) {
            $rekomendasi[] = 'Selisih rata-rata antarkelas mencapai '
                . number_format($selisih, 2, ',', '.') . ' poin. Lakukan analisis kesetaraan '
                . 'pembelajaran agar capaian tiap rombel lebih merata.';
        }
    }

    $tuntasRendah = array_values(array_filter(
        $berisi,
        static fn (array $class): bool => (float) $class['persen_tuntas'] < 50.0
    ));

    if ($tuntasRendah !== []) {
        $nama = array_map(
            static fn (array $class): string => (string) $class['kelas_nama']
                . ' (' . laporan_percent((float) $class['persen_tuntas']) . ')',
            $tuntasRendah
        );
        $rekomendasi[] = 'Kelas dengan ketuntasan di bawah 50%: ' . laporan_join_list($nama, 6)
            . '. Fokuskan tindak lanjut pada penguasaan materi prasyarat.';
    }

    if ((float) $summary['persen_tuntas'] < 75.0) {
        $rekomendasi[] = 'Ketuntasan keseluruhan baru ' . laporan_percent((float) $summary['persen_tuntas'])
            . '. Pertimbangkan pembelajaran ulang pada indikator yang paling banyak salah.';
    }

    if ($rekomendasi === []) {
        $rekomendasi[] = 'Capaian antarkelas relatif merata dan ketuntasan sudah baik. '
            . 'Pertahankan strategi pembelajaran yang berjalan.';
    }

    return ['ringkasan' => $ringkasan, 'sorotan' => $sorotan, 'rekomendasi' => $rekomendasi];
}

/**
 * Seluruh jawaban peserta untuk satu ujian dalam satu query, dikelompokkan
 * berdasarkan hasil_ujian_id. Dipakai ekspor rincian jawaban agar tidak
 * melakukan query per siswa.
 *
 * @return array<int, array<int, array<string, mixed>>>
 */
function laporan_answers_bulk(int $ujianId, ?int $kelasId = null): array
{
    if (!laporan_exam_accessible($ujianId)) {
        return [];
    }

    $sql = 'SELECT j.hasil_ujian_id, j.nomor_soal, j.tipe_soal, j.jawaban, j.kunci, j.skor, j.is_benar
            FROM jawaban_siswa j
            INNER JOIN hasil_ujian h ON h.id = j.hasil_ujian_id
            WHERE h.ujian_id = :ujian_id';
    $params = ['ujian_id' => $ujianId];

    if ($kelasId !== null && $kelasId > 0) {
        $sql .= ' AND h.kelas_id = :kelas_id';
        $params['kelas_id'] = $kelasId;
    }

    $sql .= ' ORDER BY j.hasil_ujian_id ASC, j.nomor_soal ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $grouped[(int) $row['hasil_ujian_id']][] = $row;
    }

    return $grouped;
}

/**
 * Detail satu hasil beserta seluruh jawabannya.
 *
 * @return array{result:array<string, mixed>, answers:array<int, array<string, mixed>>}|null
 */
function laporan_result_detail(int $hasilId, int $ujianId, ?int $kelasId = null): ?array
{
    if ($hasilId < 1 || !laporan_exam_accessible($ujianId)) {
        return null;
    }

    $sql = 'SELECT h.*, k.nama AS kelas_nama, k.tingkat, k.tahun_ajaran,
                   ps.kode_paket, ps.keterangan AS paket_keterangan,
                   u.nama AS ujian_nama, u.kkm, u.jumlah_soal,
                   u.tanggal_ujian, u.waktu_mulai, u.waktu_selesai,
                   mp.nama AS mapel_nama, mp.kode AS mapel_kode
            FROM hasil_ujian h
            INNER JOIN kelas k ON k.id = h.kelas_id
            INNER JOIN paket_soal ps ON ps.id = h.paket_soal_id
            INNER JOIN ujian u ON u.id = h.ujian_id
            INNER JOIN mata_pelajaran mp ON mp.id = u.mata_pelajaran_id
            WHERE h.id = :hasil_id AND h.ujian_id = :ujian_id';

    $params = ['hasil_id' => $hasilId, 'ujian_id' => $ujianId];
    if ($kelasId !== null && $kelasId > 0) {
        $sql .= ' AND h.kelas_id = :kelas_id';
        $params['kelas_id'] = $kelasId;
    }

    $stmt = db()->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    $result = $stmt->fetch();

    if ($result === false) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT nomor_soal, tipe_soal, jawaban, kunci, skor, is_benar
         FROM jawaban_siswa
         WHERE hasil_ujian_id = :hasil_id
         ORDER BY nomor_soal ASC'
    );
    $stmt->execute(['hasil_id' => $hasilId]);

    return [
        'result'  => $result,
        'answers' => $stmt->fetchAll(),
    ];
}

/**
 * Label tingkat kesukaran untuk badge UI.
 */
function laporan_difficulty_class(string $label): string
{
    return match ($label) {
        'Mudah' => 'success',
        'Sedang' => 'warning',
        'Sukar' => 'danger',
        default => 'secondary',
    };
}

/**
 * Label daya pembeda untuk badge UI.
 */
function laporan_discrimination_class(string $label): string
{
    return match ($label) {
        'Baik' => 'success',
        'Cukup' => 'primary',
        'Rendah' => 'warning',
        'Jelek' => 'danger',
        default => 'secondary',
    };
}

/**
 * Nama file aman untuk ekspor.
 */
function laporan_slug(string $text, string $fallback = 'laporan'): string
{
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return $slug !== '' ? substr($slug, 0, 80) : $fallback;
}

/**
 * Nilai persentase dengan format Indonesia.
 */
function laporan_percent(float $value): string
{
    return number_format($value, 2, ',', '.') . '%';
}
