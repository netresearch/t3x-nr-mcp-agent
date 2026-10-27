<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;

/**
 * A highlight counts when the content element is on the conversation's page,
 * or when it is a property of that page itself (ADR-020, NEXT-221): a page is
 * "on" itself, so `pages` counts only with uid and pid both the
 * conversation's page. The highlight tool has already checked that the user
 * may see the element or edit the property.
 */
final readonly class ConversationPageHighlightPolicy implements HighlightTargetPolicyInterface
{
    public function allows(Conversation $conversation, string $table, int $uid, int $pid): bool
    {
        $page = $conversation->getViewContext()['pageId'];
        if ($page <= 0 || $uid <= 0 || $pid !== $page) {
            return false;
        }

        return $table === 'tt_content' || ($table === 'pages' && $uid === $page);
    }
}
