<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

use Netresearch\NrMcpAgent\Enum\HiddenReason;
use Throwable;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * What the list "Fehlende Alt-Texte" shows (ADR-026), built from the scan:
 * the tabs, the groups and rows of each, their labels in the editor's
 * backend language, the thumbnail, and the link that opens the page or the
 * record's detail page.
 *
 * Tabs: "pages" first, then one per record type with open hits, by label.
 * Each tab pages its hits on its own; `$type` and `$page` name the tab that
 * is open and its page, every other tab shows its first page.
 *
 * Labels come from the TCA, as editors know them from the backend: the
 * content element type's label, the table's title, the field's label. Titles,
 * headers and file names are editor input and are rendered escaped.
 */
class MissingAltTextList
{
    /** Hits per page of a tab. */
    public const PAGE_SIZE = 50;

    /** The list's route: the module nr_mcp_agent_chat's sub-route. */
    public const ROUTE = 'nr_mcp_agent_chat.missingAltTexts';

    private const LL = 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_alttext.xlf:';

    /** @var array<string, string> */
    private array $languageCodes = [];

    public function __construct(
        private readonly MissingAltTextScanner $scanner,
        private readonly SiteFinder $siteFinder,
        private readonly ResourceFactory $resourceFactory,
        private readonly UriBuilder $uriBuilder,
    ) {}

    /**
     * The list's URL, optionally with a tab and its page.
     *
     * @param array<string, int|string> $parameters
     */
    public function uri(array $parameters = []): string
    {
        return (string) $this->uriBuilder->buildUriFromRoute(self::ROUTE, $parameters);
    }

    /**
     * The numbers the dashboard shows: open hits, the pages and the records
     * they lie on.
     *
     * @return array{count: int, pages: int, records: int}
     */
    public function summary(): array
    {
        $scan = $this->scanner->scanForCurrentUser();

        return ['count' => $scan->count(), 'pages' => $scan->pageCount(), 'records' => $scan->recordCount()];
    }

    /**
     * @return array{count: int, title: string, tabs: list<array<string, mixed>>}
     */
    public function build(string $type, int $page, int $pageSize = self::PAGE_SIZE): array
    {
        $scan   = $this->scanner->scanForCurrentUser();
        $counts = $scan->countsByType();
        $labels = [MissingAltTextScan::PAGES => $this->text('tab.pages')];
        foreach (array_keys($counts) as $recordType) {
            if ($recordType !== MissingAltTextScan::PAGES) {
                $labels[$recordType] = $this->tableLabel($recordType);
            }
        }

        $recordTypes = array_diff_key($labels, [MissingAltTextScan::PAGES => true]);
        uasort($recordTypes, strnatcasecmp(...));
        $order  = [MissingAltTextScan::PAGES => $labels[MissingAltTextScan::PAGES], ...$recordTypes];
        $active = array_key_exists($type, $order) ? $type : MissingAltTextScan::PAGES;

        $tabs = [];
        foreach ($order as $tabType => $label) {
            $tabs[] = $this->tab($scan->ofType($tabType), $tabType, $label, $tabType === $active, $tabType === $active ? $page : 1, $pageSize);
        }

        return ['count' => $scan->count(), 'title' => $this->title($scan->count()), 'tabs' => $tabs];
    }

    /**
     * "{n} fehlende Alt-Texte", singular for one, without a number for none.
     */
    public function title(int $count): string
    {
        return match (true) {
            $count === 0 => $this->text('title.none'),
            $count === 1 => $this->text('title.one'),
            default      => sprintf($this->text('title.other'), $count),
        };
    }

