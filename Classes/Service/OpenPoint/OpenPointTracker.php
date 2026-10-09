<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\OpenPoint;

use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\OpenPointRepository;
use Netresearch\NrMcpAgent\Enum\DenyReason;
use Netresearch\NrMcpAgent\Tool\ListOpenPointsTool;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The chat's side of open points (nr-llm ADR-214, item 9; ADR-022).
 *
 * - Recorded in exactly one case: the editor answered a card with
 *   "Überspringen" and nr-llm accepted the denial. "Andere Variante" records
 *   nothing, because a new proposal follows; a withdrawn proposal, a cancel
 *   and a refused decision record nothing either.
 * - The key is the conversation's process skill (its uid), the subject
 *   record (the conversation's page, until nr-llm can start a run with an
 *   invocation and a subject) and the target record and fields the card's
 *   pending write named. A write that creates its record names no target and
 *   records nothing; so does a conversation without a known skill uid or
 *   page.
 * - Closed only by an approved card write to the same record and field that
 *   came back applied ({@see AppliedWrite}).
 *
 * A "Überspringen" reaches the worker only for a card that offered it: the
 * request records a reason for a process card's write only (ADR-018).
 */
final readonly class OpenPointTracker implements OpenPointTrackerInterface
{
    public const SUBJECT_TABLE = 'pages';

    private const PROMPT_LIMIT = 20;

    public function __construct(
        private OpenPointRepository $repository,
        private OpenPointVisibility $visibility,
    ) {}

    public function cardTarget(Conversation $conversation, ?WaitingRunView $view): ?OpenPointTarget
    {
        if (!$view instanceof WaitingRunView
            || $view->mode !== WaitingRunView::MODE_APPROVAL
            || $view->turnDigest === null
            || $view->turnDigest !== $conversation->getApprovalTurnDigest()
        ) {
            return null;
        }

        return NrLlmCardTarget::of($view);
    }

    public function settle(Conversation $conversation, bool $approved, ?DenyReason $reason, ?OpenPointTarget $target, AgentRunResult $result): void
    {
        if (!$target instanceof OpenPointTarget) {
            return;
        }

        if ($approved) {
            if (AppliedWrite::inSteps($result->steps)) {
                $this->repository->close($target);
            }

            return;
        }

        if ($reason !== DenyReason::Skip) {
            return;
        }

        $skillUid = $conversation->getSkillUid();
        $pageUid = $conversation->getViewContext()['pageId'];
        if ($skillUid <= 0 || $pageUid <= 0) {
            return;
        }

        $this->repository->record($skillUid, self::SUBJECT_TABLE, $pageUid, $target, $conversation->getBeUser(), $conversation->getUid());
    }

    public function promptFor(Conversation $conversation): string
    {
        $skillUid = $conversation->getSkillUid();
        $pageUid = $conversation->getViewContext()['pageId'];
        if ($skillUid <= 0 || $pageUid <= 0) {
            return '';
        }

        // Only for the owner as the current user, and only what that user may
        // see of the page — the same rule as the list tool.
        $user = $GLOBALS['BE_USER'] ?? null;
        $uid = $user instanceof BackendUserAuthentication ? ($user->user['uid'] ?? null) : null;
        if (!$user instanceof BackendUserAuthentication || !is_numeric($uid) || (int) $uid !== $conversation->getBeUser()) {
            return '';
        }

        $points = $this->visibility->forPage($user, $pageUid, $skillUid, self::PROMPT_LIMIT);
        if ($points === null || $points === []) {
            return '';
        }

        $lines = [];
        foreach ($points as $point) {
            $lines[] = sprintf(
                '- %s %d%s',
                $point['targetTable'],
                $point['targetUid'],
                $point['field'] !== '' ? ', field ' . $point['field'] : ' (the record as a whole)',
            );
        }

        return 'Open points: earlier runs of this process on page ' . $pageUid . ' proposed changes the user skipped.'
            . ' They stay open until a change to the same record and field is applied. Offer them again where they'
            . ' still apply; ' . ListOpenPointsTool::NAME . " lists them.\n" . implode("\n", $lines);
    }
}
