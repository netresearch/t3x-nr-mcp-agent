<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;

/**
 * A highlight counts when the content element is on the conversation's page
 * (ADR-020). The highlight tool has already checked that the user may see it.
 */
final readonly class ConversationPageHighlightPolicy implements HighlightTargetPolicyInterface
{
    public function allows(Conversation $conversation, string $table, int $uid, int $pid): bool
    {
        $page = $conversation->getViewContext()['pageId'];

        return $table === 'tt_content' && $uid > 0 && $page > 0 && $pid === $page;
    }
}
