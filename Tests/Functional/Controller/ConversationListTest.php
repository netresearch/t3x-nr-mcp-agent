<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Controller;

use Netresearch\NrMcpAgent\Controller\ChatApiController;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
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
     * @return array<string, mixed>
     */
    private function response(): array
    {
        $response = $this->get(ChatApiController::class)->listConversations();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listed(): array
    {
        $data = $this->response();
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
        self::assertSame([25, 23, 21, 22, 24, 30, 29], array_keys($this->listed()));
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
        // The client words the time, from this and the list's locale.
        self::assertArrayNotHasKey('lastActivity', $tour);
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
    public function theListNamesTheLocaleTheTimesAreWordedIn(): void
    {
        self::assertSame('de', $this->response()['locale']);
    }

    /**
     * The rename field starts with the derived title; what the editor adds
     * is theirs and stays (review of #281).
     */
    #[Test]
    public function aTitleTheEditorExtendsStays(): void
    {
        $this->rename(21, 'SEO optimieren · Über uns (Kunde X)');

        $tour = $this->listed()[21];

        self::assertSame('SEO optimieren · Über uns (Kunde X)', $tour['displayTitle']);
        self::assertSame('own', $tour['titleKind']);
        self::assertSame('Seite „Über uns“ · SEO optimieren · Guided Tour · Punkt 2 von 5', $tour['contextLabel']);
    }

    /** A rename to exactly the derived title is still the editor's: marked so, it is never derived again. */
    #[Test]
    public function aRenameIsMarkedAsTheEditorsEvenWhenItEqualsTheDerivedTitle(): void
    {
        $this->rename(21, 'SEO optimieren · Über uns');

        $source = $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrmcpagent_conversation')
            ->select(['title_source'], 'tx_nrmcpagent_conversation', ['uid' => 21])->fetchOne();
        self::assertSame('manual', $source);
        self::assertSame('own', $this->listed()[21]['titleKind']);
    }

    /**
     * The title column holds 255 characters. A longer pasted title is cut to
     * that, and a character made of several code points ("👍🏽") that would
     * not fit whole is left out rather than split.
     */
    #[Test]
    public function aRenameIsCutToWhatTheColumnHoldsWithoutSplittingACharacter(): void
    {
        $answered = $this->rename(21, str_repeat('a', 254) . "\u{1F44D}\u{1F3FD}" . ' mehr');

        $stored = $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrmcpagent_conversation')
            ->select(['title'], 'tx_nrmcpagent_conversation', ['uid' => 21])->fetchOne();
        self::assertSame(str_repeat('a', 254), $stored);
        self::assertSame($stored, $answered);
    }

    /** @return string the title the endpoint answers with */
    private function rename(int $uid, string $title): string
    {
        $body = new Stream('php://temp', 'rw');
        $body->write(json_encode(['conversationUid' => $uid, 'title' => $title], JSON_THROW_ON_ERROR));
        $body->rewind();

        $request  = (new ServerRequest('/', 'POST'))->withBody($body);
        $response = $this->get(ChatApiController::class)->renameConversation($request);
        self::assertSame(200, $response->getStatusCode());

        $answer = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        self::assertIsString($answer['title']);

        return $answer['title'];
    }

    /** The list reads the time kept on the conversation, not its messages. */
    #[Test]
    public function theTimeKeptOnTheConversationOrdersTheList(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrmcpagent_conversation')
            ->update('tx_nrmcpagent_conversation', ['last_message_at' => strtotime('2026-10-25T08:00:00+00:00')], ['uid' => 22]);

        $listed = $this->listed();

        self::assertSame([25, 22, 23, 21, 24, 30, 29], array_keys($listed));
        self::assertSame('2026-10-25T08:00:00+00:00', $listed[22]['lastActivityAt']);
    }

    /**
     * Page 40 grants "show page" to everybody but lies outside the editor's
     * web mounts (20, 30): she may not see it in the page tree, so it is not
     * named.
     */
    #[Test]
    public function aPageOutsideTheEditorsMountsIsNotNamed(): void
    {
        $tour = $this->listed()[29];

        self::assertSame('Inhalt verbessern', $tour['displayTitle']);
        self::assertSame(0, $tour['pageUid']);
    }

    /**
     * The page is named in the conversation's language where the reader may
     * edit that language: the editor's group allows the default language only,
     * so she reads "Über uns"; the administrator reads the translation.
     */
    #[Test]
    public function thePageIsNamedInTheConversationsLanguageWhereTheReaderMayEditIt(): void
    {
        self::assertSame('Inhalt verbessern · Über uns', $this->listed()[30]['displayTitle']);

        $admin               = $this->setUpBackendUser(1);
        $admin->user['lang'] = 'de';
        $GLOBALS['BE_USER']  = $admin;

        self::assertSame('SEO optimieren · About us', $this->listed()[28]['displayTitle']);
    }

    /**
     * Workspace 7 holds a draft of the English title and a new German page
     * translation. In the live workspace neither is named: not the draft over
     * the live translation, not the new translation in place of the default
     * title.
     */
    #[Test]
    public function aTranslationOfAnotherWorkspaceIsNotNamed(): void
    {
        $admin               = $this->setUpBackendUser(1);
        $admin->user['lang'] = 'de';
        $GLOBALS['BE_USER']  = $admin;

        $listed = $this->listed();

        self::assertSame('SEO optimieren · About us', $listed[28]['displayTitle']);
        self::assertSame('Inhalt verbessern · Über uns', $listed[31]['displayTitle']);
        self::assertStringNotContainsString('workspace 7', json_encode($listed, JSON_THROW_ON_ERROR));
    }

    /** The same tour twice on the same page reads differently in a tab: by when each was started. */
    #[Test]
    public function theSameTourTwiceOnOnePageIsToldApartByItsStart(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrmcpagent_conversation')
            ->update('tx_nrmcpagent_conversation', ['skill_identifier' => 'seo-optimieren', 'view_context' => '{"pageId":20,"module":"web_layout","languageId":0}'], ['uid' => 30]);

        $listed = $this->listed();

        self::assertSame('SEO optimieren · Über uns · 20. Okt., 08:46', $listed[21]['displayTitle']);
        self::assertSame('SEO optimieren · Über uns · 14. Nov., 22:13', $listed[30]['displayTitle']);
    }

    #[Test]
    public function anotherUsersAndArchivedChatsAreNotListed(): void
    {
        $listed = $this->listed();

        self::assertArrayNotHasKey(26, $listed);
        self::assertArrayNotHasKey(27, $listed);
        self::assertArrayNotHasKey(28, $listed);
        self::assertArrayNotHasKey(31, $listed);
    }
}
