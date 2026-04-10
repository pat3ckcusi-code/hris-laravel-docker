<?php
$zip='C:\xampp\htdocs\HRIS\storage\app\templates\PDS.xlsx';
$z=new ZipArchive();
if($z->open($zip)===true){
    $s = $z->getFromName('xl/workbook.xml');
    if($s){
        $xml = simplexml_load_string($s);
        foreach($xml->sheets->sheet as $sheet){
            echo (string)$sheet['name'] . "\n";
        }
    } else {
        echo "no workbook.xml\n";
    }
    $z->close();
} else {
    echo "FAILED\n";
}
