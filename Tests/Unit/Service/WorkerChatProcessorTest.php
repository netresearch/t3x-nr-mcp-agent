<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Domain\Repository\ChatWorkerRepository;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Service\ExecChatProcessor;
use Netresearch\NrMcpAgent\Service\Typo3CliBinaryResolver;
use Netresearch\NrMcpAgent\Service\WorkerChatProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WorkerChatProcessorTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function loads(): iterable
    {
        yield 'a free worker takes the turn' => [1, 1, false];
        yield 'more workers than turns' => [2, 1, false];
        yield 'no worker is free' => [0, 1, true];
        yield 'the free worker is spoken for' => [1, 2, true];
    }

    #[Test]
    #[DataProvider('loads')]
    public function aTurnWithoutAFreeWorkerStartsAProcessOfItsOwn(int $idle, int $waiting, bool $startsProcess): void
    {
        $workers = $this->createStub(ChatWorkerRepository::class);
        $workers->method('countIdle')->willReturn($idle);

        // The repository the fallback uses tells whether it ran: a resolver
        // that refuses marks the turn failed, through this row lookup.
        $conversations = $this->createMock(ConversationRepository::class);
        $conversations->method('countWaitingForWorker')->willReturn($waiting);
        $conversations->expects($startsProcess ? self::once() : self::never())->method('findByUid')->with(7)->willReturn(null);

        $resolver = $this->createStub(Typo3CliBinaryResolver::class);
        $resolver->method('resolve')->willThrowException(new \Netresearch\NrMcpAgent\Exception\Typo3CliBinaryNotFoundException('not here'));

        (new WorkerChatProcessor($workers, $conversations, new ExecChatProcessor($resolver, $conversations, new NullLogger())))->dispatch(7);
    }
}
