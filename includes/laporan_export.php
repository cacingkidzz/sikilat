<?php
declare(strict_types=1);

/**
 * SIKILAT - Pembuat berkas ekspor laporan (Tahap 5).
 *
 * Fungsi di berkas ini tidak mengirim header HTTP. Endpoint
 * admin/laporan/excel.php dan admin/laporan/pdf.php memanggil builder di sini,
 * sehingga hasil ekspor juga dapat diuji langsung dari CLI.
 */

if (!defined('SIKILAT_APP')) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Nama berkas ekspor yang aman dan deskriptif.
 */
function laporan_export_filename(string $prefix, array $exam, ?array $selectedClass, string $extension): string
{
    $parts = [$prefix, laporan_slug((string) ($exam['nama'] ?? 'ujian'))];

    if ($selectedClass !== null) {
        $parts[] = laporan_slug((string) ($selectedClass['nama'] ?? 'kelas'));
    }

    $parts[] = date('Ymd');

    return implode('-', $parts) . '.' . $extension;
}

/* =====================================================================
 * EXCEL
 * ================================================================== */

/**
 * Menulis satu nilai sel dengan tipe eksplisit agar teks dari pengguna tidak
 * pernah dieksekusi sebagai formula.
 */
function laporan_excel_put(Worksheet $sheet, int $row, int $column, mixed $value, ?string $format = null): void
{
    $coordinate = Coordinate::stringFromColumnIndex($column) . $row;

    if (is_int($value) || is_float($value)) {
        $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_NUMERIC);
    } else {
        $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
    }

    if ($format !== null) {
        $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode($format);
    }
}

/**
 * @param array<int, mixed> $values
 * @param array<int, string|null> $formats
 */
function laporan_excel_row(Worksheet $sheet, int $row, array $values, array $formats = []): void
{
    foreach (array_values($values) as $index => $value) {
        laporan_excel_put($sheet, $row, $index + 1, $value, $formats[$index] ?? null);
    }
}

/**
 * Membuat lembar kerja dengan judul, metadata, dan baris header.
 *
 * @param array<int, string> $metaLines
 * @param array<int, string> $headers
 * @return array{0:Worksheet, 1:int}
 */
function laporan_excel_start(
    Spreadsheet $spreadsheet,
    string $sheetTitle,
    string $title,
    array $metaLines,
    array $headers
): array {
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle($sheetTitle);
    $sheet->setShowGridLines(false);
    $sheet->getSheetView()->setZoomScale(90);

    $lastColumn = max(1, count($headers));
    $lastLetter = Coordinate::stringFromColumnIndex($lastColumn);

    laporan_excel_put($sheet, 1, 1, $title);
    $sheet->mergeCells('A1:' . $lastLetter . '1');
    $sheet->getStyle('A1:' . $lastLetter . '1')->applyFromArray([
        'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0D6EFD']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(26);

    $row = 2;
    foreach ($metaLines as $line) {
        laporan_excel_put($sheet, $row, 1, $line);
        $sheet->getStyle('A' . $row)->getFont()->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF475467'));
        $sheet->getRowDimension($row)->setRowHeight(15);
        $row++;
    }

    $headerRow = $row + 1;
    laporan_excel_row($sheet, $headerRow, $headers);
    $sheet->getStyle('A' . $headerRow . ':' . $lastLetter . $headerRow)->applyFromArray([
        'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FF0A3F9E']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEAF1FF']],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical'   => Alignment::VERTICAL_CENTER,
            'wrapText'   => true,
        ],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFC9D8EE']]],
    ]);
    $sheet->getRowDimension($headerRow)->setRowHeight(30);
    $sheet->freezePane('A' . ($headerRow + 1));

    return [$sheet, $headerRow];
}

/**
 * Merapikan lebar kolom, border, dan area filter.
 *
 * @param array<int, float> $widths
 */
function laporan_excel_finish(
    Worksheet $sheet,
    int $headerRow,
    int $lastRow,
    array $widths,
    ?string $autoFilterRange = null,
    string $orientation = 'portrait'
): void {
    foreach (array_values($widths) as $index => $width) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
    }

    $lastLetter = Coordinate::stringFromColumnIndex(max(1, count($widths)));

    if ($lastRow >= $headerRow) {
        $sheet->getStyle('A' . $headerRow . ':' . $lastLetter . $lastRow)->applyFromArray([
            'font'      => ['size' => 9],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE0E7F0']]],
        ]);
        $sheet->getStyle('A' . ($headerRow + 1) . ':' . $lastLetter . $lastRow)
            ->getAlignment()
            ->setWrapText(false);
    }

    if ($autoFilterRange !== null && $lastRow > $headerRow) {
        $sheet->setAutoFilter($autoFilterRange);
    }

    $sheet->getPageSetup()->setOrientation($orientation === 'landscape' ? 'landscape' : 'portrait');
    $sheet->getPageSetup()->setFitToWidth(1);
    $sheet->getPageSetup()->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.35)->setRight(0.35);
}

/**
 * Baris total dengan latar lembut dan huruf tebal.
 *
 * @param array<int, mixed> $values
 * @param array<int, string|null> $formats
 */
function laporan_excel_total_row(Worksheet $sheet, int $row, array $values, array $formats = []): void
{
    laporan_excel_row($sheet, $row, $values, $formats);

    $lastColumn = max(1, count($values));
    $lastLetter = Coordinate::stringFromColumnIndex($lastColumn);
    $sheet->getStyle('A' . $row . ':' . $lastLetter . $row)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => 'FF0A3F9E']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF2F6FC']],
    ]);
}

/**
 * Daftar kode paket untuk metadata laporan.
 *
 * @param array<int, array<string, mixed>> $packages
 * @return array<int, string>
 */
function laporan_export_package_codes(array $packages): array
{
    $codes = [];
    foreach ($packages as $package) {
        $code = trim((string) ($package['kode_paket'] ?? ''));
        if ($code !== '') {
            $codes[] = $code;
        }
    }

    return $codes;
}

/**
 * @param array<string, mixed> $exam
 * @param array<int, array<string, mixed>> $packages
 * @return array<int, string>
 */
function laporan_export_meta_lines(array $exam, ?array $selectedClass, array $packages, float $kkm): array
{
    $classLabel = $selectedClass !== null ? (string) $selectedClass['nama'] : 'Semua kelas';
    $codes = laporan_export_package_codes($packages);
    $paketLabel = $codes !== [] ? implode(', ', $codes) : '-';

    return [
        'Ujian: ' . (string) $exam['nama'] . ' | Mata Pelajaran: ' . (string) $exam['mapel_nama']
            . ' (' . (string) $exam['mapel_kode'] . ')',
        'Kelas: ' . $classLabel . ' | Tanggal: ' . format_tanggal((string) $exam['tanggal_ujian'])
            . ' | Jumlah soal: ' . (int) $exam['jumlah_soal'] . ' | KKM: ' . format_nilai($kkm),
        'Paket soal: ' . $paketLabel . ' | Dicetak: ' . date('d/m/Y H:i') . ' WIB',
    ];
}

/**
 * Membangun workbook Excel laporan lengkap.
 *
 * @param array<string, mixed> $exam
 * @param array<int, array<string, mixed>> $results
 * @param array<int, array<string, mixed>> $classSummary
 * @param array<int, array<string, mixed>> $packageSummary
 * @param array<int, array<string, mixed>> $packages
 * @param array<int, array<string, mixed>> $items
 */
