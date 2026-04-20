<?php
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

$src = __DIR__ . '/storage/app/templates/csc-form-212-template.xlsx';
$dst = __DIR__ . '/storage/app/templates/csc-form-212-template-cleaned-test.xlsx';
$spreadsheet = IOFactory::load($src);
foreach ($spreadsheet->getDefinedNames() as $definedName) {
    $value = (string) $definedName->getValue();
    if ($value === '' || str_contains($value, '#REF!')) {
        $worksheet = $definedName->getWorksheet();
        $scope = $worksheet instanceof Worksheet ? $worksheet : null;
        $spreadsheet->removeDefinedName($definedName->getName(), $scope);
    }
}
$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save($dst);
echo "OK\n";
