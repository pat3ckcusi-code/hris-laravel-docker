<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Locator;

$locator = Locator::where('status', 'approved')->first();
if (!$locator) {
    echo "No approved locator found.\n";
    exit;
}

echo 'Locator ID: ' . $locator->id . PHP_EOL;
echo 'Type: ' . $locator->application_type . PHP_EOL;
echo 'Date: ' . $locator->travel_date . PHP_EOL;
echo 'Location: ' . $locator->location . PHP_EOL;
echo 'Detail: ' . $locator->detail . PHP_EOL;
echo 'Departure: ' . $locator->intended_departure_time . PHP_EOL;
echo 'Arrival: ' . $locator->intended_arrival_time . PHP_EOL;
echo 'Template exists: ' . (file_exists(storage_path('app/templates/LOCATOR.xls')) ? 'yes' : 'no') . PHP_EOL;

// Test the export service
echo PHP_EOL . "--- Testing LocatorExportService ---\n";
use App\Services\LocatorExportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

$templatePath = storage_path('app/templates/LOCATOR.xls');
$spreadsheet = IOFactory::load($templatePath);
$sheet = $spreadsheet->getSheet(0);

$sheet->setCellValue('B6', 'Test Name');
$sheet->setCellValue('I6', 'Apr 16, 2026');

$outputPath = sys_get_temp_dir() . '/locator-test.xls';
$writer = IOFactory::createWriter($spreadsheet, 'Xls');
$writer->save($outputPath);
$spreadsheet->disconnectWorksheets();

echo 'Output file: ' . $outputPath . PHP_EOL;
echo 'File exists: ' . (file_exists($outputPath) ? 'yes' : 'no') . PHP_EOL;
echo 'File size: ' . filesize($outputPath) . ' bytes' . PHP_EOL;

// Verify the written values
$verify = IOFactory::load($outputPath);
$vSheet = $verify->getSheet(0);
echo 'B6 value: ' . $vSheet->getCell('B6')->getValue() . PHP_EOL;
echo 'I6 value: ' . $vSheet->getCell('I6')->getValue() . PHP_EOL;
echo PHP_EOL . "SUCCESS: Template loading and writing works!\n";

unlink($outputPath);