function laporan_excel_build(
    array $exam,
    ?array $selectedClass,
    array $results,
    array $classSummary,
    array $packageSummary,
    array $packages,
    array $items,
    float $kkm
): Spreadsheet {
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator(setting('nama_aplikasi', APP_NAME))
        ->setTitle('Laporan Hasil Ujian - ' . (string) $exam['nama'])
        ->setDescription('Laporan hasil ujian, rekap nilai, rincian jawaban, dan analisis butir.');

    $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
    $spreadsheet->removeSheetByIndex(0);
    $meta = laporan_export_meta_lines($exam, $selectedClass, $packages, $kkm);
    $totalSoal = (int) $exam['jumlah_soal'];
    $summary = laporan_summary($results, $kkm);

    /* -------------------------------------------------------------
     * 1. Rekap Nilai
     * ---------------------------------------------------------- */
    $headers = [
        'No', 'Nama Siswa', 'No. Absen', 'Kelas', 'Paket', 'Jumlah Soal',
        'Benar', 'Salah', 'Kosong', 'Nilai', 'KKM', 'Keterangan',
    ];
    [$sheet, $headerRow] = laporan_excel_start(
        $spreadsheet,
        'Rekap Nilai',
        'REKAP NILAI SELURUH SISWA',
        $meta,
        $headers
    );

    $row = $headerRow + 1;
    $number = 1;
    foreach ($results as $result) {
        $nilai = (float) $result['nilai'];
        laporan_excel_row($sheet, $row, [
            $number,
            (string) $result['nama_siswa'],
            (string) $result['nomor_absen'],
            (string) $result['kelas_nama'],
            (string) $result['kode_paket'],
            (int) $result['total_soal'],
            (int) $result['jumlah_benar'],
            (int) $result['jumlah_salah'],
            (int) $result['jumlah_kosong'],
            $nilai,
            $kkm,
            $nilai + 0.00001 >= $kkm ? 'Tuntas' : 'Belum Tuntas',
        ], [null, null, null, null, null, null, null, null, null, '0.00', '0.00', null]);
        $row++;
        $number++;
    }

    laporan_excel_total_row($sheet, $row, [
        'TOTAL / RATA-RATA', '', '', '', '',
        $summary['jumlah'] > 0 ? $summary['jumlah'] * $totalSoal : 0,
        $summary['benar'],
        $summary['salah'],
        $summary['kosong'],
        $summary['rata'] ?? 0,
        $kkm,
        $summary['tuntas'] . ' tuntas / ' . $summary['belum'] . ' belum',
    ], [null, null, null, null, null, null, null, null, null, '0.00', '0.00', null]);

    laporan_excel_finish(
        $sheet,
        $headerRow,
        $row,
        [6, 30, 12, 18, 9, 12, 9, 9, 9, 10, 10, 18],
        'A' . $headerRow . ':L' . max($headerRow, $row - 1)
    );

    /* -------------------------------------------------------------
     * 2. Rekap per Kelas
     * ---------------------------------------------------------- */
    $headers = [
        'No', 'Kelas', 'Tingkat', 'Tahun Ajaran', 'Peserta', 'Rata-rata',
        'Tertinggi', 'Terendah', 'Tuntas', 'Belum Tuntas', '% Tuntas',
        'Benar', 'Salah', 'Kosong',
    ];
    [$sheet, $headerRow] = laporan_excel_start(
        $spreadsheet,
        'Rekap per Kelas',
        'REKAP NILAI BERDASARKAN KELAS',
        $meta,
        $headers
    );

    $row = $headerRow + 1;
    $number = 1;
    foreach ($classSummary as $class) {
        laporan_excel_row($sheet, $row, [
            $number,
            (string) $class['kelas_nama'],
            (string) $class['tingkat'],
            (string) $class['tahun_ajaran'],
            (int) $class['jumlah'],
            (float) ($class['rata'] ?? 0),
            (float) ($class['tertinggi'] ?? 0),
            (float) ($class['terendah'] ?? 0),
            (int) $class['tuntas'],
            (int) $class['belum'],
            (float) $class['persen_tuntas'],
            (int) $class['benar'],
            (int) $class['salah'],
            (int) $class['kosong'],
        ], [null, null, null, null, null, '0.00', '0.00', '0.00', null, null, '0.00"%"', null, null, null]);
        $row++;
        $number++;
    }

    laporan_excel_total_row($sheet, $row, [
        'TOTAL', '', '', '',
        $summary['jumlah'],
        $summary['rata'] ?? 0,
        $summary['tertinggi'] ?? 0,
        $summary['terendah'] ?? 0,
        $summary['tuntas'],
        $summary['belum'],
        $summary['persen_tuntas'],
        $summary['benar'],
        $summary['salah'],
        $summary['kosong'],
    ], [null, null, null, null, null, '0.00', '0.00', '0.00', null, null, '0.00"%"', null, null, null]);

    laporan_excel_finish(
        $sheet,
        $headerRow,
        $row,
        [6, 24, 10, 14, 9, 11, 11, 11, 9, 13, 10, 9, 9, 9],
        'A' . $headerRow . ':N' . max($headerRow, $row - 1)
    );

    /* -------------------------------------------------------------
     * 3. Rekap per Paket
     * ---------------------------------------------------------- */
    $headers = [
        'No', 'Paket', 'Keterangan', 'Peserta', 'Rata-rata', 'Tertinggi',
        'Terendah', 'Tuntas', 'Belum Tuntas', '% Tuntas', 'Benar', 'Salah', 'Kosong',
    ];
    [$sheet, $headerRow] = laporan_excel_start(
        $spreadsheet,
        'Rekap Paket',
        'REKAP NILAI BERDASARKAN PAKET SOAL',
        $meta,
        $headers
    );

    $row = $headerRow + 1;
    $number = 1;
    foreach ($packageSummary as $package) {
        laporan_excel_row($sheet, $row, [
            $number,
            'Paket ' . (string) $package['kode_paket'],
            (string) ($package['keterangan'] !== '' ? $package['keterangan'] : '-'),
            (int) $package['jumlah'],
            (float) ($package['rata'] ?? 0),
            (float) ($package['tertinggi'] ?? 0),
            (float) ($package['terendah'] ?? 0),
            (int) $package['tuntas'],
            (int) $package['belum'],
            (float) $package['persen_tuntas'],
            (int) $package['benar'],
            (int) $package['salah'],
            (int) $package['kosong'],
        ], [null, null, null, null, '0.00', '0.00', '0.00', null, null, '0.00"%"', null, null, null]);
        $row++;
        $number++;
    }

    if ($row === $headerRow + 1) {
        laporan_excel_row($sheet, $row, ['Belum ada hasil ujian pada ujian ini.']);
        $row++;
    }

    laporan_excel_finish(
        $sheet,
        $headerRow,
        $row - 1,
        [6, 12, 28, 9, 11, 11, 11, 9, 13, 10, 9, 9, 9],
        'A' . $headerRow . ':M' . max($headerRow, $row - 1)
    );

    /* -------------------------------------------------------------
     * 4. Rincian Jawaban
     * ---------------------------------------------------------- */
    $headers = [
        'No', 'Paket', 'Nama Siswa', 'No. Absen', 'Kelas',
        'No. Soal', 'Tipe', 'Jawaban', 'Kunci', 'Skor', 'Hasil',
    ];
    [$sheet, $headerRow] = laporan_excel_start(
        $spreadsheet,
        'Rincian Jawaban',
        'RINCIAN JAWABAN SETIAP SISWA',
        $meta,
        $headers
    );

    $answersMap = laporan_answers_bulk(
        (int) $exam['id'],
        $selectedClass !== null ? (int) $selectedClass['id'] : null
    );

    $row = $headerRow + 1;
    $number = 1;
    foreach ($results as $result) {
        $hasilId = (int) $result['id'];
        $answers = $answersMap[$hasilId] ?? [];

        if ($answers === []) {
            laporan_excel_row($sheet, $row, [
                $number,
                (string) $result['kode_paket'],
                (string) $result['nama_siswa'],
                (string) $result['nomor_absen'],
                (string) $result['kelas_nama'],
                '-',
                '-',
                '-',
                '-',
                '-',
                'Tidak ada jawaban',
            ]);
            $row++;
            $number++;
            continue;
        }

        foreach ($answers as $answer) {
            $tipe = soal_tipe_normalize($answer['tipe_soal'] ?? 'pg');
            $skor = (float) ($answer['skor'] ?? ((int) $answer['is_benar'] === 1 ? 1 : 0));
            $jawabanRaw = $answer['jawaban'] !== null && (string) $answer['jawaban'] !== ''
                ? (string) $answer['jawaban']
                : null;
            $jawaban = $jawabanRaw === null ? '-' : soal_answer_display($tipe, $jawabanRaw);
            $kunci = $answer['kunci'] !== null && (string) $answer['kunci'] !== ''
                ? soal_answer_display($tipe, (string) $answer['kunci'])
                : '-';

            if ($jawabanRaw === null) {
                $status = 'Kosong';
            } elseif ((int) $answer['is_benar'] === 1) {
                $status = 'Benar';
            } elseif ($skor > 0) {
                $status = 'Parsial';
            } else {
                $status = 'Salah';
            }

            laporan_excel_row($sheet, $row, [
                $number,
                (string) $result['kode_paket'],
                (string) $result['nama_siswa'],
                (string) $result['nomor_absen'],
                (string) $result['kelas_nama'],
                (int) $answer['nomor_soal'],
                soal_tipe_short($tipe),
                $jawaban,
                $kunci,
                $skor,
                $status,
            ], [null, null, null, null, null, null, null, null, null, '0.00', null]);
            $row++;
            $number++;
        }
    }

    if ($row === $headerRow + 1) {
        laporan_excel_row($sheet, $row, ['Belum ada jawaban siswa.']);
        $row++;
    }

    laporan_excel_finish(
        $sheet,
        $headerRow,
        $row - 1,
        [6, 8, 28, 11, 18, 9, 8, 12, 10, 8, 11],
        'A' . $headerRow . ':K' . max($headerRow, $row - 1),
        'landscape'
    );

    /* -------------------------------------------------------------
     * 5. Analisis Butir (dipisah per paket)
     * ---------------------------------------------------------- */
    $analysisLetters = soal_option_letters_from_items($items);
    if ($analysisLetters === []) {
        $analysisLetters = ['A', 'B', 'C', 'D', 'E'];
    }

    $headers = [
        'No', 'Paket', 'No. Soal', 'Tipe', 'Kunci', 'Benar', '% Benar', 'Tingkat',
        'Daya Pembeda', 'Kategori',
    ];
    foreach ($analysisLetters as $letter) {
        $headers[] = $letter . ' %';
    }
    $headers[] = 'Kosong %';
    $headers[] = 'Pengecoh Efektif';

    $metaAnalisis = array_merge($meta, [
        'Analisis butir dihitung terpisah per paket. Nomor soal yang sama pada paket berbeda tidak otomatis merujuk butir yang sama.',
    ]);
    [$sheet, $headerRow] = laporan_excel_start(
        $spreadsheet,
        'Analisis Butir',
        'ANALISIS BUTIR SOAL PER PAKET',
        $metaAnalisis,
        $headers
    );

    $row = $headerRow + 1;
    $number = 1;
    $firstOptionColumn = 11;
    $optionCount = count($analysisLetters);
    $kosongColumn = $firstOptionColumn + $optionCount;
    $effectiveColumn = $kosongColumn + 1;
    $lastColumn = $effectiveColumn;

    if ($items === []) {
        laporan_excel_row($sheet, $row, ['Belum ada data jawaban yang dapat dianalisis.']);
        $row++;
    } else {
        foreach ($items as $package) {
            foreach ($package['items'] as $item) {
                $discrimination = $item['daya_pembeda'];
                $cells = [
                    $number,
                    'Paket ' . (string) $package['kode_paket'],
                    (int) $item['nomor'],
                    soal_tipe_short($item['tipe_soal'] ?? 'pg'),
                    (string) ($item['kunci_tampil'] ?? $item['kunci']),
                    (int) $item['benar'],
                    (float) $item['persen_benar'],
                    (string) $item['tingkat_kesukaran'],
                    $discrimination !== null ? (float) $discrimination : '-',
                    (string) $item['daya_pembeda_label'],
                ];
                $formats = [null, null, null, null, null, null, '0.00"%"', null, '0.00', null];

                foreach ($analysisLetters as $letter) {
                    $cells[] = (float) ($item['opsi'][$letter]['persen'] ?? 0);
                    $formats[] = '0.00"%"';
                }

                $cells[] = (float) $item['kosong_persen'];
                $formats[] = '0.00"%"';
                $cells[] = $item['pengecoh_efektif'] !== [] ? implode(', ', $item['pengecoh_efektif']) : '-';
                $formats[] = null;

                laporan_excel_row($sheet, $row, $cells, $formats);

                foreach ($analysisLetters as $index => $letter) {
                    $coordinate = Coordinate::stringFromColumnIndex($firstOptionColumn + $index) . $row;
                    $option = $item['opsi'][$letter] ?? ['is_key' => false, 'effective' => false];

                    if ($option['is_key']) {
                        $sheet->getStyle($coordinate)->getFill()
                            ->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()->setARGB('FFDCEBFF');
                        $sheet->getStyle($coordinate)->getFont()->setBold(true);
                    } elseif ($option['effective']) {
                        $sheet->getStyle($coordinate)->getFill()
                            ->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()->setARGB('FFFFF3D6');
                    }
                }

                $row++;
                $number++;
            }
        }
    }

    $widths = [6, 10, 9, 8, 8, 9, 10, 11, 13, 12];
    foreach ($analysisLetters as $letter) {
        $widths[] = 9;
    }
    $widths[] = 10;
    $widths[] = 18;

    laporan_excel_finish(
        $sheet,
        $headerRow,
        $row - 1,
        $widths,
        'A' . $headerRow . ':' . Coordinate::stringFromColumnIndex($lastColumn) . max($headerRow, $row - 1),
        'landscape'
    );

    $spreadsheet->setActiveSheetIndex(0);

    return $spreadsheet;
}

/* =====================================================================
 * PDF
 * ================================================================== */

/**
 * Dokumen FPDF dengan kepala halaman resmi sekolah dan footer laporan.
 */
final class SikilatLaporanPdf extends FPDF
{
    public string $laporanJudul = '';
    public string $laporanSubjudul = '';
    public string $laporanApp = '';
    public string $laporanSekolah = '';
    public string $laporanKontak = '';
    public string $laporanFooter = '';
    public string $laporanLogo = '';

    /**
     * Ubah teks UTF-8 menjadi Latin-1 agar aman untuk font inti FPDF.
     */
    public function t(string $text): string
    {
        $text = str_replace(
            ["\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\x9C", "\xE2\x80\x9D", "\xC2\xA0"],
            ['-', '-', "'", "'", '"', '"', ' '],
            $text
        );

        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);

        if ($converted !== false) {
            return $converted;
        }

        return (string) preg_replace('/[^\x20-\x7E]/', '?', $text);
    }

    /**
     * Potong teks Latin-1 yang sudah dikonversi agar tidak melewati lebar sel.
     */
    public function clipText(string $text, float $width): string
    {
        if ($width <= 0) {
            return '';
        }

        if ($this->GetStringWidth($text) <= $width) {
            return $text;
        }

        $ellipsis = '...';
        $available = $width - $this->GetStringWidth($ellipsis);

        if ($available <= 0) {
            return '';
        }

        while ($text !== '' && $this->GetStringWidth($text) > $available) {
            $text = substr($text, 0, -1);
        }

        return rtrim($text) . $ellipsis;
    }

    /**
     * Turunkan ukuran huruf sampai teks Latin-1 muat pada lebar tertentu.
     */
    public function fitFontSize(string $text, float $width, float $start, float $min, string $style = 'B'): float
    {
        $size = $start;

        while ($size > $min) {
            $this->SetFont('Helvetica', $style, $size);

            if ($this->GetStringWidth($text) <= $width) {
                return $size;
            }

            $size -= 0.5;
        }

        $this->SetFont('Helvetica', $style, $min);

        return $min;
    }

    public function Header(): void
    {
        $width = $this->contentWidth();
        $x = $this->lMargin;

        if ($this->PageNo() > 1) {
            $this->SetY(10);
            $this->SetFont('Helvetica', 'B', 8.6);
            $this->SetTextColor(11, 42, 94);
            $this->Cell(
                $width * 0.62,
                4.8,
                $this->clipText(
                    $this->t($this->laporanSekolah !== '' ? $this->laporanSekolah : $this->laporanApp),
                    $width * 0.62 - 2
                ),
                0,
                0,
                'L'
            );
            $this->SetFont('Helvetica', '', 8);
            $this->SetTextColor(110, 122, 140);
            $this->Cell(
                $width * 0.38,
                4.8,
                $this->clipText($this->t($this->laporanJudul), $width * 0.38 - 2),
                0,
                1,
                'R'
            );

            $lineY = $this->GetY() + 1.4;
            $this->SetDrawColor(13, 110, 253);
            $this->SetLineWidth(0.35);
            $this->Line($x, $lineY, $x + $width, $lineY);
            $this->SetLineWidth(0.2);
            $this->SetTextColor(16, 24, 40);
            $this->SetY($lineY + 4.2);

            return;
        }

        $logoSize = 17.0;
        $logoBottom = $this->GetY();

        if ($this->laporanLogo !== '') {
            try {
                $this->Image($this->laporanLogo, $x, $this->GetY(), $logoSize, $logoSize);
                $logoBottom = $this->GetY() + $logoSize;
            } catch (\Throwable) {
                $this->laporanLogo = '';
            }
        }

        $textX = $x + ($this->laporanLogo !== '' ? $logoSize + 5.0 : 0.0);
        $textW = $x + $width - $textX;

        $name = $this->laporanSekolah !== '' ? $this->laporanSekolah : $this->laporanApp;
        $nameLatin = $this->t(strtoupper($name));
        $this->fitFontSize($nameLatin, $textW, 14.5, 9.5, 'B');
        $this->SetTextColor(11, 42, 94);
        $this->SetXY($textX, $this->GetY());
        $this->Cell($textW, 7.0, $this->clipText($nameLatin, $textW), 0, 1, 'C');

        if ($this->laporanKontak !== '') {
            $this->SetX($textX);
            $this->SetFont('Helvetica', '', 7.5);
            $this->SetTextColor(84, 98, 120);
            $this->Cell($textW, 4.0, $this->clipText($this->t($this->laporanKontak), $textW), 0, 1, 'C');
        }

        $this->SetX($textX);
        $this->SetFont('Helvetica', 'B', 9.2);
        $this->SetTextColor(13, 110, 253);
        $this->Cell(
            $textW,
            5.2,
            $this->clipText($this->t(strtoupper($this->laporanJudul)), $textW),
            0,
            1,
            'C'
        );

        $lineY = max($this->GetY(), $logoBottom) + 1.6;
        $this->SetDrawColor(13, 110, 253);
        $this->SetLineWidth(0.7);
        $this->Line($x, $lineY, $x + $width, $lineY);
        $this->SetDrawColor(150, 195, 246);
        $this->SetLineWidth(0.25);
        $this->Line($x, $lineY + 1.1, $x + $width, $lineY + 1.1);
        $this->SetLineWidth(0.2);
        $this->SetTextColor(16, 24, 40);
        $this->SetY($lineY + 4.8);
    }

    public function Footer(): void
    {
        $width = $this->contentWidth();
        $this->SetY(-14);
        $this->SetDrawColor(219, 228, 241);
        $this->SetLineWidth(0.25);
        $this->Line($this->lMargin, $this->GetY(), $this->lMargin + $width, $this->GetY());
        $this->SetY(-11.5);
        $this->SetFont('Helvetica', 'I', 7.4);
        $this->SetTextColor(105, 117, 135);

        $footer = $this->laporanFooter !== '' ? $this->laporanFooter : $this->laporanApp;
        $this->Cell(
            $width * 0.62,
            4.2,
            $this->clipText($this->t($footer), $width * 0.62 - 2),
            0,
            0,
            'L'
        );
        $this->Cell($width * 0.38, 4.2, 'Halaman ' . $this->PageNo() . ' dari {nb}', 0, 1, 'R');
    }

    public function sectionTitle(string $title): void
    {
        $this->ensureSpace(24);
        $this->Ln(2.0);

        $y = $this->GetY();
        $width = $this->contentWidth();

        $this->SetFillColor(240, 245, 253);
        $this->Rect($this->lMargin, $y, $width, 7.0, 'F');
        $this->SetFillColor(13, 110, 253);
        $this->Rect($this->lMargin, $y, 1.4, 7.0, 'F');

        $this->SetXY($this->lMargin + 3.4, $y + 1.15);
        $this->SetFont('Helvetica', 'B', 9.2);
        $this->SetTextColor(11, 42, 94);
        $this->Cell(
            $width - 4.4,
            4.7,
            $this->clipText($this->t($title), $width - 6.0),
            0,
            0,
            'L'
        );

        $this->SetY($y + 8.6);
        $this->SetTextColor(16, 24, 40);
    }

    public function leftMargin(): float
    {
        return $this->lMargin;
    }

    public function contentWidth(): float
    {
        return $this->GetPageWidth() - $this->lMargin - $this->rMargin;
    }

    public function bottomLimit(float $offset = 0.0): float
    {
        return $this->GetPageHeight() - $this->bMargin - $offset;
    }

    public function ensureSpace(float $height): void
    {
        if ($this->GetY() + $height > $this->bottomLimit()) {
            $this->AddPage();
        }
    }

    public function note(string $text): void
    {
        $this->ensureSpace(10);
        $this->SetFont('Helvetica', 'I', 7.6);
        $this->SetTextColor(96, 108, 126);
        $this->MultiCell($this->contentWidth(), 4.1, $this->t($text), 0, 'L');
        $this->SetTextColor(16, 24, 40);
        $this->Ln(1.6);
    }

    /**
     * Garis putus-putus untuk ambang KKM atau batas tingkat kesukaran.
     */
    public function dashedLine(
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        float $dash = 1.5,
        float $gap = 1.2
    ): void {
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $length = sqrt($dx * $dx + $dy * $dy);

        if ($length <= 0.0) {
            return;
        }

        $ux = $dx / $length;
        $uy = $dy / $length;
        $position = 0.0;

        while ($position < $length) {
            $end = min($position + $dash, $length);
            $this->Line(
                $x1 + $ux * $position,
                $y1 + $uy * $position,
                $x1 + $ux * $end,
                $y1 + $uy * $end
            );
            $position = $end + $gap;
        }
    }
}

