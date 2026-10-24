<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Domain\Repository;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * What the "Recent chats" widget reads: the candidates with their page,
 * skill and guided state, and when each one's last message was written.
 */
final class RecentConversationsTest extends FunctionalTestCase
{
    private const CONVERSATIONS = 'tx_nrmcpagent_conversation';

    protected array $coreExtensionsToLoad = ['filelist'];

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

    /**
     * @param list<string|null> $createdAt one message per entry; null for one without a time
     */
    private function conversation(int $beUser, array $createdAt, int $tstamp, bool $archived = false): int
    {
        $conversation = new Conversation();
        $conversation->setBeUser($beUser);
        $conversation->setMessages(array_map(
            static fn (?string $at): array => $at === null ? ['role' => 'user', 'content' => 'x'] : ['role' => 'user', 'content' => 'x', 'createdAt' => $at],
            $createdAt,
        ));
        $conversation->setViewContext(10, 'web_layout', 1);
        $conversation->setSkillIdentifier('seo-optimieren', 4);
        $conversation->setGuidedState(['label' => 'SEO', 'current' => 2, 'total' => 5, 'completed' => false], null);

        $uid = $this->subject->add($conversation);

        // add() writes the skill with the row; tstamp and archived as the test needs them.
        $this->get(ConnectionPool::class)->getConnectionForTable(self::CONVERSATIONS)
            ->update(self::CONVERSATIONS, ['tstamp' => $tstamp, 'archived' => (int) $archived], ['uid' => $uid]);

        return $uid;
    }

    #[Test]
    public function theCandidatesAreTheUsersConversationsWithMessagesNewestChangeFirst(): void
    {
        $old      = $this->conversation(1, ['2026-10-01T10:00:00+00:00'], 100);
        $new      = $this->conversation(1, ['2026-10-02T10:00:00+00:00'], 300);
        $empty    = $this->conversation(1, [], 400);
        $archived = $this->conversation(1, ['2026-10-03T10:00:00+00:00'], 500, archived: true);
        $foreign  = $this->conversation(2, ['2026-10-04T10:00:00+00:00'], 600);

        $candidates = $this->subject->findRecentWithMessagesByBeUser(1, 10);

        self::assertSame([$new, $old], array_map(static fn (Conversation $c): int => $c->getUid(), $candidates));
        self::assertNotContains($empty, [$new, $old]);
        self::assertNotContains($archived, [$new, $old]);
        self::assertNotContains($foreign, [$new, $old]);
    }

    /** The list needs the page, the skill and the guided state, which the plain list leaves out. */
    #[Test]
    public function aCandidateCarriesItsPageSkillAndGuidedState(): void
    {
        $this->conversation(1, ['2026-10-01T10:00:00+00:00'], 100);

        $candidate = $this->subject->findRecentWithMessagesByBeUser(1, 10)[0];

        self::assertSame(['pageId' => 10, 'module' => 'web_layout', 'languageId' => 1], $candidate->getViewContext());
        self::assertSame('seo-optimieren', $candidate->getSkillIdentifier());
        self::assertSame(['label' => 'SEO', 'current' => 2, 'total' => 5, 'completed' => false], $candidate->getGuidedState()['progress']);
    }

    #[Test]
    public function theCandidatesAreLimited(): void
    {
        for ($i = 1; $i <= 4; ++$i) {
            $this->conversation(1, ['2026-10-01T10:00:00+00:00'], 100 + $i);
        }

        self::assertCount(3, $this->subject->findRecentWithMessagesByBeUser(1, 3));
    }

    /** The time of the LAST message, read from the message itself, not the row's save time. */
    #[Test]
    public function theLastMessageTimeIsTheLastMessagesOwn(): void
    {
        $two      = $this->conversation(1, ['2026-10-01T10:00:00+00:00', '2026-10-05T12:30:00+00:00'], 100);
        $one      = $this->conversation(1, ['2026-09-30T08:00:00+02:00'], 100);
        $untimed  = $this->conversation(1, ['2026-10-01T10:00:00+00:00', null], 100);
        $notAsked = $this->conversation(1, ['2026-10-01T10:00:00+00:00'], 100);

        $times = $this->subject->findLastMessageTimes([$two, $one, $untimed]);

        self::assertSame([
            $two => strtotime('2026-10-05T12:30:00+00:00'),
            $one => strtotime('2026-09-30T06:00:00+00:00'),
        ], $times);
        self::assertArrayNotHasKey($notAsked, $times);
        self::assertSame([], $this->subject->findLastMessageTimes([]));
    }
}
