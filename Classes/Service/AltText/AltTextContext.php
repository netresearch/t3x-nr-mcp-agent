<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

use Netresearch\NrMcpAgent\Domain\Repository\ImageReferenceRepository;
use Throwable;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * What the model is told about the image use a conversation is about
 * (ADR-026), read from the database on every turn with the conversation
 * owner's rights, never taken from the browser: the reference to write,
 * the file, the language the alt text is written in, the page or the
 * record, the content element or the field, the element's text and the
 * elements around it on the page, or the record's text fields.
 *
 * Titles and texts are editor content: the block is fenced, and the system
 * prompt (AltTextFlowPrompt) tells the model it is data, not instructions.
 * No uid other than the reference's goes out: the writer needs that one.
 */
class AltTextContext
{
    /** How long a text excerpt may be. */
    private const EXCERPT = 600;

    private const NEIGHBOUR_EXCERPT = 200;

    /** How many elements before and after the image's element are named. */
    private const NEIGHBOURS = 2;

    /** How many text fields of a record are named. */
    private const RECORD_FIELDS = 4;

    public function __construct(
        private readonly MissingAltTextList $list,
        private readonly ImageReferenceRepository $rows,
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * The fenced context block for an open hit; `$imageAttached` says
     * whether the scaled image follows it, `$mayMarkDecorative` whether the
     * editor may set the decorative mark.
     */
    public function describe(MissingAltText $hit, bool $imageAttached, bool $mayMarkDecorative = true): string
    {
        $card  = $this->list->describe($hit);
        $lines = [
            sprintf('Reference to write (pass it as "reference"): %d', $hit->referenceUid),
            sprintf('File name: %s', $hit->fileName),
            sprintf('Language of this use, write the alt text in it: %s', $this->languageName($hit)),
        ];

        if ($hit->type === MissingAltTextScan::PAGES) {
            $page        = $this->rows->findLive('pages', $hit->pageUid, true) ?? [];
            $lines[]     = sprintf('Page: "%s"', $hit->pageTitle);
            $description = $this->excerpt($page['description'] ?? null, self::EXCERPT);
            if ($description !== '') {
                $lines[] = sprintf('Page description: "%s"', $description);
            }

            $lines = [...$lines, ...$this->element($hit, $card['part'])];
        } else {
            $lines[] = sprintf('Record: %s "%s"', $card['subjectType'], $card['subject']);
            $lines[] = sprintf('Image field: %s', $card['part']);
            $lines   = [...$lines, ...$this->recordTexts($hit)];
        }

        $lines[] = $imageAttached
            ? 'The image itself (a scaled copy, cropped as on the website) follows this block.'
            : 'The image itself is NOT attached: propose from the context above and the file name only.';
        if (!$mayMarkDecorative) {
            $lines[] = 'The editor may not mark this image as decorative: do not propose the mark and do not call set_file_reference_decorative; propose an alt text.';
        }

        return "<image_context>\n" . str_replace(['<image_context>', '</image_context>'], '', implode("\n", $lines)) . "\n</image_context>";
    }

    /**
     * The content element the image belongs to and the elements around it.
     *
     * @return list<string>
     */
    private function element(MissingAltText $hit, string $label): array
    {
        if ($hit->elementUid === 0) {
            return [sprintf('Where: %s', $label)];
        }

        $element = $this->rows->findLive('tt_content', $hit->elementUid, true) ?? [];
        $lines   = [sprintf('Content element: %s', $label)];
        $text    = $this->excerpt($element['bodytext'] ?? null, self::EXCERPT);
        if ($text !== '') {
            $lines[] = sprintf('Text of the element: "%s"', $text);
        }

        $neighbours = $this->rows->neighbourContent(
            $this->int($element['pid'] ?? 0),
            $this->int($element['colPos'] ?? 0),
            $this->int($element['sys_language_uid'] ?? 0),
            $this->int($element['sorting'] ?? 0),
            $hit->elementUid,
            self::NEIGHBOURS,
        );
        foreach (['before' => 'Content before it', 'after' => 'Content after it'] as $side => $heading) {
            foreach ($neighbours[$side] as $row) {
                $header  = $this->excerpt($row['header'] ?? null, self::NEIGHBOUR_EXCERPT);
                $body    = $this->excerpt($row['bodytext'] ?? null, self::NEIGHBOUR_EXCERPT);
                $lines[] = sprintf('%s: %s', $heading, trim(($header !== '' ? '"' . $header . '" ' : '') . $body));
            }
        }

        return $lines;
    }

    /**
     * The record's title and its longer text fields (TCA type `text`), the
     * way an editor reads the record: a news article's teaser and text.
     *
     * @return list<string>
     */
    private function recordTexts(MissingAltText $hit): array
    {
        $record  = $this->rows->findLive($hit->type, $hit->groupUid, false) ?? [];
        $tca     = $GLOBALS['TCA'] ?? null;
        $columns = is_array($tca) && is_array($tca[$hit->type] ?? null) && is_array($tca[$hit->type]['columns'] ?? null) ? $tca[$hit->type]['columns'] : [];
        $lines   = [];
        foreach ($columns as $name => $column) {
            $config = is_array($column) ? ($column['config'] ?? null) : null;
            if (!is_array($config) || ($config['type'] ?? null) !== 'text') {
                continue;
            }

            $text = $this->excerpt($record[$name] ?? null, self::EXCERPT);
            if ($text !== '') {
                $lines[] = sprintf('%s: "%s"', (string) $name, $text);
            }

            if (count($lines) >= self::RECORD_FIELDS) {
                break;
            }
        }

        return $lines;
    }

    /** "Deutsch (de)", from the site of the hit's page. */
    private function languageName(MissingAltText $hit): string
    {
        try {
            $language = $this->siteFinder->getSiteByPageId($hit->pageUid)->getLanguageById(max(0, $hit->languageUid));

            return sprintf('%s (%s)', $language->getTitle(), $language->getLocale()->getLanguageCode());
        } catch (Throwable) {
            return 'the default language of the site';
        }
    }

    private function excerpt(mixed $value, int $length): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ' ', $text)), ENT_QUOTES | ENT_HTML5)));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
