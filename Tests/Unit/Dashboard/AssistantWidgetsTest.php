<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Dashboard;

use LogicException;
use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Dashboard\AssistantListWidget;
use Netresearch\NrMcpAgent\Dashboard\CreateContentWidget;
use Netresearch\NrMcpAgent\Dashboard\EditPageWidget;
use Netresearch\NrMcpAgent\Dashboard\ImprovePageWidget;
use Netresearch\NrMcpAgent\Dashboard\PromptWidget;
use Netresearch\NrMcpAgent\Dashboard\QuickTasksWidget;
use Netresearch\NrMcpAgent\Dashboard\RecommendationsWidget;
use Netresearch\NrMcpAgent\Dashboard\WebsiteCheckWidget;
use Netresearch\NrMcpAgent\Domain\Repository\MissingDescriptionRepository;
use Netresearch\NrMcpAgent\Domain\Repository\PageChoiceRepository;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextList;
use Netresearch\NrMcpAgent\Service\Assistant\ChatStartUriBuilder;
use Netresearch\NrMcpAgent\Service\Assistant\ContentTypeProvider;
use Netresearch\NrMcpAgent\Service\Assistant\DescriptionTour;
use Netresearch\NrMcpAgent\Service\Assistant\GuidedSkill;
use Netresearch\NrMcpAgent\Service\Assistant\GuidedSkillProviderInterface;
use Netresearch\NrMcpAgent\Service\Assistant\OpenPoint;
use Netresearch\NrMcpAgent\Service\Assistant\OpenPointReaderInterface;
use Netresearch\NrMcpAgent\Service\Assistant\QuickTask;
use Netresearch\NrMcpAgent\Service\Assistant\QuickTaskProviderInterface;
use Netresearch\NrMcpAgent\Service\Assistant\SkillResolverInterface;
use Netresearch\NrMcpAgent\Tests\Unit\Service\Assistant\SkillLabelsStub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;

/**
 * What the AI assistant widgets put in front of an editor. Rendering is in
 * the functional AssistantWidgetsRenderingTest. Labels are not loaded here,
 * so a label reads as its key.
 */
final class AssistantWidgetsTest extends TestCase
{
    use SkillLabelsStub;

    private const LABELS = ['skill.seo-optimieren.title' => 'SEO optimieren', 'skill.inhalt-verbessern.title' => 'Inhalt verbessern'];

    private function chatAccess(bool $available): ChatToolbarItem
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn($available ? 1 : 0);
        $config->method('getAllowedGroupIds')->willReturn([]);

