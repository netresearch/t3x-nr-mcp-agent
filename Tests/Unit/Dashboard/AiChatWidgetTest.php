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
use Netresearch\NrMcpAgent\Service\ProcessRunDetectorInterface;
use Netresearch\NrMcpAgent\Service\SkillCatalogueInterface;
use Netresearch\NrMcpAgent\Tests\Unit\Service\Assistant\SkillLabelsStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;

/**
 * The "Recent chats" dashboard widget: what it hands its template.
 */
final class AiChatWidgetTest extends TestCase
{
    use SkillLabelsStub;

    private const LL = 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:';

    /** 2026-10-24 10:42 UTC, a Saturday. */
    private const NOW = '2026-10-24T10:42:00+00:00';

    private string $timezone = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    /**
     * @param array<int, array{uid: int, title: string}> $accessiblePages
     * @param list<int>                                  $gonePages
     */
    private function widget(
        int $taskUid,
        ConversationRepository $repository,
        array $accessiblePages = [],
        array $gonePages = [],
        bool $processRun = true,
        string $now = self::NOW,
        ?SkillCatalogueInterface $skills = null,
    ): AiChatWidget {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn($taskUid);
        $config->method('getAllowedGroupIds')->willReturn([]);

        $pages = $this->createMock(PageChoiceRepository::class);
        $pages->method('findAccessible')->willReturnCallback(static fn (int $uid): ?array => $accessiblePages[$uid] ?? null);
        $pages->method('isGone')->willReturnCallback(static fn (int $uid): bool => in_array($uid, $gonePages, true));

        $processRuns = $this->createMock(ProcessRunDetectorInterface::class);
        $processRuns->method('isProcessRun')->willReturn($processRun);

        // BackendViewFactory is final and only needed for rendering, which the
        // functional AiChatWidgetRenderingTest covers; the variables are tested here.
        $viewFactory = (new ReflectionClass(BackendViewFactory::class))->newInstanceWithoutConstructor();

        $context = new Context();
        $context->setAspect('date', new DateTimeAspect(new DateTimeImmutable($now)));

        $widget = new AiChatWidget(
            $this->createMock(WidgetConfigurationInterface::class),
            $viewFactory,
            $repository,
            new ChatToolbarItem($config, $this->createMock(PageRenderer::class)),
            new Locales(),
            $pages,
            $this->skillLabels(['skill.seo-optimieren.title' => 'SEO optimieren']),
            $processRuns,
            $context,
            $skills,
        );
        $widget->setRequest($this->createMock(ServerRequestInterface::class));

        return $widget;
    }

    /**
     * @param list<Conversation> $conversations
     * @param array<int, int>    $lastMessageTimes
     */
    private function repository(array $conversations, array $lastMessageTimes = []): ConversationRepository
    {
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('findRecentWithMessagesByBeUser')->willReturn($conversations);
        $repository->method('findLastMessageTimes')->willReturn($lastMessageTimes);

        return $repository;
    }

    private function actingAs(int $uid, string $lang = ''): void
    {
        $user               = $this->createMock(BackendUserAuthentication::class);
        $user->user         = ['uid' => $uid, 'usergroup' => '', 'lang' => $lang];
        $GLOBALS['BE_USER'] = $user;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function conversation(int $uid, array $row = []): Conversation
    {
        return Conversation::fromRow(['uid' => $uid, 'be_user' => 3, 'title' => 'Chat ' . $uid, 'tstamp' => strtotime(self::NOW) - 60, ...$row]);
    }

    private function withGuidedState(Conversation $conversation, int $current, int $total, bool $completed = false): Conversation
    {
        $conversation->setGuidedState(['label' => 'SEO', 'current' => $current, 'total' => $total, 'completed' => $completed], null);

        return $conversation;
    }

    #[Test]
    public function listsTheFiveMostRecentConversationsByTheirLastMessage(): void
    {
        $this->actingAs(3);
        $conversations = [];
        $times         = [];
        for ($i = 1; $i <= 7; ++$i) {
            $conversations[] = $this->conversation($i);
            // Read in changed-at order 1..7, but the last messages run the
            // other way round: 7 was written to last.
            $times[$i] = strtotime(self::NOW) - 1000 + $i;
        }

        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::once())->method('findRecentWithMessagesByBeUser')->with(3, AiChatWidget::WINDOW)->willReturn($conversations);
        $repository->expects(self::once())->method('findLastMessageTimes')->with([1, 2, 3, 4, 5, 6, 7])->willReturn($times);

        $variables = $this->widget(1, $repository)->templateVariables();

        self::assertTrue($variables['available']);
        self::assertSame([7, 6, 5, 4, 3], array_column($variables['conversations'], 'uid'));
    }

