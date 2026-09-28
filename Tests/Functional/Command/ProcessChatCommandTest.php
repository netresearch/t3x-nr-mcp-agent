<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Command;

use Netresearch\NrMcpAgent\Command\ProcessChatCommand;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * `ai-chat:process` against the real database: a turn somebody else claimed
 * is left to them.
 */
final class ProcessChatCommandTest extends FunctionalTestCase
{
    // nr_mcp_agent depends on filelist (the FAL picker's element browser).
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        // Conversation 2 is the one turn waiting in 'processing'.
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_nrmcpagent_conversation.csv');
    }

    #[Test]
    public function aTurnAWorkerClaimedFirstIsNotProcessedAgain(): void
    {
        $repository = $this->get(ConversationRepository::class);
        self::assertNotNull($repository->dequeueForWorker('worker_1'));
        $before = $this->row(2);

        $tester = new CommandTester($this->get(ProcessChatCommand::class));
        $exitCode = $tester->execute(['conversationUid' => '2']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('not in processing state', $tester->getDisplay());
        // Untouched: still the worker's, no error written, no message added.
        self::assertSame($before, $this->row(2));
        self::assertSame(ConversationStatus::Locked->value, $before['status']);
        self::assertSame('worker_1', $before['current_request_id']);
    }

    #[Test]
    public function aTurnThatIsNoLongerProcessingIsNotClaimed(): void
    {
        $before = $this->row(1);

        $tester = new CommandTester($this->get(ProcessChatCommand::class));
        $exitCode = $tester->execute(['conversationUid' => '1']);

        self::assertSame(0, $exitCode);
        self::assertSame($before, $this->row(1));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $uid): array
    {
        $row = $this->get(ConnectionPool::class)
            ->getConnectionForTable('tx_nrmcpagent_conversation')
            ->select(['status', 'current_request_id', 'error_message', 'message_count', 'tstamp'], 'tx_nrmcpagent_conversation', ['uid' => $uid])
            ->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }
}
