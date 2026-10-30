<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Dashboard;

use DateTimeImmutable;
use DateTimeInterface;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Dashboard\WidgetRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The "Recent chats" widget rendered through the dashboard's own registry, as
 * the dashboard does it.
 */
final class AiChatWidgetRenderingTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard', 'filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'nr_mcp_agent' => ['llmTaskUid' => '1'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_nrmcpagent_conversation.csv');
        $this->actingAs(1);
    }

    private function actingAs(int $uid): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser($uid);
        $GLOBALS['LANG']    = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    private function request(): ServerRequest
    {
        return (new ServerRequest('https://example.com/typo3/module/dashboard'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/dashboard', []));
    }

    private function render(): string
    {
        return $this->get(WidgetRegistry::class)
            ->getAvailableWidget($this->request(), 'nrMcpAgentAiChat')
            ->renderWidgetContent();
    }

    /** A message on the conversation, written at the given time; the list skips conversations without one. */
    private function addMessage(int $conversation, string $createdAt = 'now'): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrmcpagent_message');
        $sorting    = (int) $connection->count('uid', 'tx_nrmcpagent_message', ['conversation' => $conversation]);
        $connection->insert('tx_nrmcpagent_message', [
            'conversation' => $conversation,
            'sorting'      => $sorting,
            'role'         => 'user',
            'payload'      => json_encode(['role' => 'user', 'content' => 'Hallo', 'createdAt' => (new DateTimeImmutable($createdAt))->format(DateTimeInterface::ATOM)], JSON_THROW_ON_ERROR),
            'crdate'       => time(),
        ]);
        $this->updateConversation($conversation, ['message_count' => $sorting + 1]);
    }

    /**
     * @param array<string, int|string> $values
     */
    private function updateConversation(int $uid, array $values): void
    {
        $this->get(ConnectionPool::class)
            ->getConnectionForTable('tx_nrmcpagent_conversation')
            ->update('tx_nrmcpagent_conversation', $values, ['uid' => $uid]);
    }

    /**
     * @param array<string, int|string> $values
     */
    private function addPage(int $uid, string $title, array $values = []): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->insert('pages', [
            'uid'             => $uid,
            'pid'             => 0,
            'title'           => $title,
            'doktype'         => 1,
            'perms_userid'    => 1,
            'perms_user'      => 31,
            'perms_everybody' => 0,
            ...$values,
        ]);
    }

    #[Test]
    public function itListsTheConversationsAndOffersTheChat(): void
    {
        self::assertGreaterThan(0, $this->get(ExtensionConfiguration::class)->getLlmTaskUid(), 'precondition: the chat is configured');
        $this->addMessage(1);
        $this->addMessage(3);

        $html = $this->render();

        self::assertStringContainsString('data-nr-chat-conversation="1"', $html);
        self::assertStringContainsString('Conv 1', $html);
        // Another user's conversation.
        self::assertStringNotContainsString('Conv 3', $html);
        // Without a message there is nothing to look back on.
        self::assertStringNotContainsString('Conv 2', $html);
        self::assertStringContainsString('Look at earlier conversations or continue where you left off.', $html);
        self::assertStringContainsString('Show all chats', $html);
        self::assertStringContainsString('data-nr-chat-new="1"', $html);
    }

    #[Test]
    public function withoutChatsItSaysWhereTheyWillAppear(): void
    {
        $html = $this->render();

        self::assertStringContainsString('No chats yet. Your conversations with the assistant appear here.', $html);
        self::assertStringNotContainsString('data-nr-chat-conversation=', $html);
    }

    /** A title is user text: it must reach the page as text, never as markup. */
    #[Test]
    public function aTitleIsEscaped(): void
    {
        $this->addMessage(1);
        $this->updateConversation(1, ['title' => '<script>alert(1)</script>', 'tstamp' => time()]);

        $html = $this->render();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    /**
     * The whole entry is one link whose text is its accessible name: title,
     * context, progress, status and time, with the page for the page module.
     * A tour that started as "Check this page" is named by its skill and
     * page (ADR-027); the context names what the title does not.
     */
    #[Test]
    public function aGuidedProcessOnAPageShowsThePageTheTourItsPointAndTheTime(): void
    {
        $this->addPage(10, 'Startseite');
        $this->addMessage(1);
        $this->updateConversation(1, [
            'title'            => 'Check this page',
            'status'           => 'awaiting_input',
            'view_context'     => '{"pageId":10,"module":"web_layout","languageId":0}',
            'skill_identifier' => 'seo-optimieren',
            'guided_state'     => '{"progress":{"label":"SEO","current":3,"total":5,"completed":false},"highlight":null}',
        ]);

        $html = $this->render();

        self::assertStringContainsString('<span class="nr-recent-chats-title" title="Optimise SEO · Startseite">Optimise SEO · Startseite</span>', $html);
        self::assertStringNotContainsString('Check this page', $html);
        self::assertMatchesRegularExpression('/<span class="nr-recent-chats-context">\s*Guided tour\s*<\/span>/', $html);
        self::assertStringContainsString('Point 3 of 5', $html);
        // The status in words, as a warning: it waits for the editor.
        self::assertMatchesRegularExpression('/<span class="nr-recent-chats-status badge badge-warning" data-tone="warning">.*Waiting for your answer<\/span>/s', $html);
        self::assertMatchesRegularExpression('/<time class="nr-recent-chats-time" datetime="[^"]+" title="[^"]+">Today, /', $html);
        self::assertStringContainsString('data-nr-chat-page="10"', $html);
        self::assertStringContainsString('data-nr-chat-language="0"', $html);
    }

    /** Ready is neutral: a core default badge, not a green one. */
    #[Test]
    public function aReadyConversationHasANeutralStatus(): void
    {
        $this->addMessage(1);

        $html = $this->render();

        self::assertStringContainsString('<span class="nr-recent-chats-status badge badge-default" data-tone="neutral">Ready</span>', $html);
        self::assertStringNotContainsString('badge-success', $html);
    }

    /** Ten rows, the most recent last message first. */
    #[Test]
    public function itListsTheTenMostRecentConversations(): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrmcpagent_conversation');
        for ($i = 0; $i < 12; ++$i) {
            $connection->insert('tx_nrmcpagent_conversation', ['uid' => 100 + $i, 'be_user' => 1, 'title' => 'Chat ' . $i, 'status' => 'idle', 'tstamp' => time(), 'crdate' => time()]);
            $this->addMessage(100 + $i, sprintf('-%d minutes', 12 - $i));
        }

        $html = $this->render();
        preg_match_all('/data-nr-chat-conversation="(\d+)"/', $html, $matches);

        self::assertSame(['111', '110', '109', '108', '107', '106', '105', '104', '103', '102'], $matches[1]);
    }

    #[Test]
    public function aGuidedProcessOnADeletedPageSaysSo(): void
    {
        $this->addPage(11, 'Weg', ['deleted' => 1]);
        $this->addMessage(1);
        $this->updateConversation(1, [
            'view_context'     => '{"pageId":11,"module":"web_layout"}',
            'skill_identifier' => 'seo-optimieren',
            'guided_state'     => '{"progress":{"label":"SEO","current":2,"total":4,"completed":false},"highlight":null}',
        ]);

        $this->updateConversation(1, ['title' => 'Check this page']);

        $html = $this->render();

        self::assertStringContainsString('>Optimise SEO<', $html);
        self::assertStringContainsString('Deleted page · Guided tour', $html);
        self::assertStringNotContainsString('Point 2 of 4', $html);
        self::assertStringNotContainsString('Weg', $html);
        self::assertStringContainsString('data-nr-chat-page=""', $html);
    }

    /**
     * An ordinary chat keeps the page the browser showed with its last
     * message; that is where the editor was, not what the chat is about.
     */
    #[Test]
    public function anOrdinaryChatIsListedWithoutItsLastPage(): void
    {
        $this->addPage(10, 'Startseite');
        $this->addPage(11, 'Weg', ['deleted' => 1]);
        $this->addMessage(1);
        $this->addMessage(2);
        $this->updateConversation(1, ['view_context' => '{"pageId":10,"module":"web_layout","languageId":0}']);
        $this->updateConversation(2, ['view_context' => '{"pageId":11,"module":"web_layout"}']);

        $html = $this->render();

        self::assertStringContainsString('Conv 1', $html);
        self::assertStringContainsString('Conv 2', $html);
        self::assertStringNotContainsString('Startseite', $html);
        self::assertStringNotContainsString('Deleted page', $html);
        self::assertStringNotContainsString('nr-recent-chats-context', $html);
        self::assertStringNotContainsString('data-nr-chat-page="1', $html);
    }

    /**
     * A page the user may not see is not named, the tour is theirs and stays
     * (ADR-027; before, the widget left it out, while the chat module listed it).
     */
    #[Test]
    public function aGuidedProcessOnAPageTheUserMayNotSeeStaysWithoutThePage(): void
    {
        // Page 12 grants nothing to anyone but its owner, the admin.
        $this->addPage(12, 'Intern');
        $this->addMessage(3);
        $this->updateConversation(3, ['title' => 'Check this page', 'view_context' => '{"pageId":12,"module":"web_layout"}', 'skill_identifier' => 'seo-optimieren']);
        $this->actingAs(2);
        // The editor's groups would allow the widget (core's per-group widget
        // permission); which group does not matter here.
        $GLOBALS['BE_USER']->groupData['available_widgets'] = 'nrMcpAgentAiChat';

        $html = $this->render();

        self::assertStringContainsString('data-nr-chat-conversation="3"', $html);
        self::assertStringContainsString('>Optimise SEO<', $html);
        self::assertStringNotContainsString('Intern', $html);
        self::assertStringContainsString('data-nr-chat-page=""', $html);
    }

    /**
     * The grey lines are the backend's variant text colour, which follows the
     * colour scheme. `text-body-secondary`, the Bootstrap 5.3 utility, is not
     * defined by TYPO3's backend.css (13.4.35 and 14.3.7) and rendered in the
     * body colour.
     */
    #[Test]
    public function theMutedLinesUseTheBackendsVariantTextColour(): void
    {
        $this->addMessage(1);

        $html = $this->render();
        $css  = (string) file_get_contents(__DIR__ . '/../../../Resources/Public/Css/recent-chats-widget.css');

        self::assertStringNotContainsString('text-body-secondary', $html);
        self::assertStringContainsString('class="nr-recent-chats-time"', $html);
        self::assertMatchesRegularExpression('/\.nr-recent-chats-time \{[^}]*color: var\(--typo3-text-color-variant/', $css);
        self::assertMatchesRegularExpression('/\.nr-recent-chats-context \{[^}]*color: var\(--typo3-text-color-variant/', $css);
    }
}
