<?php
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = __DIR__ . '/storage/app/templates/csc-form-212-template.xlsx';
$sheet = IOFactory::load($path)->getSheetByName('C1');
foreach ($sheet->getMergeCells() as $range) {
    if (preg_match('/(1[0-8])/', $range)) {
        echo $range . "\n";
    }
}
