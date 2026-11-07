<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Domain\Repository;

use Netresearch\NrMcpAgent\Domain\Repository\ConversationStatisticsRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The overview module's counts. The fixture holds conversations of two users,
 * an archived and a deleted one, rows outside the period, and two without a
 * message (11, 12), which no list shows, each of which a wrong filter would
 * count.
 */
final class ConversationStatisticsRepositoryTest extends FunctionalTestCase
{
    /** @var non-empty-string[] nr_mcp_agent depends on filelist. */
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    private ConversationStatisticsRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/overview_statistics.csv');
        $this->subject = $this->get(ConversationStatisticsRepository::class);
    }

    #[Test]
    public function statusCountsLeaveOutArchivedAndDeletedConversations(): void
    {
        self::assertSame([
            'awaiting_approval' => 1,
            'awaiting_input'    => 1,
            'failed'            => 2,
            'idle'              => 1,
            'locked'            => 1,
            'processing'        => 1,
            'tool_loop'         => 1,
        ], $this->subject->countByStatus(null));
    }

    #[Test]
    public function statusCountsOfOneUserLeaveOutOtherUsers(): void
    {
        self::assertSame([
            'awaiting_approval' => 1,
            'awaiting_input'    => 1,
            'failed'            => 1,
            'idle'              => 1,
            'processing'        => 1,
        ], $this->subject->countByStatus(2));
    }

    #[Test]
    public function archivedCountsOnlyArchivedAndNotDeleted(): void
    {
        self::assertSame(1, $this->subject->countArchived(null));
        self::assertSame(0, $this->subject->countArchived(3));
    }

    #[Test]
    public function startedCountsTheConversationsCreatedInThePeriod(): void
    {
        // 1780000000..1780999999 holds 1, 2, 3, 5, 6 (user 2) and 8, 9 (user 3);
        // 4 is before, 10 after, 7 is deleted, 11 and 12 hold no message.
        self::assertSame(7, $this->subject->countStarted(1780000000, 1780999999, null));
        self::assertSame(5, $this->subject->countStarted(1780000000, 1780999999, 2));
    }

    #[Test]
    public function startedWithASkillCountsOnlyConversationsCarryingOne(): void
    {
        self::assertSame(3, $this->subject->countStartedWithSkill(1780000000, 1780999999, null));
        self::assertSame(2, $this->subject->countStartedWithSkill(1780000000, 1780999999, 2));
    }

    #[Test]
    public function failedConversationsAreCountedPerErrorCode(): void
    {
        self::assertSame(['provider_error' => 1, 'timeout' => 1], $this->subject->countFailedByErrorCode(null));
        self::assertSame(['provider_error' => 1], $this->subject->countFailedByErrorCode(2));
    }

    #[Test]
    public function openPointsAreCountedPerUserWhenAsked(): void
    {
        self::assertSame(3, $this->subject->countOpenPoints(null));
        self::assertSame(1, $this->subject->countOpenPoints(2));
    }

    #[Test]
    public function waitingConversationsOfAUserAreListedNewestFirst(): void
    {
        self::assertSame([
            ['uid' => 3, 'title' => 'own approval', 'status' => 'awaiting_approval'],
            ['uid' => 4, 'title' => 'own input', 'status' => 'awaiting_input'],
        ], $this->subject->findWaitingForUser(2, 10));
        self::assertSame([], $this->subject->findWaitingForUser(3, 10));
    }

    #[Test]
    public function theWaitingChatWithTheLatestMessageComesFirst(): void
    {
        // Started earlier, but its last message is the most recent (ADR-027).
        $this->getConnectionPool()->getConnectionForTable('tx_nrmcpagent_conversation')
            ->update('tx_nrmcpagent_conversation', ['last_message_at' => 1790000000], ['uid' => 4]);

        self::assertSame([4, 3], array_column($this->subject->findWaitingForUser(2, 10), 'uid'));
    }
}
