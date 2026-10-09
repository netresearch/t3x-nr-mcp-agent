<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;

/**
 * Whether a conversation runs a guided process (nr-llm ADR-214).
 *
 * Only a process run offers "Übernehmen / Andere Variante / Überspringen" on
 * the approval card of a write; every other card keeps approve and cancel
 * (ADR-018). Nothing in this extension implements it yet: a conversation
 * learns which skill it runs when it is started with one, and that is where
 * an implementation belongs. Without one, no conversation is a process run.
 */
interface ProcessRunDetectorInterface
{
    public function isProcessRun(Conversation $conversation): bool;
}
