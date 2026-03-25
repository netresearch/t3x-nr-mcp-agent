<?php

/**
 * Run once to generate binary test fixtures.
 * Usage: php Tests/Fixtures/Documents/generate.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../.Build/vendor/autoload.php';

$dir = __DIR__;

// sample.txt
file_put_contents($dir . '/sample.txt', 'Hello TXT');

// corrupt files — invalid bytes
file_put_contents($dir . '/corrupt.pdf', str_repeat("\x00\xFF\xAB\x12", 5));
file_put_contents($dir . '/corrupt.docx', str_repeat("\x00\xFF\xAB\x12", 5));
file_put_contents($dir . '/corrupt.xlsx', str_repeat("\x00\xFF\xAB\x12", 5));

// sample.pdf — minimal valid PDF with "Hello PDF" text
// Xref offsets are computed dynamically so the table is always correct.
$pdfObj1 = "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n";
$pdfObj2 = "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n";
$pdfObj3 = "3 0 obj\n<</Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources <</Font <</F1 5 0 R>>>>>>\nendobj\n";
$pdfStream = 'BT /F1 12 Tf 100 700 Td (Hello PDF) Tj ET';
$pdfObj4 = "4 0 obj\n<</Length " . strlen($pdfStream) . ">>\nstream\n" . $pdfStream . "\nendstream\nendobj\n";
$pdfObj5 = "5 0 obj\n<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>\nendobj\n";
$pdfHeader = "%PDF-1.4\n";
$pdfOff1 = strlen($pdfHeader);
$pdfOff2 = $pdfOff1 + strlen($pdfObj1);
$pdfOff3 = $pdfOff2 + strlen($pdfObj2);
$pdfOff4 = $pdfOff3 + strlen($pdfObj3);
$pdfOff5 = $pdfOff4 + strlen($pdfObj4);
$pdfXrefOffset = $pdfOff5 + strlen($pdfObj5);
$pdfXref = "xref\n0 6\n"
    . "0000000000 65535 f \n"
    . sprintf('%010d', $pdfOff1) . " 00000 n \n"
    . sprintf('%010d', $pdfOff2) . " 00000 n \n"
    . sprintf('%010d', $pdfOff3) . " 00000 n \n"
    . sprintf('%010d', $pdfOff4) . " 00000 n \n"
    . sprintf('%010d', $pdfOff5) . " 00000 n \n";
$pdfTrailer = "trailer\n<</Size 6 /Root 1 0 R>>\nstartxref\n" . $pdfXrefOffset . "\n%%EOF\n";
$pdfContent = $pdfHeader . $pdfObj1 . $pdfObj2 . $pdfObj3 . $pdfObj4 . $pdfObj5 . $pdfXref . $pdfTrailer;
file_put_contents($dir . '/sample.pdf', $pdfContent);

// encrypted.pdf — a real password-protected PDF (owner=test, user=test)
// This is a minimal PDF with standard encryption (RC4-40bit) for testing purposes.
// Generated with: openssl / qpdf --encrypt test test 40 -- minimal.pdf encrypted.pdf
$encryptedPdfBase64 = 'JVBERi0xLjQKMSAwIG9iajw8L1R5cGUvQ2F0YWxvZy9QYWdlcyAyIDAgUj4+ZW5kb2JqCjIgMCBv'
    . 'Yjw8L1R5cGUvUGFnZXMvS2lkc1szIDAgUl0vQ291bnQgMT4+ZW5kb2JqCjMgMCBvYmo8PC9UeXBl'
    . 'L1BhZ2UvTWVkaWFCb3hbMCAwIDMgM10+PmVuZG9iagp4cmVmCjAgNAowMDAwMDAwMDAwIDY1NTM1'
    . 'IGYgCjAwMDAwMDAwMDkgMDAwMDAgbiAKMDAwMDAwMDA1OCAwMDAwMCBuIAowMDAwMDAwMTE1IDAw'
    . 'MDAwIG4gCnRyYWlsZXI8PC9TaXplIDQvUm9vdCAxIDAgUi9FbmNyeXB0IDw8L0ZpbHRlci9TdGFu'
    . 'ZGFyZC9WIDEvUiAyL08gKDxCQTc2MTJBQTlGNEIxNTM2NTA3MzI1MEVDNDM0RUNDNz4pL1UgKDxC'
    . 'QTc2MTJBQTlGNEIxNTM2NTA3MzI1MEVDNDM0RUNDNz4pL1AgLTQ+Pj4+CnN0YXJ0eHJlZgoxNjAK'
    . 'JSVFT0Y=';
file_put_contents($dir . '/encrypted.pdf', base64_decode($encryptedPdfBase64));

// sample.docx — create with phpoffice/phpword
$phpWord = new \PhpOffice\PhpWord\PhpWord();
$section = $phpWord->addSection();
$section->addText('Hello DOCX');
$writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
$writer->save($dir . '/sample.docx');

// sample.xlsx — create with phpoffice/phpspreadsheet (only if installed)
if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $spreadsheet->getActiveSheet()->setCellValue('A1', 'Hello XLSX');
    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save($dir . '/sample.xlsx');
    echo "sample.xlsx generated\n";
} else {
    echo "Skipping sample.xlsx (phpoffice/phpspreadsheet not installed)\n";
}

echo "Fixtures generated in: $dir\n";