/**
 * Logo raster yang dapat disematkan FPDF. SVG/WEBP tidak didukung.
 */
function laporan_pdf_logo_path(): string
{
    $filename = basename((string) setting('logo_sekolah', ''));

    if ($filename === '') {
        return '';
    }

    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (!in_array($extension, ['png', 'jpg', 'jpeg'], true)) {
        return '';
    }

    $path = UPLOADS_PATH . '/logo/' . $filename;

    return is_file($path) ? $path : '';
}

function laporan_pdf_doc(string $title, string $subtitle, string $orientation = 'P'): SikilatLaporanPdf
{
    $pdf = new SikilatLaporanPdf($orientation, 'mm', 'A4');
    $pdf->laporanApp = (string) setting('nama_aplikasi', APP_NAME);
    $pdf->laporanSekolah = (string) setting('nama_sekolah', '');
    $pdf->laporanKontak = laporan_pdf_school_contact();
    $pdf->laporanJudul = $title;
    $pdf->laporanSubjudul = $subtitle;
    $pdf->laporanFooter = (string) setting('footer_laporan', 'Dicetak melalui ' . APP_NAME);
    $pdf->laporanLogo = laporan_pdf_logo_path();
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 22);
    $pdf->SetTitle($title, true);
    $pdf->SetAuthor($pdf->laporanSekolah !== '' ? $pdf->laporanSekolah : $pdf->laporanApp, true);
    $pdf->SetSubject($subtitle, true);
    $pdf->SetCreator($pdf->laporanApp, true);
    $pdf->AliasNbPages();
    $pdf->AddPage();

    return $pdf;
}

/**
 * Baris kontak sekolah untuk header PDF, hanya bagian yang terisi.
 */
function laporan_pdf_school_contact(): string
{
    $alamat = trim(setting('alamat_sekolah', ''));
    $kota = trim(setting('kota_sekolah', ''));
    $telepon = trim(setting('telepon_sekolah', ''));
    $email = trim(setting('email_sekolah', ''));
    $website = trim(setting('website_sekolah', ''));

    $lokasi = trim($alamat . ($alamat !== '' && $kota !== '' ? ', ' : '') . $kota);

    $parts = array_values(array_filter(
        [$lokasi, $telepon, $email, $website],
        static fn (string $value): bool => $value !== ''
    ));

    return implode(' | ', $parts);
}

/**
 * Kotak informasi ujian dengan judul ujian satu baris penuh.
 *
 * @param array<string, mixed> $exam
 * @param array<int, array<string, mixed>> $packages
 */
function laporan_pdf_exam_info(
    SikilatLaporanPdf $pdf,
    array $exam,
    ?array $selectedClass,
    array $packages,
    float $kkm,
    int $peserta
): void {
    $codes = laporan_export_package_codes($packages);

    $pairs = [
        ['Mata Pelajaran', (string) $exam['mapel_nama'] . ' (' . (string) $exam['mapel_kode'] . ')'],
        ['Kelas', $selectedClass !== null ? (string) $selectedClass['nama'] : 'Semua kelas'],
        ['Tanggal Ujian', format_tanggal((string) $exam['tanggal_ujian'])],
        ['Jumlah Soal', (string) (int) $exam['jumlah_soal'] . ' butir'],
        ['KKM', format_nilai($kkm)],
        ['Paket Soal', $codes !== [] ? implode(', ', $codes) : '-'],
        ['Peserta', $peserta . ' siswa'],
        ['Dicetak', date('d/m/Y H:i') . ' WIB'],
    ];

    $width = $pdf->contentWidth();
    $pad = 4.0;
    $innerW = $width - 2 * $pad;
    $colW = $innerW / 4;
    $titleH = 12.2;
    $rowH = 9.2;
    $height = $pad + $titleH + 2 * $rowH + $pad - 1.0;

    $pdf->ensureSpace($height + 2);
    $x = $pdf->leftMargin();
    $y = $pdf->GetY() + 1.0;

    $pdf->SetLineWidth(0.25);
    $pdf->SetDrawColor(213, 224, 239);
    $pdf->SetFillColor(248, 250, 253);
    $pdf->Rect($x, $y, $width, $height, 'DF');
    $pdf->SetFillColor(13, 110, 253);
    $pdf->Rect($x, $y, 1.3, $height, 'F');

    $pdf->SetFont('Helvetica', 'B', 6.3);
    $pdf->SetTextColor(112, 124, 142);
    $pdf->SetXY($x + $pad, $y + $pad - 0.8);
    $pdf->Cell($innerW, 3.0, 'UJIAN', 0, 0, 'L');

    $pdf->SetFont('Helvetica', 'B', 9.0);
    $pdf->SetTextColor(16, 32, 56);
    $pdf->SetXY($x + $pad, $y + $pad + 2.4);
    $pdf->Cell(
        $innerW,
        5.6,
        $pdf->clipText($pdf->t((string) $exam['nama']), $innerW),
        0,
        0,
        'L'
    );

    $gridY = $y + $pad + $titleH;

    foreach (array_chunk($pairs, 4) as $rowIndex => $rowPairs) {
        foreach (array_values($rowPairs) as $columnIndex => $pair) {
            $cellX = $x + $pad + $columnIndex * $colW;
            $cellY = $gridY + $rowIndex * $rowH;

            $pdf->SetFont('Helvetica', 'B', 6.2);
            $pdf->SetTextColor(118, 130, 148);
            $pdf->SetXY($cellX, $cellY);
            $pdf->Cell($colW - 3, 3.0, $pdf->t(strtoupper($pair[0])), 0, 0, 'L');

            $pdf->SetFont('Helvetica', 'B', 8.1);
            $pdf->SetTextColor(20, 34, 56);
            $pdf->SetXY($cellX, $cellY + 3.0);
            $pdf->Cell(
                $colW - 3,
                4.6,
                $pdf->clipText($pdf->t((string) $pair[1]), $colW - 4),
                0,
                0,
                'L'
            );
        }
    }

    $pdf->SetY($y + $height + 4.0);
    $pdf->SetTextColor(16, 24, 40);
    $pdf->SetDrawColor(222, 230, 242);
    $pdf->SetLineWidth(0.2);
}

/**
 * Susun lebar kolom proporsional agar selalu memenuhi lebar konten.
 *
 * @param array<int, float> $ratios
 * @return array<int, float>
 */
function laporan_pdf_widths(SikilatLaporanPdf $pdf, array $ratios): array
{
    $total = array_sum($ratios);

    if ($total <= 0) {
        return $ratios;
    }

    $scale = $pdf->contentWidth() / $total;

    return array_map(static fn (float $ratio): float => $ratio * $scale, $ratios);
}

/**
 * Kartu ringkasan berwarna, empat kartu per baris.
 *
 * @param array<int, array{label:string, value:string, sub?:string, color?:array{0:int,1:int,2:int}}> $cards
 */
function laporan_pdf_cards(SikilatLaporanPdf $pdf, array $cards): void
{
    $width = $pdf->contentWidth();
    $gap = 3.4;
    $cardW = ($width - 3 * $gap) / 4;
    $cardH = 17.0;

    foreach (array_chunk($cards, 4) as $rowIndex => $row) {
        $y = $pdf->GetY();

        if ($y + $cardH > $pdf->bottomLimit()) {
            $pdf->AddPage();
            $y = $pdf->GetY();
        }

        foreach (array_values($row) as $columnIndex => $card) {
            $x = $pdf->leftMargin() + $columnIndex * ($cardW + $gap);
            $color = $card['color'] ?? [13, 110, 253];

            $pdf->SetLineWidth(0.22);
            $pdf->SetDrawColor(219, 229, 242);
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Rect($x, $y, $cardW, $cardH, 'DF');
            $pdf->SetFillColor($color[0], $color[1], $color[2]);
            $pdf->Rect($x, $y, $cardW, 1.25, 'F');

            $pdf->SetTextColor(105, 117, 137);
            $pdf->SetFont('Helvetica', 'B', 6.3);
            $pdf->SetXY($x + 3.0, $y + 3.6);
            $pdf->Cell(
                $cardW - 6.0,
                3.0,
                $pdf->clipText($pdf->t(strtoupper((string) $card['label'])), $cardW - 6.0),
                0,
                0,
                'L'
            );

            $value = $pdf->t((string) $card['value']);
            $pdf->fitFontSize($value, $cardW - 6.0, 14.0, 9.5, 'B');
            $pdf->SetTextColor(20, 32, 52);
            $pdf->SetXY($x + 3.0, $y + 6.6);
            $pdf->Cell($cardW - 6.0, 7.0, $value, 0, 0, 'L');

            if (($card['sub'] ?? '') !== '') {
                $pdf->SetTextColor(120, 132, 150);
                $pdf->SetFont('Helvetica', '', 6.2);
                $pdf->SetXY($x + 3.0, $y + 13.6);
                $pdf->Cell(
                    $cardW - 6.0,
                    2.6,
                    $pdf->clipText($pdf->t((string) $card['sub']), $cardW - 6.0),
                    0,
                    0,
                    'L'
                );
            }
        }

        $pdf->SetY($y + $cardH + ($rowIndex === 0 && count($cards) > 4 ? 3.4 : 4.0));
    }

    $pdf->SetTextColor(16, 24, 40);
    $pdf->SetDrawColor(222, 230, 242);
    $pdf->SetLineWidth(0.2);
}

/**
 * @param array<string, mixed> $summary
 */
function laporan_pdf_summary(SikilatLaporanPdf $pdf, array $summary, float $kkm): void
{
    laporan_pdf_cards($pdf, [
        [
            'label' => 'Peserta',
            'value' => (string) (int) $summary['jumlah'],
            'sub'   => 'siswa',
            'color' => [13, 110, 253],
        ],
        [
            'label' => 'Rata-rata',
            'value' => $summary['rata'] !== null ? format_nilai($summary['rata']) : '-',
            'sub'   => 'skala 0-100',
            'color' => [15, 159, 143],
        ],
        [
            'label' => 'Nilai Tertinggi',
            'value' => $summary['tertinggi'] !== null ? format_nilai($summary['tertinggi']) : '-',
            'sub'   => 'nilai',
            'color' => [22, 163, 74],
        ],
        [
            'label' => 'Nilai Terendah',
            'value' => $summary['terendah'] !== null ? format_nilai($summary['terendah']) : '-',
            'sub'   => 'nilai',
            'color' => [217, 119, 6],
        ],
        [
            'label' => 'Tuntas',
            'value' => (string) (int) $summary['tuntas'],
            'sub'   => 'nilai >= KKM',
            'color' => [22, 163, 74],
        ],
        [
            'label' => 'Belum Tuntas',
            'value' => (string) (int) $summary['belum'],
            'sub'   => 'nilai < KKM',
            'color' => [220, 38, 38],
        ],
        [
            'label' => 'Ketuntasan',
            'value' => laporan_percent((float) $summary['persen_tuntas']),
            'sub'   => 'dari peserta',
            'color' => [13, 110, 253],
        ],
        [
            'label' => 'KKM',
            'value' => format_nilai($kkm),
            'sub'   => 'batas ketuntasan',
            'color' => [71, 84, 103],
        ],
    ]);
}

/**
 * Ringkasan khusus laporan individu siswa.
 *
 * @param array<string, mixed> $result
 */
