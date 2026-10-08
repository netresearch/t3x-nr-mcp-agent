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
use Netresearch\NrMcpAgent\Dashboard\ImprovePageWidget;
use Netresearch\NrMcpAgent\Dashboard\QuickTasksWidget;
use Netresearch\NrMcpAgent\Dashboard\RecommendationsWidget;
use Netresearch\NrMcpAgent\Domain\Repository\PageChoiceRepository;
use Netresearch\NrMcpAgent\Domain\Repository\SkillTrustRepository;
use Netresearch\NrMcpAgent\Service\Assistant\CatalogueSkillResolver;
use Netresearch\NrMcpAgent\Service\Assistant\ChatStartUriBuilder;
use Netresearch\NrMcpAgent\Service\Assistant\ConfiguredGuidedSkillProvider;
use Netresearch\NrMcpAgent\Service\Assistant\ConfiguredQuickTaskProvider;
use Netresearch\NrMcpAgent\Service\Assistant\DeterministicPageSuggestionProvider;
use Netresearch\NrMcpAgent\Service\Assistant\NullOpenPointReader;
use Netresearch\NrMcpAgent\Service\Assistant\OpenPoint;
use Netresearch\NrMcpAgent\Service\Assistant\OpenPointReaderInterface;
use Netresearch\NrMcpAgent\Service\Assistant\SkillLabels;
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
 * The installation under test has no skill catalogue, so the widgets rendered
 * through the dashboard's own registry show what an editor sees while the
 * configured skills do not exist: no tour, no task, an explanation instead.
 * The tests of the offers themselves build the widgets with a catalogue that
 * lists both default skills from source 3, as `3:<slug>`; the configured
 * defaults are bare slugs and resolve to them.
 *
 * Page 26 of the fixture is titled `<img src=x onerror=alert(1)>`: a page
 * title is editor input and must reach the widget as text.
 */
