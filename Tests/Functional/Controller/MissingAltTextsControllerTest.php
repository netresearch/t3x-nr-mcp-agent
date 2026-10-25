<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Controller;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Controller\MissingAltTextsController;
use Netresearch\NrMcpAgent\Domain\Repository\MissingAltTextRepository;
use Netresearch\NrMcpAgent\Service\AltText\ImageFieldDiscovery;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextList;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextScanner;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The list "Fehlende Alt-Texte" (ADR-026) as an editor sees it: the
 * fixture of the scan (missing_alt_texts.csv), with a site on "Wurzel" (1)
 * in German and English and a preview configuration for the test articles on
 * their folder (19).
 *
 * Editor 2 has 26 open hits: 22 on pages, 4 in two test articles.
 */
final class MissingAltTextsControllerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
        __DIR__ . '/../Fixtures/Extensions/alt_text_test',
    ];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/nr_mcp_agent/Tests/Functional/Fixtures/Frontend/pattern.png' => 'fileadmin/team.jpg',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => ['nr_mcp_agent' => ['llmTaskUid' => '1']],
        'GFX'        => ['processor_enabled' => false],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/missing_alt_texts.csv');
        $pages = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        // On the root page, as sites set it: the folder and the detail page
        // both inherit it.
        $pages->update('pages', ['is_siteroot' => 1, 'TSconfig' => "TCEMAIN.preview.tx_alttexttest_item.previewPageId = 10\nTCEMAIN.preview.tx_alttexttest_item.fieldToParameterMap.uid = tx_test[item]"], ['uid' => 1]);
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base'       => 'https://example.com/',
            'languages'  => [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/', 'flag' => 'de'],
                ['languageId' => 1, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/en/', 'flag' => 'us'],
            ],
        ]);
    }

    private function login(int $userUid): void
    {
        $user            = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
    }

    private function list(?ExtensionConfiguration $config = null): MissingAltTextList
    {
        $discovery = new ImageFieldDiscovery($config ?? new ExtensionConfiguration());
        $scanner   = new MissingAltTextScanner(
            $discovery,
            new MissingAltTextRepository($this->get(ConnectionPool::class)),
            $this->get(Context::class),
        );

        return new MissingAltTextList($scanner, $this->get(SiteFinder::class), $this->get(ResourceFactory::class), $this->get(UriBuilder::class), $discovery);
    }

    /**
     * @param array<string, string> $query
     */
    private function render(array $query = [], ?MissingAltTextsController $controller = null): DOMXPath
    {
        $module = $this->get(ModuleProvider::class)->getModule('nr_mcp_agent_chat');
        self::assertNotNull($module);
        $routeOptions = $module->getDefaultRouteOptions();
        $request      = (new ServerRequest('https://example.com/typo3/module/tools/nr-mcp-agent-chat/missing-alt-texts', 'GET'))
            ->withQueryParams($query)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('path', $routeOptions['_default'] ?? []))
            ->withAttribute('module', $module)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams([]));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $controller ??= $this->get(MissingAltTextsController::class);
        $html = (string) $controller->listAction($request)->getBody();
        $dom  = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8">' . $html);

        return new DOMXPath($dom);
    }

    /**
     * @return list<string>
     */
    private function texts(DOMXPath $xpath, string $query): array
    {
        $texts = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            $texts[] = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
        }

        return $texts;
    }

    private function one(DOMXPath $xpath, string $query): DOMElement
    {
        $node = $xpath->query($query)?->item(0);
        self::assertInstanceOf(DOMElement::class, $node, $query);

        return $node;
    }

    #[Test]
    public function theListIsARouteOfTheAssistantModule(): void
    {
        self::assertStringContainsString('/module/nr/mcp/agent/chat/missing-alt-texts', $this->list()->uri());
    }

    #[Test]
    public function theHeadingCountsEveryOpenHit(): void
    {
        $this->login(2);
        $xpath = $this->render();

        self::assertSame(['AI Assistant', 'Check website', 'Missing alt texts'], $this->texts($xpath, '//nav/ol[@class="breadcrumb"]/li'));
        self::assertSame(['26 missing alt texts'], $this->texts($xpath, '//h1'));
        self::assertSame(['Images that are currently used without a suitable alternative text.'], $this->texts($xpath, '//p[@class="lead"]'));
        self::assertStringStartsWith('An alt text has to fit the context', $this->texts($xpath, '//*[@role="note"]')[0] ?? '');
    }

    #[Test]
    public function oneHitIsNamedInTheSingularAndNoneWithoutANumber(): void
    {
        $this->login(2);

        self::assertSame('1 missing alt text', $this->list()->title(1));
        self::assertSame('Missing alt texts', $this->list()->title(0));
    }

    /** "Pages" first and open, then the record types by label, each with its count. */
    #[Test]
    public function theTabsArePagesThenTheRecordTypes(): void
    {
        $this->login(2);
        $xpath = $this->render();

        self::assertSame(['Pages', 'Test articles'], $this->texts($xpath, '//*[@role="tablist"]/*[@role="tab"]/text()'));
        // The badge shows the number; screen readers hear "22 open".
        self::assertSame(['22', '4'], $this->texts($xpath, '//*[@role="tab"]/span[@class="badge"]/span[@aria-hidden="true"]'));
        self::assertSame(['22 open', '4 open'], $this->texts($xpath, '//*[@role="tab"]/span[@class="badge"]/span[@class="visually-hidden"]'));
        $pages = $this->one($xpath, '//*[@role="tab"][@data-tab-type="pages"]');
        self::assertSame(['true', '0'], [$pages->getAttribute('aria-selected'), $pages->getAttribute('tabindex')]);
        $articles = $this->one($xpath, '//*[@role="tab"][@data-tab-type="tx_alttexttest_item"]');
        self::assertSame(['false', '-1'], [$articles->getAttribute('aria-selected'), $articles->getAttribute('tabindex')]);

        $panel = $this->one($xpath, '//*[@role="tabpanel"][@id="' . $articles->getAttribute('aria-controls') . '"]');
        self::assertTrue($panel->hasAttribute('hidden'));
        self::assertSame($articles->getAttribute('id'), $panel->getAttribute('aria-labelledby'));
    }

    /** The record types follow by their label, not by their table name. */
    #[Test]
    public function theRecordTypesFollowInTheOrderOfTheirLabels(): void
    {
        $this->login(1);
        $GLOBALS['TCA']['tx_alttexttest_item']['ctrl']['title'] = 'Articles';
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_collection')
            ->insert('sys_file_collection', ['uid' => 1, 'pid' => 19, 'title' => 'Bilder', 'type' => 'static', 'files' => 1]);
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference')
            ->insert('sys_file_reference', ['uid' => 900, 'pid' => 19, 'uid_local' => 1, 'uid_foreign' => 1, 'tablenames' => 'sys_file_collection', 'fieldname' => 'files']);

        $tabs = $this->list()->build('pages', 1)['tabs'];

        self::assertSame(['pages', 'tx_alttexttest_item', 'sys_file_collection'], array_column($tabs, 'type'));
    }

    #[Test]
    public function theRequestedTabIsOpen(): void
    {
        $this->login(2);
        $xpath = $this->render(['tab' => 'tx_alttexttest_item']);

        self::assertSame('true', $this->one($xpath, '//*[@role="tab"][@data-tab-type="tx_alttexttest_item"]')->getAttribute('aria-selected'));
        self::assertTrue($this->one($xpath, '//*[@id="nr-alt-texts-panel-pages"]')->hasAttribute('hidden'));
    }

    /** A table with column headers per tab: "Content element" on pages, "Field" for records. */
    #[Test]
    public function eachTabIsATableWithHeaders(): void
    {
        $this->login(2);
        $xpath = $this->render();

        self::assertSame(['Image', 'File', 'Content element', 'Language', 'Actions'], $this->texts($xpath, '//*[@id="nr-alt-texts-panel-pages"]//thead//th[@scope="col"]'));
        self::assertSame(['Image', 'File', 'Field', 'Language', 'Actions'], $this->texts($xpath, '//*[@id="nr-alt-texts-panel-tx_alttexttest_item"]//thead//th[@scope="col"]'));
    }

    /** Pages in page tree order, records by folder and title, each heading a group of rows. */
    #[Test]
    public function hitsAreGroupedUnderTheirPageOrRecord(): void
    {
        $this->login(2);
        $xpath = $this->render();

        self::assertSame(
            ['Zweite Seite', 'Startseite', 'Versteckt', 'Unter Versteckt', 'Unterseite', 'Geplant', 'Abgelaufen', 'Medienseite', 'Nur Inhalte', 'Unter Abgelaufen', 'Unter Geplant'],
            $this->texts($xpath, '//*[@id="nr-alt-texts-panel-pages"]//tbody/tr/th[@scope="rowgroup"]'),
        );
        self::assertSame(
            ['Erster Artikel · Folder Ordner Presse', 'Zweiter Artikel · Folder Ordner Presse'],
            $this->texts($xpath, '//*[@id="nr-alt-texts-panel-tx_alttexttest_item"]//tbody/tr/th[@scope="rowgroup"]'),
        );
    }

    /**
     * The third column names the content element by its type's label and
     * header, a page property by its field, a record's image by its field.
     */
    #[Test]
    public function eachRowNamesTheElementOrField(): void
    {
        $this->login(2);
        $xpath = $this->render();
        $label = fn (string $file, string $panel, int $row): string => $this->texts($xpath, sprintf('(//*[@id="nr-alt-texts-panel-%s"]//tbody/tr[td])[%d]/td[3]', $panel, $row))[0] ?? '';

        // A type the TCA does not list ("carousel" of the fixture) is named generically.
        self::assertStringStartsWith('Content element – “Karussell”', $label('', 'pages', 13));
        // The core labels pages.media "Files" in 13.4 and "Media" in 14.3.
        self::assertMatchesRegularExpression('/^Page properties: (Files|Media)$/', $label('', 'pages', 19));
        self::assertStringStartsWith('Images', $label('', 'tx_alttexttest_item', 1));
        self::assertStringStartsWith('Teaser image', $label('', 'tx_alttexttest_item', 2));
    }

    #[Test]
    public function aHiddenHitSaysWhyItIsNotVisible(): void
    {
        $this->login(2);
        $xpath = $this->render();

        $hints = $this->texts($xpath, '//*[contains(@class, "nr-alt-texts-hidden")]/span[@class="nr-alt-texts-reason"]');
        self::assertContains('Currently not visible · Element hidden', $hints);
        self::assertContains('Currently not visible · Image hidden', $hints);
        self::assertContains('Currently not visible · Parent page hidden', $hints);
        self::assertContains('Currently not visible · Record hidden', $hints);
        // More than an icon: the explanation is text, also for screen readers.
        self::assertContains(
            'The image is not shown in the frontend at the moment. The alt text is still needed as soon as the content is visible again.',
            $this->texts($xpath, '//*[contains(@class, "nr-alt-texts-hidden")]/span[@class="visually-hidden"]'),
        );
    }

    /** A record type whose hits are all fixed keeps its tab while it is the one asked for, saying it has no hits. */
    #[Test]
    public function aRequestedRecordTypeWithoutHitsKeepsAnEmptyTab(): void
    {
        $this->login(2);
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference')
            ->update('sys_file_reference', ['alternative' => 'Erledigt'], ['tablenames' => 'tx_alttexttest_item']);
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference')
            ->update('sys_file_reference', ['alternative' => 'Erledigt'], ['uid_foreign' => 117, 'tablenames' => 'tt_content']);

        $asked = $this->list()->build('tx_alttexttest_item', 1);
        self::assertSame(['pages', 'tx_alttexttest_item'], array_column($asked['tabs'], 'type'));
        self::assertSame([0, true, 'No open hits in Test articles.'], [$asked['tabs'][1]['count'], $asked['tabs'][1]['active'], $asked['tabs'][1]['empty']]);

        // Opened without it, the tab is gone; a table without images is no tab.
        self::assertSame(['pages'], array_column($this->list()->build('pages', 1)['tabs'], 'type'));
        self::assertSame(['pages'], array_column($this->list()->build('be_groups', 1)['tabs'], 'type'));
    }

    /** "Open page" for an image in a language opens that language's page. */
    #[Test]
    public function aPageHitInALanguageOpensThatLanguagesPage(): void
    {
        $this->login(1);
        $xpath = $this->render();

        $english = $xpath->query('//*[@id="nr-alt-texts-panel-pages"]//tbody/tr[td[4][normalize-space()="EN"]]/td[5]/a');
        self::assertGreaterThan(0, $english?->length);
        $link = $english?->item(0);
        self::assertInstanceOf(DOMElement::class, $link);
        self::assertStringContainsString('/en/', $link->getAttribute('href'));
    }

    /** Two languages with one language code are told apart by their tag. */
    #[Test]
    public function languagesSharingACodeShowTheirTag(): void
    {
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base'       => 'https://example.com/',
            'languages'  => [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/', 'flag' => 'de'],
                ['languageId' => 1, 'title' => 'Schweiz', 'locale' => 'de_CH.UTF-8', 'base' => '/ch/', 'flag' => 'ch'],
            ],
        ]);
        $this->login(1);

        $languages = $this->texts($this->render(), '//*[@id="nr-alt-texts-panel-pages"]//tbody/tr[td]/td[4]');
        self::assertContains('DE-DE', $languages);
        self::assertContains('DE-CH', $languages);
        self::assertNotContains('DE', $languages);
    }

    #[Test]
    public function theLanguageIsTheCodeOfTheSiteLanguage(): void
    {
        $this->login(1);
        $xpath = $this->render();

        $languages = $this->texts($xpath, '//*[@id="nr-alt-texts-panel-pages"]//tbody/tr[td]/td[4]');
        self::assertContains('DE', $languages);
        self::assertContains('EN', $languages);
    }

    /** "Open page" like "View" in the page module, in a new tab and named for the image. */
    #[Test]
    public function aPageHitOpensThePageInANewTab(): void
    {
        $this->login(2);
        $xpath = $this->render();

        $link = $this->one($xpath, '(//*[@id="nr-alt-texts-panel-pages"]//tbody/tr[td])[1]/td[5]/a');
        self::assertSame('Open page', trim($link->textContent));
        self::assertSame(['_blank', 'noopener'], [$link->getAttribute('target'), $link->getAttribute('rel')]);
        self::assertSame('Open page: team.jpg, opens in a new tab', $link->getAttribute('aria-label'));
        self::assertStringStartsWith('https://example.com/', $link->getAttribute('href'));
    }

    /** "Open detail page" through TCEMAIN.preview; a table without one gets no button. */
    #[Test]
    public function aRecordHitOpensItsDetailPageWhereThePreviewIsConfigured(): void
    {
        $this->login(2);
        $xpath = $this->render();

        $link = $this->one($xpath, '(//*[@id="nr-alt-texts-panel-tx_alttexttest_item"]//tbody/tr[td])[1]/td[5]/a');
        self::assertSame('Open detail page', trim($link->textContent));
        self::assertStringContainsString('tx_test%5Bitem%5D=1', $link->getAttribute('href'));

        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['TSconfig' => ''], ['uid' => 1]);
        $this->get(CacheManager::class)->getCache('runtime')->flush();
        self::assertSame(0, $this->render()->query('//*[@id="nr-alt-texts-panel-tx_alttexttest_item"]//tbody/tr[td]/td[5]/a')?->length);
    }

    /** The thumbnail shows the reference's image; a file that is not there shows an icon instead of a broken image. */
    #[Test]
    public function theThumbnailIsTheImageWhereTheFileExists(): void
    {
        $this->login(2);
        $xpath = $this->render();

        $thumbnails = $xpath->query('//*[@id="nr-alt-texts-panel-pages"]//td[contains(@class, "nr-alt-texts-thumbnail")]//img');
        self::assertGreaterThan(0, $thumbnails?->length);
        $image = $thumbnails?->item(0);
        self::assertInstanceOf(DOMElement::class, $image);
        self::assertStringContainsString('team.jpg', $image->getAttribute('src'));
        // The file name is in the next column; the thumbnail is decorative there.
        self::assertSame('', $image->getAttribute('alt'));
        self::assertGreaterThan(0, $xpath->query('//td[contains(@class, "nr-alt-texts-thumbnail")]//*[@data-identifier="mimetypes-media-image"]')?->length);
    }

    #[Test]
    public function withoutOpenHitsTheListSaysThatEveryImageIsDone(): void
    {
        // Editor 4 may not change alternative texts: nothing for them to fix.
        $this->login(4);
        $xpath = $this->render();

        self::assertSame(['Missing alt texts'], $this->texts($xpath, '//h1'));
        self::assertSame(['All images have an alt text or are marked as decorative.'], $this->texts($xpath, '//p[contains(@class, "nr-alt-texts-empty")]'));
        self::assertSame(0, $xpath->query('//*[@role="tablist"]')?->length);
    }

    /** "Pages" is always there; without hits on pages it says so. */
    #[Test]
    public function anEmptyPagesTabSaysThatItHasNoOpenHits(): void
    {
        $this->login(2);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_mcp_agent']['altTextExcludedTables'] = 'pages,tt_content,tx_alttexttest_child';

        $data = $this->list()->build('pages', 1);

        self::assertSame(['pages', 'tx_alttexttest_item'], array_column($data['tabs'], 'type'));
        self::assertSame([0, []], [$data['tabs'][0]['count'], $data['tabs'][0]['groups']]);
        self::assertSame('No open hits in Pages.', $data['tabs'][0]['empty']);
    }

    /** Many hits are listed page by page; a group cut by the page break starts the next page with its heading. */
    #[Test]
    public function manyHitsArePaged(): void
    {
        $this->login(2);

        $first  = $this->list()->build('pages', 1, 5)['tabs'][0];
        $second = $this->list()->build('pages', 2, 5)['tabs'][0];
        $last   = $this->list()->build('pages', 99, 5)['tabs'][0];

        self::assertSame('Page 1 of 5', $first['pagination']['status']);
        self::assertSame('', $first['pagination']['previous']);
        self::assertStringContainsString('page=2', $first['pagination']['next']);
        self::assertSame(['Zweite Seite', 'Startseite'], array_column($first['groups'], 'title'));
        self::assertSame(['Startseite'], array_column($second['groups'], 'title'));
        self::assertCount(5, $second['groups'][0]['rows']);
        // A page past the end shows the last one.
        self::assertSame('Page 5 of 5', $last['pagination']['status']);
        self::assertSame('', $last['pagination']['next']);
        // The other tabs stay on their first page.
        self::assertNull($this->list()->build('pages', 2, 5)['tabs'][1]['pagination']);
    }

    #[Test]
    public function aUserTheChatIsNotAvailableToIsToldSo(): void
    {
        $this->login(2);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_mcp_agent']['llmTaskUid'] = '0';
        $config                                                                 = new ExtensionConfiguration();
        $controller                                                             = new MissingAltTextsController(
            $this->get(ModuleTemplateFactory::class),
            $this->get(PageRenderer::class),
            new ChatToolbarItem($config, $this->get(PageRenderer::class)),
            $this->list($config),
        );

        $xpath = $this->render([], $controller);

        self::assertSame(0, $xpath->query('//*[@role="tablist"]')?->length);
        self::assertStringContainsString('not available', $this->texts($xpath, '//div[@class="nr-alt-texts"]/p')[0] ?? '');
    }

    #[Test]
    public function theListIsInGermanForAGermanEditor(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', ['lang' => 'de'], ['uid' => 2]);
        $this->login(2);
        $xpath = $this->render();

        self::assertSame(['26 fehlende Alt-Texte'], $this->texts($xpath, '//h1'));
        self::assertSame(['KI-Assistent', 'Website prüfen', 'Fehlende Alt-Texte'], $this->texts($xpath, '//nav/ol[@class="breadcrumb"]/li'));
        self::assertSame('Seiten', $this->texts($xpath, '//*[@role="tab"]/text()')[0] ?? '');
        self::assertSame('22 offen', $this->texts($xpath, '//*[@role="tab"]/span[@class="badge"]/span[@class="visually-hidden"]')[0] ?? '');
        self::assertContains('Aktuell nicht sichtbar · Element ausgeblendet', $this->texts($xpath, '//*[contains(@class, "nr-alt-texts-hidden")]/span[@class="nr-alt-texts-reason"]'));
    }
}
