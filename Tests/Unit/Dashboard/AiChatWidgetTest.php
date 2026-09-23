<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Dashboard;

use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Dashboard\AiChatWidget;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;

/**
 * The "AI Chat" dashboard widget (NEXT-172).
 */
final class AiChatWidgetTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    private function widget(int $taskUid, ConversationRepository $repository): AiChatWidget
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn($taskUid);
        $config->method('getAllowedGroupIds')->willReturn([]);

        // BackendViewFactory is final and only needed for rendering, which the
        // functional AiChatWidgetRenderingTest covers; the variables are tested here.
        $viewFactory = (new ReflectionClass(BackendViewFactory::class))->newInstanceWithoutConstructor();

        $widget = new AiChatWidget(
            $this->createMock(WidgetConfigurationInterface::class),
            $viewFactory,
            $repository,
            new ChatToolbarItem($config, $this->createMock(PageRenderer::class)),
            new Locales(),
        );
        $widget->setRequest($this->createMock(ServerRequestInterface::class));

        return $widget;
    }

    private function actingAs(int $uid, string $lang = ''): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->user = ['uid' => $uid, 'usergroup' => '', 'lang' => $lang];
        $GLOBALS['BE_USER'] = $user;
    }

    #[Test]
    public function listsTheUsersMostRecentConversations(): void
    {
        $this->actingAs(3);
        $conversations = [];
        for ($i = 1; $i <= 7; $i++) {
            $conversations[] = Conversation::fromRow(['uid' => $i, 'be_user' => 3, 'title' => 'Chat ' . $i, 'tstamp' => 100 - $i]);
        }
        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::once())->method('findByBeUser')->with(3)->willReturn($conversations);

        $variables = $this->widget(1, $repository)->templateVariables();

        self::assertTrue($variables['available']);
        self::assertCount(AiChatWidget::LIMIT, $variables['conversations']);
        self::assertSame(['uid' => 1, 'title' => 'Chat 1', 'status' => 'idle', 'pinned' => false, 'tstamp' => 99], array_diff_key($variables['conversations'][0], ['date' => true]));
    }

    /**
     * Core decides who may place a widget; the chat decides who may chat. A
     * user the chat is not available for sees that, not their conversations.
     */
    #[Test]
    public function aUserTheChatIsNotAvailableForSeesNoConversations(): void
    {
        $this->actingAs(3);
        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::never())->method('findByBeUser');

        $variables = $this->widget(0, $repository)->templateVariables();

        self::assertFalse($variables['available']);
        self::assertSame([], $variables['conversations']);
    }

    #[Test]
    public function itLoadsTheScriptThatOpensTheChatPanel(): void
    {
        $instructions = $this->widget(1, $this->createMock(ConversationRepository::class))->getJavaScriptModuleInstructions();

        self::assertCount(1, $instructions);
        self::assertSame('@netresearch/nr-mcp-agent/dashboard-widget.js', $instructions[0]->getName());
    }

    /** The date follows the backend user's language, not a fixed d.m.Y. */
    #[Test]
    public function theDateIsFormattedInTheUsersLanguage(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $tstamp = 1790157600; // 2026-09-23 10:00 UTC
            $repository = $this->createMock(ConversationRepository::class);
            $repository->method('findByBeUser')->willReturn([Conversation::fromRow(['uid' => 1, 'be_user' => 3, 'tstamp' => $tstamp])]);

            $this->actingAs(3, 'de');
            $german = $this->widget(1, $repository)->templateVariables()['conversations'][0]['date'];
            $this->actingAs(3, '');
            $english = $this->widget(1, $repository)->templateVariables()['conversations'][0]['date'];
        } finally {
            date_default_timezone_set($timezone);
        }

        self::assertStringStartsWith('23.09.2026', $german);
        self::assertStringStartsWith('Sep 23, 2026', $english);
    }
}
