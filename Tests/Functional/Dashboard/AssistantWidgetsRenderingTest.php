<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Dashboard;

use DOMDocument;
use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Dashboard\AbstractAssistantWidget;
use Netresearch\NrMcpAgent\Dashboard\EditPageWidget;
use Netresearch\NrMcpAgent\Dashboard\ImprovePageWidget;
use Netresearch\NrMcpAgent\Dashboard\QuickTasksWidget;
use Netresearch\NrMcpAgent\Dashboard\RecommendationsWidget;
use Netresearch\NrMcpAgent\Domain\Repository\MissingDescriptionRepository;
use Netresearch\NrMcpAgent\Domain\Repository\PageChoiceRepository;
use Netresearch\NrMcpAgent\Domain\Repository\SkillTrustRepository;
use Netresearch\NrMcpAgent\Service\Assistant\CatalogueSkillResolver;
use Netresearch\NrMcpAgent\Service\Assistant\ChatStartUriBuilder;
use Netresearch\NrMcpAgent\Service\Assistant\ConfiguredGuidedSkillProvider;
use Netresearch\NrMcpAgent\Service\Assistant\ConfiguredQuickTaskProvider;
use Netresearch\NrMcpAgent\Service\Assistant\DescriptionTour;
use Netresearch\NrMcpAgent\Service\Assistant\DeterministicPageSuggestionProvider;
use Netresearch\NrMcpAgent\Service\Assistant\NullOpenPointReader;
use Netresearch\NrMcpAgent\Service\Assistant\OpenPoint;
use Netresearch\NrMcpAgent\Service\Assistant\OpenPointReaderInterface;
use Netresearch\NrMcpAgent\Service\Assistant\PageSuggestion;
use Netresearch\NrMcpAgent\Service\Assistant\SkillLabels;
use Netresearch\NrMcpAgent\Service\Assistant\VisibleOpenPointReader;
use Netresearch\NrMcpAgent\Service\OpenPoint\OpenPointVisibility;
use Netresearch\NrMcpAgent\Tests\Functional\Service\Assistant\OpenPointStoreFixture;
use Netresearch\NrMcpAgent\Tests\Unit\Service\Assistant\SkillCatalogueStub;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Dashboard\DashboardPresetRegistry;
use TYPO3\CMS\Dashboard\WidgetGroupRegistry;
use TYPO3\CMS\Dashboard\WidgetRegistry;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The AI assistant widgets, group and preset.
 *
 * The installation under test has the chat's skill catalogue but no skills
 * attached, so the widgets rendered through the dashboard's own registry show
 * what an editor sees while the configured skills do not exist: no tour, no
 * task, an explanation instead.
 * The tests of the offers themselves build the widgets with a catalogue that
 * lists both default skills from source 3, as `3:<slug>`; the configured
 * defaults are bare slugs and resolve to them.
 *
 * Page 26 of the fixture is titled `<img src=x onerror=alert(1)>`: a page
 * title is editor input and must reach the widget as text.
 */
final class AssistantWidgetsRenderingTest extends FunctionalTestCase
{
    use OpenPointStoreFixture;

    private const XSS_TITLE = '<img src=x onerror=alert(1)>';

    private const XSS_ESCAPED = '&lt;img src=x onerror=alert(1)&gt;';

