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
 * nr-llm releases a pin on a completion report and has no call for an
 * aborted process yet; it comes with the process wiring of nr-llm ADR-214.
 * Nothing implements this interface until then, and ending a tour does the
 * guarded cancel and clears the conversation's skill only. An implementation
 * checks for nr-llm's call where it is installed.
 */
interface ProcessPinReleaseInterface
{
    /**
     * @param Conversation $before the conversation as it was before the tour ended: its run and skill
     */
    public function release(Conversation $before): void;
}
