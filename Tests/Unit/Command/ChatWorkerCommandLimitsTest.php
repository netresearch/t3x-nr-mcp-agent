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
use PHPUnit\Framework\Attributes\DataProvider;
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
    private mixed $requestTime = null;

    /**
     * The worker treats a settings file written since the process started as
     * changed, and reads that start from REQUEST_TIME, which in a test run is
     * the start of PHPUnit. Pin it, so the file times below are relative to a
     * known start however long the suite has run.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->requestTime       = $_SERVER['REQUEST_TIME'] ?? null;
        $_SERVER['REQUEST_TIME'] = time() - 10;
    }

    protected function tearDown(): void
    {
        $_SERVER['REQUEST_TIME'] = $this->requestTime;
        parent::tearDown();
    }

    /**
     * @param array<string, int> $options
     */
    private function runWorker(array $options, ConversationRepository $repository, ?ChatWorkerRepository $workers = null, BufferedOutput $output = new BufferedOutput(), ?string $configurationFile = null): int
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
            $configurationFile,
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

    /**
     * A worker reads the extension settings once, at boot. One that waited
     * while an administrator saved new settings would run the next turn on the
     * old ones, so it stops before taking another turn and its supervisor
     * starts one that reads the new settings.
     */
    #[Test]
    public function aWorkerStopsBeforeTheNextTurnWhenTheSettingsWereSavedSinceItStarted(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'settings');
        self::assertIsString($file);
        // Both times lie before the process start, as when a deploy replaces
        // the file and keeps an older time: only the difference tells.
        touch($file, time() - 120);

        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::once())->method('dequeueForWorker')->willReturnCallback(static function () use ($file): ?Conversation {
            touch($file, time() - 60);

            return null;
        });
        $output = new BufferedOutput();

        try {
            self::assertSame(0, $this->runWorker(['--time-limit' => 5, '--poll-interval' => 1], $repository, output: $output, configurationFile: $file));
        } finally {
            unlink($file);
        }

        self::assertStringContainsString('settings changed', $output->fetch());
    }

    /**
     * A save in the moment between TYPO3 reading the settings and the worker
     * noting the file's time would go unseen: a file written between the
     * second the process started and the worker's first look counts as
     * changed, before any turn. Both ends of that window are the realistic
     * times, since a boot takes about a second.
     *
     * @return iterable<string, array{int}>
     */
    public static function timesDuringTheBoot(): iterable
    {
        yield 'after the start' => [-2];
        yield 'in the second the process started' => [-10];
        yield 'in the second the worker looked' => [0];
    }

    #[Test]
    #[DataProvider('timesDuringTheBoot')]
    public function aWorkerStopsAtOnceWhenTheSettingsWereWrittenDuringItsBoot(int $offset): void
    {
        $file = tempnam(sys_get_temp_dir(), 'settings');
        self::assertIsString($file);
        // REQUEST_TIME is time() - 10 (setUp).
        touch($file, time() + $offset);

        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::never())->method('dequeueForWorker');

        try {
            self::assertSame(0, $this->runWorker(['--time-limit' => 5, '--poll-interval' => 1], $repository, configurationFile: $file));
        } finally {
            unlink($file);
        }
    }

    /**
     * A replacement that keeps the modification time (a deploy copying with
     * preserved times) is a change too.
     */
    #[Test]
    public function aWorkerStopsWhenTheSettingsFileIsReplacedWithTheSameModificationTime(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'settings');
        self::assertIsString($file);
        touch($file, time() - 120);

        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::once())->method('dequeueForWorker')->willReturnCallback(static function () use ($file): ?Conversation {
            $replacement = $file . '.new';
            file_put_contents($replacement, '<?php return [];');
            touch($replacement, (int) filemtime($file));
            rename($replacement, $file);

            return null;
        });
        $output = new BufferedOutput();

        try {
            $this->runWorker(['--time-limit' => 5, '--poll-interval' => 1], $repository, output: $output, configurationFile: $file);
        } finally {
            unlink($file);
        }

        self::assertStringContainsString('settings changed', $output->fetch());
    }

    #[Test]
    public function aWorkerWhoseSettingsStayTheSameKeepsWaiting(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'settings');
        self::assertIsString($file);
        touch($file, time() - 60);
        $calls      = 0;
        $repository = $this->createStub(ConversationRepository::class);
        $repository->method('dequeueForWorker')->willReturnCallback(static function () use (&$calls): ?Conversation {
            ++$calls;

            return null;
        });

        try {
            $this->runWorker(['--time-limit' => 2, '--poll-interval' => 1], $repository, configurationFile: $file);
        } finally {
            unlink($file);
        }

        self::assertGreaterThan(1, $calls);
    }

    /**
     * A file time ahead of this host's clock is not a save during the boot:
     * stopping on it would restart every worker until the clock catches up.
     */
    #[Test]
    public function aSettingsFileFromTheFutureIsNoChange(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'settings');
        self::assertIsString($file);
        touch($file, time() + 600);
        $calls      = 0;
        $repository = $this->createStub(ConversationRepository::class);
        $repository->method('dequeueForWorker')->willReturnCallback(static function () use (&$calls): ?Conversation {
            ++$calls;

            return null;
        });

        try {
            $this->runWorker(['--time-limit' => 2, '--poll-interval' => 1], $repository, configurationFile: $file);
        } finally {
            unlink($file);
        }

        self::assertGreaterThan(1, $calls);
    }

    #[Test]
    public function aMissingSettingsFileIsNoChange(): void
    {
        $calls      = 0;
        $repository = $this->createStub(ConversationRepository::class);
        $repository->method('dequeueForWorker')->willReturnCallback(static function () use (&$calls): ?Conversation {
            ++$calls;

            return null;
        });

        $this->runWorker(['--time-limit' => 2, '--poll-interval' => 1], $repository, configurationFile: sys_get_temp_dir() . '/no-such-settings-' . bin2hex(random_bytes(4)) . '.php');

        self::assertGreaterThan(1, $calls);
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
        $clock = ['EXEC_TIME', 'ACCESS_TIME', 'SIM_EXEC_TIME', 'SIM_ACCESS_TIME'];
        foreach ($clock as $global) {
            $GLOBALS[$global] = 1000;
        }

        GeneralUtility::makeInstance(Context::class)->setAspect('date', new DateTimeAspect(new DateTimeImmutable('@1000')));
        $repository = $this->createStub(ConversationRepository::class);
        $repository->method('dequeueForWorker')->willReturnCallback(
            static fn (): Conversation => Conversation::fromRow(['uid' => 7, 'be_user' => 1]),
        );
        $before = time();

        $this->runWorker(['--max-turns' => 1], $repository);

        foreach ($clock as $global) {
            self::assertGreaterThanOrEqual($before - 60, $GLOBALS[$global]);
        }

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
                    $this->armedFor = pcntl_alarm(0);
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
