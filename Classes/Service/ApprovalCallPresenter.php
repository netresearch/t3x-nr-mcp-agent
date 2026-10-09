<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Service\Agent\Inbox\PendingCallView;
use Netresearch\NrMcpAgent\Service\OpenPoint\NrLlmCardTarget;
use Netresearch\NrMcpAgent\Service\OpenPoint\OpenPointTarget;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * The pending calls of an approval as the chat's card renders them.
 *
 * Two things are added to what nr-llm hands over, both for the editorial
 * rules the card follows (10, 14-16, 22, 26):
 *
 * - `actionLabel`: the tool's human name from its editor action declaration
 *   (nr-llm ADR-152), in the reader's language, or '' when the tool declares
 *   none. The card names the change and its button with it.
 * - `technicalDetails`: the identifiers nr-llm appends as the LAST preview
 *   line ("Technical details: …", nr-llm 0.39 and later), taken out of
 *   `previewLines` so the card can put them behind "Show technical details".
 *
 * What names the change, in this order:
 *
 * 1. The preview's first line, but only when nr-llm recognises it as one of
 *    the headings its previews open with ("Seite löschen", editorial rule
 *    16; nr-llm's ApprovalPreviewHeadings). It is per call and more
 *    specific than the tool's label. It becomes `actionLabel`, leaves
 *    `previewLines`, and `actionLabelFromPreview` says so. A first line
 *    that is a summary ("Page [10002] "Home" — 1 field(s):", nr-llm up to
 *    0.39) or a refusal ("Page not found or not permitted.") arrives with
 *    the preview not marked failed, and must never become the approve
 *    button, so position alone never decides.
 * 2. The tool's editor action label.
 * 3. Nothing ('') — the card's generic wording.
 *
 * Display only. The decision the card sends carries the run's turn digest and
 * nothing of this payload, and the preview lines nr-llm stored with the run —
 * the ones ADR-184 compares on resume — are read, never written.
 *
 * The technical line is recognised by nr-llm's own label, never by position
 * alone: nr-llm before 0.39 and tools outside nr-llm end their preview with
 * an ordinary line, and taking that away would hide part of what is being
 * decided.
 *
 * Preview lines are written in the language of the run's ACTING user (nr-llm
 * ADR-213), so the heading and the technical line are matched in that
 * language (`$previewLanguage`), and only the editor action label, which the
 * card itself renders, in the reader's. In this chat the two are the same
 * person — a run acts as the conversation's owner, and only the owner reads
 * the conversation — but the caller passes them separately all the same.
 */
