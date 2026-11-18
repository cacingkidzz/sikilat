<?php
declare(strict_types=1);

/**
 * SIKILAT - Ekspor laporan Excel (Tahap 5).
 *
 * Membuat satu berkas .xlsx berisi rekap nilai, rekap kelas, rekap paket,
 * rincian jawaban, dan analisis butir. Semua data dibatasi pada ujian yang
 * dipilih; analisis butir dipisah per paket.
 */

require_once __DIR__ . '/../../config/app.php';
require_once INCLUDES_PATH . '/laporan.php';
require_once INCLUDES_PATH . '/laporan_export.php';

require_auth();

use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$examId = (int) input('ujian_id', 0);
$exam = laporan_exam($examId);

if ($exam === null) {
    render_error_page(404, 'Ujian Tidak Ditemukan', 'Ujian yang diminta tidak tersedia atau bukan hak akses Anda.');
}

$classId = (int) input('kelas_id', 0);
if ($classId > 0 && !laporan_class_valid($examId, $classId)) {
    render_error_page(400, 'Kelas Tidak Sesuai', 'Kelas yang dipilih bukan peserta ujian ini.');
}

$selectedClass = null;
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
$items = laporan_item_analysis($examId, $classId > 0 ? $classId : null, $results, (int) $exam['jumlah_soal']);

$spreadsheet = laporan_excel_build(
    $exam,
    $selectedClass,
    $results,
    $classSummary,
    $packageSummary,
    $packages,
    $items,
    $kkm
);

$filename = laporan_export_filename('laporan-hasil-ujian', $exam, $selectedClass, 'xlsx');

if (!headers_sent()) {
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0, must-revalidate');
    header('Pragma: public');
}

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
$spreadsheet->disconnectWorksheets();

exit;
