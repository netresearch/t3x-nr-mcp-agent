<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * The warning before an image that carries content is marked as decorative
 * (the specification's case 2, ADR-026): "Dieses Bild gehört zum Abschnitt
 * „{Überschrift}“ …", for a record "zum {Datensatztyp} „{Titel}“ …". Empty
 * when the image belongs to neither: a content element without a header, an
 * image of the page itself.
 *
 * The section is the content element the image belongs to, followed through
 * inline fields as the list files a hit (a carousel item's image belongs to
 * its element); a record outside the pages is named by its type and title.
 */
class DecorativeWarning
{
    private const MAX_OWNER_DEPTH = 4;

    private const LL = 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_alttext.xlf:';

    public function __construct(
        private readonly ImageFieldDiscovery $discovery,
    ) {}

    public function of(WritableReference $reference, LanguageService $language): string
    {
        $subject = $this->subjectOf($reference->table, $reference->record);
        if ($subject === null) {
            return '';
        }

        [$table, $row] = $subject;
        if ($table === 'tt_content') {
            $header = trim(is_scalar($row['header'] ?? null) ? (string) $row['header'] : '');

            return $header === '' ? '' : sprintf($this->text($language, 'decorative.warning.section'), $header);
        }

        return sprintf($this->text($language, 'decorative.warning.record'), $this->tableLabel($language, $table), ReferenceWriteAccess::titleOf($table, $row));
    }

    /**
     * The content element or outer record the image belongs to; null for an
     * image of a page.
     *
     * @param array<string, mixed> $row
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function subjectOf(string $table, array $row): ?array
    {
        for ($depth = 0; $depth <= self::MAX_OWNER_DEPTH; ++$depth) {
            if ($table === 'pages') {
                return null;
            }

            if ($table === 'tt_content') {
                return [$table, $row];
            }

            $owner = $this->ownerOf($table, $row);
            if ($owner === null) {
                return [$table, $row];
            }

            [$table, $row] = $owner;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function ownerOf(string $table, array $row): ?array
    {
        foreach ($this->discovery->ownersOf($table) as $owner) {
            $uid = is_numeric($row[$owner->foreignField] ?? null) ? (int) $row[$owner->foreignField] : 0;
            if ($uid <= 0 || ($owner->foreignTableField !== '' && ($row[$owner->foreignTableField] ?? null) !== $owner->table)) {
                continue;
            }

            $ownerRow = BackendUtility::getRecord($owner->table, $uid);
            if (!is_array($ownerRow)) {
                return null;
            }

            $fields = [];
            foreach ($ownerRow as $name => $value) {
                $fields[(string) $name] = $value;
            }

            return [$owner->table, $fields];
        }

        return null;
    }

    private function tableLabel(LanguageService $language, string $table): string
    {
        $tca   = $GLOBALS['TCA'] ?? null;
        $ctrl  = is_array($tca) && is_array($tca[$table] ?? null) && is_array($tca[$table]['ctrl'] ?? null) ? $tca[$table]['ctrl'] : [];
        $label = is_string($ctrl['title'] ?? null) ? trim($language->sL($ctrl['title'])) : '';

        return $label !== '' ? $label : $table;
    }

    private function text(LanguageService $language, string $key): string
    {
        $text = $language->sL(self::LL . $key);

        return $text !== '' ? $text : $key;
    }
}
