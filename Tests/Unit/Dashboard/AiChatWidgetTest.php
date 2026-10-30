<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Dashboard;

use DateTimeImmutable;
use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Dashboard\AiChatWidget;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Domain\Repository\PageChoiceRepository;
use Netresearch\NrMcpAgent\Service\Assistant\SkillLabels;
use Netresearch\NrMcpAgent\Service\ConversationList\ConversationListItem;
use Netresearch\NrMcpAgent\Service\ConversationList\ConversationListPresenter;
use Netresearch\NrMcpAgent\Service\ProcessRunDetectorInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;

/**
 * The "Recent chats" dashboard widget: which conversations it hands its
 * template. What each of them shows is ConversationListPresenter's, tested
 * in ConversationListPresenterTest.
 */
final class AiChatWidgetTest extends TestCase
{
    /** 2026-10-24 10:42 UTC, a Saturday. */
    private const NOW = '2026-10-24T10:42:00+00:00';

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    /**
     * @param array<int, int> $lastMessageTimes
     */
    private function widget(int $taskUid, ConversationRepository $repository, array $lastMessageTimes = []): AiChatWidget
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn($taskUid);
        $config->method('getAllowedGroupIds')->willReturn([]);

        // The presenter reads the last message times through its own
        // repository; the widget's reads the candidates.
        $timesRepository = $this->createStub(ConversationRepository::class);
        $timesRepository->method('findLastMessageTimes')->willReturn($lastMessageTimes);

        $languageService = $this->createStub(LanguageService::class);
        $languageService->method('sL')->willReturn('');
        $factory = $this->createStub(LanguageServiceFactory::class);
        $factory->method('createFromUserPreferences')->willReturn($languageService);
        $factory->method('create')->willReturn($languageService);

        $context = new Context();
        $context->setAspect('date', new DateTimeAspect(new DateTimeImmutable(self::NOW)));

        $presenter = new ConversationListPresenter(
            $timesRepository,
            $this->createStub(PageChoiceRepository::class),
            new SkillLabels($factory),
            $this->createStub(ProcessRunDetectorInterface::class),
            $factory,
            new Locales(),
            $context,
        );

        // BackendViewFactory is final and only needed for rendering, which the
        // functional AiChatWidgetRenderingTest covers; the variables are tested here.
        $viewFactory = (new ReflectionClass(BackendViewFactory::class))->newInstanceWithoutConstructor();

        $widget = new AiChatWidget(
            $this->createMock(WidgetConfigurationInterface::class),
            $viewFactory,
            $repository,
            new ChatToolbarItem($config, $this->createMock(PageRenderer::class)),
            $presenter,
            new Locales(),
        );
        $widget->setRequest($this->createMock(ServerRequestInterface::class));

        return $widget;
    }

    private function actingAs(int $uid): void
    {
        $user               = $this->createMock(BackendUserAuthentication::class);
        $user->user         = ['uid' => $uid, 'usergroup' => '', 'lang' => ''];
        $GLOBALS['BE_USER'] = $user;
    }

    private function conversation(int $uid): Conversation
    {
        return Conversation::fromRow(['uid' => $uid, 'be_user' => 3, 'title' => 'Chat ' . $uid, 'message_count' => 1, 'tstamp' => strtotime(self::NOW) - 60]);
    }

    #[Test]
    public function listsTheTenMostRecentConversationsByTheirLastMessage(): void
    {
        $this->actingAs(3);
        $conversations = [];
        $times         = [];
        for ($i = 1; $i <= 12; ++$i) {
            $conversations[] = $this->conversation($i);
            // Read in changed-at order 1..12, but the last messages run the
            // other way round: 12 was written to last.
            $times[$i] = strtotime(self::NOW) - 1000 + $i;
        }

        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::once())->method('findRecentWithMessagesByBeUser')->with(3, AiChatWidget::WINDOW)->willReturn($conversations);

        $variables = $this->widget(1, $repository, $times)->templateVariables();

        self::assertTrue($variables['available']);
        // The locale the widget's script words the times in.
        self::assertSame('en', $variables['locale']);
        self::assertSame(10, AiChatWidget::LIMIT);
        self::assertSame([12, 11, 10, 9, 8, 7, 6, 5, 4, 3], array_map(static fn (ConversationListItem $item): int => $item->uid, $variables['conversations']));
    }

    #[Test]
    public function withoutAUserItListsNothing(): void
    {
        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::never())->method('findRecentWithMessagesByBeUser');

        self::assertSame([], $this->widget(1, $repository)->templateVariables()['conversations']);
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
        $repository->expects(self::never())->method('findRecentWithMessagesByBeUser');

        $variables = $this->widget(0, $repository)->templateVariables();

        self::assertFalse($variables['available']);
        self::assertSame([], $variables['conversations']);
    }

    #[Test]
    public function itLoadsTheScriptThatOpensTheChatPanelAndItsStylesheet(): void
    {
        $widget       = $this->widget(1, $this->createMock(ConversationRepository::class));
        $instructions = $widget->getJavaScriptModuleInstructions();

        self::assertCount(1, $instructions);
        self::assertSame('@netresearch/nr-mcp-agent/dashboard-widget.js', $instructions[0]->getName());
        self::assertSame(['EXT:nr_mcp_agent/Resources/Public/Css/recent-chats-widget.css'], $widget->getCssFiles());
    }
}
