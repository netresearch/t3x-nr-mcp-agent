<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\LiveStream;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Service\LiveStream\ConversationStream;
use Netresearch\NrMcpAgent\Service\LiveStream\EventEmitterInterface;
use Netresearch\NrMcpAgent\Service\LiveStream\ServerSentEventEmitter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConversationStreamTest extends TestCase
{
    private float $now = 0.0;

    /** @var list<array<string, mixed>> */
    private array $events = [];

    private int $beats = 0;

    private bool $gone = false;

    private bool $opened = false;

    /**
     * @return array{status: string, activity: list<array<string, bool|int|string>>, live_text: string}
     */
    private function state(string $status = 'locked', string $text = '', array $activity = []): array
    {
        return ['status' => $status, 'message_count' => 1, 'activity' => $activity, 'live_text' => $text];
    }

    /**
     * @param list<array{status: string, activity: list<array<string, bool|int|string>>, live_text: string}|null> $states
     */
    private function stream(array $states, ?ConversationRepository $repository = null, int $maxStreams = 3): ConversationStream
    {
        if (!$repository instanceof ConversationRepository) {
            $repository = $this->createStub(ConversationRepository::class);
        }

        $last = end($states);
        $repository->method('findStreamState')->willReturnCallback(static function () use (&$states, $last): ?array {
            return $states === [] ? $last : array_shift($states);
        });

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getMaxLiveStreams')->willReturn($maxStreams);

        $test    = $this;
        $emitter = new class ($test) implements EventEmitterInterface {
            public function __construct(private readonly ConversationStreamTest $test) {}

            public function open(): void
            {
                $this->test->recordOpen();
            }

            public function send(array $event): void
            {
                $this->test->recordEvent($event);
            }

            public function heartbeat(): void
            {
                $this->test->recordBeat();
            }

            public function aborted(): bool
            {
                return $this->test->isGone();
            }
        };

        return new ConversationStream(
            $repository,
            $config,
            $emitter,
            function (int $microseconds): void {
                $this->now += $microseconds / 1e6;
            },
            fn (): float => $this->now,
        );
    }

    public function recordOpen(): void
    {
        $this->opened = true;
    }

    /**
     * @param array<string, mixed> $event
     */
    public function recordEvent(array $event): void
    {
        $this->events[] = $event;
    }

    public function recordBeat(): void
    {
        ++$this->beats;
    }

    public function isGone(): bool
    {
        return $this->gone;
    }

    #[Test]
    public function theTextGoesOutAsItGrowsAndTheEndOfTheTurnEndsTheStream(): void
    {
        $this->stream([
            $this->state('locked', 'Hal'),
            $this->state('locked', 'Hallo'),
            $this->state('locked', 'Hallo Welt'),
            $this->state('idle'),
        ])->run(7, 1);

        self::assertTrue($this->opened);
        self::assertSame([
            ['status' => 'locked', 'totalCount' => 1, 'activity' => [], 'text' => 'Hal'],
            ['append' => 'lo'],
            ['append' => ' Welt'],
            ['status' => 'idle', 'text' => ''],
            ['done' => true],
        ], $this->events);
    }

    #[Test]
    public function aTickWithNothingNewSendsNothing(): void
    {
        $this->stream([$this->state(), $this->state(), $this->state(), $this->state('idle')])->run(7, 1);

        self::assertSame([
            ['status' => 'locked', 'totalCount' => 1, 'activity' => []],
            ['status' => 'idle'],
            ['done' => true],
        ], $this->events);
    }

    #[Test]
    public function aRestartedRoundReplacesTheText(): void
    {
        $this->stream([$this->state('locked', 'erster'), $this->state('locked', 'zweiter'), $this->state('idle')])->run(7, 1);

        self::assertSame('zweiter', $this->events[1]['text'] ?? null);
    }

    #[Test]
    public function aReaderThatComesLateGetsTheWholeTextFirst(): void
    {
        $this->stream([$this->state('locked', 'Schon viel Text'), $this->state('idle')])->run(7, 1);

        self::assertSame('Schon viel Text', $this->events[0]['text'] ?? null);
    }

    #[Test]
    public function theActivityGoesOutWhenItChanges(): void
    {
        $entry = ['kind' => 'llm', 'round' => 1, 'ms' => 900];
        $this->stream([$this->state(), $this->state('locked', '', [$entry]), $this->state('idle', '', [$entry])])->run(7, 1);

        self::assertSame(['activity' => [$entry]], $this->events[1]);
        self::assertArrayNotHasKey('activity', $this->events[2]);
    }

    #[Test]
    public function aMessageInsideARunningTurnIsAnnounced(): void
    {
        $more                  = $this->state();
        $more['message_count'] = 2;

        $this->stream([$this->state(), $more, $this->state('idle')])->run(7, 1);

        self::assertSame(['totalCount' => 2], $this->events[1]);
    }

    #[Test]
    public function aPauseForApprovalEndsTheStream(): void
    {
        $this->stream([$this->state('awaiting_approval')])->run(7, 1);

        self::assertSame(['done' => true], end($this->events));
    }

    #[Test]
    public function aConversationThatIsGoneEndsTheStream(): void
    {
        $this->stream([null])->run(7, 1);

        self::assertSame([['done' => true]], $this->events);
    }

    #[Test]
    public function aReaderThatLeftEndsTheStream(): void
    {
        $this->gone = true;
        $this->stream([$this->state(), $this->state('locked', 'mehr')])->run(7, 1);

        self::assertCount(1, $this->events);
    }

    #[Test]
    public function aLongQuietTurnGetsHeartbeats(): void
    {
        $states = array_fill(0, 110, $this->state());
        $this->stream([...$states, $this->state('idle')])->run(7, 1);

        self::assertSame(1, $this->beats);
    }

    #[Test]
    public function theStreamEndsAtItsTimeLimitAndAsksForAReconnect(): void
    {
        $this->stream([$this->state()])->run(7, 1);

        self::assertSame(['reconnect' => true], end($this->events));
        self::assertGreaterThanOrEqual(ConversationStream::MAX_SECONDS, $this->now);
        self::assertLessThan(ConversationStream::MAX_SECONDS + 1, $this->now);
    }

    #[Test]
    public function theLeaseIsRenewedAndAlwaysReleased(): void
    {
        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::once())->method('releaseStreamLease')->with(7, 1);
        $repository->expects(self::atLeast(19))->method('renewStreamLease');

        $this->stream([$this->state()], $repository)->run(7, 1);
    }

    #[Test]
    public function aFailureOfTheStreamReleasesTheLease(): void
    {
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('findStreamState')->willThrowException(new RuntimeException('gone'));
        $repository->expects(self::once())->method('releaseStreamLease');

        $config = $this->createStub(ExtensionConfiguration::class);
        $stream = new ConversationStream($repository, $config, $this->createStub(EventEmitterInterface::class));

        $this->expectException(RuntimeException::class);
        $stream->run(7, 1);
    }

    #[Test]
    public function aSlotIsTakenWithinTheLimit(): void
    {
        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::once())->method('takeStreamLease')
            ->with(7, 1, self::greaterThan(time()), 3)
            ->willReturn(true);

        self::assertTrue($this->stream([$this->state()], $repository)->admit(7, 1));
    }

    #[Test]
    public function allSlotsTakenRefuses(): void
    {
        $repository = $this->createStub(ConversationRepository::class);
        $repository->method('takeStreamLease')->willReturn(false);

        self::assertFalse($this->stream([$this->state()], $repository)->admit(7, 1));
    }

    #[Test]
    public function aLimitOfZeroSwitchesStreamingOff(): void
    {
        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::never())->method('takeStreamLease');

        self::assertFalse($this->stream([$this->state()], $repository, maxStreams: 0)->admit(7, 1));
    }

    #[Test]
    public function aShortEventIsPaddedAndEndsWithABlankLine(): void
    {
        $wire = ServerSentEventEmitter::format(['append' => 'a']);

        self::assertSame(ServerSentEventEmitter::MIN_EVENT_BYTES, strlen($wire));
        self::assertStringStartsWith('data: {"append":"a"}' . "\n:", $wire);
        self::assertStringEndsWith("\n\n", $wire);
    }

    #[Test]
    public function aLongEventIsNotPadded(): void
    {
        $wire = ServerSentEventEmitter::format(['text' => str_repeat('a', 5000)]);

        self::assertStringEndsWith("\"}\n\n", $wire);
    }
}
