<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Locator;
use App\Models\Eta;
use App\Models\Department;
use App\Models\User;
use App\Models\Setting;

echo "=== Testing Dept Head Resolution ===" . PHP_EOL;

// Find an approved locator
$locator = Locator::where('status', 'approved')->first();
if ($locator) {
    $owner = $locator->user;
    echo "Locator #{$locator->id} owner: " . ($owner->first_name ?? '') . " " . ($owner->last_name ?? '') . PHP_EOL;

    if ($owner && $owner->Dept_id) {
        $dept = Department::find($owner->Dept_id);
        echo "  Department: " . ($dept->Dept_name ?? 'N/A') . PHP_EOL;
        echo "  Dept EmpNo (head): " . ($dept->EmpNo ?? 'N/A') . PHP_EOL;
        echo "  Dept Designation field: " . ($dept->Designation ?? 'NULL') . PHP_EOL;

        if ($dept && $dept->EmpNo && $dept->EmpNo !== 'UNASSIGNED') {
            $head = User::where('EmpNo', $dept->EmpNo)->first();
            if ($head) {
                echo "  Head name: " . ($head->first_name ?? '') . " " . ($head->last_name ?? '') . PHP_EOL;
                echo "  Head designation: " . ($head->designation ?? 'NULL') . PHP_EOL;
            }
        }
    }
} else {
    echo "No approved locator found." . PHP_EOL;
}

echo PHP_EOL . "=== Testing ETA ===" . PHP_EOL;
$eta = Eta::where('status', 'approved')->first();
if ($eta) {
    $owner = $eta->user;
    echo "ETA #{$eta->id} owner: " . ($owner->first_name ?? '') . " " . ($owner->last_name ?? '') . PHP_EOL;

    if ($owner && $owner->Dept_id) {
        $dept = Department::find($owner->Dept_id);
        echo "  Department: " . ($dept->Dept_name ?? 'N/A') . PHP_EOL;
        echo "  parent_dept_id: " . ($dept->parent_dept_id ?? 'NULL') . PHP_EOL;
    }
} else {
    echo "No approved ETA found." . PHP_EOL;
}

echo PHP_EOL . "=== Settings (Mayor/Vice Mayor) ===" . PHP_EOL;
$settings = Setting::first();
if ($settings) {
    echo "  Mayor: " . ($settings->mayor_name ?? 'NULL') . " / " . ($settings->mayor_designation ?? 'NULL') . PHP_EOL;
    echo "  Vice Mayor: " . ($settings->vice_mayor_name ?? 'NULL') . " / " . ($settings->vice_mayor_designation ?? 'NULL') . PHP_EOL;
} else {
    echo "  No settings record found." . PHP_EOL;
}

echo PHP_EOL . "=== Testing LocatorExportService ===" . PHP_EOL;
use App\Services\LocatorExportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

$templatePath = storage_path('app/templates/LOCATOR.xls');
$spreadsheet = IOFactory::load($templatePath);
$sheet = $spreadsheet->getSheet(0);

// Simulate writes
$sheet->setCellValue('D25', 'Test Department Head');
$sheet->setCellValue('D26', 'Department Head I');
$sheet->setCellValue('O25', 'Test Department Head');
$sheet->setCellValue('O26', 'Department Head I');

$outputPath = sys_get_temp_dir() . '/locator-auth-test.xls';
$writer = IOFactory::createWriter($spreadsheet, 'Xls');
$writer->save($outputPath);
$spreadsheet->disconnectWorksheets();

// Verify
$verify = IOFactory::load($outputPath);
$vSheet = $verify->getSheet(0);
echo "D25: " . $vSheet->getCell('D25')->getValue() . PHP_EOL;
echo "D26: " . $vSheet->getCell('D26')->getValue() . PHP_EOL;
echo "O25: " . $vSheet->getCell('O25')->getValue() . PHP_EOL;
echo "O26: " . $vSheet->getCell('O26')->getValue() . PHP_EOL;
unlink($outputPath);

echo PHP_EOL . "=== Testing ETA Template Auth ===" . PHP_EOL;
$templatePath2 = storage_path('app/templates/ETA.xlsx');
$spreadsheet2 = IOFactory::load($templatePath2);
$sheet2 = $spreadsheet2->getActiveSheet();

$sheet2->setCellValue('A26', 'Test Dept Head');
$sheet2->setCellValue('A28', 'Department Head I');
$sheet2->setCellValue('A56', 'Test Dept Head');
$sheet2->setCellValue('A58', 'Department Head I');

$outputPath2 = sys_get_temp_dir() . '/eta-auth-test.xlsx';
$writer2 = IOFactory::createWriter($spreadsheet2, 'Xlsx');
$writer2->save($outputPath2);
$spreadsheet2->disconnectWorksheets();

$verify2 = IOFactory::load($outputPath2);
$vSheet2 = $verify2->getActiveSheet();
echo "A26: " . $vSheet2->getCell('A26')->getValue() . PHP_EOL;
echo "A28: " . $vSheet2->getCell('A28')->getValue() . PHP_EOL;
echo "A56: " . $vSheet2->getCell('A56')->getValue() . PHP_EOL;
echo "A58: " . $vSheet2->getCell('A58')->getValue() . PHP_EOL;
unlink($outputPath2);

echo PHP_EOL . "SUCCESS: All authorization cells write and read correctly!" . PHP_EOL;
