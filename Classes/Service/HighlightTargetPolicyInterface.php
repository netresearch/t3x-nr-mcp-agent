<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;

/**
 * Which element a guided process may highlight in a conversation (ADR-020,
 * ADR-023).
 *
 * nr-llm ADR-214 (item 9) limits a highlight to the targets the run
 * registered from its invocation's subject record. nr-llm cannot start a run
 * with an invocation yet; until then {@see ConversationPageHighlightPolicy}
 * keeps the chat's rule — an element on the conversation's page — and an
 * implementation reading the run's registered targets replaces it once the
 * invocation exists.
 */
interface HighlightTargetPolicyInterface
{
    /**
     * @param int $pid the page the element is on, as the highlight tool read it
     */
    public function allows(Conversation $conversation, string $table, int $uid, int $pid): bool;
}