function laporan_pdf_student_summary(SikilatLaporanPdf $pdf, array $result, float $kkm): void
{
    $nilai = (float) $result['nilai'];
    $tuntas = $nilai + 0.00001 >= $kkm;
    $statusColor = $tuntas ? [22, 163, 74] : [220, 38, 38];
    $skorTotal = (float) ($result['skor_total'] ?? $result['jumlah_benar']);

    laporan_pdf_cards($pdf, [
        [
            'label' => 'Nilai Akhir',
            'value' => format_nilai($nilai),
            'sub'   => 'skala 0-100',
            'color' => $statusColor,
        ],
        [
            'label' => 'Keterangan',
            'value' => $tuntas ? 'Tuntas' : 'Belum Tuntas',
            'sub'   => 'KKM ' . format_nilai($kkm),
            'color' => $statusColor,
        ],
        [
            'label' => 'Jawaban Benar',
            'value' => (string) (int) $result['jumlah_benar'],
            'sub'   => 'tepat seluruhnya',
            'color' => [13, 110, 253],
        ],
        [
            'label' => 'Jawaban Salah',
            'value' => (string) (int) $result['jumlah_salah'],
            'sub'   => 'termasuk parsial',
            'color' => [217, 119, 6],
        ],
        [
            'label' => 'Jawaban Kosong',
            'value' => (string) (int) $result['jumlah_kosong'],
            'sub'   => 'butir soal',
            'color' => [120, 132, 150],
        ],
        [
            'label' => 'Skor Total',
            'value' => format_nilai($skorTotal),
            'sub'   => 'dari ' . (string) (int) $result['total_soal'] . ' butir',
            'color' => [13, 110, 253],
        ],
        [
            'label' => 'Jumlah Soal',
            'value' => (string) (int) $result['total_soal'],
            'sub'   => 'butir soal',
            'color' => [71, 84, 103],
        ],
        [
            'label' => 'Paket Soal',
            'value' => 'Paket ' . (string) $result['kode_paket'],
            'sub'   => 'kunci sesuai paket',
            'color' => [15, 159, 143],
        ],
        [
            'label' => 'Kelas',
            'value' => (string) $result['kelas_nama'],
            'sub'   => (string) $result['tahun_ajaran'],
            'color' => [13, 110, 253],
        ],
    ]);
}

/**
 * @param array<int, string> $headers
 * @param array<int, float> $widths
 * @param array<int, string> $aligns
 */
function laporan_pdf_table_header(SikilatLaporanPdf $pdf, array $headers, array $widths, array $aligns = []): void
{
    $pdf->ensureSpace(12);
    $pdf->SetFont('Helvetica', 'B', 7.1);
    $pdf->SetFillColor(16, 63, 145);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetDrawColor(16, 63, 145);
    $pdf->SetLineWidth(0.2);

    foreach ($headers as $index => $header) {
        $pdf->Cell(
            $widths[$index],
            6.3,
            $pdf->clipText($pdf->t($header), $widths[$index] - 1.6),
            1,
            0,
            $aligns[$index] ?? 'C',
            true
        );
    }

    $pdf->Ln();
    $pdf->SetTextColor(16, 24, 40);
    $pdf->SetDrawColor(222, 230, 242);
}

/**
 * @param array<int, string> $cells
 * @param array<int, float> $widths
 * @param array<int, string> $aligns
 * @param array{0:int,1:int,2:int}|null $fill
 */
function laporan_pdf_table_row(
    SikilatLaporanPdf $pdf,
    array $cells,
    array $widths,
    array $aligns = [],
    ?array $fill = null,
    float $height = 5.7,
    bool $bold = false
): void {
    $pdf->SetFont('Helvetica', $bold ? 'B' : '', 7.2);
    $pdf->SetDrawColor(226, 233, 243);

    if ($fill !== null) {
        $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
    }

    if ($bold) {
        $pdf->SetTextColor(11, 42, 94);
    }

    foreach ($cells as $index => $cell) {
        $pdf->Cell(
            $widths[$index],
            $height,
            $pdf->clipText($pdf->t((string) $cell), $widths[$index] - 1.6),
            1,
            0,
            $aligns[$index] ?? 'L',
            $fill !== null
        );
    }

    $pdf->Ln();
    $pdf->SetTextColor(16, 24, 40);
}

/**
 * Tabel daftar nilai siswa dengan garis statistik kelas di atasnya.
 *
 * @param array<int, array<string, mixed>> $rows
 * @param array<string, mixed>|null $stats
 */
function laporan_pdf_student_table(
    SikilatLaporanPdf $pdf,
    array $rows,
    float $kkm,
    string $caption = '',
    ?array $stats = null
): void {
    $pdf->ensureSpace(30);

    if ($caption !== '') {
        $pdf->ensureSpace(18);
        $pdf->SetFont('Helvetica', 'B', 9.0);
        $pdf->SetTextColor(11, 42, 94);
        $pdf->Cell(
            0,
            5.6,
            $pdf->clipText($pdf->t($caption), $pdf->contentWidth()),
            0,
            1,
            'L'
        );
    }

    if ($stats !== null) {
        $line = 'Peserta: ' . (int) $stats['jumlah']
            . '   |   Rata-rata: ' . ($stats['rata'] !== null ? format_nilai($stats['rata']) : '-')
            . '   |   Tertinggi: ' . ($stats['tertinggi'] !== null ? format_nilai($stats['tertinggi']) : '-')
            . '   |   Terendah: ' . ($stats['terendah'] !== null ? format_nilai($stats['terendah']) : '-')
            . '   |   Tuntas: ' . (int) $stats['tuntas']
            . ' (' . laporan_percent((float) $stats['persen_tuntas']) . ')';

        $pdf->SetFont('Helvetica', '', 7.3);
        $pdf->SetTextColor(96, 108, 126);
        $pdf->Cell(
            0,
            4.2,
            $pdf->clipText($pdf->t($line), $pdf->contentWidth()),
            0,
            1,
            'L'
        );
    }

    $pdf->SetTextColor(16, 24, 40);
    $pdf->Ln(1.2);

    usort($rows, static function (array $a, array $b): int {
        $compare = (float) $b['nilai'] <=> (float) $a['nilai'];

        return $compare !== 0
            ? $compare
            : strcmp((string) $a['nomor_absen'], (string) $b['nomor_absen']);
    });

    $headers = ['No', 'Absen', 'Nama Siswa', 'Paket', 'Benar', 'Salah', 'Kosong', 'Nilai', 'Ket.'];
    $widths = laporan_pdf_widths($pdf, [7, 13, 46, 11, 11, 11, 13, 14, 17]);
    $aligns = ['C', 'C', 'L', 'C', 'C', 'C', 'C', 'C', 'C'];

    laporan_pdf_table_header($pdf, $headers, $widths, $aligns);

    $number = 1;

    foreach ($rows as $row) {
        if ($pdf->GetY() + 5.8 > $pdf->bottomLimit()) {
            $pdf->AddPage();
            laporan_pdf_table_header($pdf, $headers, $widths, $aligns);
        }

        $nilai = (float) $row['nilai'];
        $tuntas = $nilai + 0.00001 >= $kkm;
        $fill = $number % 2 === 0 ? [247, 250, 254] : null;

        $pdf->SetFont('Helvetica', '', 7.1);
        $pdf->SetDrawColor(226, 233, 243);

        if ($fill !== null) {
            $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
        }

        $cells = [
            [(string) $number, 'C'],
            [(string) $row['nomor_absen'], 'C'],
            [(string) $row['nama_siswa'], 'L'],
            [(string) $row['kode_paket'], 'C'],
            [(string) (int) $row['jumlah_benar'], 'C'],
            [(string) (int) $row['jumlah_salah'], 'C'],
            [(string) (int) $row['jumlah_kosong'], 'C'],
            [format_nilai($nilai), 'C'],
            [$tuntas ? 'Tuntas' : 'Belum', 'C'],
        ];

        foreach ($cells as $index => $cell) {
            $pdf->Cell(
                $widths[$index],
                5.7,
                $pdf->clipText($pdf->t($cell[0]), $widths[$index] - 1.6),
                1,
                0,
                $cell[1],
                $fill !== null
            );
        }

        $pdf->Ln();
        $number++;
    }

    $pdf->Ln(2.5);
    $pdf->SetTextColor(16, 24, 40);
}

/**
 * @param array<int, array<string, mixed>> $distribution
 */
function laporan_pdf_distribution(SikilatLaporanPdf $pdf, array $distribution, int $total): void
{
    $pdf->ensureSpace(40);
    $pdf->sectionTitle('Distribusi Nilai');

    $headers = ['Rentang Nilai', 'Jumlah', 'Persentase', 'Visual'];
    $widths = laporan_pdf_widths($pdf, [15, 10, 13, 62]);
    $aligns = ['C', 'C', 'C', 'L'];
    laporan_pdf_table_header($pdf, $headers, $widths, $aligns);

    $max = 1;
    foreach ($distribution as $bin) {
        $max = max($max, (int) $bin['jumlah']);
    }

    foreach (array_values($distribution) as $index => $bin) {
        if ($pdf->GetY() + 7.2 > $pdf->bottomLimit()) {
            $pdf->AddPage();
            laporan_pdf_table_header($pdf, $headers, $widths, $aligns);
        }

        $y = $pdf->GetY();
        $x = $pdf->leftMargin();
        $fill = $index % 2 === 1 ? [247, 250, 254] : null;

        $pdf->SetFont('Helvetica', '', 7.6);
        $pdf->SetDrawColor(226, 233, 243);

        if ($fill !== null) {
            $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
        }

        $pdf->Cell($widths[0], 7.0, $pdf->t((string) $bin['label']), 1, 0, 'C', $fill !== null);
        $pdf->Cell($widths[1], 7.0, (string) (int) $bin['jumlah'], 1, 0, 'C', $fill !== null);
        $pdf->Cell(
            $widths[2],
            7.0,
            laporan_percent((float) $bin['persen']),
            1,
            0,
            'C',
            $fill !== null
        );
        $pdf->Cell($widths[3], 7.0, '', 1, 0, 'L', $fill !== null);

        $trackX = $x + $widths[0] + $widths[1] + $widths[2] + 2.0;
        $trackW = $widths[3] - 4.0;

        $pdf->SetFillColor(232, 239, 249);
        $pdf->Rect($trackX, $y + 2.1, $trackW, 2.8, 'F');

        $barWidth = ($max > 0 ? ((int) $bin['jumlah'] / $max) : 0) * $trackW;

        if ($barWidth > 0) {
            $pdf->SetFillColor(13, 110, 253);
            $pdf->Rect($trackX, $y + 2.1, $barWidth, 2.8, 'F');
        }

        $pdf->SetY($y + 7.0);
        $pdf->SetX($x);
    }

    $pdf->SetFont('Helvetica', '', 7.8);
    $pdf->SetTextColor(96, 108, 126);
    $pdf->Cell(0, 5.2, $pdf->t('Total peserta: ' . $total . ' siswa'), 0, 1, 'L');
    $pdf->SetTextColor(16, 24, 40);
    $pdf->Ln(2.5);
}

/**
 * @param array<int, array<string, mixed>> $classSummary
 * @param array<string, mixed> $summary
 */
function laporan_pdf_class_recap(SikilatLaporanPdf $pdf, array $classSummary, array $summary): void
{
    $pdf->ensureSpace(36);
    $pdf->sectionTitle('Rekap Nilai per Kelas');

    if ($classSummary === []) {
        laporan_pdf_empty($pdf, 'Belum ada kelas yang memiliki hasil ujian.');
        return;
    }

    $headers = ['No', 'Kelas', 'Peserta', 'Rata-rata', 'Tertinggi', 'Terendah', '% Tuntas'];
    $widths = laporan_pdf_widths($pdf, [6, 34, 12, 16, 16, 16, 18]);
    $aligns = ['C', 'L', 'C', 'C', 'C', 'C', 'C'];
    laporan_pdf_table_header($pdf, $headers, $widths, $aligns);

    foreach (array_values($classSummary) as $index => $class) {
        if ($pdf->GetY() + 5.8 > $pdf->bottomLimit()) {
            $pdf->AddPage();
            laporan_pdf_table_header($pdf, $headers, $widths, $aligns);
        }

        laporan_pdf_table_row($pdf, [
            (string) ($index + 1),
            (string) $class['kelas_nama'],
            (string) (int) $class['jumlah'],
            $class['rata'] !== null ? format_nilai($class['rata']) : '-',
            $class['tertinggi'] !== null ? format_nilai($class['tertinggi']) : '-',
            $class['terendah'] !== null ? format_nilai($class['terendah']) : '-',
            laporan_percent((float) $class['persen_tuntas']),
        ], $widths, $aligns, $index % 2 === 1 ? [247, 250, 254] : null);
    }

    laporan_pdf_table_row($pdf, [
        '',
        'TOTAL',
        (string) (int) $summary['jumlah'],
        $summary['rata'] !== null ? format_nilai($summary['rata']) : '-',
        $summary['tertinggi'] !== null ? format_nilai($summary['tertinggi']) : '-',
        $summary['terendah'] !== null ? format_nilai($summary['terendah']) : '-',
        laporan_percent((float) $summary['persen_tuntas']),
    ], $widths, $aligns, [234, 241, 252], 6.0, true);

    $pdf->Ln(3);
}

/**
 * @param array<int, array<string, mixed>> $packageSummary
 * @param array<string, mixed> $summary
 */
function laporan_pdf_package_recap(SikilatLaporanPdf $pdf, array $packageSummary, array $summary): void
{
    $pdf->ensureSpace(36);
    $pdf->sectionTitle('Rekap Nilai per Paket');

    if ($packageSummary === []) {
        laporan_pdf_empty($pdf, 'Belum ada paket soal yang memiliki hasil ujian.');
        return;
    }

    $headers = [
        'No', 'Paket', 'Keterangan', 'Peserta', 'Rata-rata', 'Tertinggi', 'Terendah',
        'Tuntas', 'Belum', '% Tuntas', 'Benar', 'Salah', 'Kosong',
    ];
    $widths = laporan_pdf_widths($pdf, [5, 8, 28, 9, 11, 11, 11, 9, 9, 11, 9, 9, 9]);
    $aligns = ['C', 'C', 'L', 'C', 'C', 'C', 'C', 'C', 'C', 'C', 'C', 'C', 'C'];
    laporan_pdf_table_header($pdf, $headers, $widths, $aligns);

    foreach (array_values($packageSummary) as $index => $package) {
        if ($pdf->GetY() + 5.8 > $pdf->bottomLimit()) {
            $pdf->AddPage();
            laporan_pdf_table_header($pdf, $headers, $widths, $aligns);
        }

        laporan_pdf_table_row($pdf, [
            (string) ($index + 1),
            (string) $package['kode_paket'],
            (string) ($package['keterangan'] !== '' ? $package['keterangan'] : '-'),
            (string) (int) $package['jumlah'],
            $package['rata'] !== null ? format_nilai($package['rata']) : '-',
            $package['tertinggi'] !== null ? format_nilai($package['tertinggi']) : '-',
            $package['terendah'] !== null ? format_nilai($package['terendah']) : '-',
            (string) (int) $package['tuntas'],
            (string) (int) $package['belum'],
            laporan_percent((float) $package['persen_tuntas']),
            (string) (int) $package['benar'],
            (string) (int) $package['salah'],
            (string) (int) $package['kosong'],
        ], $widths, $aligns, $index % 2 === 1 ? [247, 250, 254] : null);
    }

    laporan_pdf_table_row($pdf, [
        '',
        'TOTAL',
        '',
        (string) (int) $summary['jumlah'],
        $summary['rata'] !== null ? format_nilai($summary['rata']) : '-',
        $summary['tertinggi'] !== null ? format_nilai($summary['tertinggi']) : '-',
        $summary['terendah'] !== null ? format_nilai($summary['terendah']) : '-',
        (string) (int) $summary['tuntas'],
        (string) (int) $summary['belum'],
        laporan_percent((float) $summary['persen_tuntas']),
        (string) (int) $summary['benar'],
        (string) (int) $summary['salah'],
        (string) (int) $summary['kosong'],
    ], $widths, $aligns, [234, 241, 252], 6.0, true);

    $pdf->Ln(3);
}

