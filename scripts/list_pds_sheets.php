<?php
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = __DIR__ . '/../storage/app/templates/PDS.xlsx';
if (!is_file($path)) {
    echo "MISSING: $path\n";
    exit(1);
}
$ss = IOFactory::load($path);
$names = $ss->getSheetNames();
foreach ($names as $i => $name) {
    echo ($i + 1) . ': ' . $name . PHP_EOL;
}