    /** Pinning or renaming moves the changed-at time, not the last message. */
    #[Test]
    public function aConversationWhoseLastMessageHasNoTimeCountsByItsChangedAtTime(): void
    {
        $this->actingAs(3);
        $older  = $this->conversation(1, ['tstamp' => strtotime(self::NOW) - 30]);
        $newer  = $this->conversation(2, ['tstamp' => strtotime(self::NOW) - 10]);
        $result = $this->widget(1, $this->repository([$newer, $older], [2 => strtotime(self::NOW) - 3600]))->templateVariables();

        self::assertSame([1, 2], array_column($result['conversations'], 'uid'));
    }

    #[Test]
    public function aFreeConversationIsTitledByItsOwnTitleAndHasNoContext(): void
    {
        $this->actingAs(3);
        $entry = $this->widget(1, $this->repository([$this->conversation(1)]))->templateVariables()['conversations'][0];

        self::assertSame('Chat 1', $entry['title']);
        self::assertSame([], $entry['context']);
        self::assertNull($entry['page']);
        self::assertFalse($entry['pageGone']);
        self::assertNull($entry['progress']);
    }

    #[Test]
    public function aGuidedProcessIsTitledByItsSkillAndNamesThePageAndTheTour(): void
    {
        $this->actingAs(3);
        $conversation = $this->conversation(1);
        $conversation->setViewContext(12, 'web_layout', 1);
        $conversation->setSkillIdentifier('seo-optimieren', 4);

        $entry = $this->widget(1, $this->repository([$conversation]), [12 => ['uid' => 12, 'title' => 'Über uns']])
            ->templateVariables()['conversations'][0];

        self::assertSame('SEO optimieren', $entry['title']);
        self::assertSame([
            ['key' => self::LL . 'widget.context.page', 'arguments' => ['Über uns']],
            ['key' => self::LL . 'widget.context.guidedTour', 'arguments' => []],
        ], $entry['context']);
        self::assertSame(['uid' => 12, 'languageUid' => 1], $entry['page']);
    }

    /** A skill without a title has no name an editor would understand. */
    #[Test]
    public function aGuidedProcessWithoutASkillTitleKeepsTheConversationTitle(): void
    {
        $this->actingAs(3);
        $conversation = $this->conversation(1);
        $conversation->setSkillIdentifier('unlabelled-skill');

        self::assertSame('Chat 1', $this->widget(1, $this->repository([$conversation]))->templateVariables()['conversations'][0]['title']);
    }

    /** A skill nr-llm marks as plain is no guided process: no tour, no skill title, no chip. */
    #[Test]
    public function aPlainSkillIsNoGuidedTour(): void
    {
        $this->actingAs(3);
        $conversation = $this->withGuidedState($this->conversation(1), 2, 5);
        $conversation->setSkillIdentifier('seo-optimieren');

        $entry = $this->widget(1, $this->repository([$conversation]), processRun: false)->templateVariables()['conversations'][0];

        self::assertSame('Chat 1', $entry['title']);
        self::assertSame([], $entry['context']);
        self::assertNull($entry['progress']);
    }

    #[Test]
    public function anInterruptedGuidedProcessShowsThePointItIsAt(): void
    {
        $this->actingAs(3);
        $conversation = $this->withGuidedState($this->conversation(1), 3, 5);
        $conversation->setSkillIdentifier('seo-optimieren');

        self::assertSame(['current' => 3, 'total' => 5], $this->widget(1, $this->repository([$conversation]))->templateVariables()['conversations'][0]['progress']);
    }

    #[Test]
    public function aFinishedGuidedProcessShowsNoProgress(): void
    {
        $this->actingAs(3);
        $conversation = $this->withGuidedState($this->conversation(1), 5, 5, completed: true);
        $conversation->setSkillIdentifier('seo-optimieren');

        self::assertNull($this->widget(1, $this->repository([$conversation]))->templateVariables()['conversations'][0]['progress']);
    }

    /** Point 0 is the analysis before the first point; the header says so, the list shows no chip yet. */
    #[Test]
    public function aGuidedProcessShowsNoProgressBeforeItsFirstPoint(): void
    {
        $this->actingAs(3);
        $conversation = $this->withGuidedState($this->conversation(1), 0, 5);
        $conversation->setSkillIdentifier('seo-optimieren');

        self::assertNull($this->widget(1, $this->repository([$conversation]))->templateVariables()['conversations'][0]['progress']);
    }

