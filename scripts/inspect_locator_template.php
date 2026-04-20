<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = storage_path('app/templates/LOCATOR.xls');
echo 'File exists: ' . (file_exists($path) ? 'yes' : 'no') . PHP_EOL;

$spreadsheet = IOFactory::load($path);

echo 'Number of sheets: ' . $spreadsheet->getSheetCount() . PHP_EOL;

for ($i = 0; $i < $spreadsheet->getSheetCount(); $i++) {
    $sheet = $spreadsheet->getSheet($i);
    echo PHP_EOL . '=== Sheet ' . $i . ': ' . $sheet->getTitle() . ' ===' . PHP_EOL;
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
}