final class AssistantWidgetsRenderingTest extends FunctionalTestCase
{
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
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
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
     * @param list<string> $skills
     */
    private function render(string $class, ?OpenPointReaderInterface $reader = null, array $skills = ['seo-optimieren', 'inhalt-verbessern']): string
    {
        $catalogue = new class ($skills) {
            /** @param list<string> $skills */
            public function __construct(private readonly array $skills) {}

            public function isAvailable(): bool
            {
                return true;
            }

            public function requiresSecondApprover(): bool
            {
                return false;
            }

            /** @return list<array{identifier: string, uid: int}> */
            public function catalogue(): array
            {
                return array_map(static fn(string $skill): array => ['identifier' => '3:' . $skill, 'uid' => 0], $this->skills);
            }
        };
        $resolver = new CatalogueSkillResolver($this->get(SkillTrustRepository::class), $catalogue);
        $config = new ExtensionConfiguration();
        $labels = new SkillLabels($this->get(LanguageServiceFactory::class));
        $common = [
            $this->createMock(WidgetConfigurationInterface::class),
            $this->get(BackendViewFactory::class),
            new ChatToolbarItem($config, $this->get(PageRenderer::class)),
        ];
        $pages = new PageChoiceRepository($this->get(ConnectionPool::class), $this->get(SiteFinder::class), $this->get(TcaSchemaFactory::class));
        $suggestions = new DeterministicPageSuggestionProvider($reader ?? new NullOpenPointReader(), $pages, $this->get(LanguageServiceFactory::class));

        $widget = match ($class) {
            ImprovePageWidget::class => new ImprovePageWidget(...$common, ...[new ConfiguredGuidedSkillProvider($config, $labels, $resolver), $pages, $suggestions, $this->chatStart()]),
            QuickTasksWidget::class => new QuickTasksWidget(...$common, ...[new ConfiguredQuickTaskProvider($config, $labels, $resolver), $pages, $this->chatStart()]),
            RecommendationsWidget::class => new RecommendationsWidget(...$common, ...[$reader ?? new NullOpenPointReader(), $pages, $labels, $resolver, $this->chatStart()]),
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
        foreach (['nrMcpAgentImprovePage', 'nrMcpAgentQuickTasks', 'nrMcpAgentRecommendations', 'nrMcpAgentAiChat'] as $identifier) {
            self::assertArrayHasKey($identifier, $widgets);
        }
        self::assertArrayHasKey('nrMcpAgent', $this->get(WidgetGroupRegistry::class)->getWidgetGroups());

        $presets = $this->get(DashboardPresetRegistry::class)->getDashboardPresets();
        self::assertArrayHasKey('nrMcpAgent', $presets);
        // 13.4 lists the identifiers, 14.3 one ['identifier' => …] entry each.
        $defaultWidgets = array_map(
            static fn(mixed $widget): mixed => is_array($widget) ? ($widget['identifier'] ?? null) : $widget,
            $presets['nrMcpAgent']->getDefaultWidgets(),
        );
        self::assertSame(
            ['nrMcpAgentImprovePage', 'nrMcpAgentQuickTasks', 'nrMcpAgentRecommendations', 'nrMcpAgentAiChat'],
            $defaultWidgets,
        );
        self::assertTrue($presets['nrMcpAgent']->isShowInWizard());
    }

    /**
     * A GET form drops the query string of its action, so the form must carry
     * every other parameter of the module URL itself: the form's request and
     * the built link are the same request.
     */
    #[Test]
    public function theFormReachesTheSameUrlAsTheBuiltLink(): void
    {
        $link = $this->chatStart()->build('3:seo-optimieren', 21, 0);
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
     * One submit button per tour next to the shared page choice; the page
     * choice lists the suggested pages first ("Recommended", with the
     * reason), then the other recently changed pages. A GET form: keyboard
     * and no JavaScript suffice.
     *
     * Every page of this fixture lacks a meta description, so the five newest
     * pages the admin may edit are suggested for the SEO tour.
     */
    #[Test]
    public function improveAPageRendersAKeyboardUsableForm(): void
    {
        $html = $this->render(ImprovePageWidget::class);
        $target = $this->chatStart()->formTarget();

        self::assertStringContainsString('<form action="' . htmlspecialchars($target['action']) . '" method="get"', $html);
        self::assertStringContainsString('<input type="hidden" name="token" value="' . htmlspecialchars($target['hidden']['token']) . '"', $html);
        // Decision 4: the pages offered are default-language versions.
        self::assertStringContainsString('<input type="hidden" name="languageUid" value="0" />', $html);
        self::assertMatchesRegularExpression('/<label for="(nr-mcp-agent-improve-[0-9a-f]{8}-page)" class="form-label">Page<\/label>\s*<select id="\1" name="pageUid" class="form-select" required="required" aria-describedby="\1-hint">/', $html);

        // The page choice: suggestions first, then the rest, no page twice.
        $groups = self::optionGroups($html);
        self::assertSame(['Recommended', 'Recently changed'], array_keys($groups));
        self::assertSame([29, 28, 30, 22, 21], array_keys($groups['Recommended']));
        self::assertSame('Inhalt A – Meta description missing, Page title too short', $groups['Recommended'][21]);
        self::assertSame([26, 27, 20], array_keys($groups['Recently changed']));

        // One button per tour, in the configured order, each submitting its tour.
        self::assertSame(1, preg_match_all('/<button type="submit" class="btn btn-primary" name="skill" value="3:seo-optimieren" aria-describedby="(nr-mcp-agent-improve-[0-9a-f]{8})-skill-0-description">Optimise SEO<\/button>\s*<span id="\1-skill-0-description"/', $html));
        self::assertSame(1, preg_match_all('/<button type="submit" class="btn btn-primary" name="skill" value="3:inhalt-verbessern" aria-describedby="nr-mcp-agent-improve-[0-9a-f]{8}-skill-1-description">Improve content<\/button>/', $html));
        self::assertSame(2, substr_count($html, '<button type="submit"'));
        self::assertStringNotContainsString('type="radio"', $html);
    }

    /**
     * The options of the select, per option group, as text by value.
     *
     * @return array<string, array<int, string>>
     */
    private static function optionGroups(string $html): array
    {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8"?>' . $html);
        $groups = [];
        foreach ($document->getElementsByTagName('optgroup') as $group) {
            foreach ($group->getElementsByTagName('option') as $option) {
                $groups[$group->getAttribute('label')][(int) $option->getAttribute('value')] = trim((string) preg_replace('/\s+/', ' ', $option->textContent));
            }
        }

        return $groups;
    }

    /**
     * Union over the tours: an open point of the SEO tour comes first with
     * its reason; a page both tours suggest appears once, with the reasons of
     * both; the group stays at five.
     */
    #[Test]
    public function suggestionsAreTheUnionOverTheToursOnePerPage(): void
    {
        $reader = new class implements OpenPointReaderInterface {
            public function findOpen(int $limit): array
            {
                return [
                    new OpenPoint(20, 0, '3:seo-optimieren', 'Der Seitentitel wiederholt die Überschrift.'),
                    new OpenPoint(29, 0, 'inhalt-verbessern', 'Ein Absatz ist sehr lang.'),
                ];
            }
        };

        $groups = self::optionGroups($this->render(ImprovePageWidget::class, $reader));

        self::assertSame([20, 29, 28, 30, 22], array_keys($groups['Recommended']));
        self::assertSame('Bereich A – Open point from a guided tour', $groups['Recommended'][20]);
        self::assertSame('Gesperrt fuer Bearbeitung – Meta description missing, Page title too short, Open point from a guided tour', $groups['Recommended'][29]);
        self::assertSame([21, 26, 27], array_keys($groups['Recently changed']));
    }

    #[Test]
    public function aPageTitleIsEscapedInTheImproveWidget(): void
    {
        $html = $this->render(ImprovePageWidget::class);

        self::assertStringNotContainsString(self::XSS_TITLE, $html);
        self::assertStringContainsString('<option value="26">' . self::XSS_ESCAPED . '</option>', $html);
    }

    /** A suggested page's title is escaped as well. */
    #[Test]
    public function aSuggestedPageTitleIsEscaped(): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        $connection->update('pages', ['tstamp' => 99999], ['uid' => 26]);

        $html = $this->render(ImprovePageWidget::class);

        self::assertStringNotContainsString(self::XSS_TITLE, $html);
        self::assertStringContainsString('<option value="26">' . self::XSS_ESCAPED . ' – Meta description missing', $html);
    }

    #[Test]
    public function aPageTitleIsEscapedInTheTasksWidget(): void
    {
        $html = $this->render(QuickTasksWidget::class);

        self::assertStringNotContainsString(self::XSS_TITLE, $html);
        self::assertStringContainsString('<option value="26">' . self::XSS_ESCAPED . '</option>', $html);
    }

    #[Test]
    public function aTaskOnAPageAsksForThePageUnderAHiddenLabel(): void
    {
        $html = $this->render(QuickTasksWidget::class);

        self::assertMatchesRegularExpression('/<label for="(nr-mcp-agent-tasks-[0-9a-f]{8}-page-0)" class="visually-hidden">Page for “Optimise SEO”<\/label>\s*<select id="\1" name="pageUid"/', $html);
        self::assertStringContainsString('<input type="hidden" name="skill" value="3:seo-optimieren" />', $html);
        self::assertStringContainsString('<input type="hidden" name="languageUid" value="0" />', $html);
        self::assertStringContainsString('<button type="submit" class="btn btn-default">Optimise SEO</button>', $html);
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

    #[Test]
    public function withoutAStoreTheRecommendationsSayHowPointsComeAbout(): void
    {
        $html = $this->renderFromRegistry('nrMcpAgentRecommendations');

        self::assertStringContainsString('No open recommendations. When the AI assistant finds something to improve during a guided tour, it appears here.', $html);
    }

    /**
     * A point's summary is written by a model through a tool, the page title
     * by an editor: both reach the page as text.
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
        self::assertStringContainsString('<a href="' . htmlspecialchars($this->chatStart()->build('3:seo-optimieren', 26, 0)) . '">Optimise SEO on “' . self::XSS_ESCAPED . '”</a>', $html);
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
        $pages = new PageChoiceRepository($this->get(ConnectionPool::class), $this->get(SiteFinder::class), $this->get(TcaSchemaFactory::class));
        $provider = new DeterministicPageSuggestionProvider($reader, $pages, $this->get(LanguageServiceFactory::class));
        $pointUids = static fn(array $suggestions): array => array_values(array_unique(array_map(static fn($s): int => $s->pageUid, array_filter($suggestions, static fn($s): bool => $s->reason === 'Open point from a guided tour'))));

        // Core drops a web mount the user may not see, so user 4 gets a page of their own as mount.
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->insert('pages', [
            'uid' => 60, 'pid' => 0, 'title' => 'Seite der Uebersetzerin', 'doktype' => 1,
            'perms_userid' => 4, 'perms_user' => 31, 'tstamp' => 1,
        ]);
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', ['db_mountpoints' => '60'], ['uid' => 4]);

        $GLOBALS['BE_USER'] = $this->setUpBackendUser(3);
        $html = $this->render(RecommendationsWidget::class, $reader);
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
}
