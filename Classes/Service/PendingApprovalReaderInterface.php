<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;

/**
 * Reads the pending tool call of a suspended run.
 *
 * A seam of this extension's own, for one reason: nr-llm's WaitingRunViewFactory
 * is final, so a test cannot double it, and building a real one means building
 * its three collaborators as well. Everything behind this interface is nr-llm's;
 * the interface exists so the chat service can be tested without it.
 */
interface PendingApprovalReaderInterface
{
    /**
     * The pending call as the approvals inbox describes it, or null when the run
     * does not exist, is not suspended, or the actor may not read it — the three
     * are deliberately indistinguishable from the outside.
     */
    public function read(AiActorContext $actor, string $runUuid): ?WaitingRunView;

    /**
     * The input schema a run waiting for the user's answer is suspended on
     * (nr-llm ADR-105), or null under the same conditions as read(), and when
     * the run is not an input pause or its schema is not an object of
     * properties. The view read() returns flattens labelled options away, and
     * the chat needs their labels for its buttons (ADR-018).
     *
     * @return array<string, mixed>|null
     */
    public function inputSchema(AiActorContext $actor, string $runUuid): ?array;
}
