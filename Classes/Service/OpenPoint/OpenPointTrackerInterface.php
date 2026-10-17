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
use Netresearch\NrMcpAgent\Enum\DenyReason;

/**
 * Records and closes open points from the chat's own card decisions
 * (nr-llm ADR-214, item 9; ADR-022).
 */
interface OpenPointTrackerInterface
{
    /**
     * The target of the card the recorded decision was taken on: read before
     * the decision goes to nr-llm, from the view whose digest is the
     * decision's, so the key is what the card showed.
     */
    public function cardTarget(Conversation $conversation, ?WaitingRunView $view): ?OpenPointTarget;

    /**
     * After nr-llm has accepted the decision: "Überspringen" records an open
     * point, an approved write that came back applied closes the open points
     * on its record and fields. Nothing else records or closes one.
     */
    public function settle(Conversation $conversation, bool $approved, ?DenyReason $reason, ?OpenPointTarget $target, AgentRunResult $result): void;

    /**
     * The system prompt's list of the points the conversation's process left
     * open on its page, or '' when there are none: record identities and
     * field names only.
     */
    public function promptFor(Conversation $conversation): string;
}
