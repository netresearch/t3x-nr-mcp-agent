<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Document\Extractor;

use Netresearch\NrMcpAgent\Document\DocumentExtractorInterface;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;
use Throwable;

final class DocxExtractor implements DocumentExtractorInterface
{
    public function getSupportedMimeTypes(): array
    {
        return ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    }

    public function isAvailable(): bool
    {
        return class_exists(PhpWord::class);
    }

    public function validate(string $path): void
    {
        try {
            IOFactory::load($path);
        } catch (Throwable $e) {
            throw new RuntimeException('DOCX validation failed: ' . $e->getMessage(), 1743000040, $e);
        }
    }

    public function extract(string $path): string
    {
        try {
            $phpWord = IOFactory::load($path);
            return $this->extractText($phpWord);
        } catch (Throwable $e) {
            throw new RuntimeException('DOCX extraction failed: ' . $e->getMessage(), 1743000041, $e);
        }
    }

    private function extractText(PhpWord $phpWord): string
    {
        $parts = [];
        foreach ($phpWord->getSections() as $section) {
            $parts[] = $this->extractFromSection($section);
        }
        return trim(implode("\n", array_filter($parts)));
    }

    private function extractFromSection(Section $section): string
    {
        $parts = [];
        foreach ($section->getElements() as $element) {
            $text = $this->extractFromElement($element);
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        return implode("\n", $parts);
    }

    private function extractFromElement(AbstractElement $element): string
    {
        if ($element instanceof Text) {
            return $element->getText();
        }
        if ($element instanceof TextRun) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                $text = $this->extractFromElement($child);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            return implode('', $parts);
        }
        return '';
    }
}
