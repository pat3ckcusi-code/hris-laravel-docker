<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use PhpOffice\PhpSpreadsheet\IOFactory;

// ETA template inspection
$path = storage_path('app/templates/ETA.xlsx');
echo "=== ETA.xlsx ===" . PHP_EOL;
echo 'File exists: ' . (file_exists($path) ? 'yes' : 'no') . PHP_EOL;

$spreadsheet = IOFactory::load($path);
$sheet = $spreadsheet->getActiveSheet();
echo 'Sheet: ' . $sheet->getTitle() . PHP_EOL;
echo 'Highest row: ' . $sheet->getHighestRow() . PHP_EOL;
echo 'Highest col: ' . $sheet->getHighestColumn() . PHP_EOL;

echo PHP_EOL . '--- All cells with values ---' . PHP_EOL;
foreach ($sheet->getRowIterator() as $row) {
    foreach ($row->getCellIterator() as $cell) {
        $val = $cell->getValue();
        if ($val !== null && $val !== '') {
            echo $cell->getCoordinate() . ' => ' . json_encode($val) . PHP_EOL;
        }
    }
}

echo PHP_EOL . '--- Merged cell ranges ---' . PHP_EOL;
foreach ($sheet->getMergeCells() as $range) {
    echo $range . PHP_EOL;
}

// Now the LOCATOR template
echo PHP_EOL . PHP_EOL;
$path2 = storage_path('app/templates/LOCATOR.xls');
echo "=== LOCATOR.xls ===" . PHP_EOL;
$spreadsheet2 = IOFactory::load($path2);
$sheet2 = $spreadsheet2->getSheet(0);
echo 'Sheet: ' . $sheet2->getTitle() . PHP_EOL;

// Focus on rows 22-35 (authorization/approval sections)
echo PHP_EOL . '--- Locator rows 22-35 (approval section) ---' . PHP_EOL;
for ($r = 22; $r <= 35; $r++) {
    $cols = range('A', 'U');
    foreach ($cols as $c) {
        $val = $sheet2->getCell($c . $r)->getValue();
        if ($val !== null && $val !== '') {
            echo $c . $r . ' => ' . json_encode($val) . PHP_EOL;
        }
    }
}
