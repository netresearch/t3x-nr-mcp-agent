<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Command;

use Netresearch\NrMcpAgent\Command\CleanupCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class CleanupCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    private const TABLE = 'tx_nrmcpagent_conversation';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
    }

    #[Test]
    public function timeoutStuckConversationsMarksThemAsFailed(): void
    {
        $conn = $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE);

        // Insert a conversation stuck in "processing" for >5 minutes
        $oldTstamp = time() - 600; // 10 minutes ago
        $conn->insert(self::TABLE, [
            'pid' => 0,
            'be_user' => 1,
            'title' => 'Stuck processing',
            'messages' => '[]',
            'message_count' => 0,
            'status' => 'processing',
            'current_request_id' => '',
            'system_prompt' => '',
            'archived' => 0,
            'pinned' => 0,
            'error_message' => '',
            'deleted' => 0,
            'tstamp' => $oldTstamp,
            'crdate' => $oldTstamp,
        ]);
        $stuckUid = (int) $conn->lastInsertId();

        // Insert a conversation stuck in "locked" for >5 minutes
        $conn->insert(self::TABLE, [
            'pid' => 0,
            'be_user' => 1,
            'title' => 'Stuck locked',
            'messages' => '[]',
            'message_count' => 0,
            'status' => 'locked',
            'current_request_id' => 'worker_1',
            'system_prompt' => '',
            'archived' => 0,
            'pinned' => 0,
            'error_message' => '',
            'deleted' => 0,
            'tstamp' => $oldTstamp,
            'crdate' => $oldTstamp,
        ]);
        $lockedUid = (int) $conn->lastInsertId();

        // Insert a conversation stuck in "tool_loop" for >5 minutes
        $conn->insert(self::TABLE, [
            'pid' => 0,
            'be_user' => 1,
            'title' => 'Stuck tool_loop',
            'messages' => '[]',
            'message_count' => 0,
            'status' => 'tool_loop',
            'current_request_id' => '',
            'system_prompt' => '',
            'archived' => 0,
            'pinned' => 0,
            'error_message' => '',
            'deleted' => 0,
            'tstamp' => $oldTstamp,
            'crdate' => $oldTstamp,
        ]);
        $toolLoopUid = (int) $conn->lastInsertId();

        // Insert a recent "processing" conversation that should NOT be timed out
        $conn->insert(self::TABLE, [
            'pid' => 0,
            'be_user' => 1,
            'title' => 'Recent processing',
            'messages' => '[]',
            'message_count' => 0,
            'status' => 'processing',
            'current_request_id' => '',
            'system_prompt' => '',
            'archived' => 0,
            'pinned' => 0,
            'error_message' => '',
            'deleted' => 0,
            'tstamp' => time(),
            'crdate' => time(),
        ]);
        $recentUid = (int) $conn->lastInsertId();

        $command = $this->get(CleanupCommand::class);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('Timed out 3 stuck conversation(s)', $output);
        self::assertStringContainsString('Timed out stuck conversations: 3', $output);

        // Verify DB state: stuck conversations are now 'failed'
        $stuckRow = $this->fetchRow($stuckUid);
        self::assertSame('failed', $stuckRow['status']);
        self::assertStringContainsString('Timed out', $stuckRow['error_message']);

        $lockedRow = $this->fetchRow($lockedUid);
        self::assertSame('failed', $lockedRow['status']);

        $toolLoopRow = $this->fetchRow($toolLoopUid);
        self::assertSame('failed', $toolLoopRow['status']);

        // Recent conversation should remain unchanged
        $recentRow = $this->fetchRow($recentUid);
        self::assertSame('processing', $recentRow['status']);
    }

    #[Test]
    public function autoArchiveInactiveConversations(): void
    {
        $conn = $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE);

        // Insert an old idle conversation (older than 30 days)
        $oldTstamp = time() - (31 * 86400);
        $conn->insert(self::TABLE, [
            'pid' => 0,
            'be_user' => 1,
            'title' => 'Old idle',
            'messages' => '[]',
            'message_count' => 0,
            'status' => 'idle',
            'current_request_id' => '',
            'system_prompt' => '',
            'archived' => 0,
            'pinned' => 0,
            'error_message' => '',
            'deleted' => 0,
            'tstamp' => $oldTstamp,
            'crdate' => $oldTstamp,
        ]);
        $oldIdleUid = (int) $conn->lastInsertId();

        // Insert a recent idle conversation that should NOT be archived
        $conn->insert(self::TABLE, [
            'pid' => 0,
            'be_user' => 1,
            'title' => 'Recent idle',
            'messages' => '[]',
            'message_count' => 0,
            'status' => 'idle',
            'current_request_id' => '',
            'system_prompt' => '',
            'archived' => 0,
            'pinned' => 0,
            'error_message' => '',
            'deleted' => 0,
            'tstamp' => time(),
            'crdate' => time(),
        ]);
        $recentIdleUid = (int) $conn->lastInsertId();

        $command = $this->get(CleanupCommand::class);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('Auto-archived 1 inactive conversation(s)', $output);
        self::assertStringContainsString('Auto-archived inactive conversations: 1', $output);

        // Old idle should now be archived
        $oldRow = $this->fetchRow($oldIdleUid);
        self::assertSame('1', (string) $oldRow['archived']);

        // Recent idle should remain unarchived
        $recentRow = $this->fetchRow($recentIdleUid);
        self::assertSame('0', (string) $recentRow['archived']);
    }

    #[Test]
    public function deleteOldArchivedConversations(): void
    {
        $conn = $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE);

        // Insert an old archived conversation (older than 90 days)
        $oldTstamp = time() - (91 * 86400);
        $conn->insert(self::TABLE, [
            'pid' => 0,
            'be_user' => 1,
            'title' => 'Old archived',
            'messages' => '[]',
            'message_count' => 0,
            'status' => 'idle',
            'current_request_id' => '',
            'system_prompt' => '',
            'archived' => 1,
            'pinned' => 0,
            'error_message' => '',
            'deleted' => 0,
            'tstamp' => $oldTstamp,
            'crdate' => $oldTstamp,
        ]);
        $oldArchivedUid = (int) $conn->lastInsertId();

        // Insert a recently archived conversation that should NOT be deleted
        $conn->insert(self::TABLE, [
            'pid' => 0,
            'be_user' => 1,
            'title' => 'Recent archived',
            'messages' => '[]',
            'message_count' => 0,
            'status' => 'idle',
            'current_request_id' => '',
            'system_prompt' => '',
            'archived' => 1,
            'pinned' => 0,
            'error_message' => '',
            'deleted' => 0,
            'tstamp' => time(),
            'crdate' => time(),
        ]);
        $recentArchivedUid = (int) $conn->lastInsertId();

        $command = $this->get(CleanupCommand::class);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('Deleted 1 old archived conversation(s)', $output);
        self::assertStringContainsString('Deleted old archived conversations: 1', $output);

        // Old archived row should be hard-deleted from DB
        $oldRow = $this->fetchRow($oldArchivedUid);
        self::assertFalse($oldRow, 'Old archived conversation should be hard-deleted');

        // Recent archived should still exist
        $recentRow = $this->fetchRow($recentArchivedUid);
        self::assertIsArray($recentRow);
    }

    #[Test]
    public function deleteAfterDaysOptionIsRespected(): void
    {
        $conn = $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE);

        // Insert archived conversation from 10 days ago
        $tstamp = time() - (10 * 86400);
        $conn->insert(self::TABLE, [
            'pid' => 0,
            'be_user' => 1,
            'title' => '10 day old archived',
            'messages' => '[]',
            'message_count' => 0,
            'status' => 'idle',
            'current_request_id' => '',
            'system_prompt' => '',
            'archived' => 1,
            'pinned' => 0,
            'error_message' => '',
            'deleted' => 0,
            'tstamp' => $tstamp,
            'crdate' => $tstamp,
        ]);
        $uid = (int) $conn->lastInsertId();

        // Use --delete-after-days=5 so 10-day-old archived conv gets deleted
        $command = $this->get(CleanupCommand::class);
        $tester = new CommandTester($command);
        $tester->execute(['--delete-after-days' => '5']);

        self::assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('Deleted 1 old archived conversation(s)', $output);

        $row = $this->fetchRow($uid);
        self::assertFalse($row, 'Archived conversation older than 5 days should be deleted');
    }

    #[Test]
    public function cleanupSummaryOutputContainsAllCounts(): void
    {
        // No data inserted — all counts should be 0
        $command = $this->get(CleanupCommand::class);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('Cleanup summary:', $output);
        self::assertStringContainsString('Timed out stuck conversations: 0', $output);
        self::assertStringContainsString('Auto-archived inactive conversations: 0', $output);
        self::assertStringContainsString('Deleted old archived conversations: 0', $output);
    }

    #[Test]
    public function deletedConversationsAreNotTimedOut(): void
    {
        $conn = $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE);

        // Insert a stuck but soft-deleted conversation
        $oldTstamp = time() - 600;
        $conn->insert(self::TABLE, [
            'pid' => 0,
            'be_user' => 1,
            'title' => 'Deleted stuck',
            'messages' => '[]',
            'message_count' => 0,
            'status' => 'processing',
            'current_request_id' => '',
            'system_prompt' => '',
            'archived' => 0,
            'pinned' => 0,
            'error_message' => '',
            'deleted' => 1,
            'tstamp' => $oldTstamp,
            'crdate' => $oldTstamp,
        ]);
        $uid = (int) $conn->lastInsertId();

        $command = $this->get(CleanupCommand::class);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('Timed out stuck conversations: 0', $output);

        // Row should still have 'processing' status (not changed to 'failed')
        $row = $this->fetchRow($uid);
        self::assertIsArray($row);
        self::assertSame('processing', $row['status']);
    }

    /**
     * @return array<string, mixed>|false
     */
    private function fetchRow(int $uid): array|false
    {
        /** @var ConnectionPool $pool */
        $pool = $this->get(ConnectionPool::class);
        $conn = $pool->getConnectionForTable(self::TABLE);
        return $conn->executeQuery(
            'SELECT * FROM ' . self::TABLE . ' WHERE uid = ?',
            [$uid],
            [Connection::PARAM_INT],
        )->fetchAssociative();
    }
}
