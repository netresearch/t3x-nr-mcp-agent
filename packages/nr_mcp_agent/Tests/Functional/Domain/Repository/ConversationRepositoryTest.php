<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Domain\Repository;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

class ConversationRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['netresearch/nr-mcp-agent'];

    private ConversationRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tx_nrmcpagent_conversation.csv');
        $this->subject = $this->get(ConversationRepository::class);
    }

    #[Test]
    public function findByUidReturnsConversation(): void
    {
        $conversation = $this->subject->findByUid(1);
        self::assertInstanceOf(Conversation::class, $conversation);
        self::assertSame('Conv 1', $conversation->getTitle());
    }

    #[Test]
    public function findByUidReturnsNullForDeleted(): void
    {
        self::assertNull($this->subject->findByUid(5));
    }

    #[Test]
    public function findByUidReturnsNullForNonExistent(): void
    {
        self::assertNull($this->subject->findByUid(999));
    }

    #[Test]
    public function findByBeUserReturnsOnlyOwnConversations(): void
    {
        $conversations = $this->subject->findByBeUser(1);
        // User 1 has uid 1 (idle), 2 (processing) — not 4 (archived) or 5 (deleted)
        self::assertCount(2, $conversations);
    }

    #[Test]
    public function findByBeUserIncludesArchivedWhenRequested(): void
    {
        $conversations = $this->subject->findByBeUser(1, includeArchived: true);
        self::assertCount(3, $conversations); // 1, 2, 4
    }

    #[Test]
    public function findOneByUidAndBeUserEnforcesOwnership(): void
    {
        // Conv 3 belongs to user 2 — user 1 must not see it
        self::assertNull($this->subject->findOneByUidAndBeUser(3, 1));
        self::assertInstanceOf(Conversation::class, $this->subject->findOneByUidAndBeUser(3, 2));
    }

    #[Test]
    public function countActiveByBeUserCountsProcessingAndLocked(): void
    {
        // User 1 has conv 2 in 'processing'
        self::assertSame(1, $this->subject->countActiveByBeUser(1));
        self::assertSame(0, $this->subject->countActiveByBeUser(2));
    }

    #[Test]
    public function addInsertsAndReturnsUid(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setTitle('New conversation');

        $uid = $this->subject->add($conversation);

        self::assertGreaterThan(0, $uid);
        $loaded = $this->subject->findByUid($uid);
        self::assertSame('New conversation', $loaded->getTitle());
    }

    #[Test]
    public function updatePersistsChanges(): void
    {
        $conversation = $this->subject->findByUid(1);
        $conversation->setTitle('Updated title');
        $this->subject->update($conversation);

        $reloaded = $this->subject->findByUid(1);
        self::assertSame('Updated title', $reloaded->getTitle());
    }

    #[Test]
    public function updateStatusChangesOnlyStatusInDatabase(): void
    {
        // Conv 1 is 'idle' — change to 'processing'
        $this->subject->updateStatus(1, ConversationStatus::Processing);

        $reloaded = $this->subject->findByUid(1);
        self::assertSame(ConversationStatus::Processing, $reloaded->getStatus());
        // Title must be unchanged
        self::assertSame('Conv 1', $reloaded->getTitle());
    }

    #[Test]
    public function dequeueForWorkerClaimsOldestProcessingRow(): void
    {
        // Conv 2 has status 'processing' — should be claimed
        $conversation = $this->subject->dequeueForWorker('test_worker_1');

        self::assertNotNull($conversation);
        self::assertSame(2, $conversation->getUid());

        // Verify DB state: status should now be 'locked'
        $reloaded = $this->subject->findByUid(2);
        self::assertSame(ConversationStatus::Locked, $reloaded->getStatus());
        self::assertSame('test_worker_1', $reloaded->getCurrentRequestId());
    }

    #[Test]
    public function dequeueForWorkerReturnsNullWhenQueueEmpty(): void
    {
        // First call claims the only 'processing' row (Conv 2)
        $this->subject->dequeueForWorker('worker_a');

        // Second call should find nothing
        $result = $this->subject->dequeueForWorker('worker_b');
        self::assertNull($result);
    }

    #[Test]
    public function dequeueForWorkerClaimsOldestWhenMultipleProcessing(): void
    {
        // Add a second 'processing' conversation with older tstamp
        $older = new Conversation();
        $older->setBeUser(1);
        $older->setTitle('Older conv');
        $older->setStatus(ConversationStatus::Processing);
        $olderUid = $this->subject->add($older);

        // Update tstamp to be older than Conv 2
        $conn = $this->get(\TYPO3\CMS\Core\Database\ConnectionPool::class)
            ->getConnectionForTable('tx_nrmcpagent_conversation');
        $conn->update('tx_nrmcpagent_conversation', ['tstamp' => 1700000000], ['uid' => $olderUid]);

        // Dequeue should return the older one first
        $claimed = $this->subject->dequeueForWorker('worker_order');
        self::assertNotNull($claimed);
        self::assertSame($olderUid, $claimed->getUid());
    }
}
