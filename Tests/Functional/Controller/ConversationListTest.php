<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Controller;

use Netresearch\NrMcpAgent\Controller\ChatApiController;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * GET /ai-chat/conversations as the container wires it, against a real
 * database (ADR-027): what every list shows about a conversation.
 *
 * The editor (3) may show page 20 ("Über uns") but not page 30 ("Intern").
 * Her conversations: two guided tours that both started as "Diese Seite
 * prüfen", one on each page; an ordinary chat that was on page 20 when its
 * last message was sent; a chat without a message; a pinned chat that failed;
 * an archived one. Conversation 26 belongs to the administrator.
 */
final class ConversationListTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'nr_mcp_agent' => ['llmTaskUid' => '1'],
        ],
        'SYS' => [
            'phpTimeZone' => 'UTC',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/guided_tools.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/conversation_list.csv');

        $user               = $this->setUpBackendUser(3);
        $user->user['lang'] = 'de';
        $GLOBALS['BE_USER'] = $user;
        $GLOBALS['LANG']    = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listed(): array
    {
        $response = $this->get(ChatApiController::class)->listConversations();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['conversations']);

        $byUid = [];
        foreach ($data['conversations'] as $conversation) {
            self::assertIsArray($conversation);
            $byUid[(int) $conversation['uid']] = $conversation;
        }

        return $byUid;
    }

    /** Pinned first, then by the last message, not by the last change: every tstamp is the same here. */
    #[Test]
    public function theListIsPinnedFirstThenByTheLastMessage(): void
    {
        self::assertSame([25, 23, 21, 22, 24], array_keys($this->listed()));
    }

    /** Two tours that were both "Diese Seite prüfen" are told apart by skill and page. */
    #[Test]
    public function aTourIsNamedByItsSkillAndItsPage(): void
    {
        $tour = $this->listed()[21];

        self::assertSame('Diese Seite prüfen', $tour['title']);
        self::assertSame('SEO optimieren · Über uns', $tour['displayTitle']);
        self::assertSame('tour', $tour['titleKind']);
        self::assertSame('Guided Tour · Punkt 2 von 5', $tour['contextLabel']);
        self::assertSame(20, $tour['pageUid']);
        self::assertSame('SEO optimieren', $tour['skillTitle']);
        self::assertTrue($tour['guided']);
        self::assertSame(['current' => 2, 'total' => 5, 'label' => 'Punkt 2 von 5'], $tour['progress']);
        self::assertSame('2026-10-20T10:00:00+00:00', $tour['lastActivityAt']);
        self::assertSame('2025-10-20T08:46:40+00:00', $tour['createdAt']);
        self::assertSame('awaiting_input', $tour['status']);
        self::assertSame('warning', $tour['statusTone']);
        self::assertSame('Wartet auf deine Antwort', $tour['statusLabel']);
        self::assertSame(2, $tour['messageCount']);
        self::assertMatchesRegularExpression('/^20\. Okt\./', $tour['lastActivity']['label']);
        self::assertStringContainsString('2026', $tour['lastActivity']['absolute']);
    }

    /** The title of a page the editor may not see is not named; the tour stays in her list. */
    #[Test]
    public function aPageTheEditorMayNotSeeIsNotNamed(): void
    {
        $tour = $this->listed()[22];

        self::assertSame('SEO optimieren', $tour['displayTitle']);
        self::assertSame(0, $tour['pageUid']);
        self::assertStringNotContainsString('Intern', json_encode($tour, JSON_THROW_ON_ERROR));
        self::assertSame('neutral', $tour['statusTone']);
        self::assertSame('Bereit', $tour['statusLabel']);
    }

    /** An ordinary chat is titled by its message, shortened; the page it was sent from is not its subject. */
    #[Test]
    public function anOrdinaryChatIsTitledByItsMessageWithoutAPage(): void
    {
        $chat = $this->listed()[23];

        self::assertSame('Welche Seiten haben keine Meta-Beschreibung und wie viele…', $chat['displayTitle']);
        self::assertSame('Welche Seiten haben keine Meta-Beschreibung und wie viele sind es insgesamt?', $chat['fullTitle']);
        self::assertSame('', $chat['contextLabel']);
        self::assertSame(0, $chat['pageUid']);
    }

    /**
     * A chat without a message is still sent — the chat opens it right after
     * creating it from this list — and carries what the lists hide it by.
     */
    #[Test]
    public function aChatWithoutAMessageIsSentWithItsCountForTheListsToHide(): void
    {
        $empty = $this->listed()[24];

        self::assertSame(0, $empty['messageCount']);
        self::assertSame('', $empty['displayTitle']);
        self::assertSame('empty', $empty['titleKind']);
    }

    #[Test]
    public function aFailedChatIsShownAsAnError(): void
    {
        $pinned = $this->listed()[25];

        self::assertTrue($pinned['pinned']);
        self::assertSame('danger', $pinned['statusTone']);
        self::assertSame('Fehlgeschlagen', $pinned['statusLabel']);
    }

    #[Test]
    public function anotherUsersAndArchivedChatsAreNotListed(): void
    {
        $listed = $this->listed();

        self::assertArrayNotHasKey(26, $listed);
        self::assertArrayNotHasKey(27, $listed);
    }
}
