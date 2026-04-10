<?php
require __DIR__ . '/vendor/autoload.php';
$src = __DIR__ . '/storage/app/templates/csc-form-212-template.xlsx';
$dst = __DIR__ . '/storage/app/templates/csc-form-212-template-cleaned-test.xlsx';
$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($src);
foreach ($spreadsheet->getDefinedNames() as $definedName) {
    $value = (string) $definedName->getValue();
    if ($value === '' || str_contains($value, '#REF!')) {
        $worksheet = $definedName->getWorksheet();
        $scope = $worksheet instanceof \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet ? $worksheet : null;
        $spreadsheet->removeDefinedName($definedName->getName(), $scope);
    }
}
$writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save($dst);
echo "OK\n";
