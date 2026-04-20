<?php

require '/var/www/html/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = '/var/www/html/storage/app/templates/ETA.xlsx';
echo 'Template exists: ' . (file_exists($path) ? 'YES' : 'NO') . PHP_EOL;

$spreadsheet = IOFactory::load($path);
$sheet = $spreadsheet->getActiveSheet();

// Test setting values - Copy 1
$sheet->setCellValue('D6', 'John Doe');
$sheet->setCellValue('D7', 'Engineering');
$sheet->setCellValue('D8', 'Developer');
$sheet->setCellValue('D9', 'Manila');
$sheet->setCellValue('K6', 'Jan 15, 2025');
$sheet->setCellValue('K8', 'Jan 20, 2025');
$sheet->setCellValue('A14', 'Business meeting with client');
$sheet->setCellValue('F10', '✓');
$sheet->setCellValue('D24', '✓');
$sheet->setCellValue('A26', 'Jane Smith');
$sheet->setCellValue('J27', 'Jan 14, 2025');

// Copy 2
$sheet->setCellValue('D36', 'John Doe');
$sheet->setCellValue('D37', 'Engineering');
$sheet->setCellValue('D38', 'Developer');
$sheet->setCellValue('D39', 'Manila');
$sheet->setCellValue('K36', 'Jan 15, 2025');
$sheet->setCellValue('K38', 'Jan 20, 2025');
$sheet->setCellValue('A44', 'Business meeting with client');
$sheet->setCellValue('F40', '✓');
$sheet->setCellValue('D54', '✓');
$sheet->setCellValue('A56', 'Jane Smith');
$sheet->setCellValue('J58', 'Jan 14, 2025');

$outDir = '/var/www/html/storage/app/eta/prints';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}
$outPath = $outDir . '/test-output.xlsx';
$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save($outPath);
$spreadsheet->disconnectWorksheets();

echo 'Output file created: ' . (file_exists($outPath) ? 'YES' : 'NO') . PHP_EOL;
echo 'Output file size: ' . filesize($outPath) . ' bytes' . PHP_EOL;

// Verify values
$verify = IOFactory::load($outPath);
$vs = $verify->getActiveSheet();
echo 'D6 = ' . $vs->getCell('D6')->getValue() . PHP_EOL;
echo 'D7 = ' . $vs->getCell('D7')->getValue() . PHP_EOL;
echo 'F10 = ' . $vs->getCell('F10')->getValue() . PHP_EOL;
echo 'D24 = ' . $vs->getCell('D24')->getValue() . PHP_EOL;
echo 'A14 = ' . $vs->getCell('A14')->getValue() . PHP_EOL;
echo 'D36 = ' . $vs->getCell('D36')->getValue() . PHP_EOL;
echo 'A26 = ' . $vs->getCell('A26')->getValue() . PHP_EOL;
echo 'J27 = ' . $vs->getCell('J27')->getValue() . PHP_EOL;
echo 'D54 = ' . $vs->getCell('D54')->getValue() . PHP_EOL;
echo 'A44 = ' . $vs->getCell('A44')->getValue() . PHP_EOL;
$verify->disconnectWorksheets();

unlink($outPath);
echo 'Test passed!' . PHP_EOL;