/**
 * Palet warna grafik laporan PDF.
 *
 * @return array<int, array{0:int,1:int,2:int}>
 */
function laporan_pdf_chart_palette(): array
{
    return [
        [13, 110, 253],
        [32, 201, 151],
        [253, 126, 20],
        [111, 66, 193],
        [214, 51, 132],
        [25, 135, 84],
        [13, 202, 240],
        [220, 53, 69],
    ];
}

/**
 * Format angka gaya Indonesia untuk label grafik.
 */
function laporan_pdf_chart_number(float $value, int $decimals = 0): string
{
    return number_format($value, $decimals, ',', '.');
}

/**
 * Atur warna gambar, isi, atau teks dari triplet RGB.
 *
 * @param array{0:int,1:int,2:int} $color
 */
function laporan_pdf_chart_color(SikilatLaporanPdf $pdf, array $color, string $mode = 'draw'): void
{
    if ($mode === 'fill') {
        $pdf->SetFillColor($color[0], $color[1], $color[2]);
        return;
    }

    if ($mode === 'text') {
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        return;
    }

    $pdf->SetDrawColor($color[0], $color[1], $color[2]);
}

/**
 * Bingkai kartu grafik sekaligus area plot di dalamnya.
 *
 * @return array{left:float, right:float, top:float, bottom:float}
 */
function laporan_pdf_chart_card(
    SikilatLaporanPdf $pdf,
    float $x,
    float $y,
    float $w,
    float $h,
    string $title,
    string $subtitle = '',
    float $topPad = 10.5
): array {
    $pdf->SetLineWidth(0.22);
    $pdf->SetDrawColor(222, 230, 242);
    $pdf->SetFillColor(252, 253, 255);
    $pdf->Rect($x, $y, $w, $h, 'DF');
    $pdf->SetFillColor(13, 110, 253);
    $pdf->Rect($x, $y, $w, 0.9, 'F');

    $pdf->SetFont('Helvetica', 'B', 7.4);
    $pdf->SetTextColor(11, 42, 94);
    $pdf->SetXY($x + 3.0, $y + 2.5);
    $pdf->Cell($w - 6.0, 3.6, $pdf->clipText($pdf->t($title), $w - 6.0), 0, 0, 'L');

    if ($subtitle !== '') {
        $pdf->SetFont('Helvetica', '', 5.7);
        $pdf->SetTextColor(120, 132, 150);
        $pdf->SetXY($x + 3.0, $y + 5.8);
        $pdf->Cell($w - 6.0, 3.0, $pdf->clipText($pdf->t($subtitle), $w - 6.0), 0, 0, 'L');
    }

    $pdf->SetTextColor(16, 24, 40);
    $pdf->SetDrawColor(222, 230, 242);
    $pdf->SetLineWidth(0.2);

    return [
        'left'   => $x + 14.0,
        'right'  => $x + $w - 5.0,
        'top'    => $y + $topPad,
        'bottom' => $y + $h - 10.5,
    ];
}

/**
 * Legenda kecil di dalam kartu grafik.
 *
 * @param array<int, array{label:string, color:array{0:int,1:int,2:int}}> $entries
 */
function laporan_pdf_chart_legend(
    SikilatLaporanPdf $pdf,
    float $x,
    float $y,
    float $maxWidth,
    array $entries
): void {
    $cursorX = $x;
    $cursorY = $y;

    foreach ($entries as $entry) {
        $label = (string) $entry['label'];
        $color = $entry['color'];
        $entryWidth = 8.0 + $pdf->GetStringWidth($pdf->t($label));

        if ($cursorX > $x && $cursorX + $entryWidth > $x + $maxWidth) {
            $cursorX = $x;
            $cursorY += 3.5;
        }

        laporan_pdf_chart_color($pdf, $color, 'fill');
        $pdf->Rect($cursorX, $cursorY - 1.1, 3.4, 1.4, 'F');

        $pdf->SetFont('Helvetica', '', 5.8);
        $pdf->SetTextColor(84, 98, 120);
        $pdf->Text($cursorX + 4.4, $cursorY, $pdf->t($label));

        $cursorX += $entryWidth + 3.2;
    }

    $pdf->SetTextColor(16, 24, 40);
}

/**
 * Grafik garis dengan sumbu 0-100%. Dipakai untuk kurva kesukaran butir
 * dan kurva sebaran nilai per kelas.
 *
 * @param array<int, string> $labels
 * @param array<int, array<string, mixed>> $datasets
 * @param array<int, array{value:float, label?:string, color?:array{0:int,1:int,2:int}}> $guides
 */
function laporan_pdf_line_chart(
    SikilatLaporanPdf $pdf,
    float $x,
    float $y,
    float $w,
    float $h,
    array $labels,
    array $datasets,
    string $title,
    string $subtitle = '',
    array $guides = []
): void {
    $multiple = count($datasets) > 1;
    $plot = laporan_pdf_chart_card(
        $pdf,
        $x,
        $y,
        $w,
        $h,
        $title,
        $subtitle,
        $multiple ? 15.2 : 11.0
    );

    $left = $plot['left'];
    $right = $plot['right'];
    $top = $plot['top'];
    $bottom = $plot['bottom'];
    $plotW = max(1.0, $right - $left);
    $plotH = max(1.0, $bottom - $top);
    $count = count($labels);
    $palette = laporan_pdf_chart_palette();

    if ($count === 0 || $datasets === []) {
        return;
    }

    if ($multiple) {
        $entries = [];
        foreach ($datasets as $index => $dataset) {
            $entries[] = [
                'label' => (string) ($dataset['label'] ?? ('Seri ' . ($index + 1))),
                'color' => $dataset['color'] ?? $palette[$index % count($palette)],
            ];
        }

        laporan_pdf_chart_legend($pdf, $left, $plot['top'] - 3.8, $plotW, $entries);
    }

    $pdf->SetFont('Helvetica', '', 5.6);

    for ($tick = 0; $tick <= 100; $tick += 25) {
        $tickY = $bottom - ($tick / 100) * $plotH;
        $pdf->SetDrawColor(234, 240, 248);
        $pdf->SetLineWidth(0.18);
        $pdf->Line($left, $tickY, $right, $tickY);

        $label = $tick . '%';
        $pdf->SetTextColor(125, 137, 155);
        $pdf->Text($left - 1.6 - $pdf->GetStringWidth($label), $tickY + 1.0, $label);
    }

    $pdf->SetDrawColor(196, 208, 223);
    $pdf->SetLineWidth(0.25);
    $pdf->Line($left, $bottom, $right, $bottom);
    $pdf->Line($left, $top, $left, $bottom);

    $step = max(1, (int) ceil($count / 9));
    $pdf->SetFont('Helvetica', '', 5.4);
    $pdf->SetTextColor(125, 137, 155);

    for ($index = 0; $index < $count; $index++) {
        if ($index % $step !== 0 && $index !== $count - 1) {
            continue;
        }

        $centerX = $count === 1 ? ($left + $right) / 2 : $left + ($index / ($count - 1)) * $plotW;
        $label = $pdf->t($labels[$index]);
        $pdf->Text($centerX - $pdf->GetStringWidth($label) / 2, $bottom + 3.3, $label);
    }

    foreach ($guides as $guide) {
        $value = max(0.0, min(100.0, (float) $guide['value']));
        $guideY = $bottom - ($value / 100) * $plotH;
        $color = $guide['color'] ?? [152, 162, 179];

        laporan_pdf_chart_color($pdf, $color, 'draw');
        $pdf->SetLineWidth(0.28);
        $pdf->dashedLine($left, $guideY, $right, $guideY);

        if (($guide['label'] ?? '') !== '') {
            laporan_pdf_chart_color($pdf, $color, 'text');
            $pdf->SetFont('Helvetica', 'B', 5.4);
            $label = $pdf->t((string) $guide['label']);
            $pdf->Text(
                $right - $pdf->GetStringWidth($label) - 0.5,
                max($top + 2.0, $guideY - 0.8),
                $label
            );
        }
    }

    foreach ($datasets as $index => $dataset) {
        $color = $dataset['color'] ?? $palette[$index % count($palette)];
        $values = array_values($dataset['values'] ?? []);
        $pointColors = array_values($dataset['pointColors'] ?? []);
        $points = [];

        foreach ($values as $pointIndex => $value) {
            if ($value === null) {
                $points[] = null;
                continue;
            }

            $centerX = $count === 1
                ? ($left + $right) / 2
                : $left + ($pointIndex / ($count - 1)) * $plotW;
            $value = max(0.0, min(100.0, (float) $value));
            $points[] = [$centerX, $bottom - ($value / 100) * $plotH];
        }

        laporan_pdf_chart_color($pdf, $color, 'draw');
        $pdf->SetLineWidth(0.55);
        $previous = null;

        foreach ($points as $point) {
            if ($point === null) {
                $previous = null;
                continue;
            }

            if ($previous !== null) {
                $pdf->Line($previous[0], $previous[1], $point[0], $point[1]);
            }

            $previous = $point;
        }

        foreach ($points as $pointIndex => $point) {
            if ($point === null) {
                continue;
            }

            $dotColor = $pointColors[$pointIndex] ?? $color;
            laporan_pdf_chart_color($pdf, $dotColor, 'fill');
            $pdf->Rect($point[0] - 0.75, $point[1] - 0.75, 1.5, 1.5, 'F');
        }
    }

    $pdf->SetLineWidth(0.2);
    $pdf->SetDrawColor(222, 230, 242);
    $pdf->SetTextColor(16, 24, 40);
}

/**
 * Peta butir: tingkat kesukaran (sumbu X) terhadap daya pembeda (sumbu Y).
 *
 * @param array<int, array{x:float, y:float, tingkat:string}> $points
 */
function laporan_pdf_scatter_chart(
    SikilatLaporanPdf $pdf,
    float $x,
    float $y,
    float $w,
    float $h,
    array $points,
    string $title,
    string $subtitle
): void {
    $plot = laporan_pdf_chart_card($pdf, $x, $y, $w, $h, $title, $subtitle, 11.0);

    $left = $plot['left'] + 2.0;
    $right = $plot['right'];
    $top = $plot['top'];
    $bottom = $plot['bottom'];
    $plotW = max(1.0, $right - $left);
    $plotH = max(1.0, $bottom - $top);
    $yMin = -0.2;
    $yMax = 1.0;

    // Latar kuadran: sukar, sedang, mudah.
    $zones = [
        [0.0, 0.3, [253, 242, 242]],
        [0.3, 0.7, [255, 250, 235]],
        [0.7, 1.0, [240, 251, 246]],
    ];

    foreach ($zones as [$from, $to, $color]) {
        $pdf->SetFillColor($color[0], $color[1], $color[2]);
        $pdf->Rect(
            $left + $from * $plotW,
            $top,
            ($to - $from) * $plotW,
            $plotH,
            'F'
        );
    }

    $pdf->SetFont('Helvetica', '', 5.4);

    foreach ([0, 20, 40, 60, 80, 100] as $tick) {
        $tickX = $left + ($tick / 100) * $plotW;
        $pdf->SetDrawColor(231, 238, 247);
        $pdf->SetLineWidth(0.18);
        $pdf->Line($tickX, $top, $tickX, $bottom);
        $pdf->SetTextColor(125, 137, 155);
        $label = $tick . '%';
        $pdf->Text($tickX - $pdf->GetStringWidth($label) / 2, $bottom + 3.3, $label);
    }

    for ($tick = -0.2; $tick <= 1.0001; $tick += 0.2) {
        $tickY = $bottom - (($tick - $yMin) / ($yMax - $yMin)) * $plotH;
        $pdf->SetDrawColor(231, 238, 247);
        $pdf->SetLineWidth(0.18);
        $pdf->Line($left, $tickY, $right, $tickY);

        $label = laporan_pdf_chart_number($tick, 1);
        $pdf->SetTextColor(125, 137, 155);
        $pdf->Text($left - 1.5 - $pdf->GetStringWidth($label), $tickY + 1.0, $label);
    }

    // Garis ambang kesukaran dan DP nol.
    foreach ([30, 70] as $threshold) {
        $tickX = $left + ($threshold / 100) * $plotW;
        $pdf->SetDrawColor(152, 162, 179);
        $pdf->SetLineWidth(0.28);
        $pdf->dashedLine($tickX, $top, $tickX, $bottom);
        $pdf->SetFont('Helvetica', 'B', 5.2);
        $pdf->SetTextColor(125, 137, 155);
        $pdf->Text($tickX + 0.8, $top + 2.4, $threshold . '%');
    }

    $zeroY = $bottom - ((0.0 - $yMin) / ($yMax - $yMin)) * $plotH;
    $pdf->SetDrawColor(152, 162, 179);
    $pdf->SetLineWidth(0.28);
    $pdf->dashedLine($left, $zeroY, $right, $zeroY);
    $pdf->SetFont('Helvetica', 'B', 5.2);
    $pdf->SetTextColor(125, 137, 155);
    $pdf->Text($right - $pdf->GetStringWidth('DP 0') - 0.5, $zeroY - 0.8, 'DP 0');

    foreach ($points as $point) {
        $valueX = max(0.0, min(100.0, (float) $point['x']));
        $valueY = max($yMin, min($yMax, (float) $point['y']));
        $centerX = $left + ($valueX / 100) * $plotW;
        $centerY = $bottom - (($valueY - $yMin) / ($yMax - $yMin)) * $plotH;

        $color = match ($point['tingkat'] ?? 'Sedang') {
            'Mudah' => [32, 201, 151],
            'Sukar' => [220, 53, 69],
            default => [255, 193, 7],
        };

        laporan_pdf_chart_color($pdf, $color, 'fill');
        $pdf->Rect($centerX - 0.8, $centerY - 0.8, 1.6, 1.6, 'F');
    }

    laporan_pdf_chart_legend(
        $pdf,
        $left,
        $bottom + 6.0,
        $plotW,
        [
            ['label' => 'Mudah', 'color' => [32, 201, 151]],
            ['label' => 'Sedang', 'color' => [255, 193, 7]],
            ['label' => 'Sukar', 'color' => [220, 53, 69]],
        ]
    );

    $pdf->SetLineWidth(0.2);
    $pdf->SetDrawColor(222, 230, 242);
    $pdf->SetTextColor(16, 24, 40);
}

