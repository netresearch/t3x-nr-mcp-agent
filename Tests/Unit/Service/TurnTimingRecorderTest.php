<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Service\TurnTimingRecorder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class TurnTimingRecorderTest extends TestCase
{
    /** @var list<array{int, array<string, int>}> */
    private array $writes = [];

    private function recorder(): TurnTimingRecorder
    {
        $repository = $this->createStub(ConversationRepository::class);
        $repository->method('updateTurnTiming')->willReturnCallback(function (int $uid, string $json): void {
            /** @var array<string, int> $marks */
            $marks          = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $this->writes[] = [$uid, $marks];
        });

        return new TurnTimingRecorder($repository);
    }

    /**
     * @param array<string, int> $marks
     */
    private function conversation(array $marks = []): Conversation
    {
        return Conversation::fromRow(['uid' => 7, 'be_user' => 1, 'turn_timing' => json_encode($marks, JSON_THROW_ON_ERROR)]);
    }

    #[Test]
    public function aDispatchWritesTheRequestAndTheHandOverAndStartsANewList(): void
    {
        $_SERVER['REQUEST_TIME_FLOAT'] = 1000.5;

        $before = (int) (microtime(true) * 1000);
        $this->recorder()->dispatched(7);

        self::assertSame(7, $this->writes[0][0]);
        self::assertSame(['request', 'dispatched'], array_keys($this->writes[0][1]));
        self::assertSame(1000500, $this->writes[0][1]['request']);
        self::assertGreaterThanOrEqual($before, $this->writes[0][1]['dispatched']);
    }

    #[Test]
    public function theProcessAddsToTheStampsTheRequestLeft(): void
    {
        $_SERVER['REQUEST_TIME_FLOAT'] = 1001.25;

        $recorder = $this->recorder();
        $recorder->begin($this->conversation(['request' => 11, 'dispatched' => 12]));
        $recorder->mark('runBegin');
        $recorder->finish();

        [$uid, $marks] = $this->writes[0];
        self::assertSame(7, $uid);
        self::assertSame(['request', 'dispatched', 'processStart', 'claimed', 'runBegin', 'stored'], array_keys($marks));
        self::assertSame(11, $marks['request']);
        self::assertSame(12, $marks['dispatched']);
        self::assertSame(1001250, $marks['processStart']);
    }

    #[Test]
    public function aWorkerThatRunsManyTurnsHasNoProcessStart(): void
    {
        $recorder = $this->recorder();
        $recorder->begin($this->conversation(), startedForTurn: false);
        $recorder->finish();

        self::assertSame(['claimed', 'stored'], array_keys($this->writes[0][1]));
    }

    #[Test]
    public function theFirstStampOfAPhaseCounts(): void
    {
        $recorder = $this->recorder();
        $recorder->begin($this->conversation(['runBegin' => 5]));
        $recorder->mark('runBegin');
        $recorder->finish();

        self::assertSame(5, $this->writes[0][1]['runBegin']);
    }

    #[Test]
    public function aNewTurnInTheSameProcessDoesNotInheritTheLastOne(): void
    {
        $recorder = $this->recorder();
        $recorder->begin($this->conversation());
        $recorder->mark('firstLlm');
        $recorder->finish();
        $recorder->begin($this->conversation());
        $recorder->finish();

        self::assertArrayHasKey('firstLlm', $this->writes[0][1]);
        self::assertArrayNotHasKey('firstLlm', $this->writes[1][1]);
    }

    #[Test]
    public function nothingIsWrittenWithoutATurn(): void
    {
        $this->recorder()->finish();

        self::assertSame([], $this->writes);
    }

    #[Test]
    public function aFailedWriteIsLoggedAndDropped(): void
    {
        $repository = $this->createStub(ConversationRepository::class);
        $repository->method('updateTurnTiming')->willThrowException(new RuntimeException('gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $recorder = new TurnTimingRecorder($repository);
        $recorder->setLogger($logger);
        $recorder->dispatched(7);
    }
}
