<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\RunStateRepository;

/**
 * Takes what the guided-state tools reported for a run over into the
 * conversation the run belongs to (ADR-020).
 *
 * Called when a run returns — completed, paused or failed — because only then
 * does the chat know the run's uuid; the header and the highlight follow at
 * that moment, not while the run works. Only state the conversation's owner
 * wrote is read, and a highlight counts only when the policy allows it — the
 * element is on the conversation's page until nr-llm registers a run's
 * subject targets (HighlightTargetPolicyInterface): the tool checked that the
 * user may see the element, this checks that it belongs to what the
 * conversation is about. A completion
 * report ends the tour, so it also drops the highlight. A run that moves to
 * another point and names no element drops the highlight of the point
 * before; a run that stays on the point and names none keeps it.
 */
readonly class GuidedStateLinker
{
    public function __construct(
        private RunStateRepository $runState,
        private ?HighlightTargetPolicyInterface $highlightPolicy = null,
    ) {}

    public function absorb(Conversation $conversation, string $runUuid): void
    {
        $state = $runUuid !== '' ? $this->runState->find($runUuid, $conversation->getBeUser()) : null;
        if ($state === null) {
            return;
        }

        $current   = $conversation->getGuidedState();
        $reported  = $this->progress($state['progress']);
        $progress  = $reported ?? $current['progress'];
        $highlight = $current['highlight'];
        if ($state['highlight'] !== null) {
            $highlight = $this->highlight($state['highlight'], $conversation);
        } elseif ($reported !== null && $reported['current'] !== ($current['progress']['current'] ?? null)) {
            // A new point without a highlight is about no element: the
            // element of the point before is no longer what it is about
            // (NEXT-221). Both come from this run's state, so the order of
            // the two tool calls within the point does not matter.
            $highlight = null;
        }

        $conversation->setGuidedState($progress, ($progress['completed'] ?? false) ? null : $highlight);
        // Taken over: the conversation holds it now, and the table stays small.
        $this->runState->delete($runUuid, $conversation->getBeUser());
    }

    /**
     * @param array<string, mixed>|null $progress
     *
     * @return array{label: string, current: int, total: int, completed: bool}|null
     */
    private function progress(?array $progress): ?array
    {
        $label   = $progress['label'] ?? null;
        $current = $progress['current'] ?? null;
        $total   = $progress['total'] ?? null;

        return is_string($label) && is_int($current) && is_int($total)
            ? ['label' => $label, 'current' => $current, 'total' => $total, 'completed' => ($progress['completed'] ?? false) === true]
            : null;
    }

    /**
     * A content element keeps its uid; a page property (NEXT-221) gets the
     * conversation's page, which the tool cannot know — the property is
     * always one of the page the conversation is about.
     *
     * @param array<string, mixed> $highlight
     *
     * @return array{table: string, uid: int, field?: string}|null
     */
    private function highlight(array $highlight, Conversation $conversation): ?array
    {
        $policy = $this->highlightPolicy ?? new ConversationPageHighlightPolicy();
        $table  = $highlight['table'] ?? null;

        if ($table === 'pages') {
            $page  = $conversation->getViewContext()['pageId'];
            $field = $highlight['field'] ?? null;

            return is_string($field) && $policy->allows($conversation, 'pages', $page, $page)
                ? ['table' => 'pages', 'uid' => $page, 'field' => $field]
                : null;
        }

        $uid = $highlight['uid'] ?? null;
        $pid = $highlight['pid'] ?? null;

        return $table === 'tt_content' && is_int($uid) && is_int($pid)
            && $policy->allows($conversation, $table, $uid, $pid)
            ? ['table' => 'tt_content', 'uid' => $uid]
            : null;
    }
}
