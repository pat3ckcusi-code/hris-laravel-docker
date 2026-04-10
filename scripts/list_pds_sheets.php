<?php
require __DIR__ . '/../vendor/autoload.php';
$path = __DIR__ . '/../storage/app/templates/PDS.xlsx';
if (!is_file($path)) {
    echo "MISSING: $path\n";
    exit(1);
}
$ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
$names = $ss->getSheetNames();
foreach ($names as $i => $name) {
    echo ($i + 1) . ': ' . $name . PHP_EOL;
}