/**
 * Grafik batang horizontal rata-rata nilai kelas dengan garis KKM.
 *
 * @param array<int, array<string, mixed>> $classes
 */
function laporan_pdf_class_average_chart(
    SikilatLaporanPdf $pdf,
    float $x,
    float $y,
    float $w,
    float $h,
    array $classes,
    float $kkm,
    string $title
): void {
    $plot = laporan_pdf_chart_card(
        $pdf,
        $x,
        $y,
        $w,
        $h,
        $title,
        'Hijau di atas KKM, merah di bawah KKM',
        11.0
    );

    $classes = array_slice(array_values($classes), 0, 10);
    if ($classes === []) {
        return;
    }

    $left = $x + 33.0;
    $right = $x + $w - 11.0;
    $top = $plot['top'];
    $bottom = $plot['bottom'];
    $plotW = max(1.0, $right - $left);
    $plotH = max(1.0, $bottom - $top);
    $rowH = $plotH / count($classes);

    $pdf->SetFont('Helvetica', '', 5.4);

    foreach ([0, 20, 40, 60, 80, 100] as $tick) {
        $tickX = $left + ($tick / 100) * $plotW;
        $pdf->SetDrawColor(234, 240, 248);
        $pdf->SetLineWidth(0.18);
        $pdf->Line($tickX, $top, $tickX, $bottom);
        $pdf->SetTextColor(125, 137, 155);
        $label = (string) $tick;
        $pdf->Text($tickX - $pdf->GetStringWidth($label) / 2, $bottom + 3.3, $label);
    }

    $kkmX = $left + (max(0.0, min(100.0, $kkm)) / 100) * $plotW;
    $pdf->SetDrawColor(220, 53, 69);
    $pdf->SetLineWidth(0.3);
    $pdf->dashedLine($kkmX, $top, $kkmX, $bottom);
    $pdf->SetFont('Helvetica', 'B', 5.2);
    $pdf->SetTextColor(220, 53, 69);
    $pdf->Text($kkmX + 0.8, $top + 2.4, 'KKM ' . laporan_pdf_chart_number($kkm, 2));

    foreach ($classes as $index => $class) {
        $value = max(0.0, min(100.0, (float) ($class['rata'] ?? 0)));
        $centerY = $top + ($index + 0.5) * $rowH;
        $barH = min(4.0, $rowH * 0.62);
        $barW = ($value / 100) * $plotW;

        $pdf->SetFont('Helvetica', '', 5.8);
        $pdf->SetTextColor(52, 68, 90);
        $pdf->Text(
            $x + 3.0,
            $centerY + 0.9,
            $pdf->clipText($pdf->t((string) $class['kelas_nama']), 28.0)
        );

        if ($barW > 0) {
            $color = $value + 0.00001 >= $kkm ? [25, 135, 84] : [220, 53, 69];
            laporan_pdf_chart_color($pdf, $color, 'fill');
            $pdf->Rect($left, $centerY - $barH / 2, $barW, $barH, 'F');
        }

        $pdf->SetFont('Helvetica', 'B', 5.4);
        $pdf->SetTextColor(52, 68, 90);
        $pdf->Text($left + $barW + 1.4, $centerY + 0.9, laporan_pdf_chart_number($value, 2));
    }

    $pdf->SetLineWidth(0.2);
    $pdf->SetDrawColor(222, 230, 242);
    $pdf->SetTextColor(16, 24, 40);
}

/**
 * Grafik batang bertumpuk ketuntasan per kelas (100%).
 *
 * @param array<int, array<string, mixed>> $classes
 */
function laporan_pdf_class_completion_chart(
    SikilatLaporanPdf $pdf,
    float $x,
    float $y,
    float $w,
    float $h,
    array $classes,
    string $title
): void {
    $plot = laporan_pdf_chart_card(
        $pdf,
        $x,
        $y,
        $w,
        $h,
        $title,
        'Hijau tuntas, merah belum tuntas',
        11.0
    );

    $classes = array_slice(array_values($classes), 0, 10);
    if ($classes === []) {
        return;
    }

    $left = $x + 33.0;
    $right = $x + $w - 15.0;
    $top = $plot['top'];
    $bottom = $plot['bottom'];
    $plotW = max(1.0, $right - $left);
    $plotH = max(1.0, $bottom - $top);
    $rowH = $plotH / count($classes);

    $pdf->SetFont('Helvetica', '', 5.4);

    foreach ([0, 25, 50, 75, 100] as $tick) {
        $tickX = $left + ($tick / 100) * $plotW;
        $pdf->SetDrawColor(234, 240, 248);
        $pdf->SetLineWidth(0.18);
        $pdf->Line($tickX, $top, $tickX, $bottom);
        $pdf->SetTextColor(125, 137, 155);
        $label = $tick . '%';
        $pdf->Text($tickX - $pdf->GetStringWidth($label) / 2, $bottom + 3.3, $label);
    }

    foreach ($classes as $index => $class) {
        $percent = max(0.0, min(100.0, (float) ($class['persen_tuntas'] ?? 0)));
        $centerY = $top + ($index + 0.5) * $rowH;
        $barH = min(4.0, $rowH * 0.62);
        $tuntasW = ($percent / 100) * $plotW;

        $pdf->SetFont('Helvetica', '', 5.8);
        $pdf->SetTextColor(52, 68, 90);
        $pdf->Text(
            $x + 3.0,
            $centerY + 0.9,
            $pdf->clipText($pdf->t((string) $class['kelas_nama']), 28.0)
        );

        $pdf->SetFillColor(25, 135, 84);
        $pdf->Rect($left, $centerY - $barH / 2, $tuntasW, $barH, 'F');
        $pdf->SetFillColor(227, 93, 106);
        $pdf->Rect($left + $tuntasW, $centerY - $barH / 2, $plotW - $tuntasW, $barH, 'F');

        $pdf->SetFont('Helvetica', 'B', 5.2);
        $pdf->SetTextColor(52, 68, 90);
        $pdf->Text(
            $right + 1.6,
            $centerY + 0.9,
            (int) ($class['tuntas'] ?? 0) . '/' . (int) ($class['jumlah'] ?? 0)
        );
    }

    $pdf->SetLineWidth(0.2);
    $pdf->SetDrawColor(222, 230, 242);
    $pdf->SetTextColor(16, 24, 40);
}

/**
 * Subjudul kecil untuk blok narasi.
 */
function laporan_pdf_subheading(SikilatLaporanPdf $pdf, string $text): void
{
    $pdf->ensureSpace(8);
    $pdf->SetFont('Helvetica', 'B', 8.2);
    $pdf->SetTextColor(11, 42, 94);
    $pdf->Cell(
        0,
        5.2,
        $pdf->clipText($pdf->t($text), $pdf->contentWidth()),
        0,
        1,
        'L'
    );
    $pdf->SetTextColor(16, 24, 40);
    $pdf->Ln(0.8);
}

/**
 * Blok narasi: paragraf penjelasan dan daftar tindak lanjut.
 *
 * @param array<int, string> $paragraphs
 * @param array<string, array<int, string>> $lists
 */
function laporan_pdf_narrative_body(SikilatLaporanPdf $pdf, array $paragraphs, array $lists = []): void
{
    $width = $pdf->contentWidth();
    $pdf->SetTextColor(63, 79, 102);

    foreach ($paragraphs as $paragraph) {
        if (trim($paragraph) === '') {
            continue;
        }

        $pdf->ensureSpace(8);
        $pdf->SetFont('Helvetica', '', 7.7);
        $pdf->MultiCell($width, 4.1, $pdf->t($paragraph), 0, 'L');
        $pdf->Ln(1.1);
    }

    foreach ($lists as $label => $items) {
        if ($items === []) {
            continue;
        }

        $pdf->ensureSpace(9);
        $pdf->SetFont('Helvetica', 'B', 6.4);
        $pdf->SetTextColor(112, 124, 142);
        $pdf->Cell($width, 3.6, $pdf->t(strtoupper($label)), 0, 1, 'L');

        $pdf->SetFont('Helvetica', '', 7.4);
        $pdf->SetTextColor(63, 79, 102);

        foreach ($items as $item) {
            $pdf->ensureSpace(6);
            $pdf->SetX($pdf->leftMargin() + 2.0);
            $pdf->MultiCell($width - 2.0, 3.9, $pdf->t('- ' . $item), 0, 'L');
            $pdf->Ln(0.4);
        }

        $pdf->Ln(1.0);
    }

    $pdf->SetTextColor(16, 24, 40);
    $pdf->Ln(0.6);
}

/**
 * Grafik karakteristik butir untuk satu paket: kurva kesukaran dan peta butir.
 *
 * @param array<string, mixed> $package
 */
function laporan_pdf_package_charts(SikilatLaporanPdf $pdf, array $package): void
{
    $items = $package['items'] ?? [];
    if ($items === []) {
        return;
    }

    $labels = [];
    $values = [];
    $pointColors = [];
    $scatter = [];
    $difficultyColors = [
        'Mudah' => [32, 201, 151],
        'Sedang' => [255, 193, 7],
        'Sukar' => [220, 53, 69],
    ];

    foreach ($items as $item) {
        $labels[] = (string) (int) $item['nomor'];
        $values[] = (float) $item['persen_benar'];
        $tingkat = (string) $item['tingkat_kesukaran'];
        $pointColors[] = $difficultyColors[$tingkat] ?? [152, 162, 179];

        if ($item['daya_pembeda'] !== null) {
            $scatter[] = [
                'x' => (float) $item['persen_benar'],
                'y' => (float) $item['daya_pembeda'],
                'tingkat' => $tingkat,
            ];
        }
    }

    $guides = [
        ['value' => 30.0, 'label' => '30%', 'color' => [220, 53, 69]],
        ['value' => 70.0, 'label' => '70%', 'color' => [32, 201, 151]],
    ];
    $datasets = [[
        'label' => 'Jawaban benar',
        'values' => $values,
        'color' => [13, 110, 253],
        'pointColors' => $pointColors,
    ]];

    $landscape = $pdf->GetPageWidth() >= 250;
    $chartH = $landscape ? 58.0 : 56.0;
    $gap = 4.0;
    $hasMap = $scatter !== [];

    if ($landscape) {
        $chartW = ($pdf->contentWidth() - ($hasMap ? $gap : 0.0)) / ($hasMap ? 2 : 1);
        $pdf->ensureSpace($chartH + 5);
        $chartY = $pdf->GetY();
        $chartX = $pdf->leftMargin();

        laporan_pdf_line_chart(
            $pdf,
            $chartX,
            $chartY,
            $chartW,
            $chartH,
            $labels,
            $datasets,
            'Kurva Tingkat Kesukaran',
            'Persentase jawaban benar per nomor',
            $guides
        );

        if ($hasMap) {
            laporan_pdf_scatter_chart(
                $pdf,
                $chartX + $chartW + $gap,
                $chartY,
                $chartW,
                $chartH,
                $scatter,
                'Peta Butir',
                'Kesukaran x daya pembeda, kanan-atas ideal'
            );
        }

        $pdf->SetY($chartY + $chartH + 3.0);
    } else {
        $chartW = $pdf->contentWidth();
        $chartX = $pdf->leftMargin();

        $pdf->ensureSpace($chartH + 5);
        $chartY = $pdf->GetY();
        laporan_pdf_line_chart(
            $pdf,
            $chartX,
            $chartY,
            $chartW,
            $chartH,
            $labels,
            $datasets,
            'Kurva Tingkat Kesukaran',
            'Persentase jawaban benar per nomor',
            $guides
        );
        $pdf->SetY($chartY + $chartH + 3.0);

        if ($hasMap) {
            $pdf->ensureSpace($chartH + 5);
            $chartY = $pdf->GetY();
            laporan_pdf_scatter_chart(
                $pdf,
                $chartX,
                $chartY,
                $chartW,
                $chartH,
                $scatter,
                'Peta Butir',
                'Kesukaran x daya pembeda, kanan-atas ideal'
            );
            $pdf->SetY($chartY + $chartH + 3.0);
        }
    }
}

/**
 * Bagian analisis rombel: narasi, kurva sebaran, ketuntasan, dan rata-rata.
 *
 * @param array<int, array<string, mixed>> $results
 * @param array<int, array<string, mixed>> $classSummary
 */