    /**
     * @param list<MissingAltText> $hits
     *
     * @return array<string, mixed>
     */
    private function tab(array $hits, string $type, string $label, bool $active, int $page, int $pageSize): array
    {
        $pageSize  = max(1, $pageSize);
        $pageCount = max(1, (int) ceil(count($hits) / $pageSize));
        $page      = min(max(1, $page), $pageCount);
        // Consecutive hits of one page or record form a group; a group cut by
        // a page break starts the next page again with its heading.
        $heads    = [];
        $rows     = [];
        $groupKey = null;
        foreach (array_slice($hits, ($page - 1) * $pageSize, $pageSize) as $hit) {
            $key = $hit->type . ':' . $hit->groupUid;
            if ($key !== $groupKey) {
                $groupKey = $key;
                $heads[]  = [
                    'title'  => $hit->groupTitle !== '' ? $hit->groupTitle : BackendUtility::getNoRecordTitle(),
                    'folder' => $hit->type !== MissingAltTextScan::PAGES && $hit->pageTitle !== '' ? sprintf($this->text('group.folder'), $hit->pageTitle) : '',
                ];
                $rows[] = [];
            }

            $rows[count($rows) - 1][] = $this->row($hit);
        }

        $groups = [];
        foreach ($heads as $index => $head) {
            $groups[] = [...$head, 'rows' => $rows[$index] ?? []];
        }

        return [
            'type'       => $type,
            'id'         => preg_replace('/[^a-z0-9_-]/i', '_', $type) ?? $type,
            'label'      => $label,
            'count'      => count($hits),
            'active'     => $active,
            'column'     => $this->text($type === MissingAltTextScan::PAGES ? 'column.element' : 'column.field'),
            'groups'     => $groups,
            'empty'      => sprintf($this->text('empty.tab'), $label),
            'caption'    => sprintf($this->text('table.caption'), $label),
            'pagination' => $pageCount > 1 ? [
                'label'    => sprintf($this->text('pagination.label'), $label),
                'status'   => sprintf($this->text('pagination.status'), $page, $pageCount),
                'previous' => $page > 1 ? $this->uri(['tab' => $type, 'page' => $page - 1]) : '',
                'next'     => $page < $pageCount ? $this->uri(['tab' => $type, 'page' => $page + 1]) : '',
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(MissingAltText $hit): array
    {
        $isPages = $hit->type === MissingAltTextScan::PAGES;
        $action  = $isPages ? $this->pageUri($hit) : $this->recordUri($hit);
        $label   = $isPages ? $this->text('action.openPage') : $this->text('action.openDetail');

        return [
            'referenceUid' => $hit->referenceUid,
            'fileName'     => $hit->fileName,
            'image'        => $this->thumbnail($hit->referenceUid),
            'label'        => $isPages ? $this->elementLabel($hit) : $this->fieldLabel($hit->table, $hit->field),
            'hidden'       => $hit->hiddenReason === null ? '' : sprintf($this->text('hidden.hint'), $this->reason($hit->hiddenReason, $hit->hiddenDate)),
            'language'     => $this->languageCode($hit->pageUid, $hit->languageUid),
            'action'       => $action === null ? null : [
                'uri'   => $action,
                'label' => $label,
                'name'  => sprintf($this->text('action.context'), $label . ': ' . $hit->fileName),
            ],
        ];
    }

    /**
     * "{type} – “{header}”" for a content element; "Page properties: {field}"
     * for an image of the page itself.
     */
    private function elementLabel(MissingAltText $hit): string
    {
        if ($hit->elementUid === 0) {
            return sprintf($this->text('element.pageProperty'), $this->fieldLabel($hit->table, $hit->field));
        }

        $typeLabel = $this->contentTypeLabel($hit->elementType);
        if ($typeLabel === '') {
            $typeLabel = $this->text('element.unknownType');
        }

        $header = trim($hit->elementHeader);

        return $header === '' ? $typeLabel : sprintf($this->text('element.withHeader'), $typeLabel, $header);
    }

    /**
     * The label of the content element type, as the type selector and the
     * New Content Element Wizard show it; read from the TCA items, the same
     * in 13.4 and 14.3.
     */
    private function contentTypeLabel(string $type): string
    {
        $tca     = $GLOBALS['TCA'] ?? null;
        $config  = is_array($tca) ? ($tca['tt_content'] ?? null) : null;
        $columns = is_array($config) ? ($config['columns'] ?? null) : null;
        $column  = is_array($columns) ? ($columns['CType'] ?? null) : null;
        $items   = is_array($column) && is_array($column['config'] ?? null) ? ($column['config']['items'] ?? null) : null;
        foreach (is_array($items) ? $items : [] as $item) {
            $value = is_array($item) ? ($item['value'] ?? null) : null;
            if (is_array($item) && is_scalar($value) && (string) $value === $type && is_string($item['label'] ?? null)) {
                return trim($this->languageService()?->sL($item['label']) ?? '');
            }
        }

        return '';
    }

    private function fieldLabel(string $table, string $field): string
    {
        $tca     = $GLOBALS['TCA'] ?? null;
        $config  = is_array($tca) ? ($tca[$table] ?? null) : null;
        $columns = is_array($config) ? ($config['columns'] ?? null) : null;
        $column  = is_array($columns) ? ($columns[$field] ?? null) : null;
        $label   = is_array($column) && is_string($column['label'] ?? null) ? trim($this->languageService()?->sL($column['label']) ?? '') : '';

        return $label !== '' ? $label : $this->text('field.unknown');
    }

    private function tableLabel(string $table): string
    {
        $tca    = $GLOBALS['TCA'] ?? null;
        $config = is_array($tca) ? ($tca[$table] ?? null) : null;
        $ctrl   = is_array($config) ? ($config['ctrl'] ?? null) : null;
        $label  = is_array($ctrl) && is_string($ctrl['title'] ?? null) ? trim($this->languageService()?->sL($ctrl['title']) ?? '') : '';

        return $label !== '' ? $label : $table;
    }

    private function reason(HiddenReason $reason, int $date): string
    {
        $text = $this->text('hidden.' . $reason->value);

        return $date > 0 ? sprintf($text, BackendUtility::date($date)) : $text;
    }

    /**
     * The language's code from the site configuration ("DE", "EN"); empty
     * for a page outside every site.
     */
    private function languageCode(int $pageUid, int $languageUid): string
    {
        $key = $pageUid . ':' . $languageUid;
        if (!array_key_exists($key, $this->languageCodes)) {
            try {
                $code = $this->siteFinder->getSiteByPageId($pageUid)->getLanguageById($languageUid)->getLocale()->getLanguageCode();
            } catch (Throwable) {
                $code = '';
            }

            $this->languageCodes[$key] = strtoupper($code);
        }

        return $this->languageCodes[$key];
    }

    /**
     * The reference for the thumbnail, or null when its file is not there:
     * a missing file would only show a broken image.
     */
    private function thumbnail(int $referenceUid): ?FileReference
    {
        try {
            $reference = $this->resourceFactory->getFileReferenceObject($referenceUid);

            return $reference->getOriginalFile()->exists() ? $reference : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The page in the hit's language, built like "View" in the page module.
     */
    private function pageUri(MissingAltText $hit): ?string
    {
        try {
            $uri = PreviewUriBuilder::create($hit->pageUid)
                ->withRootLine(BackendUtility::BEgetRootLine($hit->pageUid))
                ->withLanguage($hit->languageUid)
                ->withSection($hit->elementUid > 0 ? '#c' . $hit->elementUid : '')
                ->buildUri();
        } catch (Throwable) {
            return null;
        }

        return $uri === null ? null : (string) $uri;
    }

    /**
     * The record's detail page through the table's preview configuration
     * (Page TSconfig TCEMAIN.preview), like "View" in the list module, which
     * builds it the same way; null when the table has none: the core then
     * finds no previewable page.
     */
    private function recordUri(MissingAltText $hit): ?string
    {
        try {
            $uri = PreviewUriBuilder::createForRecordPreview($hit->type, $hit->groupUid, $hit->pageUid)->buildUri();
        } catch (Throwable) {
            return null;
        }

        return $uri === null ? null : (string) $uri;
    }

    private function text(string $key): string
    {
        $text = $this->languageService()?->sL(self::LL . $key) ?? '';

        return $text !== '' ? $text : $key;
    }

    private function languageService(): ?LanguageService
    {
        $language = $GLOBALS['LANG'] ?? null;

        return $language instanceof LanguageService ? $language : null;
    }
}
