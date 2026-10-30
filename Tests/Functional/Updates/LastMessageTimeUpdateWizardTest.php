<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Updates;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Updates\LastMessageTimeUpdateWizard;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * `last_message_at` (ADR-027): written with every save of the messages, and
 * filled by the wizard for conversations saved before the column existed.
 */
final class LastMessageTimeUpdateWizardTest extends FunctionalTestCase
{
    private const CONVERSATIONS = 'tx_nrmcpagent_conversation';

    private const MESSAGES = 'tx_nrmcpagent_message';

    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    private ConversationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->repository = $this->get(ConversationRepository::class);
    }

    private function connection(): Connection
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable(self::CONVERSATIONS);
    }

    private function lastMessageAt(int $uid): int
    {
        $value = $this->connection()->select(['last_message_at'], self::CONVERSATIONS, ['uid' => $uid])->fetchOne();

        return is_numeric($value) ? (int) $value : -1;
    }

    /**
     * A row as a save before the column left it: messages, no time.
     *
     * @param list<string|null> $createdAt one message per entry, null for one without a time
     */
    private function legacyRow(int $uid, array $createdAt, int $tstamp = 1700000000): void
    {
        $this->connection()->insert(self::CONVERSATIONS, ['uid' => $uid, 'be_user' => 1, 'title' => 'Alt ' . $uid, 'message_count' => count($createdAt), 'tstamp' => $tstamp, 'crdate' => $tstamp]);
        foreach ($createdAt as $sorting => $time) {
            $payload = ['role' => 'user', 'content' => 'Hallo'] + ($time === null ? [] : ['createdAt' => $time]);
            $this->connection()->insert(self::MESSAGES, ['conversation' => $uid, 'sorting' => $sorting, 'role' => 'user', 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'crdate' => $tstamp]);
        }
    }

    #[Test]
    public function aSaveKeepsTheTimeOfTheLastMessage(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Erste Frage');

        $uid = $this->repository->add($conversation);

        $stored = $this->repository->findByUid($uid);
        self::assertNotNull($stored);
        $messages = $stored->getDecodedMessages();
        self::assertSame(strtotime((string) $messages[0]['createdAt']), $this->lastMessageAt($uid));

        // A later message moves it; the time is the message's own.
        $messages[] = ['role' => 'assistant', 'content' => 'Antwort', 'createdAt' => '2026-10-28T09:15:00+00:00'];
        $stored->setMessages($messages);
        $this->repository->update($stored);

        self::assertSame(strtotime('2026-10-28T09:15:00+00:00'), $this->lastMessageAt($uid));
        self::assertSame(strtotime('2026-10-28T09:15:00+00:00'), $this->repository->findByBeUser(1)[0]->getLastMessageAt());
    }

    #[Test]
    public function aNewConversationWithoutAMessageHasNoTime(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);

        self::assertSame(0, $this->lastMessageAt($this->repository->add($conversation)));
    }

    #[Test]
    public function theWizardFillsTheTimeOfOlderRows(): void
    {
        $this->legacyRow(11, ['2026-10-01T08:00:00+00:00', '2026-10-02T09:30:00+00:00']);
        // The last message carries no time: the row's changed-at time stands in.
        $this->legacyRow(12, ['2026-10-01T08:00:00+00:00', null], 1760000000);
        // Messages counted, none in the table (a legacy transcript not yet moved).
        $this->connection()->insert(self::CONVERSATIONS, ['uid' => 13, 'be_user' => 1, 'message_count' => 2, 'tstamp' => 1750000000, 'crdate' => 1750000000]);
        // No message at all: nothing to fill.
        $this->connection()->insert(self::CONVERSATIONS, ['uid' => 14, 'be_user' => 1, 'message_count' => 0, 'tstamp' => 1750000000, 'crdate' => 1750000000]);
        // Already written by a save: left alone.
        $this->legacyRow(15, ['2026-10-01T08:00:00+00:00']);
        $this->connection()->update(self::CONVERSATIONS, ['last_message_at' => 1761111111], ['uid' => 15]);

        $wizard = new LastMessageTimeUpdateWizard($this->repository);
        self::assertTrue($wizard->updateNecessary());
        self::assertSame(3, $this->repository->countWithoutLastMessageTime());

        self::assertTrue($wizard->executeUpdate());

        self::assertSame(strtotime('2026-10-02T09:30:00+00:00'), $this->lastMessageAt(11));
        self::assertSame(1760000000, $this->lastMessageAt(12));
        self::assertSame(1750000000, $this->lastMessageAt(13));
        self::assertSame(0, $this->lastMessageAt(14));
        self::assertSame(1761111111, $this->lastMessageAt(15));
        self::assertFalse($wizard->updateNecessary());
    }

    /** A batch smaller than the backlog: the wizard takes the next one until none is left. */
    #[Test]
    public function theWizardWorksThroughTheBacklogInBatches(): void
    {
        for ($uid = 100; $uid < 103; ++$uid) {
            $this->legacyRow($uid, ['2026-10-01T08:00:00+00:00']);
        }

        self::assertSame(2, $this->repository->backfillLastMessageTimes(2));
        self::assertSame(1, $this->repository->countWithoutLastMessageTime());
        self::assertSame(1, $this->repository->backfillLastMessageTimes(2));
        self::assertSame(0, $this->repository->backfillLastMessageTimes(2));
    }
}
