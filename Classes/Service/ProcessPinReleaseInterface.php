<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;

/**
 * Releases the process pin of a guided process the editor ended (ADR-023;
 * nr-llm ADR-214, item 6).
 *
 * {@see ChatService} implements it with nr-llm's
 * `AgentRuntimeInterface::endProcess()` (nr-llm ADR-228) for the
 * conversation's last run. A failure there is logged; the chat has ended the
 * process either way.
 */
interface ProcessPinReleaseInterface
{
    /**
     * @param Conversation $before the conversation as it was before the tour ended: its run and skill
     */
    public function release(Conversation $before): void;
}