function laporan_pdf_rombel_analysis(
    SikilatLaporanPdf $pdf,
    array $results,
    array $classSummary,
    float $kkm
): void {
    if ($results === [] || $classSummary === []) {
        return;
    }

    $distribution = laporan_class_distribution($results);
    $summary = laporan_summary($results, $kkm);
    $narrative = laporan_rombel_narrative($classSummary, $distribution, $summary, $kkm);

    $pdf->ensureSpace(42);
    $pdf->sectionTitle('Analisis Rombel');
    laporan_pdf_narrative_body(
        $pdf,
        $narrative['ringkasan'],
        [
            'Sorotan per kelas' => $narrative['sorotan'],
            'Tindak lanjut' => $narrative['rekomendasi'],
        ]
    );

    $bars = array_values($classSummary);
    usort($bars, static function (array $a, array $b): int {
        return ((int) $b['jumlah'] <=> (int) $a['jumlah'])
            ?: strcmp((string) $a['kelas_nama'], (string) $b['kelas_nama']);
    });
    $bars = array_slice($bars, 0, 10);

    $bins = laporan_distribution_bins();
    $labels = array_map(static fn (array $bin): string => (string) $bin['label'], $bins);
    $distributionMap = [];
    foreach ($distribution as $row) {
        $distributionMap[(int) $row['kelas_id']] = $row;
    }

    $palette = laporan_pdf_chart_palette();
    $datasets = [];
    foreach (array_slice($bars, 0, 8) as $index => $class) {
        $dist = $distributionMap[(int) $class['kelas_id']] ?? null;
        if ($dist === null) {
            continue;
        }

        $datasets[] = [
            'label' => (string) $class['kelas_nama'],
            'values' => $dist['persen'],
            'color' => $palette[$index % count($palette)],
        ];
    }

    $portrait = $pdf->GetPageWidth() < 250;
    $chartH = $portrait ? 54.0 : 60.0;
    $gap = 4.0;

    if ($portrait) {
        $chartW = $pdf->contentWidth();
        $chartX = $pdf->leftMargin();

        $pdf->ensureSpace($chartH + 5);
        $chartY = $pdf->GetY();
        laporan_pdf_class_average_chart(
            $pdf,
            $chartX,
            $chartY,
            $chartW,
            $chartH,
            $bars,
            $kkm,
            'Rata-rata Nilai per Kelas'
        );
        $pdf->SetY($chartY + $chartH + 3.0);

        $pdf->ensureSpace($chartH + 5);
        $chartY = $pdf->GetY();
        laporan_pdf_class_completion_chart(
            $pdf,
            $chartX,
            $chartY,
            $chartW,
            $chartH,
            $bars,
            'Ketuntasan per Kelas'
        );
        $pdf->SetY($chartY + $chartH + 3.0);

        if ($datasets !== []) {
            $pdf->ensureSpace($chartH + 5);
            $chartY = $pdf->GetY();
            laporan_pdf_line_chart(
                $pdf,
                $chartX,
                $chartY,
                $chartW,
                $chartH,
                $labels,
                $datasets,
                'Kurva Sebaran Nilai per Kelas',
                'Persentase peserta tiap rentang nilai'
            );
            $pdf->SetY($chartY + $chartH + 3.0);
        }
    } else {
        $chartW = ($pdf->contentWidth() - $gap) / 2;
        $chartX = $pdf->leftMargin();

        $pdf->ensureSpace($chartH + 5);
        $chartY = $pdf->GetY();
        laporan_pdf_class_average_chart(
            $pdf,
            $chartX,
            $chartY,
            $chartW,
            $chartH,
            $bars,
            $kkm,
            'Rata-rata Nilai per Kelas'
        );
        laporan_pdf_class_completion_chart(
            $pdf,
            $chartX + $chartW + $gap,
            $chartY,
            $chartW,
            $chartH,
            $bars,
            'Ketuntasan per Kelas'
        );
        $pdf->SetY($chartY + $chartH + 3.0);

        if ($datasets !== []) {
            $pdf->ensureSpace($chartH + 5);
            $chartY = $pdf->GetY();
            laporan_pdf_line_chart(
                $pdf,
                $chartX,
                $chartY,
                $pdf->contentWidth(),
                $chartH,
                $labels,
                $datasets,
                'Kurva Sebaran Nilai per Kelas',
                'Persentase peserta tiap rentang nilai'
            );
            $pdf->SetY($chartY + $chartH + 3.0);
        }
    }

    if (count($classSummary) > 10) {
        $pdf->note(
            'Grafik batang menampilkan 10 kelas dengan peserta terbanyak. Seluruh kelas tetap '
            . 'tercantum pada tabel rekap.'
        );
    }
}

/**
 * @param array<int, array<string, mixed>> $items
 */
function laporan_pdf_item_analysis(SikilatLaporanPdf $pdf, array $items, int $peserta = 0): void
{
    $pdf->ensureSpace(62);
    $pdf->sectionTitle('Analisis Butir per Paket');
    $pdf->note(
        'Analisis butir dihitung terpisah untuk setiap paket. Nomor soal yang sama pada paket '
        . 'berbeda tidak otomatis merujuk butir yang sama karena isi soal dan kunci dapat berbeda.'
    );

    if ($items === []) {
        laporan_pdf_empty($pdf, 'Belum ada jawaban siswa yang dapat dianalisis.');
        return;
    }

    if ($peserta <= 0) {
        $peserta = (int) array_sum(array_column($items, 'peserta'));
    }

    $overview = laporan_item_overview($items);
    $itemNarrative = laporan_item_narrative($items, $overview, $peserta);
    laporan_pdf_subheading($pdf, 'Interpretasi Analisis Soal');
    laporan_pdf_narrative_body(
        $pdf,
        $itemNarrative['ringkasan'],
        ['Tindak lanjut' => $itemNarrative['rekomendasi']]
    );

    $letters = soal_option_letters_from_items($items);
    if ($letters === []) {
        $letters = ['A', 'B', 'C', 'D', 'E'];
    }

    $headers = ['No', 'Tipe', 'Kunci', 'Benar', '%', 'Tingkat', 'DP', 'Kategori'];
    $ratios = [7, 6, 8, 10, 9, 14, 10, 14];
    foreach ($letters as $letter) {
        $headers[] = $letter;
        $ratios[] = 16;
    }
    $headers[] = 'Kosong';
    $ratios[] = 12;

    $widths = laporan_pdf_widths($pdf, $ratios);
    $aligns = array_fill(0, count($headers), 'C');
    $optionStart = 8;
    $kosongIndex = $optionStart + count($letters);

    foreach ($items as $package) {
        $pdf->ensureSpace(100);

        $pdf->SetFont('Helvetica', 'B', 9.2);
        $pdf->SetTextColor(11, 42, 94);
        $pdf->Cell(
            0,
            5.6,
            $pdf->clipText(
                $pdf->t('Paket ' . (string) $package['kode_paket'] . '  -  ' . (int) $package['peserta'] . ' peserta'),
                $pdf->contentWidth()
            ),
            0,
            1,
            'L'
        );

        $stats = 'Rata-rata: ' . format_nilai($package['rata_nilai'])
            . '   |   Tertinggi: ' . format_nilai($package['nilai_tertinggi'])
            . '   |   Terendah: ' . format_nilai($package['nilai_terendah'])
            . '   |   Kelompok atas/bawah: ' . (int) $package['kelompok_atas'] . ' siswa'
            . '   |   Mudah: ' . (int) $package['tingkat_counts']['Mudah']
            . '   |   Sedang: ' . (int) $package['tingkat_counts']['Sedang']
            . '   |   Sukar: ' . (int) $package['tingkat_counts']['Sukar']
            . '   |   Pengecoh efektif: ' . (int) $package['pengecoh_efektif']
            . '/' . (int) $package['pengecoh_total'];

        $pdf->SetFont('Helvetica', '', 7.1);
        $pdf->SetTextColor(96, 108, 126);
        $pdf->Cell(0, 4.2, $pdf->clipText($pdf->t($stats), $pdf->contentWidth()), 0, 1, 'L');
        $pdf->SetTextColor(16, 24, 40);
        $pdf->Ln(1.4);

        laporan_pdf_package_charts($pdf, $package);

        $packageNarrative = laporan_package_narrative($package);
        laporan_pdf_subheading($pdf, 'Catatan Paket ' . (string) $package['kode_paket']);
        laporan_pdf_narrative_body(
            $pdf,
            $packageNarrative['paragraf'],
            ['Tindak lanjut' => $packageNarrative['rekomendasi']]
        );

        $pdf->ensureSpace(18);
        laporan_pdf_table_header($pdf, $headers, $widths, $aligns);

        foreach ($package['items'] as $item) {
            if ($pdf->GetY() + 5.8 > $pdf->bottomLimit()) {
                $pdf->AddPage();
                $pdf->SetFont('Helvetica', 'B', 7.6);
                $pdf->SetTextColor(11, 42, 94);
                $pdf->Cell(
                    0,
                    4.6,
                    $pdf->t('Paket ' . (string) $package['kode_paket'] . ' (lanjutan)'),
                    0,
                    1,
                    'L'
                );
                $pdf->SetTextColor(16, 24, 40);
                laporan_pdf_table_header($pdf, $headers, $widths, $aligns);
            }

            $pdf->SetFont('Helvetica', '', 5.9);
            $pdf->SetTextColor(16, 24, 40);
            $pdf->SetDrawColor(228, 234, 243);

            $pdf->Cell($widths[0], 5.6, (string) $item['nomor'], 1, 0, 'C');
            $pdf->Cell($widths[1], 5.6, $pdf->t(soal_tipe_short($item['tipe_soal'] ?? 'pg')), 1, 0, 'C');
            $pdf->Cell($widths[2], 5.6, $pdf->t((string) ($item['kunci_tampil'] ?? $item['kunci'])), 1, 0, 'C');
            $pdf->Cell($widths[3], 5.6, (string) (int) $item['benar'], 1, 0, 'C');
            $pdf->Cell(
                $widths[4],
                5.6,
                number_format((float) $item['persen_benar'], 1, ',', '.') . '%',
                1,
                0,
                'C'
            );
            $pdf->Cell($widths[5], 5.6, $pdf->t((string) $item['tingkat_kesukaran']), 1, 0, 'C');
            $pdf->Cell(
                $widths[6],
                5.6,
                $item['daya_pembeda'] !== null
                    ? number_format((float) $item['daya_pembeda'], 2, ',', '.')
                    : '-',
                1,
                0,
                'C'
            );
            $pdf->Cell($widths[7], 5.6, $pdf->t((string) $item['daya_pembeda_label']), 1, 0, 'C');

            foreach ($letters as $index => $letter) {
                $option = $item['opsi'][$letter] ?? ['count' => 0, 'persen' => 0, 'is_key' => false, 'effective' => false];
                $text = (int) $option['count'] . ' (' . number_format((float) $option['persen'], 1, ',', '.') . '%)';
                $width = $widths[$optionStart + $index];

                if ($option['is_key']) {
                    $pdf->SetFont('Helvetica', 'B', 5.9);
                    $pdf->SetFillColor(214, 232, 255);
                    $pdf->Cell($width, 5.6, $pdf->t($text), 1, 0, 'C', true);
                    $pdf->SetFont('Helvetica', '', 5.9);
                } elseif ($option['effective']) {
                    $pdf->SetFillColor(255, 244, 214);
                    $pdf->Cell($width, 5.6, $pdf->t($text), 1, 0, 'C', true);
                } else {
                    $pdf->Cell($width, 5.6, $pdf->t($text), 1, 0, 'C');
                }
            }

            $pdf->Cell(
                $widths[$kosongIndex],
                5.6,
                number_format((float) $item['kosong_persen'], 1, ',', '.') . '%',
                1,
                0,
                'C'
            );
            $pdf->Ln();
        }

        $pdf->SetFont('Helvetica', 'I', 6.7);
        $pdf->SetTextColor(96, 108, 126);
        $pdf->Ln(1.4);
        $pdf->Cell(
            0,
            4.2,
            $pdf->clipText(
                $pdf->t('Biru = kunci jawaban, kuning = pengecoh efektif (dipilih minimal 5% peserta). DP = daya pembeda. '
                    . 'PG memakai A-E, Benar/Salah memakai B/S, Multiple Response boleh lebih dari satu opsi dan memakai skor parsial.'),
                $pdf->contentWidth()
            ),
            0,
            1,
            'L'
        );
        $pdf->SetTextColor(16, 24, 40);
        $pdf->Ln(3.5);
    }
}

/**
 * Grid identitas dua kolom.
 *
 * @param array<int, array{0:string,1:string}> $pairs
 */
function laporan_pdf_identity(SikilatLaporanPdf $pdf, array $pairs): void
{
    $widths = laporan_pdf_widths($pdf, [15, 32, 15, 32]);
    $rowHeight = 6.8;

    foreach (array_chunk($pairs, 2) as $rowPairs) {
        $pdf->ensureSpace($rowHeight);

        $y = $pdf->GetY();
        $x = $pdf->leftMargin();

        foreach (array_values($rowPairs) as $columnIndex => $pair) {
            $cellX = $x + $columnIndex * ($widths[0] + $widths[1]);

            $pdf->SetLineWidth(0.2);
            $pdf->SetDrawColor(221, 230, 242);
            $pdf->SetFillColor(244, 247, 252);
            $pdf->Rect($cellX, $y, $widths[0], $rowHeight, 'DF');
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Rect($cellX + $widths[0], $y, $widths[1], $rowHeight, 'DF');

            $pdf->SetFont('Helvetica', 'B', 6.3);
            $pdf->SetTextColor(112, 124, 142);
            $pdf->SetXY($cellX + 2.0, $y + 1.8);
            $pdf->Cell(
                $widths[0] - 3.5,
                3.2,
                $pdf->clipText($pdf->t(strtoupper($pair[0])), $widths[0] - 3.5),
                0,
                0,
                'L'
            );

            $pdf->SetFont('Helvetica', 'B', 8.1);
            $pdf->SetTextColor(20, 34, 56);
            $pdf->SetXY($cellX + $widths[0] + 2.0, $y + 1.65);
            $pdf->Cell(
                $widths[1] - 3.5,
                3.4,
                $pdf->clipText($pdf->t($pair[1]), $widths[1] - 4.0),
                0,
                0,
                'L'
            );
        }

        $pdf->SetY($y + $rowHeight);
    }

    $pdf->Ln(3);
    $pdf->SetTextColor(16, 24, 40);
}

