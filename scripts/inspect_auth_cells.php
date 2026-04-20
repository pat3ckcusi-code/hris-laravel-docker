<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use PhpOffice\PhpSpreadsheet\IOFactory;

// ETA template - authorization section detail
$path = storage_path('app/templates/ETA.xlsx');
$spreadsheet = IOFactory::load($path);
$sheet = $spreadsheet->getActiveSheet();

echo "=== ETA Authorization area (rows 20-30, Copy 1) ===" . PHP_EOL;
for ($r = 20; $r <= 30; $r++) {
    foreach (range('A', 'O') as $c) {
        $val = $sheet->getCell($c . $r)->getValue();
        if ($val !== null && $val !== '') {
            echo $c . $r . ' => ' . json_encode($val) . PHP_EOL;
        }
    }
}

echo PHP_EOL . "=== ETA Authorization area (rows 50-60, Copy 2) ===" . PHP_EOL;
for ($r = 50; $r <= 60; $r++) {
    foreach (range('A', 'O') as $c) {
        $val = $sheet->getCell($c . $r)->getValue();
        if ($val !== null && $val !== '') {
            echo $c . $r . ' => ' . json_encode($val) . PHP_EOL;
        }
    }
}

echo PHP_EOL . "=== ETA Merged ranges in auth area ===" . PHP_EOL;
foreach ($sheet->getMergeCells() as $range) {
    // Only show ranges that touch rows 20-30 or 50-60
    preg_match('/(\d+)/', $range, $m);
    $rowNum = (int)$m[1];
    if (($rowNum >= 20 && $rowNum <= 30) || ($rowNum >= 50 && $rowNum <= 60)) {
        echo $range . PHP_EOL;
    }
}

// Locator template - auth area rows 22-28
echo PHP_EOL . "=== LOCATOR Authorization area (rows 22-28, both copies) ===" . PHP_EOL;
$path2 = storage_path('app/templates/LOCATOR.xls');
$spreadsheet2 = IOFactory::load($path2);
$sheet2 = $spreadsheet2->getSheet(0);
for ($r = 22; $r <= 28; $r++) {
    foreach (range('A', 'U') as $c) {
        $val = $sheet2->getCell($c . $r)->getValue();
        if ($val !== null && $val !== '') {
            echo $c . $r . ' => ' . json_encode($val) . PHP_EOL;
        }
    }
}

echo PHP_EOL . "=== LOCATOR Merged ranges in auth area ===" . PHP_EOL;
foreach ($sheet2->getMergeCells() as $range) {
    preg_match('/(\d+)/', $range, $m);
    $rowNum = (int)$m[1];
    if ($rowNum >= 22 && $rowNum <= 28) {
        echo $range . PHP_EOL;
    }
}
