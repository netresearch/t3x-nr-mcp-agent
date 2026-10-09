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
 * report ends the tour, so it also drops the highlight.
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

        $current = $conversation->getGuidedState();
        $progress = $this->progress($state['progress']) ?? $current['progress'];
        $highlight = $state['highlight'] !== null ? $this->highlight($state['highlight'], $conversation) : $current['highlight'];
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
        $label = $progress['label'] ?? null;
        $current = $progress['current'] ?? null;
        $total = $progress['total'] ?? null;

        return is_string($label) && is_int($current) && is_int($total)
            ? ['label' => $label, 'current' => $current, 'total' => $total, 'completed' => ($progress['completed'] ?? false) === true]
            : null;
    }

    /**
     * @param array<string, mixed> $highlight
     *
     * @return array{table: string, uid: int}|null
     */
    private function highlight(array $highlight, Conversation $conversation): ?array
    {
        $uid = $highlight['uid'] ?? null;
        $pid = $highlight['pid'] ?? null;
        $table = $highlight['table'] ?? null;

        return $table === 'tt_content' && is_int($uid) && is_int($pid)
            && ($this->highlightPolicy ?? new ConversationPageHighlightPolicy())->allows($conversation, $table, $uid, $pid)
            ? ['table' => 'tt_content', 'uid' => $uid]
            : null;
    }
}
