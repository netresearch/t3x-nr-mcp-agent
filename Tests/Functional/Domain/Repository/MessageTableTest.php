<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Domain\Repository;

use Netresearch\NrMcpAgent\Command\CleanupCommand;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Updates\MigrateMessagesToTableUpdateWizard;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Messages in their own table (NEXT-172, ADR-016): written with the
 * conversation in one transaction, read back in order, legacy transcripts
 * read until moved, moved by the wizard, deleted with their conversation.
 */
final class MessageTableTest extends FunctionalTestCase
{
    private const CONVERSATIONS = 'tx_nrmcpagent_conversation';

    private const MESSAGES = 'tx_nrmcpagent_message';

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
        $this->subject = $this->get(ConversationRepository::class);
    }

    private function connection(): Connection
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable(self::CONVERSATIONS);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messageRows(int $conversationUid): array
    {
        return $this->connection()->select(['sorting', 'role', 'payload'], self::MESSAGES, ['conversation' => $conversationUid], [], ['sorting' => 'ASC'])->fetchAllAssociative();
    }

    private function legacyColumn(int $uid): string
    {
        $value = $this->connection()->select(['messages'], self::CONVERSATIONS, ['uid' => $uid])->fetchOne();

        return is_string($value) ? $value : '';
    }

    private function newConversation(string ...$userMessages): int
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        foreach ($userMessages as $i => $content) {
            $conversation->appendMessage(MessageRole::User, $content);
            $conversation->appendMessage(MessageRole::Assistant, 'answer ' . $i);
        }

        return $this->subject->add($conversation);
    }

    /**
     * A conversation row as an earlier release wrote it: transcript in the
     * column, no message rows.
     *
     * @param list<array<string, mixed>> $messages
     */
    private function legacyConversation(array $messages): int
    {
        $this->connection()->insert(self::CONVERSATIONS, [
            'pid' => 0,
            'be_user' => 1,
            'title' => 'legacy',
            'messages' => json_encode($messages, JSON_THROW_ON_ERROR),
            'message_count' => count($messages),
            'status' => 'idle',
            'tstamp' => time(),
            'crdate' => time(),
        ]);

        return (int) $this->connection()->lastInsertId();
    }

    #[Test]
    public function aSavedTranscriptIsOneRowPerMessageInOrder(): void
    {
        $uid = $this->newConversation('first', 'second');

        $rows = $this->messageRows($uid);
        self::assertSame([0, 1, 2, 3], array_map(static fn(array $r): int => (int) $r['sorting'], $rows));
        self::assertSame(['user', 'assistant', 'user', 'assistant'], array_column($rows, 'role'));
        self::assertSame('', $this->legacyColumn($uid), 'the transcript must not be kept twice');

        $reloaded = $this->subject->findByUid($uid);
        self::assertNotNull($reloaded);
        self::assertSame(['first', 'answer 0', 'second', 'answer 1'], array_column($reloaded->getDecodedMessages(), 'content'));
        self::assertSame(4, $reloaded->getMessageCount());
    }

    #[Test]
    public function aShorterTranscriptRemovesTheRowsBeyondIt(): void
    {
        $uid = $this->newConversation('first', 'second');
        $conversation = $this->subject->findByUid($uid);
        self::assertNotNull($conversation);

        $conversation->setMessages(array_slice($conversation->getDecodedMessages(), 0, 1));
        $this->subject->update($conversation);

        self::assertCount(1, $this->messageRows($uid));
    }

    /**
     * The claim and the transcript are one write. A claim that loses the race
     * must leave the message rows exactly as they were — otherwise the loser's
     * message would sit in a conversation somebody else now owns.
     */
    #[Test]
    public function aLostClaimLeavesTheMessagesUntouched(): void
    {
        $uid = $this->newConversation('first');
        $conversation = $this->subject->findByUid($uid);
        self::assertNotNull($conversation);

        $conversation->appendMessage(MessageRole::User, 'lost');
        $conversation->setStatus(ConversationStatus::Processing);

        self::assertFalse($this->subject->updateIf($conversation, ConversationStatus::Failed));
        self::assertCount(2, $this->messageRows($uid));
        self::assertStringNotContainsString('lost', implode('', array_column($this->messageRows($uid), 'payload')));
    }

    #[Test]
    public function aWonClaimWritesTheNewMessage(): void
    {
        $uid = $this->newConversation('first');
        $conversation = $this->subject->findByUid($uid);
        self::assertNotNull($conversation);

        $conversation->appendMessage(MessageRole::User, 'next');
        $conversation->setStatus(ConversationStatus::Processing);

        self::assertTrue($this->subject->updateIf($conversation, ConversationStatus::Idle));
        self::assertCount(3, $this->messageRows($uid));

        $claimed = $this->subject->dequeueForWorker('worker-1');
        self::assertNotNull($claimed);
        self::assertSame('next', $claimed->getDecodedMessages()[2]['content'] ?? null, 'the worker sees the message the claim wrote');
    }

    #[Test]
    public function aLegacyTranscriptIsReadUntilItIsMoved(): void
    {
        $uid = $this->legacyConversation([['role' => 'user', 'content' => 'old question'], ['role' => 'assistant', 'content' => 'old answer']]);

        $conversation = $this->subject->findOneByUidAndBeUser($uid, 1);

        self::assertNotNull($conversation);
        self::assertSame(['old question', 'old answer'], array_column($conversation->getDecodedMessages(), 'content'));
        self::assertSame([], $this->messageRows($uid));
    }

    #[Test]
    public function savingALegacyConversationMovesItsTranscript(): void
    {
        $uid = $this->legacyConversation([['role' => 'user', 'content' => 'old question']]);
        $conversation = $this->subject->findByUid($uid);
        self::assertNotNull($conversation);

        $this->subject->update($conversation);

        self::assertCount(1, $this->messageRows($uid));
        self::assertSame('', $this->legacyColumn($uid));
    }

    #[Test]
    public function theWizardMovesEveryLegacyTranscript(): void
    {
        $first = $this->legacyConversation([['role' => 'user', 'content' => 'a'], ['role' => 'assistant', 'content' => 'b']]);
        $second = $this->legacyConversation([['role' => 'user', 'content' => 'c', 'fileUid' => 5]]);
        $wizard = new MigrateMessagesToTableUpdateWizard($this->subject);

        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());

        self::assertFalse($wizard->updateNecessary());
        self::assertSame('', $this->legacyColumn($first));
        self::assertSame('', $this->legacyColumn($second));
        self::assertCount(2, $this->messageRows($first));
        $moved = $this->subject->findByUid($second);
        self::assertNotNull($moved);
        self::assertSame([['role' => 'user', 'content' => 'c', 'fileUid' => 5]], $moved->getDecodedMessages());
    }

    /**
     * Message rows are the later write; a stale copy in the column must not
     * replace them.
     */
    #[Test]
    public function theWizardKeepsRowsThatAlreadyExist(): void
    {
        $uid = $this->newConversation('current');
        $this->connection()->update(self::CONVERSATIONS, ['messages' => '[{"role":"user","content":"stale"}]'], ['uid' => $uid]);

        (new MigrateMessagesToTableUpdateWizard($this->subject))->executeUpdate();

        self::assertSame('', $this->legacyColumn($uid));
        $conversation = $this->subject->findByUid($uid);
        self::assertNotNull($conversation);
        self::assertSame('current', $conversation->getDecodedMessages()[0]['content'] ?? null);
    }

    #[Test]
    public function deletingOldArchivedConversationsDeletesTheirMessages(): void
    {
        $old = $this->newConversation('old');
        $kept = $this->newConversation('kept');
        $this->connection()->update(self::CONVERSATIONS, ['archived' => 1, 'tstamp' => time() - 200 * 86400], ['uid' => $old]);

        (new CommandTester($this->get(CleanupCommand::class)))->execute([]);

        self::assertSame([], $this->messageRows($old));
        self::assertCount(2, $this->messageRows($kept));
    }

    /**
     * A legacy value that is not a JSON list made the conversation unopenable
     * before. The wizard must neither destroy it nor pick it up forever.
     */
    #[Test]
    public function theWizardKeepsAnUndecodableTranscriptAndFinishes(): void
    {
        $this->connection()->insert(self::CONVERSATIONS, [
            'pid' => 0, 'be_user' => 1, 'title' => 'broken', 'messages' => '[{"role":"user"', 'status' => 'idle', 'tstamp' => time(), 'crdate' => time(),
        ]);
        $uid = (int) $this->connection()->lastInsertId();
        $wizard = new MigrateMessagesToTableUpdateWizard($this->subject);

        self::assertTrue($wizard->executeUpdate());

        self::assertFalse($wizard->updateNecessary());
        self::assertSame(ConversationRepository::UNDECODABLE_MARKER . '[{"role":"user"', $this->legacyColumn($uid));
        self::assertSame([], $this->messageRows($uid));
    }

    #[Test]
    public function orphanedMessagesAreRemovedEvenWhenNothingIsDeleted(): void
    {
        $this->connection()->insert(self::MESSAGES, ['pid' => 0, 'conversation' => 9999, 'sorting' => 0, 'role' => 'user', 'payload' => '{}', 'crdate' => time()]);
        $kept = $this->newConversation('kept');

        (new CommandTester($this->get(CleanupCommand::class)))->execute([]);

        self::assertSame([], $this->messageRows(9999));
        self::assertCount(2, $this->messageRows($kept));
    }
}