/**
 * Tabel rincian jawaban siswa.
 *
 * @param array<int, array<string, mixed>> $answers
 */
function laporan_pdf_answers(SikilatLaporanPdf $pdf, array $answers): void
{
    $pdf->ensureSpace(24);

    $headers = ['No. Soal', 'Tipe', 'Jawaban Siswa', 'Kunci', 'Skor', 'Hasil'];
    $widths = laporan_pdf_widths($pdf, [14, 10, 38, 28, 12, 28]);
    $aligns = ['C', 'C', 'C', 'C', 'C', 'C'];
    laporan_pdf_table_header($pdf, $headers, $widths, $aligns);

    if ($answers === []) {
        laporan_pdf_empty($pdf, 'Rincian jawaban belum tersedia untuk hasil ini.');
        return;
    }

    foreach ($answers as $answer) {
        if ($pdf->GetY() + 5.8 > $pdf->bottomLimit()) {
            $pdf->AddPage();
            laporan_pdf_table_header($pdf, $headers, $widths, $aligns);
        }

        $tipe = soal_tipe_normalize($answer['tipe_soal'] ?? 'pg');
        $jawabanRaw = $answer['jawaban'] !== null && (string) $answer['jawaban'] !== ''
            ? (string) $answer['jawaban']
            : null;
        $jawaban = $jawabanRaw === null ? '-' : soal_answer_display($tipe, $jawabanRaw);
        $kunci = $answer['kunci'] !== null && (string) $answer['kunci'] !== ''
            ? soal_answer_display($tipe, (string) $answer['kunci'])
            : '-';
        $skor = (float) ($answer['skor'] ?? ((int) $answer['is_benar'] === 1 ? 1 : 0));

        if ($jawabanRaw === null) {
            $status = 'Kosong';
            $fill = [242, 245, 249];
        } elseif ((int) $answer['is_benar'] === 1) {
            $status = 'Benar';
            $fill = [232, 248, 238];
        } elseif ($skor > 0) {
            $status = 'Parsial';
            $fill = [255, 247, 224];
        } else {
            $status = 'Salah';
            $fill = [252, 238, 238];
        }

        $pdf->SetFont('Helvetica', '', 7.6);
        $pdf->SetTextColor(16, 24, 40);
        $pdf->SetDrawColor(228, 234, 243);

        $pdf->Cell($widths[0], 5.7, (string) $answer['nomor_soal'], 1, 0, 'C');
        $pdf->Cell($widths[1], 5.7, $pdf->t(soal_tipe_short($tipe)), 1, 0, 'C');
        $pdf->Cell(
            $widths[2],
            5.7,
            $pdf->clipText($pdf->t($jawaban), $widths[2] - 1.6),
            1,
            0,
            'C'
        );
        $pdf->Cell(
            $widths[3],
            5.7,
            $pdf->clipText($pdf->t($kunci), $widths[3] - 1.6),
            1,
            0,
            'C'
        );
        $pdf->Cell(
            $widths[4],
            5.7,
            number_format($skor, 2, ',', '.'),
            1,
            0,
            'C'
        );
        $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
        $pdf->Cell($widths[5], 5.7, $pdf->t($status), 1, 0, 'C', true);
        $pdf->Ln();
    }

    $pdf->Ln(2.5);
}

/**
 * Blok tanda tangan kepala sekolah. Dilewati jika nama belum diisi.
 */
function laporan_pdf_signature(SikilatLaporanPdf $pdf): void
{
    $kepala = trim((string) setting('kepala_sekolah', ''));
    $nip = trim((string) setting('nip_kepala_sekolah', ''));
    $kota = trim((string) setting('kota_sekolah', ''));
    $blockW = 68.0;
    $height = 43.0;

    $pdf->ensureSpace($height);

    $x = $pdf->leftMargin() + $pdf->contentWidth() - $blockW;
    $y = max($pdf->GetY() + 7.0, $pdf->bottomLimit() - $height + 2.0);
    $tanggal = ($kota !== '' ? $kota . ', ' : '') . format_tanggal(date('Y-m-d'));

    $pdf->SetFont('Helvetica', '', 8.3);
    $pdf->SetTextColor(30, 42, 60);
    $pdf->SetXY($x, $y);
    $pdf->Cell($blockW, 4.6, $pdf->clipText($pdf->t($tanggal), $blockW), 0, 1, 'C');

    $pdf->SetX($x);
    $pdf->Cell($blockW, 4.8, $pdf->t('Kepala Sekolah,'), 0, 1, 'C');

    $pdf->SetY($y + 4.6 + 4.8 + 16.0);
    $pdf->SetX($x);
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetTextColor(16, 24, 40);

    if ($kepala !== '') {
        $pdf->Cell($blockW, 5.0, $pdf->clipText($pdf->t($kepala), $blockW - 4), 0, 1, 'C');

        $nameW = min($blockW - 8, $pdf->GetStringWidth($pdf->t($kepala)) + 5);
        $lineY = $pdf->GetY() - 0.5;
        $pdf->SetDrawColor(60, 72, 90);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($x + ($blockW - $nameW) / 2, $lineY, $x + ($blockW + $nameW) / 2, $lineY);
        $pdf->SetLineWidth(0.2);
    } else {
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->Cell($blockW, 5.0, '..............................', 0, 1, 'C');
    }

    $pdf->SetX($x);
    $pdf->SetFont('Helvetica', '', 8.0);
    $pdf->Cell(
        $blockW,
        4.6,
        $pdf->t($nip !== '' ? 'NIP. ' . $nip : 'NIP. ..............................'),
        0,
        1,
        'C'
    );

    $pdf->SetY(max($pdf->GetY(), $y + $height));
    $pdf->SetTextColor(16, 24, 40);
}

/**
 * Kotak pesan saat laporan belum memiliki data.
 */
function laporan_pdf_empty(SikilatLaporanPdf $pdf, string $message): void
{
    $pdf->ensureSpace(26);

    $width = $pdf->contentWidth();
    $y = $pdf->GetY();
    $height = 20.0;

    $pdf->SetLineWidth(0.25);
    $pdf->SetDrawColor(214, 224, 238);
    $pdf->SetFillColor(250, 251, 253);
    $pdf->Rect($pdf->leftMargin(), $y, $width, $height, 'DF');

    $pdf->SetFont('Helvetica', 'B', 9.0);
    $pdf->SetTextColor(71, 84, 103);
    $pdf->SetXY($pdf->leftMargin(), $y + 4.8);
    $pdf->Cell($width, 5.0, $pdf->t('Belum ada data untuk ditampilkan'), 0, 1, 'C');

    $pdf->SetFont('Helvetica', '', 7.8);
    $pdf->SetTextColor(110, 122, 140);
    $pdf->SetX($pdf->leftMargin());
    $pdf->Cell($width, 4.4, $pdf->clipText($pdf->t($message), $width - 10), 0, 1, 'C');

    $pdf->SetY($y + $height + 4);
    $pdf->SetTextColor(16, 24, 40);
    $pdf->SetDrawColor(222, 230, 242);
}

/**
 * @param array<string, mixed> $exam
 * @param array<int, array<string, mixed>> $results
 * @param array<int, array<string, mixed>> $classSummary
 * @param array<int, array<string, mixed>> $packageSummary
 * @param array<int, array<string, mixed>> $packages
 * @param array<int, array<string, mixed>> $distribution
 * @param array<int, array<string, mixed>> $items
 * @param array{result:array<string, mixed>, answers:array<int, array<string, mixed>>}|null $detail
 */
function laporan_pdf_build(
    string $jenis,
    array $exam,
    ?array $selectedClass,
    array $results,
    array $classSummary,
    array $packageSummary,
    array $packages,
    array $distribution,
    array $items,
    float $kkm,
    ?array $detail = null
): string {
    $subscript = (string) $exam['nama'] . ' | ' . (string) $exam['mapel_nama']
        . ' | ' . ($selectedClass !== null ? (string) $selectedClass['nama'] : 'Semua kelas');
    $summary = laporan_summary($results, $kkm);
    $hasResults = $results !== [];

    switch ($jenis) {
        case 'analisis':
            $pdf = laporan_pdf_doc('Laporan Analisis Soal dan Rombel', $subscript, 'L');
            laporan_pdf_exam_info($pdf, $exam, $selectedClass, $packages, $kkm, count($results));
            laporan_pdf_summary($pdf, $summary, $kkm);

            if (!$hasResults) {
                laporan_pdf_empty($pdf, 'Analisis butir akan tersedia setelah siswa mengirim jawaban.');
                laporan_pdf_signature($pdf);
                break;
            }

            laporan_pdf_rombel_analysis($pdf, $results, $classSummary, $kkm);
            laporan_pdf_class_recap($pdf, $classSummary, $summary);
            laporan_pdf_package_recap($pdf, $packageSummary, $summary);
            laporan_pdf_item_analysis($pdf, $items, count($results));
            laporan_pdf_signature($pdf);
            break;

        case 'siswa':
            if ($detail === null) {
                throw new InvalidArgumentException('Detail hasil siswa tidak tersedia.');
            }

            $result = $detail['result'];
            $nilai = (float) $result['nilai'];
            $tuntas = $nilai + 0.00001 >= $kkm;
            $durasi = '-';

            if (isset($result['durasi_detik']) && $result['durasi_detik'] !== null) {
                $seconds = max(0, (int) $result['durasi_detik']);
                $durasi = intdiv($seconds, 60) . ' menit ' . ($seconds % 60) . ' detik';
            }

            $pdf = laporan_pdf_doc(
                'Laporan Individu Siswa',
                (string) $result['nama_siswa'] . ' | ' . (string) $result['kelas_nama']
            );
            // Laporan individu hanya memuat paket yang dikerjakan siswa tersebut.
            $studentPackages = [['kode_paket' => (string) $result['kode_paket']]];
            laporan_pdf_exam_info($pdf, $exam, $selectedClass, $studentPackages, $kkm, 1);
            laporan_pdf_student_summary($pdf, $result, $kkm);

            $pdf->sectionTitle('Identitas Siswa');
            laporan_pdf_identity($pdf, [
                ['Nama Siswa', (string) $result['nama_siswa']],
                ['Nomor Absen', (string) $result['nomor_absen']],
                ['Kelas', (string) $result['kelas_nama']],
                ['Tingkat', (string) $result['tingkat']],
                ['Tahun Ajaran', (string) $result['tahun_ajaran']],
                ['Paket Soal', 'Paket ' . (string) $result['kode_paket']],
                ['Mata Pelajaran', (string) $exam['mapel_nama']],
                ['Tanggal Ujian', format_tanggal((string) $exam['tanggal_ujian'])],
                ['Waktu Selesai', format_tanggal((string) $result['submitted_at'], true)],
                ['Durasi Pengerjaan', $durasi],
            ]);

            $pdf->sectionTitle('Rincian Jawaban');
            $pdf->note(
                'Kunci jawaban mengikuti Paket ' . (string) $result['kode_paket']
                . '. Jawaban kosong berarti tidak dijawab. Soal Multiple Response memakai skor parsial: '
                . 'opsi benar yang dipilih dikurangi opsi salah, dibagi jumlah kunci. Keterangan: '
                . ($tuntas ? 'Tuntas' : 'Belum Tuntas') . ' terhadap KKM ' . format_nilai($kkm) . '.'
            );
            laporan_pdf_answers($pdf, $detail['answers']);
            laporan_pdf_signature($pdf);
            break;

        case 'distribusi':
            $pdf = laporan_pdf_doc('Laporan Distribusi Nilai', $subscript);
            laporan_pdf_exam_info($pdf, $exam, $selectedClass, $packages, $kkm, count($results));
            laporan_pdf_summary($pdf, $summary, $kkm);

            if (!$hasResults) {
                laporan_pdf_empty($pdf, 'Distribusi nilai akan tersedia setelah siswa mengirim jawaban.');
                laporan_pdf_signature($pdf);
                break;
            }

            laporan_pdf_distribution($pdf, $distribution, count($results));
            laporan_pdf_class_recap($pdf, $classSummary, $summary);
            laporan_pdf_rombel_analysis($pdf, $results, $classSummary, $kkm);

            $needed = 18.0 + count($packageSummary) * 5.8 + 46.0;
            if ($pdf->GetY() + $needed > $pdf->bottomLimit()) {
                $pdf->AddPage();
            }

            laporan_pdf_package_recap($pdf, $packageSummary, $summary);
            laporan_pdf_signature($pdf);
            break;

        case 'kelas':
        default:
            $pdf = laporan_pdf_doc('Laporan Hasil Ujian', $subscript);
            laporan_pdf_exam_info($pdf, $exam, $selectedClass, $packages, $kkm, count($results));
            laporan_pdf_summary($pdf, $summary, $kkm);

            if (!$hasResults) {
                laporan_pdf_empty($pdf, 'Hasil ujian akan tersedia setelah siswa mengirim jawaban.');
                laporan_pdf_signature($pdf);
                break;
            }

            if ($selectedClass !== null) {
                $pdf->sectionTitle('Daftar Nilai Kelas ' . (string) $selectedClass['nama']);
                laporan_pdf_student_table($pdf, $results, $kkm, '', $summary);
                laporan_pdf_distribution($pdf, $distribution, count($results));
            } else {
                laporan_pdf_class_recap($pdf, $classSummary, $summary);
                laporan_pdf_distribution($pdf, $distribution, count($results));

                $grouped = [];
                foreach ($results as $result) {
                    $grouped[(int) $result['kelas_id']][] = $result;
                }

                foreach ($classSummary as $class) {
                    $classRows = $grouped[(int) $class['kelas_id']] ?? [];

                    if ($classRows === []) {
                        continue;
                    }

                    $pdf->AddPage();
                    $pdf->sectionTitle('Daftar Nilai Kelas ' . (string) $class['kelas_nama']);
                    laporan_pdf_student_table($pdf, $classRows, $kkm, '', $class);
                }
            }

            laporan_pdf_signature($pdf);
            break;
    }

    return $pdf->Output('S');
}
