<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\RunStateRepository;
use Netresearch\NrMcpAgent\Service\OpenPoint\OpenPointTarget;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

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
 *
 * A run that pauses on a proposal of a running process and reported neither
 * progress nor highlight gets both from the proposal (amendment of
 * 2026-10-31): the highlight is the record or page property the proposal
 * writes, under the same checks as the highlight tool, and the point moves
 * on by one when the editor's last decision settled the point before, or
 * when the overview (point 0) is behind. What the model reported always wins.
 */
readonly class GuidedStateLinker
{
    public function __construct(
        private RunStateRepository $runState,
        private ?HighlightTargetPolicyInterface $highlightPolicy = null,
    ) {}

    /**
     * @param bool                 $pausedOnProposal whether the run paused on a proposal, a write waiting for approval
     * @param OpenPointTarget|null $proposal         the record that proposal writes, when its tool names one
     */
    public function absorb(Conversation $conversation, string $runUuid, bool $pausedOnProposal = false, ?OpenPointTarget $proposal = null): void
    {
        $state = $runUuid !== '' ? $this->runState->find($runUuid, $conversation->getBeUser()) : null;
        if ($state === null && !$pausedOnProposal) {
            return;
        }

        $current  = $conversation->getGuidedState();
        $settled  = $conversation->isGuidedPointSettled();
        $reported = $this->progress($state['progress'] ?? null);
        $progress = $reported ?? $current['progress'];
        $running  = $progress !== null && !$progress['completed'];

        $derivedPoint = $reported === null && $pausedOnProposal && $running && ($settled || $progress['current'] === 0);
        if ($derivedPoint) {
            $progress['current'] = min($progress['current'] + 1, $progress['total']);
        }

        // The model named the point itself, or the point moved on: either
        // way, the decision before has been accounted for.
        if ($reported !== null || $derivedPoint) {
            $settled = false;
        }

        $named   = $state['highlight'] ?? null;
        $derived = $named === null && $proposal instanceof OpenPointTarget && $pausedOnProposal && $running
            ? $this->proposalHighlight($proposal, $conversation)
            : null;
        $highlight = $this->nextHighlight($current, $progress, $named, $derived, $conversation);

        $completed = $progress['completed'] ?? false;
        $conversation->setGuidedState($progress, $completed ? null : $highlight, $settled && !$completed);
        // Taken over: the conversation holds it now, and the table stays small.
        if ($state !== null) {
            $this->runState->delete($runUuid, $conversation->getBeUser());
        }
    }

    /**
     * What a reported highlight names wins, then what the proposal names. A
     * new point without either is about no element: the element of the
     * point before is no longer what it is about (NEXT-221). Both come from
     * this run's state, so the order of the two tool calls within one run
     * does not matter; a highlight set before an approval pause and progress
     * reported only after the resume would drop it (the tours report
     * progress first). A point moved on by a proposal that names nothing the
     * editor may see is the same case. A run on the same point keeps it.
     *
     * @param array{progress: array{label: string, current: int, total: int, completed: bool}|null, highlight: array{table: string, uid: int, field?: string}|null} $current
     * @param array{label: string, current: int, total: int, completed: bool}|null                                                                                  $progress
     * @param array<string, mixed>|null                                                                                                                             $named
     * @param array{table: string, uid: int, field?: string}|null                                                                                                   $derived
     *
     * @return array{table: string, uid: int, field?: string}|null
     */
    private function nextHighlight(array $current, ?array $progress, ?array $named, ?array $derived, Conversation $conversation): ?array
    {
        if ($named !== null) {
            return $this->highlight($named, $conversation);
        }

        if ($derived !== null || ($progress !== null && $progress['current'] !== ($current['progress']['current'] ?? null))) {
            return $derived;
        }

        return $current['highlight'];
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

    /**
     * What a proposal is about, under the checks `chat_highlight_element`
     * applies, for the conversation's owner: a content element the owner may
     * see, or ONE property of the conversation's own page the owner may edit.
     * Anything else — another page, several fields, a record of another
     * table, no owner to check for — names nothing.
     *
     * @return array{table: string, uid: int, field?: string}|null
     */
    private function proposalHighlight(OpenPointTarget $proposal, Conversation $conversation): ?array
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        $uid  = $user instanceof BackendUserAuthentication ? ($user->user['uid'] ?? null) : null;
        if (!$user instanceof BackendUserAuthentication || !is_numeric($uid) || (int) $uid !== $conversation->getBeUser()) {
            return null;
        }

        if ($proposal->table === 'pages') {
            $fields = $proposal->fields;
            $page   = $conversation->getViewContext()['pageId'];

            return count($fields) === 1 && $proposal->uid === $page && PageProperty::isAllowedField($fields[0], $user)
                ? $this->highlight(['table' => 'pages', 'field' => $fields[0]], $conversation)
                : null;
        }

        if ($proposal->table !== 'tt_content') {
            return null;
        }

        $element = VisibleContentElement::resolve($user, $proposal->uid);

        return $element === null ? null : $this->highlight(['table' => 'tt_content', ...$element], $conversation);
    }
}