    /**
     * A guided process on a page the user may not see is left out, and the
     * next one takes its place.
     */
    #[Test]
    public function aGuidedProcessOnAPageTheUserMayNotSeeIsLeftOut(): void
    {
        $this->actingAs(3);
        $conversations = [];
        for ($i = 1; $i <= 6; ++$i) {
            $conversations[] = $this->conversation($i, ['tstamp' => strtotime(self::NOW) - $i]);
        }

        $conversations[1]->setViewContext(99, 'web_layout');
        $conversations[1]->setSkillIdentifier('seo-optimieren');

        $entries = $this->widget(1, $this->repository($conversations))->templateVariables()['conversations'];

        self::assertSame([1, 3, 4, 5, 6], array_column($entries, 'uid'));
    }

    /**
     * Any other conversation stores the page the browser showed with its last
     * message: where the editor was, not what the chat is about. It is not
     * named, not checked and not opened, whether the page is there, hidden
     * from the user or deleted.
     *
     * @return iterable<string, array{array<int, array{uid: int, title: string}>, list<int>}>
     */
    public static function lastPagesOfAnOrdinaryChat(): iterable
    {
        yield 'a page the user may see' => [[12 => ['uid' => 12, 'title' => 'Über uns']], []];
        yield 'a page the user may not see' => [[], []];
        yield 'a deleted page' => [[], [12]];
    }

    /**
     * @param array<int, array{uid: int, title: string}> $accessible
     * @param list<int>                                  $gone
     */
    #[Test]
    #[DataProvider('lastPagesOfAnOrdinaryChat')]
    public function anOrdinaryChatIsListedWithoutItsLastPage(array $accessible, array $gone): void
    {
        $this->actingAs(3);
        $conversation = $this->conversation(1);
        $conversation->setViewContext(12, 'web_layout', 0);

        $entries = $this->widget(1, $this->repository([$conversation]), $accessible, $gone)->templateVariables()['conversations'];

        self::assertCount(1, $entries);
        self::assertSame([], $entries[0]['context']);
        self::assertNull($entries[0]['page']);
        self::assertFalse($entries[0]['pageGone']);
    }

    /**
     * A catalogue whose one skill declares a page need (ADR-025).
     */
    private function catalogueDeclaring(string $page, bool $process): SkillCatalogueInterface
    {
        $skills = $this->createStub(SkillCatalogueInterface::class);
        $skills->method('find')->willReturn(['identifier' => 'seo-optimieren', 'name' => 'SEO', 'description' => '', 'uid' => 4, 'process' => $process, 'page' => $page]);

        return $skills;
    }

    /**
     * A process that only may work on a page (ADR-025) follows the browser,
     * so its stored page is where the editor last was: not named, and not a
     * reason to hide it when the user may not see that page.
     *
     * @return iterable<string, array{array<int, array{uid: int, title: string}>}>
     */
    public static function lastPagesOfAnOptionalProcess(): iterable
    {
        yield 'a page the user may see' => [[12 => ['uid' => 12, 'title' => 'Über uns']]];
        yield 'a page the user may not see' => [[]];
    }

    /**
     * @param array<int, array{uid: int, title: string}> $accessible
     */
    #[Test]
    #[DataProvider('lastPagesOfAnOptionalProcess')]
    public function aProcessThatMayWorkOnAPageIsListedWithoutItsLastPage(array $accessible): void
    {
        $this->actingAs(3);
        $conversation = $this->conversation(1);
        $conversation->setViewContext(12, 'web_layout', 0);
        $conversation->setSkillIdentifier('seo-optimieren');

        $entries = $this->widget(1, $this->repository([$conversation]), $accessible, skills: $this->catalogueDeclaring('optional', true))->templateVariables()['conversations'];

        self::assertCount(1, $entries);
        self::assertNull($entries[0]['page']);
        self::assertSame([['key' => self::LL . 'widget.context.guidedTour', 'arguments' => []]], $entries[0]['context']);
    }

    /** A skill that is no process but needs its page is bound to it, and named by it. */
    #[Test]
    public function aPlainSkillThatNeedsAPageNamesItsPage(): void
    {
        $this->actingAs(3);
        $conversation = $this->conversation(1);
        $conversation->setViewContext(12, 'web_layout', 1);
        $conversation->setSkillIdentifier('seo-optimieren');

        $entry = $this->widget(1, $this->repository([$conversation]), [12 => ['uid' => 12, 'title' => 'Über uns']], processRun: false, skills: $this->catalogueDeclaring('required', false))
            ->templateVariables()['conversations'][0];

        self::assertSame(['uid' => 12, 'languageUid' => 1], $entry['page']);
        self::assertSame([['key' => self::LL . 'widget.context.page', 'arguments' => ['Über uns']]], $entry['context']);
    }

