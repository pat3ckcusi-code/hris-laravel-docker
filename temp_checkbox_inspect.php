<?php
require __DIR__ . '/vendor/autoload.php';
$path = __DIR__ . '/storage/app/templates/csc-form-212-template.xlsx';
$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
$sheet = $spreadsheet->getSheetByName('C1');
$ranges = [
  ['A10','S18'],
];
foreach ($ranges as [$start,$end]) {
  [$startCol,$startRow] = [preg_replace('/\d+/','',$start),(int)preg_replace('/\D+/','',$start)];
  [$endCol,$endRow] = [preg_replace('/\d+/','',$end),(int)preg_replace('/\D+/','',$end)];
  $startIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($startCol);
  $endIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($endCol);
  for ($r=$startRow; $r<=$endRow; $r++) {
    echo "ROW $r\n";
    for ($c=$startIndex; $c<=$endIndex; $c++) {
      $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
      $coord = $col.$r;
      $value = $sheet->getCell($coord)->getValue();
      $locked = $sheet->getStyle($coord)->getProtection()->getLocked();
      $text = str_replace(["\r","\n"],' ',(string)($value ?? ''));
      echo str_pad($coord,5) . ' | ' . str_pad($locked,13) . ' | ' . $text . "\n";
    }
    echo "----\n";
  }
}
