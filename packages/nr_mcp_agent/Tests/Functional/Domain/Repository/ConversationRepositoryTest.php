<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Domain\Repository;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
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
}