    /** Such a skill gives up a deleted page (it asks for a new one), so the list shows no deleted page. */
    #[Test]
    public function aPlainSkillOnADeletedPageIsNotShownAsDeleted(): void
    {
        $this->actingAs(3);
        $conversation = $this->conversation(1);
        $conversation->setViewContext(42, 'web_layout', 0);
        $conversation->setSkillIdentifier('seo-optimieren');

        $entries = $this->widget(1, $this->repository([$conversation]), gonePages: [42], processRun: false, skills: $this->catalogueDeclaring('required', false))->templateVariables()['conversations'];

        self::assertCount(1, $entries);
        self::assertFalse($entries[0]['pageGone']);
        self::assertNull($entries[0]['page']);
        self::assertSame([], $entries[0]['context']);
    }

    /** A deleted page is no reason to hide the guided process: it is said, not named; it cannot go on, so no point is shown. */
    #[Test]
    public function aGuidedProcessOnADeletedPageStaysAndSaysSo(): void
    {
        $this->actingAs(3);
        $conversation = $this->withGuidedState($this->conversation(1), 2, 4);
        $conversation->setViewContext(42, 'web_layout', 0);
        $conversation->setSkillIdentifier('seo-optimieren');

        $entry = $this->widget(1, $this->repository([$conversation]), gonePages: [42])->templateVariables()['conversations'][0];

        self::assertTrue($entry['pageGone']);
        self::assertNull($entry['page']);
        self::assertSame([
            ['key' => self::LL . 'widget.context.pageGone', 'arguments' => []],
            ['key' => self::LL . 'widget.context.guidedTour', 'arguments' => []],
        ], $entry['context']);
        self::assertNull($entry['progress']);
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

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function times(): iterable
    {
        yield 'today, German' => ['2026-10-24T08:05:00+00:00', 'de', 'today', '/^08:05$/'];
        yield 'today, English' => ['2026-10-24T08:05:00+00:00', '', 'today', '/^8:05\s?AM$/u'];
        yield 'just after midnight is today' => ['2026-10-24T00:01:00+00:00', 'de', 'today', '/^00:01$/'];
        yield 'yesterday, German' => ['2026-10-23T16:05:00+00:00', 'de', 'yesterday', '/^16:05$/'];
        yield 'yesterday, English' => ['2026-10-23T16:05:00+00:00', '', 'yesterday', '/^4:05\s?PM$/u'];
        yield 'the day before, German' => ['2026-10-22T23:59:00+00:00', 'de', 'date', '/^22\. Okt\.$/'];
        yield 'earlier this year, German' => ['2026-10-03T09:00:00+00:00', 'de', 'date', '/^3\. Okt\.$/'];
        yield 'earlier this year, English' => ['2026-10-03T09:00:00+00:00', '', 'date', '/^Oct 3$/'];
        yield 'just under a year, German' => ['2025-10-25T09:00:00+00:00', 'de', 'date', '/^25\. Okt\.$/'];
        yield 'over a year, German' => ['2025-10-23T09:00:00+00:00', 'de', 'date', '/^23\. Okt\. 2025$/'];
        yield 'over a year, English' => ['2025-10-23T09:00:00+00:00', '', 'date', '/^Oct 23, 2025$/'];
    }

    /** Calendar days relative to the request's time, in the user's language. */
    #[Test]
    #[DataProvider('times')]
    public function theTimeOfTheLastMessageIsRelativeToToday(string $at, string $lang, string $kind, string $text): void
    {
        $this->actingAs(3, $lang);
        $time = $this->widget(1, $this->repository([$this->conversation(1)], [1 => strtotime($at)]))
            ->templateVariables()['conversations'][0]['time'];

        self::assertSame($kind, $time['kind']);
        self::assertMatchesRegularExpression($text, $time['text']);
        self::assertSame((new DateTimeImmutable($at))->format(DATE_ATOM), $time['datetime']);
    }

    /** "Today" is the server's calendar day, not the last 24 hours. */
    #[Test]
    public function todayFollowsTheServersTimeZone(): void
    {
        date_default_timezone_set('Europe/Berlin');
        $this->actingAs(3, 'de');
        // 23:30 UTC on the 23rd is 01:30 on the 24th in Berlin.
        $time = $this->widget(1, $this->repository([$this->conversation(1)], [1 => strtotime('2026-10-23T23:30:00+00:00')]))
            ->templateVariables()['conversations'][0]['time'];

        self::assertSame('today', $time['kind']);
        self::assertSame('01:30', $time['text']);
    }
}
