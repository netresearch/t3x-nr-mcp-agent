<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Service\Agent\Inbox\PendingCallView;
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
 * Display only. The decision the card sends carries the run's turn digest and
 * nothing of this payload, and the preview lines nr-llm stored with the run —
 * the ones ADR-184 compares on resume — are read, never written.
 *
 * The technical line is recognised by nr-llm's own label in the reader's
 * language, never by position alone: nr-llm before 0.39 and tools outside
 * nr-llm end their preview with an ordinary line, and taking that away would
 * hide part of what is being decided. The preview is written in the language
 * of the run's acting user, who is the reader of their own conversation; where
 * the two differ the line does not match and stays visible as it is.
 */
final readonly class ApprovalCallPresenter
{
    /** nr-llm's label for the last preview line; `%s` holds the identifiers. */
    public const TECHNICAL_DETAILS_LABEL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:approvalPreview.technical.details';

    public function __construct(
        private ?EditorActionLabelsInterface $editorActionLabels = null,
    ) {}

    /**
     * @param list<PendingCallView> $calls
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
     * }>
     */
    public function present(array $calls, ?LanguageService $language): array
    {
        $labelReferences = $this->editorActionLabels?->labelReferences() ?? [];
        $technicalLabel  = $this->resolve(self::TECHNICAL_DETAILS_LABEL, $language);

        $presented = [];
        foreach ($calls as $call) {
            [$lines, $technical] = $call->previewFailed
                // A failed or withheld preview carries the reason in its lines.
                ? [$call->previewLines, '']
                : $this->splitTechnicalLine($call->previewLines, $technicalLabel);

            $presented[] = [
                'name'                => $call->name,
                'actionLabel'         => $this->resolve($labelReferences[$call->name] ?? '', $language),
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
            ];
        }

        return $presented;
    }

    /**
     * @param list<string> $lines
     *
     * @return array{list<string>, string} the lines without the technical one, and its identifiers
     */
    private function splitTechnicalLine(array $lines, string $label): array
    {
        $placeholder = strpos($label, '%s');
        if ($lines === [] || $placeholder === false) {
            return [$lines, ''];
        }

        $prefix = substr($label, 0, $placeholder);
        $suffix = substr($label, $placeholder + 2);
        $last   = $lines[array_key_last($lines)];
        if ($prefix === '' || !str_starts_with($last, $prefix) || !str_ends_with($last, $suffix)) {
            return [$lines, ''];
        }

        $details = substr($last, strlen($prefix), strlen($last) - strlen($prefix) - strlen($suffix));
        if ($details === '') {
            return [$lines, ''];
        }

        return [array_slice($lines, 0, -1), $details];
    }

    private function resolve(string $reference, ?LanguageService $language): string
    {
        if ($reference === '' || !$language instanceof LanguageService) {
            return '';
        }

        return trim($language->sL($reference));
    }
}
