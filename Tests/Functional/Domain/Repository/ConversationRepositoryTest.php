<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Domain\Repository;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class ConversationRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

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
        // Conv 1 is 'idle' — change to 'processing' (belongs to be_user 1)
        $this->subject->updateStatus(1, ConversationStatus::Processing, 1);

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
    public function updateIfReturnsTrueWhenStatusMatches(): void
    {
        // Conv 1 is 'idle'
        $conversation = $this->subject->findByUid(1);
        self::assertNotNull($conversation);
        $conversation->setTitle('CAS updated');
        $conversation->setStatus(ConversationStatus::Processing);

        $result = $this->subject->updateIf($conversation, ConversationStatus::Idle);
        self::assertTrue($result);

        $reloaded = $this->subject->findByUid(1);
        self::assertNotNull($reloaded);
        self::assertSame('CAS updated', $reloaded->getTitle());
        self::assertSame(ConversationStatus::Processing, $reloaded->getStatus());
    }

    #[Test]
    public function updateIfReturnsFalseWhenStatusDoesNotMatch(): void
    {
        // Conv 1 is 'idle' — try to update expecting 'processing'
        $conversation = $this->subject->findByUid(1);
        self::assertNotNull($conversation);
        $conversation->setTitle('Should not be saved');
        $conversation->setStatus(ConversationStatus::Processing);

        $result = $this->subject->updateIf($conversation, ConversationStatus::Processing);
        self::assertFalse($result);

        // Verify row is unchanged
        $reloaded = $this->subject->findByUid(1);
        self::assertNotNull($reloaded);
        self::assertSame('Conv 1', $reloaded->getTitle());
        self::assertSame(ConversationStatus::Idle, $reloaded->getStatus());
    }

    #[Test]
    public function updateIfReturnsFalseForDeletedRow(): void
    {
        // Conv 5 is deleted
        $conn = $this->get(\TYPO3\CMS\Core\Database\ConnectionPool::class)
            ->getConnectionForTable('tx_nrmcpagent_conversation');
        // Read the raw row to build a Conversation (findByUid filters deleted rows)
        $row = $conn->executeQuery(
            'SELECT * FROM tx_nrmcpagent_conversation WHERE uid = 5',
        )->fetchAssociative();
        self::assertIsArray($row);

        $conversation = Conversation::fromRow($row);
        $conversation->setTitle('Try update deleted');

        $result = $this->subject->updateIf($conversation, ConversationStatus::Idle);
        self::assertFalse($result);
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

    #[Test]
    public function updateArchivedChangesDbRow(): void
    {
        // Conv 1 belongs to user 1, archived=0
        $before = $this->subject->findByUid(1);
        self::assertNotNull($before);
        self::assertFalse($before->isArchived());

        $this->subject->updateArchived(1, true, 1);

        $after = $this->subject->findByUid(1);
        self::assertNotNull($after);
        self::assertTrue($after->isArchived());
        // Title must remain unchanged
        self::assertSame('Conv 1', $after->getTitle());
    }

    #[Test]
    public function updateArchivedDoesNotAffectWrongUser(): void
    {
        // Conv 1 belongs to user 1 — updating with user 2 should have no effect
        $this->subject->updateArchived(1, true, 2);

        $after = $this->subject->findByUid(1);
        self::assertNotNull($after);
        self::assertFalse($after->isArchived());
    }

    #[Test]
    public function updatePinnedChangesDbRow(): void
    {
        // Conv 1 belongs to user 1, pinned=0
        $before = $this->subject->findByUid(1);
        self::assertNotNull($before);
        self::assertFalse($before->isPinned());

        $this->subject->updatePinned(1, true, 1);

        $after = $this->subject->findByUid(1);
        self::assertNotNull($after);
        self::assertTrue($after->isPinned());
        // Title must remain unchanged
        self::assertSame('Conv 1', $after->getTitle());
    }

    #[Test]
    public function updatePinnedDoesNotAffectWrongUser(): void
    {
        // Conv 1 belongs to user 1 — updating with user 2 should have no effect
        $this->subject->updatePinned(1, true, 2);

        $after = $this->subject->findByUid(1);
        self::assertNotNull($after);
        self::assertFalse($after->isPinned());
    }

    #[Test]
    public function updateStatusDoesNotAffectWrongUser(): void
    {
        // Conv 1 belongs to user 1 — updating with user 2 should have no effect
        $this->subject->updateStatus(1, ConversationStatus::Processing, 2);

        $reloaded = $this->subject->findByUid(1);
        self::assertNotNull($reloaded);
        self::assertSame(ConversationStatus::Idle, $reloaded->getStatus());
    }

    #[Test]
    public function findPollStatusReturnsCorrectShape(): void
    {
        $result = $this->subject->findPollStatus(1, 1);
        self::assertIsArray($result);
        self::assertArrayHasKey('status', $result);
        self::assertArrayHasKey('message_count', $result);
        self::assertArrayHasKey('error_message', $result);
        self::assertSame('idle', $result['status']);
        self::assertSame(0, $result['message_count']);
        self::assertSame('', $result['error_message']);
    }

    #[Test]
    public function findPollStatusReturnsNullForWrongUser(): void
    {
        // Conv 1 belongs to user 1 — user 2 must not see it
        self::assertNull($this->subject->findPollStatus(1, 2));
    }

    #[Test]
    public function findPollStatusReturnsNullForDeletedRow(): void
    {
        // Conv 5 is deleted
        self::assertNull($this->subject->findPollStatus(5, 1));
    }

    #[Test]
    public function findPollStatusReturnsNullForNonExistent(): void
    {
        self::assertNull($this->subject->findPollStatus(999, 1));
    }

    #[Test]
    public function findByBeUserExcludesMessagesColumn(): void
    {
        // Insert a conversation with actual message data
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setTitle('With messages');
        $conversation->setMessages([['role' => 'user', 'content' => 'Hello']]);
        $uid = $this->subject->add($conversation);

        // findByBeUser uses LIST_COLUMNS which excludes 'messages'
        $list = $this->subject->findByBeUser(1);
        $found = null;
        foreach ($list as $item) {
            if ($item->getUid() === $uid) {
                $found = $item;
                break;
            }
        }
        self::assertNotNull($found);
        // messages should be empty since LIST_COLUMNS does not include it
        self::assertSame('', $found->getMessages());

        // But findByUid (which uses SELECT *) should have the messages
        $full = $this->subject->findByUid($uid);
        self::assertNotNull($full);
        self::assertNotSame('', $full->getMessages());
        self::assertCount(1, $full->getDecodedMessages());
    }

    #[Test]
    public function addAndFindByUidRoundtrip(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(2);
        $conversation->setTitle('Roundtrip test');
        $conversation->setStatus(ConversationStatus::Processing);
        $conversation->setMessages([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there'],
        ]);
        $conversation->setSystemPrompt('You are a helpful assistant.');
        $conversation->setArchived(true);
        $conversation->setPinned(true);
        $conversation->setErrorMessage('some error');

        $uid = $this->subject->add($conversation);
        self::assertGreaterThan(0, $uid);

        $loaded = $this->subject->findByUid($uid);
        self::assertNotNull($loaded);
        self::assertSame($uid, $loaded->getUid());
        self::assertSame(2, $loaded->getBeUser());
        self::assertSame('Roundtrip test', $loaded->getTitle());
        self::assertSame(ConversationStatus::Processing, $loaded->getStatus());
        self::assertCount(2, $loaded->getDecodedMessages());
        self::assertSame('You are a helpful assistant.', $loaded->getSystemPrompt());
        self::assertTrue($loaded->isArchived());
        self::assertTrue($loaded->isPinned());
        self::assertSame('some error', $loaded->getErrorMessage());
        self::assertSame(2, $loaded->getMessageCount());
    }
}
