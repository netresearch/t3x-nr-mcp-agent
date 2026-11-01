<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Service\LiveTextRecorder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class LiveTextRecorderTest extends TestCase
{
    /** @var list<string> */
    private array $writes = [];

    private function recorder(): LiveTextRecorder
    {
        $repository = $this->createStub(ConversationRepository::class);
        $repository->method('updateLiveText')->willReturnCallback(function (int $uid, string $text): void {
            $this->writes[] = $text;
        });

        return new LiveTextRecorder($repository);
    }

    #[Test]
    public function theFirstPieceGoesOutAtOnce(): void
    {
        $this->recorder()->append(7, 1, 'Hal');

        self::assertSame(['Hal'], $this->writes);
    }

    #[Test]
    public function piecesWithinTheIntervalWaitAndTheFlushDeliversThem(): void
    {
        $recorder = $this->recorder();
        $recorder->append(7, 1, 'Hal');
        $recorder->append(7, 1, 'lo');
        $recorder->append(7, 1, ' Welt');

        self::assertSame(['Hal'], $this->writes);

        $recorder->flush(7);

        self::assertSame(['Hal', 'Hallo Welt'], $this->writes);
    }

    #[Test]
    public function aPieceAfterTheIntervalIsWritten(): void
    {
        $recorder = $this->recorder();
        $recorder->append(7, 1, 'Hal');
        usleep(101_000);
        $recorder->append(7, 1, 'lo');

        self::assertSame(['Hal', 'Hallo'], $this->writes);
    }

    #[Test]
    public function aFlushWithNothingNewWritesNothing(): void
    {
        $recorder = $this->recorder();
        $recorder->append(7, 1, 'Hal');
        $recorder->flush(7);

        self::assertSame(['Hal'], $this->writes);
    }

    #[Test]
    public function roundsOfOneTurnFollowEachOtherWithAParagraph(): void
    {
        $recorder = $this->recorder();
        $recorder->append(7, 1, 'Ich lese die Seite.');
        $recorder->append(7, 2, 'Hier ist sie.');
        $recorder->flush(7);

        self::assertSame("Ich lese die Seite.\n\nHier ist sie.", end($this->writes));
    }

    #[Test]
    public function aRestartDropsThisRoundAndKeepsTheEarlierOnes(): void
    {
        $recorder = $this->recorder();
        $recorder->append(7, 1, 'Erste Runde.');
        $recorder->append(7, 2, 'erster Versuch');
        $recorder->restart(7);

        self::assertSame("Erste Runde.\n\n", end($this->writes));
    }

    #[Test]
    public function clearEmptiesTheColumnAndTheNextTurnStartsFresh(): void
    {
        $recorder = $this->recorder();
        $recorder->append(7, 1, 'Hal');
        $recorder->clear(7);
        $recorder->flush(7);
        usleep(101_000);
        $recorder->append(7, 1, 'neu');

        self::assertSame(['Hal', '', 'neu'], $this->writes);
    }

    #[Test]
    public function aNewTurnEmptiesWhatADeadProcessLeft(): void
    {
        $this->recorder()->reset(7);

        self::assertSame([''], $this->writes);
    }

    #[Test]
    public function aTurnWithoutTextTouchesNothing(): void
    {
        $this->recorder()->clear(7);

        self::assertSame([], $this->writes);
    }

    #[Test]
    public function aFailedWriteIsLoggedAndDropped(): void
    {
        $repository = $this->createStub(ConversationRepository::class);
        $repository->method('updateLiveText')->willThrowException(new RuntimeException('gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $recorder = new LiveTextRecorder($repository);
        $recorder->setLogger($logger);
        $recorder->append(7, 1, 'Hal');
    }
}