final readonly class ApprovalCallPresenter
{
    /** nr-llm's label for the last preview line; `%s` holds the identifiers. */
    public const TECHNICAL_DETAILS_LABEL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:approvalPreview.technical.details';

    public function __construct(
        private ?EditorActionLabelsInterface $editorActionLabels = null,
        private ?PreviewHeadingRecogniserInterface $previewHeadings = null,
    ) {}

    /**
     * @param list<PendingCallView> $calls
     * @param ?LanguageService $language        the reader's, for the editor action label
     * @param ?LanguageService $previewLanguage the run's acting user's, the preview lines' language
     *
     * @return list<array{
     *     name: string,
     *     actionLabel: string,
     *     toolStillRegistered: bool,
     *     previewLines: list<string>,
     *     technicalDetails: string,
     *     previewFailed: bool,
     *     previewStale: bool,
     *     argumentsJson: string,
     *     actionLabelFromPreview: bool,
     *     affected: array{table: string, uid: int, fields: list<string>, tableLabel: string, fieldLabels: list<string>}|null,
     *     structured: list<array{field: string, current: string, proposed: string, measure: array{count: int, min: int, max: int}|null}>|null,
     * }>
     */
    public function present(array $calls, ?LanguageService $language, ?LanguageService $previewLanguage): array
    {
        $labelReferences = $this->editorActionLabels?->labelReferences() ?? [];
        $technicalLabel  = $this->resolve(self::TECHNICAL_DETAILS_LABEL, $previewLanguage);

        $presented = [];
        foreach ($calls as $call) {
            [$lines, $technical] = $call->previewFailed
                // A failed or withheld preview carries the reason in its lines.
                ? [$call->previewLines, '']
                : $this->splitTechnicalLine($call->previewLines, $technicalLabel);

            [$actionLabel, $lines, $fromPreview] = $this->nameTheChange(
                $this->resolve($labelReferences[$call->name] ?? '', $language),
                $lines,
                $call->previewFailed,
                $previewLanguage,
            );

            $presented[] = [
                'name'                => $call->name,
                'actionLabel'         => $actionLabel,
                'actionLabelFromPreview' => $fromPreview,
                'toolStillRegistered' => $call->toolStillRegistered,
                'previewLines'        => $lines,
                'technicalDetails'    => $technical,
                'previewFailed'       => $call->previewFailed,
                // nr-llm refuses an approved write whose record changed after
                // the preview and hands the run back with this flag. Without
                // it the card returns looking exactly as it did before the
                // click, and only the approvals module said why.
                'previewStale'        => $call->previewStale,
                'argumentsJson'       => $call->argumentsJson,
                // The proposal block of a process card (ADR-023): what the
                // write is about, from nr-llm's structured target, and its
                // current and proposed values where nr-llm structures them.
                'affected'            => $this->affected($call, $language),
                'structured'          => $call->previewFailed ? null : StructuredPreview::of($call),
            ];
        }

        return $presented;
    }

    /**
     * The record and fields the write names, from nr-llm's `pendingTarget`
     * (nr-llm PR 1024), with the table's and the fields' labels from the TCA in
     * the reader's language: schema labels, no record content. Null without a
     * target — a create, a tool that names none, an nr-llm before 0.41.
     *
     * @return array{table: string, uid: int, fields: list<string>, tableLabel: string, fieldLabels: list<string>}|null
     */
    private function affected(PendingCallView $call, ?LanguageService $language): ?array
    {
        $target = NrLlmCardTarget::ofCall($call);
        if (!$target instanceof OpenPointTarget) {
            return null;
        }

        return [
            'table'       => $target->table,
            'uid'         => $target->uid,
            'fields'      => $target->fields,
            'tableLabel'  => $this->tableLabel($target->table, $language),
            'fieldLabels' => $this->fieldLabels($target->table, $target->fields, $language),
        ];
    }

    /**
     * The conversation's proposal outcomes as the chat shows them (ADR-023):
     * where each happened, what became of it, and what it was about in the
     * reader's language — the fields' labels, else the change's name, and the
     * record.
     *
     * @param list<array{outcome: string, after: int, tool: string, table: string, uid: int, fields: list<string>}> $outcomes
     *
     * @return list<array{outcome: string, after: int, subject: string, record: string}>
     */
    public function presentOutcomes(array $outcomes, ?LanguageService $language): array
    {
        $labelReferences = $this->editorActionLabels?->labelReferences() ?? [];
        $presented = [];
        foreach ($outcomes as $entry) {
            $fields = $entry['table'] !== '' ? implode(', ', $this->fieldLabels($entry['table'], $entry['fields'], $language)) : '';
            $presented[] = [
                'outcome' => $entry['outcome'],
                'after'   => $entry['after'],
                'subject' => $fields !== '' ? $fields : $this->resolve($labelReferences[$entry['tool']] ?? '', $language),
                'record'  => $entry['table'] !== '' && $entry['uid'] > 0 ? $this->tableLabel($entry['table'], $language) . ' ' . $entry['uid'] : '',
            ];
        }

        return $presented;
    }

    private function tableLabel(string $table, ?LanguageService $language): string
    {
        $ctrl = $this->tca($table)['ctrl'] ?? null;
        $title = is_array($ctrl) && is_string($ctrl['title'] ?? null) ? $this->resolve($ctrl['title'], $language) : '';

        return $title !== '' ? $title : $table;
    }

    /**
     * @param list<string> $fields
     *
     * @return list<string>
     */
    private function fieldLabels(string $table, array $fields, ?LanguageService $language): array
    {
        $columns = $this->tca($table)['columns'] ?? null;
        $labels = [];
        foreach ($fields as $field) {
            $column = is_array($columns) ? ($columns[$field] ?? null) : null;
            $label = is_array($column) && is_string($column['label'] ?? null) ? $this->resolve($column['label'], $language) : '';
            $labels[] = $label !== '' ? $label : $field;
        }

        return $labels;
    }

    /**
     * @return array<mixed>
     */
    private function tca(string $table): array
    {
        $tca = $GLOBALS['TCA'] ?? null;
        $config = is_array($tca) ? ($tca[$table] ?? null) : null;

        return is_array($config) ? $config : [];
    }

    /**
     * The preview's first line when it is a heading, taken out of the lines;
     * otherwise the editor action label, which may be ''.
     *
     * @param list<string> $lines
     *
     * @return array{string, list<string>, bool} the label, the remaining lines, whether the label came from them
     */
    private function nameTheChange(string $editorActionLabel, array $lines, bool $previewFailed, ?LanguageService $previewLanguage): array
    {
        $first = $lines[0] ?? '';
        if ($previewFailed
            || !$previewLanguage instanceof LanguageService
            || $this->previewHeadings?->isHeading($first, $previewLanguage) !== true
        ) {
            return [$editorActionLabel, $lines, false];
        }

        return [trim($first), array_slice($lines, 1), true];
    }

    /**
     * @param list<string> $lines
     *
     * @return array{list<string>, string} the lines without the technical one, and its identifiers
     */
    private function splitTechnicalLine(array $lines, string $label): array
    {
        // A preview made of the technical line alone keeps it: moving it would
        // leave a card that shows nothing of what is decided.
        $details = count($lines) < 2 ? '' : $this->technicalDetailsOf($lines[array_key_last($lines)], $label);

        return $details === '' ? [$lines, ''] : [array_slice($lines, 0, -1), $details];
    }

    /**
     * The identifiers `$line` carries when it is the technical line, else ''.
     * A label without text before its placeholder would match every line, so
     * it matches none.
     */
    private function technicalDetailsOf(string $line, string $label): string
    {
        $placeholder = strpos($label, '%s');
        $prefix      = $placeholder === false ? '' : substr($label, 0, $placeholder);
        $suffix      = $placeholder === false ? '' : substr($label, $placeholder + 2);
        if ($prefix === '' || !str_starts_with($line, $prefix) || !str_ends_with($line, $suffix)) {
            return '';
        }

        return substr($line, strlen($prefix), strlen($line) - strlen($prefix) - strlen($suffix));
    }

    private function resolve(string $reference, ?LanguageService $language): string
    {
        if ($reference === '' || !$language instanceof LanguageService) {
            return '';
        }

        return trim($language->sL($reference));
    }
}
