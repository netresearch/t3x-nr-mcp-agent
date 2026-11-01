<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Command;

use DateTimeImmutable;
use Netresearch\NrMcpAgent\Command\ChatWorkerCommand;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ChatWorkerRepository;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\TurnTimingRecorder;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The ways a worker ends. A worker that ends is the normal case: its supervisor
 * starts a fresh one.
 */
final class ChatWorkerCommandLimitsTest extends TestCase
{
    /**
     * @param array<string, int> $options
     */
    private function runWorker(array $options, ConversationRepository $repository, ?ChatWorkerRepository $workers = null, BufferedOutput $output = new BufferedOutput()): int
    {
        // BackendUserInitializer needs a database, and a turn is not what this
        // test is about: the initializer's failure takes the error path, which
        // ends the turn the same way.
        $command = new ChatWorkerCommand(
            // Final, and never reached: the backend user cannot be found.
            (new ReflectionClass(ChatService::class))->newInstanceWithoutConstructor(),
            $repository,
            $this->createStub(ConnectionPool::class),
            $this->createStub(TurnTimingRecorder::class),
            $workers,
        );
        $input = new ArrayInput($options, $command->getDefinition());

        return $command->run($input, $output);
    }

    #[Test]
    public function aWorkerStopsAfterItsTurns(): void
    {
        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::exactly(2))->method('dequeueForWorker')->willReturnCallback(
            static fn (): Conversation => Conversation::fromRow(['uid' => 7, 'be_user' => 1]),
        );

        self::assertSame(0, $this->runWorker(['--max-turns' => 2, '--poll-interval' => 1], $repository));
    }

    #[Test]
    public function anIdleWorkerStopsAtItsTimeLimit(): void
    {
        $repository = $this->createStub(ConversationRepository::class);
        $repository->method('dequeueForWorker')->willReturn(null);

        $started = time();
        self::assertSame(0, $this->runWorker(['--time-limit' => 1, '--poll-interval' => 1], $repository));
        self::assertLessThanOrEqual(3, time() - $started);
    }

    #[Test]
    public function aWorkerAboveItsMemoryLimitStops(): void
    {
        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::once())->method('dequeueForWorker')->willReturn(null);

        self::assertSame(0, $this->runWorker(['--memory-limit' => 1, '--time-limit' => 0], $repository));
    }

    #[Test]
    public function anIdleWorkerReportsItselfAndLeavesItsRowWhenItEnds(): void
    {
        $repository = $this->createStub(ConversationRepository::class);
        $repository->method('dequeueForWorker')->willReturn(null);
        $workers = $this->createMock(ChatWorkerRepository::class);
        $workers->expects(self::once())->method('reportIdle');
        $workers->expects(self::once())->method('withdraw');

        $this->runWorker(['--memory-limit' => 1], $repository, $workers);
    }

    #[Test]
    public function aWorkerWithATurnIsNoLongerIdle(): void
    {
        $order      = [];
        $repository = $this->createStub(ConversationRepository::class);
        $repository->method('dequeueForWorker')->willReturnCallback(static function () use (&$order): Conversation {
            $order[] = 'dequeued';

            return Conversation::fromRow(['uid' => 7, 'be_user' => 1]);
        });
        $workers = $this->createStub(ChatWorkerRepository::class);
        $workers->method('reportIdle')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'idle';
        });
        $workers->method('withdraw')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'withdrawn';
        });

        $this->runWorker(['--max-turns' => 1], $repository, $workers);

        self::assertSame(['idle', 'dequeued', 'withdrawn', 'withdrawn'], $order);
    }

    #[Test]
    public function aTurnRunsAtTheTimeItIsTaken(): void
    {
        $GLOBALS['EXEC_TIME'] = $GLOBALS['ACCESS_TIME'] = $GLOBALS['SIM_EXEC_TIME'] = $GLOBALS['SIM_ACCESS_TIME'] = 1000;
        GeneralUtility::makeInstance(Context::class)->setAspect('date', new DateTimeAspect(new DateTimeImmutable('@1000')));
        $repository = $this->createStub(ConversationRepository::class);
        $repository->method('dequeueForWorker')->willReturnCallback(
            static fn (): Conversation => Conversation::fromRow(['uid' => 7, 'be_user' => 1]),
        );
        $before = time();

        $this->runWorker(['--max-turns' => 1], $repository);

        self::assertGreaterThanOrEqual($before, $GLOBALS['EXEC_TIME']);
        self::assertGreaterThanOrEqual($before, $GLOBALS['SIM_EXEC_TIME']);
        self::assertGreaterThan(1000, $GLOBALS['ACCESS_TIME']);
        self::assertGreaterThan(1000, $GLOBALS['SIM_ACCESS_TIME']);
        self::assertGreaterThanOrEqual($before, GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp'));
    }

    #[Test]
    public function aWorkerSaysSoAtOnceWhenItsTurnIsOver(): void
    {
        $repository = $this->createStub(ConversationRepository::class);
        $repository->method('dequeueForWorker')->willReturnCallback(
            static fn (): Conversation => Conversation::fromRow(['uid' => 7, 'be_user' => 1]),
        );
        $workers = $this->createMock(ChatWorkerRepository::class);
        $workers->expects(self::exactly(2))->method('reportIdle');

        $this->runWorker(['--max-turns' => 2], $repository, $workers);
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    public function aRunningTurnHasItsLimitAndTheNextWaitHasNone(): void
    {
        $repository = $this->createStub(ConversationRepository::class);
        $repository->method('dequeueForWorker')->willReturnCallback(
            static fn (): Conversation => Conversation::fromRow(['uid' => 7, 'be_user' => 1]),
        );
        $output = new class extends BufferedOutput {
            public int $armedFor = 0;

            protected function doWrite(string $message, bool $newline): void
            {
                if (str_contains($message, 'Processing conversation')) {
                    $this->armedFor = (int) pcntl_alarm(0);
                    pcntl_alarm($this->armedFor);
                }
            }
        };

        $this->runWorker(['--max-turns' => 1, '--turn-limit' => 60], $repository, output: $output);

        self::assertGreaterThan(0, $output->armedFor);
        self::assertLessThanOrEqual(60, $output->armedFor);
        self::assertSame(0, pcntl_alarm(0));
    }

    #[Test]
    public function aTurnAtItsLimitFailsWithoutItsDecisionSoTheRetryDoesNotCarryItOut(): void
    {
        $conversation = Conversation::fromRow(['uid' => 7, 'be_user' => 1, 'status' => ConversationStatus::Locked->value]);
        $conversation->recordApprovalDecision(true, 'digest');

        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::once())->method('updateIf')->with($conversation, ConversationStatus::Locked);

        $command = new ChatWorkerCommand(
            (new ReflectionClass(ChatService::class))->newInstanceWithoutConstructor(),
            $repository,
            $this->createStub(ConnectionPool::class),
        );
        (new ReflectionMethod($command, 'failAtTheTurnLimit'))->invoke($command, $conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertSame('', $conversation->getApprovalDecision());
        self::assertNotSame('', $conversation->getErrorMessage());
    }
}
