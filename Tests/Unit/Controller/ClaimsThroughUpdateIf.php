<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Controller;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\TurnClaim;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * The claims that start a turn (claimTurn(), addWithinLimit()) answered by
 * the repository double's updateIf() and add(), as the test sets them, with
 * no limit: the limit is tested against the database
 * (Functional\Controller\ActiveConversationLimitTest).
 */
trait ClaimsThroughUpdateIf
{
    private function claimThroughUpdateIf(ConversationRepository&MockObject $repository): void
    {
        $repository->method('claimTurn')->willReturnCallback(
            static fn (Conversation $conversation, ConversationStatus $expected): TurnClaim => $repository->updateIf($conversation, $expected) ? TurnClaim::Claimed : TurnClaim::Lost,
        );
        $repository->method('addWithinLimit')->willReturnCallback(
            static fn (Conversation $conversation): int => $repository->add($conversation),
        );
    }
}
