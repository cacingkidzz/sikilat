<?php
declare(strict_types=1);

/**
 * SIKILAT - Ekspor laporan PDF (Tahap 5).
 *
 * Jenis laporan:
 * - kelas      : hasil ujian dan daftar nilai per kelas
 * - analisis   : analisis nilai dan butir per paket
 * - siswa      : laporan individu beserta rincian jawaban
 * - distribusi : distribusi nilai dan rekap kelas
 */

require_once __DIR__ . '/../../config/app.php';
require_once INCLUDES_PATH . '/laporan.php';
require_once INCLUDES_PATH . '/laporan_export.php';

require_auth();

$examId = (int) input('ujian_id', 0);
$exam = laporan_exam($examId);

if ($exam === null) {
    render_error_page(404, 'Ujian Tidak Ditemukan', 'Ujian yang diminta tidak tersedia atau bukan hak akses Anda.');
}

$classId = (int) input('kelas_id', 0);
if ($classId > 0 && !laporan_class_valid($examId, $classId)) {
    render_error_page(400, 'Kelas Tidak Sesuai', 'Kelas yang dipilih bukan peserta ujian ini.');
}

$jenis = (string) input('jenis', 'kelas');
$allowedTypes = ['kelas', 'analisis', 'siswa', 'distribusi'];
if (!in_array($jenis, $allowedTypes, true)) {
    render_error_page(400, 'Jenis Laporan Tidak Valid', 'Jenis laporan yang diminta tidak dikenali.');
}

$detail = null;
$selectedClass = null;
$results = [];

if ($jenis === 'siswa') {
    $hasilId = (int) input('hasil_id', 0);
    $detail = laporan_result_detail($hasilId, $examId, $classId > 0 ? $classId : null);

    if ($detail === null) {
        render_error_page(404, 'Hasil Tidak Ditemukan', 'Hasil siswa yang diminta tidak tersedia pada ujian ini.');
    }

    $classId = (int) $detail['result']['kelas_id'];
}

foreach (laporan_classes($examId) as $class) {
    if ((int) $class['id'] === $classId) {
        $selectedClass = $class;
        break;
    }
}

$kkm = (float) $exam['kkm'];
$results = laporan_results($examId, $classId > 0 ? $classId : null);
$classSummary = laporan_class_summary($results, $kkm);
$packageSummary = laporan_package_summary($results, $kkm);
$packages = laporan_packages($examId);
$distribution = laporan_distribution($results);
$items = $jenis === 'analisis'
    ? laporan_item_analysis($examId, $classId > 0 ? $classId : null, $results, (int) $exam['jumlah_soal'])
    : [];

$pdfBytes = laporan_pdf_build(
    $jenis,
    $exam,
    $selectedClass,
    $results,
    $classSummary,
    $packageSummary,
    $packages,
    $distribution,
    $items,
    $kkm,
    $detail
);

$prefix = match ($jenis) {
    'analisis' => 'analisis-ujian',
    'siswa' => 'laporan-siswa-' . ($detail !== null ? laporan_slug((string) $detail['result']['nomor_absen']) : ''),
    'distribusi' => 'distribusi-nilai',
    default => 'hasil-ujian',
};

$filename = laporan_export_filename($prefix, $exam, $selectedClass, 'pdf');

if (!headers_sent()) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfBytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
}

echo $pdfBytes;
exit;
