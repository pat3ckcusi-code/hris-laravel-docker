<?php
require '/var/www/html/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$spreadsheet = IOFactory::load('/var/www/html/storage/app/templates/ETA.xlsx');
$sheet = $spreadsheet->getActiveSheet();

echo '=== Checkbox column positions ===' . PHP_EOL;
// The checkbox marker typically goes in the cell BEFORE the label
// Row 10: G10=Audit, L10=Client Support, N10=Conference
// Row 11: C11=Construction, H11=Economic, L11=General
// Row 12: C12=Legal, G12=Legislator, J12=Meeting, L12=Training, N12=Seminar

// Check what's in the cells before each label
$checkCells = ['F10','K10','M10','B11','G11','K11','B12','F12','I12','K12','M12',
               'F40','K40','M40','B41','G41','K41','B42','F42','I42','K42','M42'];
foreach ($checkCells as $cell) {
    $val = $sheet->getCell($cell)->getValue();
    echo $cell . ' => ' . ($val === null ? '(null)' : '"'.$val.'"') . PHP_EOL;
}

echo PHP_EOL . '=== Approval checkbox positions ===' . PHP_EOL;
$approveCells = ['D24','E24','I24','J24','D54','E54','I54','J54'];
foreach ($approveCells as $cell) {
    $val = $sheet->getCell($cell)->getValue();
    echo $cell . ' => ' . ($val === null ? '(null)' : '"'.$val.'"') . PHP_EOL;
}

echo PHP_EOL . '=== Row 26/A28 area ===' . PHP_EOL;
for ($r = 25; $r <= 29; $r++) {
    for ($c = 'A'; $c <= 'O'; $c++) {
        $val = $sheet->getCell($c.$r)->getValue();
        if ($val !== null && $val !== '') {
            echo $c.$r . ' => "' . $val . '"' . PHP_EOL;
        }
    }
}
echo PHP_EOL . '=== Row 55-59 area ===' . PHP_EOL;
for ($r = 55; $r <= 60; $r++) {
    for ($c = 'A'; $c <= 'O'; $c++) {
        $val = $sheet->getCell($c.$r)->getValue();
        if ($val !== null && $val !== '') {
            echo $c.$r . ' => "' . $val . '"' . PHP_EOL;
        }
    }
}