        return new ChatToolbarItem($config, $this->createMock(PageRenderer::class));
    }

    private function uriBuilder(): UriBuilder
    {
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturnCallback(
            static fn (string $route, array $parameters = []): Uri => new Uri('/' . $route . '?' . http_build_query(['token' => 't'] + $parameters)),
        );

        return $uriBuilder;
    }

    private function chatStart(): ChatStartUriBuilder
    {
        return new ChatStartUriBuilder($this->uriBuilder());
    }

    private function viewFactory(): BackendViewFactory
    {
        // Final, and only needed for rendering.
        return (new ReflectionClass(BackendViewFactory::class))->newInstanceWithoutConstructor();
    }

    private function improve(bool $available, GuidedSkillProviderInterface $skills): ImprovePageWidget
    {
        return new ImprovePageWidget($this->createMock(WidgetConfigurationInterface::class), $this->viewFactory(), $this->chatAccess($available), $skills, $this->chatStart());
    }

    private function prompt(bool $available): PromptWidget
    {
        return new PromptWidget($this->createMock(WidgetConfigurationInterface::class), $this->viewFactory(), $this->chatAccess($available), $this->chatStart());
    }

    private function tasks(bool $available, QuickTaskProviderInterface $tasks): QuickTasksWidget
    {
        return new QuickTasksWidget($this->createMock(WidgetConfigurationInterface::class), $this->viewFactory(), $this->chatAccess($available), $tasks, $this->chatStart());
    }

    /** Every skill resolves to `3:<skill>`, except those listed. */
    private function resolverWithout(string ...$missing): SkillResolverInterface
    {
        $resolver = $this->createMock(SkillResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static fn (string $skill): ?string => in_array($skill, $missing, true) ? null : '3:' . $skill);

        return $resolver;
    }

    /**
     * @param array{count: int, translations: int, first: array{pageUid: int, languageUid: int, title: string}|null}|null $summary
     */
    private function descriptionTour(?array $summary, string ...$missingSkills): DescriptionTour
    {
        $repository = $this->createMock(MissingDescriptionRepository::class);
        $repository->method('summary')->willReturn($summary);

        return new DescriptionTour($repository, $this->skillLabels(self::LABELS), $this->resolverWithout(...$missingSkills), $this->createMock(FrontendInterface::class));
    }

    /**
     * @param array{count: int, pages: int, records: int}|null $altTexts
     */
    private function recommendations(bool $available, OpenPointReaderInterface $reader, PageChoiceRepository $pages, ?array $altTexts = null, ?DescriptionTour $descriptions = null): RecommendationsWidget
    {
        $list = null;
        if ($altTexts !== null) {
            $list = $this->createMock(MissingAltTextList::class);
            $list->method('summary')->willReturn($altTexts);
            $list->method('uri')->willReturn('/alt-texts');
        }

        return new RecommendationsWidget(
            $this->createMock(WidgetConfigurationInterface::class),
            $this->viewFactory(),
            $this->chatAccess($available),
            $reader,
            $pages,
            $this->skillLabels(self::LABELS),
            $this->resolverWithout('inhalt-verbessern'),
            $this->chatStart(),
            $list,
            $descriptions,
        );
    }

    private function noPoints(): OpenPointReaderInterface
    {
        $reader = $this->createMock(OpenPointReaderInterface::class);
        $reader->method('findOpen')->willReturn([]);

        return $reader;
    }

    #[Test]
    public function aUserTheChatIsNotAvailableForIsOfferedNothing(): void
    {
        $skills = $this->createMock(GuidedSkillProviderInterface::class);
        $skills->expects(self::never())->method('getGuidedSkills');
        $tasks = $this->createMock(QuickTaskProviderInterface::class);
        $tasks->expects(self::never())->method('getQuickTasks');
        $reader = $this->createMock(OpenPointReaderInterface::class);
        $reader->expects(self::never())->method('findOpen');
        $pages = $this->createMock(PageChoiceRepository::class);
        $pages->expects(self::never())->method(self::anything());
        $types = $this->createMock(ContentTypeProvider::class);
        $types->expects(self::never())->method('available');
        $repository = $this->createMock(MissingDescriptionRepository::class);
        $repository->expects(self::never())->method('summary');
        $descriptions = new DescriptionTour($repository, $this->skillLabels(self::LABELS), $this->resolverWithout(), $this->createMock(FrontendInterface::class));

        self::assertSame(['available' => false, 'rows' => []], $this->improve(false, $skills)->templateVariables());
        self::assertSame(['available' => false, 'rows' => []], $this->tasks(false, $tasks)->templateVariables());
        self::assertSame(['available' => false, 'intro' => '', 'tiles' => [], 'points' => []], $this->recommendations(false, $reader, $pages, ['count' => 3, 'pages' => 1, 'records' => 0], $descriptions)->templateVariables());
        self::assertSame(['available' => false, 'form' => null, 'idPrefix' => ''], $this->prompt(false)->templateVariables());
        self::assertSame(['available' => false, 'uri' => ''], (new EditPageWidget($this->createMock(WidgetConfigurationInterface::class), $this->viewFactory(), $this->chatAccess(false), $this->uriBuilder()))->templateVariables());
        self::assertSame(['available' => false, 'types' => [], 'uri' => ''], (new CreateContentWidget($this->createMock(WidgetConfigurationInterface::class), $this->viewFactory(), $this->chatAccess(false), $types, $this->uriBuilder()))->templateVariables());
        self::assertSame(['available' => false, 'checks' => []], (new WebsiteCheckWidget($this->createMock(WidgetConfigurationInterface::class), $this->viewFactory(), $this->chatAccess(false), $this->createMock(MissingAltTextList::class), $this->chatStart(), $descriptions))->templateVariables());
    }

    /**
     * The prompt's form targets the chat module, the way in where the panel
     * is not available; the question itself is handed to the panel by its
     * script, which the dashboard loads with the widget's stylesheet.
     */
    #[Test]
    public function thePromptOffersAFormToTheChatAndItsScript(): void
    {
        $widget    = $this->prompt(true);
        $variables = $widget->templateVariables();

        self::assertTrue($variables['available']);
        self::assertSame(['action' => '/nr_mcp_agent_chat', 'hidden' => ['token' => 't']], $variables['form']);
        self::assertMatchesRegularExpression('/^nr-mcp-agent-prompt-[0-9a-f]{8}$/', $variables['idPrefix']);
        self::assertNotSame($variables['idPrefix'], $this->prompt(true)->templateVariables()['idPrefix']);

        $modules = $widget->getJavaScriptModuleInstructions();
        self::assertCount(1, $modules);
        self::assertSame('@netresearch/nr-mcp-agent/prompt-widget.js', $modules[0]->getName());
        self::assertSame(['EXT:nr_mcp_agent/Resources/Public/Css/prompt-widget.css'], $widget->getCssFiles());
        self::assertFileExists(__DIR__ . '/../../../Resources/Public/Css/prompt-widget.css');
        self::assertFileExists(__DIR__ . '/../../../Resources/Public/JavaScript/prompt-widget.js');
    }

    /** The widgets whose actions start the chat load the start script and the shared styles. */
    #[Test]
    public function theActionWidgetsLoadTheStartScriptAndTheirStyles(): void
    {
        $widget = $this->improve(true, $this->createMock(GuidedSkillProviderInterface::class));
        self::assertInstanceOf(AssistantListWidget::class, $widget);

        $modules = $widget->getJavaScriptModuleInstructions();
        self::assertCount(1, $modules);
        self::assertSame('@netresearch/nr-mcp-agent/chat-start.js', $modules[0]->getName());
        self::assertSame(['EXT:nr_mcp_agent/Resources/Public/Css/assistant-widgets.css'], $widget->getCssFiles());
        self::assertFileExists(__DIR__ . '/../../../Resources/Public/Css/assistant-widgets.css');
        self::assertFileExists(__DIR__ . '/../../../Resources/Public/JavaScript/chat-start.js');

        foreach ([CreateContentWidget::class, EditPageWidget::class, QuickTasksWidget::class, RecommendationsWidget::class, WebsiteCheckWidget::class] as $class) {
            self::assertTrue(is_subclass_of($class, AssistantListWidget::class), $class);
        }
    }

    /** Rendering needs the request the dashboard hands over; without it the widget says so. */
    #[Test]
    public function renderingWithoutTheRequestFailsWithAClearMessage(): void
    {
        $widget = $this->recommendations(false, $this->createMock(OpenPointReaderInterface::class), $this->createMock(PageChoiceRepository::class));

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1792937620);
        $widget->renderWidgetContent();
    }

    /** One row per tour; each row starts the tour, which then asks for the page. */
    #[Test]
    public function improveAPageOffersOneRowPerTour(): void
    {
        $skills = $this->createMock(GuidedSkillProviderInterface::class);
        $skills->method('getGuidedSkills')->willReturn([new GuidedSkill('3:seo-optimieren', 'SEO optimieren', 'Prüfen'), new GuidedSkill('3:inhalt-verbessern', 'Inhalt verbessern', '')]);

        self::assertSame(
            ['available' => true, 'rows' => [
                ['label' => 'SEO optimieren', 'description' => 'Prüfen', 'uri' => '/nr_mcp_agent_chat?token=t&skill=3%3Aseo-optimieren', 'skill' => '3:seo-optimieren'],
                ['label' => 'Inhalt verbessern', 'description' => '', 'uri' => '/nr_mcp_agent_chat?token=t&skill=3%3Ainhalt-verbessern', 'skill' => '3:inhalt-verbessern'],
            ]],
            $this->improve(true, $skills)->templateVariables(),
        );
    }

    /** Every task is a link that starts its skill; whether it needs a page decides where it opens. */
    #[Test]
    public function everyTaskIsARowThatStartsItsSkill(): void
    {
        $tasks = $this->createMock(QuickTaskProviderInterface::class);
        $tasks->method('getQuickTasks')->willReturn([new QuickTask('Neue Seite anlegen', 'neue-seite', false), new QuickTask('SEO optimieren', 'seo-optimieren', true)]);

        self::assertSame(
            ['available' => true, 'rows' => [
                ['label' => 'Neue Seite anlegen', 'skill' => 'neue-seite', 'needsPage' => false, 'uri' => '/nr_mcp_agent_chat?token=t&skill=neue-seite'],
                ['label' => 'SEO optimieren', 'skill' => 'seo-optimieren', 'needsPage' => true, 'uri' => '/nr_mcp_agent_chat?token=t&skill=seo-optimieren'],
            ]],
            $this->tasks(true, $tasks)->templateVariables(),
        );
    }

    /** "Edit a page" links to the page module, the way in without the chat panel. */
    #[Test]
    public function editAPageLinksToThePageModule(): void
    {
        $widget = new EditPageWidget($this->createMock(WidgetConfigurationInterface::class), $this->viewFactory(), $this->chatAccess(true), $this->uriBuilder());

        self::assertSame(['available' => true, 'uri' => '/web_layout?token=t'], $widget->templateVariables());
    }

    /** "Create content" offers the kinds the provider names, and the chat module as the way in. */
    #[Test]
    public function createContentOffersTheKindsTheChatCanCreate(): void
    {
        $types = $this->createMock(ContentTypeProvider::class);
        $types->method('available')->willReturn(['page']);
        $widget = new CreateContentWidget($this->createMock(WidgetConfigurationInterface::class), $this->viewFactory(), $this->chatAccess(true), $types, $this->uriBuilder());

        self::assertSame(['available' => true, 'types' => ['page'], 'uri' => '/nr_mcp_agent_chat?token=t'], $widget->templateVariables());
    }

    /**
     * The store is not trusted to filter: a point on a page the user may not
     * access, for a skill without a name or that does not resolve, or in a
     * language the chat would not start in, is not shown. The link carries
     * the resolved skill and the point's language (decision 4), and so does
     * the start in the panel.
     */
    #[Test]
    public function recommendationsShowOnlyPointsOnAccessiblePagesWithANamedSkill(): void
    {
        $reader = $this->createMock(OpenPointReaderInterface::class);
        $reader->expects(self::once())->method('findOpen')->with(RecommendationsWidget::WINDOW)->willReturn([
            new OpenPoint(30, 0, 'seo-optimieren', 'Fremde Seite.'),
            new OpenPoint(21, 0, 'unlabelled', 'Ohne Namen.'),
            new OpenPoint(21, 0, 'inhalt-verbessern', 'Skill nicht startbar.'),
            new OpenPoint(21, 2, 'seo-optimieren', 'Sprache nicht startbar.'),
            new OpenPoint(21, 1, 'seo-optimieren', 'Die Meta Description fehlt.'),
        ]);
        $pages = $this->createMock(PageChoiceRepository::class);
        $pages->method('findAccessible')->willReturnCallback(
            static fn (int $uid): ?array => $uid === 21 ? ['uid' => 21, 'title' => 'Inhalt A'] : null,
        );
        $pages->method('mayStartIn')->willReturnCallback(static fn (int $uid, int $language): bool => $language !== 2);

        $variables = $this->recommendations(true, $reader, $pages)->templateVariables();

        self::assertTrue($variables['available']);
        self::assertSame('recommendations.intro', $variables['intro']);
        self::assertSame(
            [['summary' => 'Die Meta Description fehlt.', 'start' => 'recommendations.start', 'uri' => '/nr_mcp_agent_chat?token=t&skill=3%3Aseo-optimieren&pageUid=21&languageUid=1', 'skill' => '3:seo-optimieren', 'page' => 21, 'language' => 1]],
            $variables['points'],
        );
    }

    /**
     * Points the widget leaves out do not take its places: with the newest
     * points all left out, older ones fill the list, which stops at its limit.
     */
    #[Test]
    public function leftOutPointsDoNotTakeThePlacesOfOlderOnes(): void
    {
        $gone   = array_map(static fn (int $i): OpenPoint => new OpenPoint(21, 0, 'inhalt-verbessern', 'Skill nicht startbar ' . $i . '.'), range(1, 5));
        $valid  = array_map(static fn (int $i): OpenPoint => new OpenPoint(21, 0, 'seo-optimieren', 'Punkt ' . $i . '.'), range(1, 7));
        $reader = $this->createMock(OpenPointReaderInterface::class);
        // The reader keeps to its limit, as the store does.
        $reader->method('findOpen')->willReturnCallback(static fn (int $limit): array => array_slice([...$gone, ...$valid], 0, $limit));
        $pages = $this->createMock(PageChoiceRepository::class);
        $pages->method('findAccessible')->willReturn(['uid' => 21, 'title' => 'Inhalt A']);
        $pages->method('mayStartIn')->willReturn(true);

        self::assertSame(
            ['Punkt 1.', 'Punkt 2.', 'Punkt 3.', 'Punkt 4.', 'Punkt 5.'],
            array_column($this->recommendations(true, $reader, $pages)->templateVariables()['points'], 'summary'),
        );
    }

    /**
     * The tiles: alt texts with where they are and a link to the list; meta
     * descriptions with the SEO tour on the newest page without one, in its
     * language. Each action's name carries the number and what it counts.
     */
    #[Test]
    public function theTilesCountAndStartTheMatchingFlow(): void
    {
        $tiles = $this->recommendations(
            true,
            $this->noPoints(),
            $this->createMock(PageChoiceRepository::class),
            ['count' => 23, 'pages' => 11, 'records' => 5],
            $this->descriptionTour(['count' => 8, 'translations' => 0, 'first' => ['pageUid' => 21, 'languageUid' => 1, 'title' => 'Startseite']]),
        )->templateVariables()['tiles'];

        self::assertSame([
            [
                'key'    => 'altTexts',
                'count'  => 23,
                'label'  => 'altTexts.label',
                'detail' => 'altTexts.foundBoth',
                'done'   => '',
                'action' => ['text' => 'altTexts.view', 'name' => 'tile.actionName', 'uri' => '/alt-texts', 'start' => null],
            ],
            [
                'key'    => 'descriptions',
                'count'  => 8,
                'label'  => 'descriptions.label',
                'detail' => 'descriptions.firstPage',
                'done'   => '',
                'action' => [
                    'text'  => 'descriptions.start',
                    'name'  => 'descriptions.startName',
                    'uri'   => '/nr_mcp_agent_chat?token=t&skill=3%3Aseo-optimieren&pageUid=21&languageUid=1',
                    'start' => ['skill' => '3:seo-optimieren', 'page' => 21, 'language' => 1],
                ],
            ],
        ], $tiles);
    }

    /** One of a kind is named in the singular. */
    #[Test]
    public function aSingleHitIsNamedInTheSingular(): void
    {
        $tiles = $this->recommendations(
            true,
            $this->noPoints(),
            $this->createMock(PageChoiceRepository::class),
            ['count' => 1, 'pages' => 1, 'records' => 0],
            $this->descriptionTour(['count' => 1, 'translations' => 0, 'first' => ['pageUid' => 21, 'languageUid' => 0, 'title' => '']]),
        )->templateVariables()['tiles'];

        self::assertSame(['altTexts.labelOne', 'descriptions.labelOne'], array_column($tiles, 'label'));
        self::assertSame('altTexts.foundOne', $tiles[0]['detail']);
    }

    /** The label says what is counted: pages, translations, or both. */
    #[Test]
    public function theLabelSaysWhetherTranslationsAreCounted(): void
    {
        $label = fn (int $count, int $translations): string => $this->recommendations(
            true,
            $this->noPoints(),
            $this->createMock(PageChoiceRepository::class),
            null,
            $this->descriptionTour(['count' => $count, 'translations' => $translations, 'first' => ['pageUid' => 21, 'languageUid' => 0, 'title' => 'A']]),
        )->templateVariables()['tiles'][0]['label'];

        self::assertSame('descriptions.label', $label(3, 0));
        self::assertSame('descriptions.labelOne', $label(1, 0));
        self::assertSame('descriptions.translations', $label(3, 3));
        self::assertSame('descriptions.translationOne', $label(1, 1));
        self::assertSame('descriptions.labelMixed', $label(3, 1));
    }

    /** A count of 0 is a short success state without an action; the opening sentence says nothing is open. */
    #[Test]
    public function aCountOfZeroIsASuccessWithoutAnAction(): void
    {
        $variables = $this->recommendations(
            true,
            $this->noPoints(),
            $this->createMock(PageChoiceRepository::class),
            ['count' => 0, 'pages' => 0, 'records' => 0],
            $this->descriptionTour(['count' => 0, 'translations' => 0, 'first' => null]),
        )->templateVariables();

        self::assertSame('recommendations.allDone', $variables['intro']);
        self::assertSame([
            ['key' => 'altTexts', 'count' => 0, 'label' => 'altTexts.label', 'detail' => '', 'done' => 'altTexts.done', 'action' => null],
            ['key' => 'descriptions', 'count' => 0, 'label' => 'descriptions.label', 'detail' => '', 'done' => 'descriptions.done', 'action' => null],
        ], $variables['tiles']);
    }

    /**
     * No tile where nothing can be counted for the user, or where the SEO
     * tour is not available to them: an action that starts nothing is no
     * action. Without tiles and points the widget says how points come about.
     */
    #[Test]
    public function noTileWhereNothingCanBeCountedOrStarted(): void
    {
        $pages = $this->createMock(PageChoiceRepository::class);
        $first = ['count' => 4, 'translations' => 0, 'first' => ['pageUid' => 21, 'languageUid' => 0, 'title' => 'A']];

        $withoutAccess = $this->recommendations(true, $this->noPoints(), $pages, null, $this->descriptionTour(null))->templateVariables();
        self::assertSame([], $withoutAccess['tiles']);
        self::assertSame('recommendations.empty', $withoutAccess['intro']);

        self::assertSame([], $this->recommendations(true, $this->noPoints(), $pages, null, $this->descriptionTour($first, 'seo-optimieren'))->templateVariables()['tiles']);
        self::assertSame(['descriptions'], array_column($this->recommendations(true, $this->noPoints(), $pages, null, $this->descriptionTour($first))->templateVariables()['tiles'], 'key'));
    }

    /**
     * "Check website" always lists the alt texts; the meta descriptions while
     * a page lacks one, starting the SEO tour on it.
     */
    #[Test]
    public function checkWebsiteListsTheChecksThatHaveSomethingToDo(): void
    {
        $altTexts = $this->createMock(MissingAltTextList::class);
        $altTexts->method('uri')->willReturn('/alt-texts');
        $widget = fn (?DescriptionTour $tour): WebsiteCheckWidget => new WebsiteCheckWidget($this->createMock(WidgetConfigurationInterface::class), $this->viewFactory(), $this->chatAccess(true), $altTexts, $this->chatStart(), $tour);

        self::assertSame(
            ['available' => true, 'checks' => [
                ['label' => 'websiteCheck.altTexts', 'uri' => '/alt-texts', 'start' => null],
                ['label' => 'websiteCheck.descriptions', 'uri' => '/nr_mcp_agent_chat?token=t&skill=3%3Aseo-optimieren&pageUid=9&languageUid=0', 'start' => ['skill' => '3:seo-optimieren', 'page' => 9, 'language' => 0]],
            ]],
            $widget($this->descriptionTour(['count' => 2, 'translations' => 0, 'first' => ['pageUid' => 9, 'languageUid' => 0, 'title' => 'Neun']]))->templateVariables(),
        );
        self::assertSame(['websiteCheck.altTexts'], array_column($widget($this->descriptionTour(['count' => 0, 'translations' => 0, 'first' => null]))->templateVariables()['checks'], 'label'));
        self::assertSame(['websiteCheck.altTexts'], array_column($widget(null)->templateVariables()['checks'], 'label'));
    }
}