    protected array $coreExtensionsToLoad = ['dashboard', 'filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            // The default lists no frequent task; this one makes the widget show one.
            'nr_mcp_agent' => ['llmTaskUid' => '1', 'dashboardQuickTasks' => 'seo-optimieren|page'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/dashboard_page_choice.csv');
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['LANG']    = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    private function request(): ServerRequest
    {
        return (new ServerRequest('https://example.com/typo3/module/dashboard'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/dashboard', []));
    }

    /** The widget as the dashboard renders it, on this installation without a skill catalogue. */
    private function renderFromRegistry(string $identifier): string
    {
        return $this->get(WidgetRegistry::class)
            ->getAvailableWidget($this->request(), $identifier)
            ->renderWidgetContent();
    }

    /**
     * The widget with a skill catalogue that lists $skills (by default both
     * default skills).
     *
     * @param class-string<AbstractAssistantWidget> $class
     * @param list<string>                          $skills
     */
    private function render(string $class, ?OpenPointReaderInterface $reader = null, array $skills = ['seo-optimieren', 'inhalt-verbessern'], bool $descriptions = false): string
    {
        $catalogue = new SkillCatalogueStub(array_map(static fn (string $skill): array => ['identifier' => '3:' . $skill, 'uid' => 0], $skills));
        $resolver  = new CatalogueSkillResolver($this->get(SkillTrustRepository::class), $catalogue);
        $config    = new ExtensionConfiguration();
        $labels    = new SkillLabels($this->get(LanguageServiceFactory::class));
        $common    = [
            $this->createMock(WidgetConfigurationInterface::class),
            $this->get(BackendViewFactory::class),
            new ChatToolbarItem($config, $this->get(PageRenderer::class)),
        ];
        $pages = new PageChoiceRepository($this->get(ConnectionPool::class), $this->get(SiteFinder::class), $this->get(TcaSchemaFactory::class));
        $tour  = $descriptions ? new DescriptionTour(new MissingDescriptionRepository($this->get(ConnectionPool::class), $pages), $labels, $resolver) : null;

        $widget = match ($class) {
            ImprovePageWidget::class     => new ImprovePageWidget(...$common, ...[new ConfiguredGuidedSkillProvider($config, $labels, $resolver), $this->chatStart()]),
            QuickTasksWidget::class      => new QuickTasksWidget(...$common, ...[new ConfiguredQuickTaskProvider($config, $labels, $resolver), $this->chatStart()]),
            RecommendationsWidget::class => new RecommendationsWidget(...$common, ...[$reader ?? new NullOpenPointReader(), $pages, $labels, $resolver, $this->chatStart(), null, $tour]),
        };
        $widget->setRequest($this->request());

        return $widget->renderWidgetContent();
    }

    private function chatStart(): ChatStartUriBuilder
    {
        return new ChatStartUriBuilder($this->get(UriBuilder::class));
    }

    #[Test]
    public function theWidgetsAreRegisteredInTheirGroupAndPreset(): void
    {
        $widgets = $this->get(WidgetRegistry::class)->getAllWidgets();
        foreach (['nrMcpAgentPrompt', 'nrMcpAgentEditPage', 'nrMcpAgentCreateContent', 'nrMcpAgentImprovePage', 'nrMcpAgentQuickTasks', 'nrMcpAgentRecommendations', 'nrMcpAgentWebsiteCheck', 'nrMcpAgentAiChat'] as $identifier) {
            self::assertArrayHasKey($identifier, $widgets);
        }

        self::assertArrayHasKey('nrMcpAgent', $this->get(WidgetGroupRegistry::class)->getWidgetGroups());

        $presets = $this->get(DashboardPresetRegistry::class)->getDashboardPresets();
        self::assertArrayHasKey('nrMcpAgent', $presets);
        // 13.4 lists the identifiers, 14.3 one ['identifier' => …] entry each.
        $defaultWidgets = array_map(
            static fn (mixed $widget): mixed => is_array($widget) ? ($widget['identifier'] ?? null) : $widget,
            $presets['nrMcpAgent']->getDefaultWidgets(),
        );
        self::assertSame(
            ['nrMcpAgentPrompt', 'nrMcpAgentEditPage', 'nrMcpAgentCreateContent', 'nrMcpAgentAiChat', 'nrMcpAgentRecommendations', 'nrMcpAgentImprovePage', 'nrMcpAgentWebsiteCheck'],
            $defaultWidgets,
        );
        self::assertTrue($presets['nrMcpAgent']->isShowInWizard());
    }

    /**
     * The prompt as the dashboard renders it: the question under a visible
     * label, a send button with a name that starts disabled, and a form to the
     * chat module that does not carry the question (the field has no name).
     */
    #[Test]
    public function thePromptRendersALabelledFieldAndADisabledSendButton(): void
    {
        $html   = $this->renderFromRegistry('nrMcpAgentPrompt');
        $target = $this->chatStart()->formTarget();

        self::assertStringContainsString('<form action="' . htmlspecialchars($target['action']) . '" method="get" class="nr-mcp-agent-prompt" data-nr-mcp-agent-prompt="1">', $html);
        self::assertStringContainsString('<input type="hidden" name="token" value="' . htmlspecialchars($target['hidden']['token']) . '"', $html);
        self::assertMatchesRegularExpression('/<label class="nr-mcp-agent-prompt-question" for="(nr-mcp-agent-prompt-[0-9a-f]{8})-question">What would you like to do\?<\/label>\s*<div class="nr-mcp-agent-prompt-field">\s*<textarea id="\1-question"\s+class="form-control nr-mcp-agent-prompt-input"\s+aria-describedby="\1-error"\s+rows="3"\s+placeholder="Ask the assistant …"><\/textarea>/u', $html);
        // Why a question was refused is said in a live region the field names
        // (<output> is one: its implicit role is status).
        self::assertMatchesRegularExpression('/<output id="nr-mcp-agent-prompt-[0-9a-f]{8}-error" class="nr-mcp-agent-prompt-error" data-nr-mcp-agent-prompt-error="1"><\/output>/', $html);
        self::assertMatchesRegularExpression('/<button type="submit"\s+class="nr-mcp-agent-prompt-send"\s+aria-label="Send"\s+title="Send"\s+disabled>/', $html);

        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8"?>' . $html);
        $textarea = $document->getElementsByTagName('textarea')->item(0);
        self::assertNotNull($textarea);
        self::assertFalse($textarea->hasAttribute('name'), 'the question must not travel with the form');
        self::assertSame(1, $document->getElementsByTagName('button')->length);
    }

    /**
     * A GET form drops the query string of its action, so the form must carry
     * every other parameter of the module URL itself: the form's request and
     * the built link are the same request.
     */
    #[Test]
    public function theFormReachesTheSameUrlAsTheBuiltLink(): void
    {
        $link   = $this->chatStart()->build('3:seo-optimieren', 21, 0);
        $target = $this->chatStart()->formTarget();

        $linkPath = (string) parse_url($link, PHP_URL_PATH);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $linkQuery);
        $formQuery = $target['hidden'] + [$target['skillField'] => '3:seo-optimieren', $target['pageField'] => '21', $target['languageField'] => '0'];

        self::assertSame($linkPath, (string) parse_url($target['action'], PHP_URL_PATH));
        ksort($linkQuery);
        ksort($formQuery);
        self::assertSame($linkQuery, $formQuery);
        // Backend module URLs carry a route token; without it as a hidden
        // field the form would be refused.
        self::assertArrayHasKey('token', $target['hidden']);
    }

    /**
     * One row per tour, the whole row a link: to the chat module that starts
     * the tour, where the chat panel is not available, and with what the
     * panel needs to start it beside the page module, where the tour asks for
     * the page (chat-start.js). No form, no page choice in the widget.
     */
    #[Test]
    public function improveAPageRendersOneRowPerTour(): void
    {
        $html = $this->render(ImprovePageWidget::class);

        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('<select', $html);
        self::assertStringContainsString('<p class="nr-mcp-agent-intro">Choose a page, and we’ll go through it together step by step.</p>', $html);
        self::assertStringContainsString('<ul class="nr-mcp-agent-rows" role="list">', $html);
        foreach (['3:seo-optimieren' => 'Optimise SEO', '3:inhalt-verbessern' => 'Improve content'] as $skill => $title) {
            self::assertMatchesRegularExpression(
                '#<a class="nr-mcp-agent-row" href="' . preg_quote(htmlspecialchars($this->chatStart()->build($skill)), '#') . '" data-nr-chat-start="1" data-nr-chat-skill="' . preg_quote($skill, '#') . '" data-nr-chat-page="" data-nr-chat-language="" data-nr-chat-page-module="1"><span class="nr-mcp-agent-row-text"><span class="nr-mcp-agent-row-label">' . $title . '</span></span><svg class="nr-mcp-agent-row-chevron"[^>]*aria-hidden="true" focusable="false">#',
                $html,
            );
        }

        self::assertSame(2, substr_count($html, 'class="nr-mcp-agent-row"'));
    }

    /**
     * A task on one page opens the page module and asks for the page there;
     * a task without a page opens the chat module on it, as it always did.
     */
    #[Test]
    public function aTaskOnAPageStartsBesideThePageModule(): void
    {
        $html = $this->render(QuickTasksWidget::class);

        self::assertStringNotContainsString('<select', $html);
        self::assertStringContainsString('<a class="nr-mcp-agent-row" href="' . htmlspecialchars($this->chatStart()->build('3:seo-optimieren')) . '" data-nr-chat-start="1" data-nr-chat-skill="3:seo-optimieren" data-nr-chat-page="" data-nr-chat-language="" data-nr-chat-page-module="1">', $html);
    }

    /** "Edit a page": one primary action, to the page module, that the panel opens with a new chat beside it. */
    #[Test]
    public function editAPageRendersOnePrimaryAction(): void
    {
        $html = $this->renderFromRegistry('nrMcpAgentEditPage');
        $uri  = (string) $this->get(UriBuilder::class)->buildUriFromRoute(EditPageWidget::PAGE_MODULE);

        self::assertStringContainsString('<p class="nr-mcp-agent-intro">Choose a page, and we’ll change its content together.</p>', $html);
        self::assertStringContainsString('<a class="btn btn-primary nr-mcp-agent-primary" href="' . htmlspecialchars($uri) . '" data-nr-chat-start="1" data-nr-chat-page-module="1">Choose a page to edit</a>', $html);
        self::assertSame(1, substr_count($html, '<a '));
    }

    /**
     * No tool to create pages or news is enabled on this installation, so
     * "Create content" offers no button and says who can change that.
     */
    #[Test]
    public function withoutTheToolsCreateContentOffersNoButtonAndExplainsWhy(): void
    {
        $html = $this->renderFromRegistry('nrMcpAgentCreateContent');

        self::assertStringNotContainsString('<a ', $html);
        self::assertStringContainsString('I can’t create new content for you here yet. Your administrator can enable the tools for it.', $html);
    }

    /**
     * The skills seo-optimieren and inhalt-verbessern do not exist here: the
     * widget offers no tour that the chat would refuse, and says why.
     */
    #[Test]
    public function withoutTheSkillsImproveAPageOffersNoTourAndExplainsWhy(): void
    {
        $html = $this->renderFromRegistry('nrMcpAgentImprovePage');

        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('seo-optimieren', $html);
        self::assertStringContainsString('No guided tours are set up yet. Your administrator can set them up.', $html);
    }

    #[Test]
    public function withoutTheSkillsFrequentTasksOffersNoTaskAndExplainsWhy(): void
    {
        $html = $this->renderFromRegistry('nrMcpAgentQuickTasks');

        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('seo-optimieren', $html);
        self::assertStringContainsString('No frequent tasks are set up yet. Your administrator can set them up.', $html);
    }

    /**
     * An open point whose skill has a label but is not in the catalogue is
     * not offered as a tour the chat would refuse.
     */
    #[Test]
    public function aRecommendationForASkillThatDoesNotExistIsLeftOut(): void
    {
        $reader = new class implements OpenPointReaderInterface {
            public function findOpen(int $limit): array
            {
                return [new OpenPoint(21, 0, 'inhalt-verbessern', 'Die Seite hat keine Überschrift.')];
            }
        };

        $html = $this->render(RecommendationsWidget::class, $reader, ['seo-optimieren']);

        self::assertStringNotContainsString('keine Überschrift', $html);
        self::assertStringContainsString('No open recommendations.', $html);
    }

    /**
     * As the dashboard renders it here: no image lacks an alt text, so that
     * tile is a success; the SEO tour does not exist here, so there is no
     * meta-description tile, which could start nothing.
     */
    #[Test]
    public function withNothingOpenTheRecommendationsSayItIsDone(): void
    {
        $html = $this->renderFromRegistry('nrMcpAgentRecommendations');

        self::assertStringContainsString('<p class="nr-mcp-agent-intro">I’ve checked: nothing is open right now. Well done!</p>', $html);
        self::assertStringContainsString('<p class="nr-mcp-agent-tile-done-text">No missing alt texts – great!</p>', $html);
        self::assertStringNotContainsString('meta description', $html);
    }

    /**
     * A point's summary holds a page title, which an editor wrote, and so
     * does the link text: both reach the page as text.
     */
    #[Test]
    public function aRecommendationLinksToItsTourAndEscapesItsText(): void
    {
        $reader = new class implements OpenPointReaderInterface {
            public function findOpen(int $limit): array
            {
                return [new OpenPoint(26, 0, 'seo-optimieren', '<script>alert(2)</script> Meta Description fehlt.')];
            }
        };
        $html = $this->render(RecommendationsWidget::class, $reader);

        self::assertStringNotContainsString('<script>alert(2)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(2)&lt;/script&gt; Meta Description fehlt.', $html);
        self::assertStringNotContainsString(self::XSS_TITLE, $html);
        self::assertStringContainsString(
            '<a class="nr-mcp-agent-row" href="' . htmlspecialchars($this->chatStart()->build('3:seo-optimieren', 26, 0)) . '" data-nr-chat-start="1" data-nr-chat-skill="3:seo-optimieren" data-nr-chat-page="26" data-nr-chat-language="0" data-nr-chat-page-module="0">'
            . '<span class="nr-mcp-agent-row-text"><span class="nr-mcp-agent-row-label">&lt;script&gt;alert(2)&lt;/script&gt; Meta Description fehlt.</span>'
            . '<span class="nr-mcp-agent-row-secondary">Optimise SEO on “' . self::XSS_ESCAPED . '”</span></span>',
            $html,
        );
        self::assertStringContainsString('<h2 class="nr-mcp-agent-subheading">Still open from your guided tours</h2>', $html);
    }

    /**
     * The meta-description tile: the number, what it counts, and one action
     * that starts the SEO tour on the newest page without one; its name says
     * the number, what it counts and the page. Every page of this fixture the
     * admin may improve lacks a description (20, 21, 22, 26, 28, 29, 30); the
     * English translation 25 is in a language its page's site (none) lacks.
     * Page 26 is changed last, and its title is editor input.
     */
    #[Test]
    public function theMetaDescriptionTileCountsAndStartsTheSeoTourOnTheNewestPage(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['tstamp' => 99999], ['uid' => 26]);

        $html = $this->render(RecommendationsWidget::class, null, ['seo-optimieren'], true);

        self::assertStringNotContainsString(self::XSS_TITLE, $html);
        self::assertStringContainsString('<p class="nr-mcp-agent-intro">I’ve found a few things on your website that need work. Let’s tackle them together.</p>', $html);
        self::assertStringContainsString('<p class="nr-mcp-agent-tile-figure"><span class="nr-mcp-agent-tile-count">7</span> <span class="nr-mcp-agent-tile-label">Pages without a meta description</span></p>', $html);
        self::assertStringContainsString('<p class="nr-mcp-agent-tile-detail">We’ll start with “' . self::XSS_ESCAPED . '”.</p>', $html);
        self::assertStringContainsString(
            '<a class="btn btn-primary nr-mcp-agent-tile-action" href="' . htmlspecialchars($this->chatStart()->build('3:seo-optimieren', 26, 0)) . '"'
            . ' aria-label="Start now: 7 Pages without a meta description, with the SEO tour, starting with “' . self::XSS_ESCAPED . '”"'
            . ' data-nr-chat-start="1" data-nr-chat-skill="3:seo-optimieren" data-nr-chat-page="26" data-nr-chat-language="0">Start now</a>',
            $html,
        );
    }

    /** Once every page has a description, the tile says so and offers no action. */
    #[Test]
    public function aMetaDescriptionCountOfZeroIsASuccess(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['description' => 'Steht da.'], ['deleted' => 0]);

        $html = $this->render(RecommendationsWidget::class, null, ['seo-optimieren'], true);

        self::assertStringContainsString('<p class="nr-mcp-agent-intro">I’ve checked: nothing is open right now. Well done!</p>', $html);
        self::assertMatchesRegularExpression('#<li class="nr-mcp-agent-tile nr-mcp-agent-tile-descriptions nr-mcp-agent-tile-done">\s*<svg class="nr-mcp-agent-tile-check"[^>]*aria-hidden="true"[^>]*>.*</svg>\s*<p class="nr-mcp-agent-tile-done-text">Every page has a meta description – great!</p>#s', $html);
        self::assertStringNotContainsString('nr-mcp-agent-tile-action', $html);
    }

    /**
     * A point the visibility rule hides appears in neither reader of open
     * points — the "Recommended for your website" widget nor the page
     * suggestions — while a visible point appears in both. Hidden here: a
     * page outside the editor's mount (30), a page they may not see (22), and
     * a point in a language their groups do not allow: user 4 owns page 60,
     * their web mount, and may see it, but may edit language 1 only.
     */
    #[Test]
    public function aPointTheVisibilityRuleHidesAppearsInNeitherReader(): void
    {
        $reader = new class implements OpenPointReaderInterface {
            public function findOpen(int $limit): array
            {
                return [
                    new OpenPoint(30, 0, 'seo-optimieren', 'Punkt ausserhalb des Mounts.'),
                    new OpenPoint(22, 0, 'seo-optimieren', 'Punkt auf gesperrter Seite.'),
                    new OpenPoint(21, 0, 'seo-optimieren', 'Sichtbarer Punkt.'),
                    new OpenPoint(60, 0, 'seo-optimieren', 'Punkt in fremder Sprache.'),
                ];
            }
        };
        $pages     = new PageChoiceRepository($this->get(ConnectionPool::class), $this->get(SiteFinder::class), $this->get(TcaSchemaFactory::class));
        $provider  = new DeterministicPageSuggestionProvider($reader, $pages, $this->get(LanguageServiceFactory::class));
        $pointUids = static fn (array $suggestions): array => array_values(array_unique(array_map(static fn ($s): int => $s->pageUid, array_filter($suggestions, static fn ($s): bool => $s->reason === 'Open point from a guided tour'))));

        // Core drops a web mount the user may not see, so user 4 gets a page of their own as mount.
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->insert('pages', [
            'uid'          => 60, 'pid' => 0, 'title' => 'Seite der Uebersetzerin', 'doktype' => 1,
            'perms_userid' => 4, 'perms_user' => 31, 'tstamp' => 1,
        ]);
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', ['db_mountpoints' => '60'], ['uid' => 4]);

        $GLOBALS['BE_USER'] = $this->setUpBackendUser(3);
        $html               = $this->render(RecommendationsWidget::class, $reader);
        self::assertStringNotContainsString('ausserhalb', $html);
        self::assertStringNotContainsString('gesperrter', $html);
        self::assertStringContainsString('Sichtbarer Punkt.', $html);
        self::assertSame([21], $pointUids($provider->suggest('seo-optimieren')));

        // User 4 may see page 60, so only the language hides its default-language point.
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(4);
        self::assertNotNull($pages->findAccessible(60), 'precondition: user 4 may see page 60');
        self::assertStringNotContainsString('fremder Sprache', $this->render(RecommendationsWidget::class, $reader));
        self::assertSame([], $pointUids($provider->suggest('seo-optimieren')));
    }

    /**
     * The chat's store (the real OpenPointVisibility over stored rows) feeds
     * both readers of open points: its point appears in the widget and among
     * the page suggestions, under the trusted source's identifier even when the
     * store names another source. A point whose skill is gone appears in
     * neither.
     */
    #[Test]
    public function theStoresPointsAppearInBothReaders(): void
    {
        $this->storeSkill(7, '7:seo-optimieren');
        $this->storePoint(21, 7, 'pages', 21, 'description', 200);
        $this->storePoint(20, 9, 'pages', 20, 'title', 100);
        $reader  = new VisibleOpenPointReader($this->get(OpenPointVisibility::class));
        $summary = $reader->findOpen(5)[0]->summary ?? '';
        self::assertStringContainsString('Inhalt A', $summary, 'precondition: the store returns the point on page 21');
        $pages    = new PageChoiceRepository($this->get(ConnectionPool::class), $this->get(SiteFinder::class), $this->get(TcaSchemaFactory::class));
        $provider = new DeterministicPageSuggestionProvider($reader, $pages, $this->get(LanguageServiceFactory::class));

        $html = $this->render(RecommendationsWidget::class, $reader);
        self::assertStringContainsString(htmlspecialchars($summary), $html);
        self::assertStringContainsString('href="' . htmlspecialchars($this->chatStart()->build('3:seo-optimieren', 21, 0)) . '"', $html);
        self::assertStringNotContainsString('Bereich A', $html);

        $points = array_values(array_map(
            static fn (PageSuggestion $s): int => $s->pageUid,
            array_filter($provider->suggest('seo-optimieren'), static fn (PageSuggestion $s): bool => $s->reason === 'Open point from a guided tour'),
        ));
        self::assertSame([21], $points);
    }

    /** With an empty store, the widget shows no point and says how points come about. */
    #[Test]
    public function anEmptyStoreShowsNoPoint(): void
    {
        $html = $this->render(RecommendationsWidget::class, new VisibleOpenPointReader($this->get(OpenPointVisibility::class)));

        self::assertStringContainsString('No open recommendations. When the AI assistant finds something to improve during a guided tour, it appears here.', $html);
    }
}
