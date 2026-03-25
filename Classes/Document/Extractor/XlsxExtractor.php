<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Document\Extractor;

use Netresearch\NrMcpAgent\Document\DocumentExtractorInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;
use Throwable;

final class XlsxExtractor implements DocumentExtractorInterface
{
    public function getSupportedMimeTypes(): array
    {
        return ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    public function getSupportedFileExtensions(): array
    {
        return ['xlsx'];
    }

    public function isAvailable(): bool
    {
        return class_exists(Spreadsheet::class);
    }

    public function validate(string $path): void
    {
        try {
            IOFactory::load($path);
        } catch (Throwable $e) {
            throw new RuntimeException('XLSX validation failed: ' . $e->getMessage(), 1743000050, $e);
        }
    }

    public function extract(string $path): string
    {
        try {
            $spreadsheet = IOFactory::load($path);
            $parts = [];
            foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                $parts[] = $worksheet->getTitle();
                foreach ($worksheet->getRowIterator() as $row) {
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(true);
                    $cells = [];
                    foreach ($cellIterator as $cell) {
                        $value = $cell->getValue();
                        if ($value !== null && $value !== '') {
                            $cells[] = (string) $value;
                        }
                    }
                    if ($cells !== []) {
                        $parts[] = implode("\t", $cells);
                    }
                }
            }
            return trim(implode("\n", $parts));
        } catch (Throwable $e) {
            throw new RuntimeException('XLSX extraction failed: ' . $e->getMessage(), 1743000051, $e);
        }
    }
}
