<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Dashboard;

use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Dashboard\RecommendationsWidget;
use Netresearch\NrMcpAgent\Dashboard\WebsiteCheckWidget;
use Netresearch\NrMcpAgent\Domain\Repository\MissingAltTextRepository;
use Netresearch\NrMcpAgent\Domain\Repository\PageChoiceRepository;
use Netresearch\NrMcpAgent\Domain\Repository\SkillTrustRepository;
use Netresearch\NrMcpAgent\Service\AltText\ImageFieldDiscovery;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextList;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextScanner;
use Netresearch\NrMcpAgent\Service\Assistant\CatalogueSkillResolver;
use Netresearch\NrMcpAgent\Service\Assistant\ChatStartUriBuilder;
use Netresearch\NrMcpAgent\Service\Assistant\NullOpenPointReader;
use Netresearch\NrMcpAgent\Service\Assistant\SkillLabels;
use Netresearch\NrMcpAgent\Tests\Unit\Service\Assistant\SkillCatalogueStub;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The two ways into the list "Fehlende Alt-Texte" (ADR-026): the entry in
 * "Website prüfen", always there, and the tile in "Für deine Website
 * empfohlen", there while images lack an alt text. Fixture of the scan
 * (missing_alt_texts.csv): editor 2 has 26 open hits on 11 pages and in 2
 * records, editor 4 none.
 */
final class MissingAltTextsWidgetsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard', 'filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
        __DIR__ . '/../Fixtures/Extensions/alt_text_test',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => ['nr_mcp_agent' => ['llmTaskUid' => '1']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/missing_alt_texts.csv');
    }

    private function login(int $userUid): void
    {
        $user            = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
    }

    private function list(): MissingAltTextList
    {
        $scanner = new MissingAltTextScanner(
            new ImageFieldDiscovery(new ExtensionConfiguration()),
            new MissingAltTextRepository($this->get(ConnectionPool::class)),
            $this->get(Context::class),
        );

        return new MissingAltTextList($scanner, $this->get(SiteFinder::class), $this->get(ResourceFactory::class), $this->get(UriBuilder::class), new ImageFieldDiscovery(new ExtensionConfiguration()));
    }

    /**
     * @return array{0: WidgetConfigurationInterface, 1: BackendViewFactory, 2: ChatToolbarItem}
     */
    private function common(string $llmTaskUid = '1'): array
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_mcp_agent']['llmTaskUid'] = $llmTaskUid;

        return [
            $this->createMock(WidgetConfigurationInterface::class),
            $this->get(BackendViewFactory::class),
            new ChatToolbarItem(new ExtensionConfiguration(), $this->get(PageRenderer::class)),
        ];
    }

    private function request(): ServerRequest
    {
        return (new ServerRequest('https://example.com/typo3/module/dashboard'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/dashboard', []));
    }

    private function websiteCheck(string $llmTaskUid = '1'): string
    {
        $widget = new WebsiteCheckWidget(...$this->common($llmTaskUid), ...[$this->list()]);
        $widget->setRequest($this->request());

        return $widget->renderWidgetContent();
    }

    private function recommendations(): string
    {
        $resolver = new CatalogueSkillResolver($this->get(SkillTrustRepository::class), new SkillCatalogueStub([]));
        $pages    = new PageChoiceRepository($this->get(ConnectionPool::class), $this->get(SiteFinder::class), $this->get(TcaSchemaFactory::class));
        $widget   = new RecommendationsWidget(
            ...$this->common(),
            ...[new NullOpenPointReader(), $pages, new SkillLabels($this->get(LanguageServiceFactory::class)), $resolver, new ChatStartUriBuilder($this->get(UriBuilder::class)), $this->list()],
        );
        $widget->setRequest($this->request());

        return $widget->renderWidgetContent();
    }

    #[Test]
    public function websiteCheckListsMissingAltTextsWithALinkToTheList(): void
    {
        $this->login(2);

        $html = $this->websiteCheck();

        self::assertMatchesRegularExpression('#<a href="[^"]*/module/nr/mcp/agent/chat/missing-alt-texts[^"]*">Missing alt texts</a>#', $html);
    }

    /** The entry stays when nothing is open: the list then says every image is done. */
    #[Test]
    public function websiteCheckListsTheEntryAlsoWithoutOpenHits(): void
    {
        $this->login(4);

        self::assertStringContainsString('>Missing alt texts</a>', $this->websiteCheck());
    }

    #[Test]
    public function websiteCheckTellsAUserTheChatIsNotAvailableTo(): void
    {
        $this->login(2);

        $html = $this->websiteCheck('0');

        self::assertStringNotContainsString('missing-alt-texts', $html);
        self::assertStringContainsString('not available', $html);
    }

    /** The tile: the count, where the images are, and "View" to the list. */
    #[Test]
    public function recommendationsShowTheCountOfImagesWithoutAltText(): void
    {
        $this->login(2);

        $html = (string) preg_replace('/\s+/', ' ', $this->recommendations());

        self::assertStringContainsString('<span class="fs-3 fw-bold me-1">26</span> Images without alt text', $html);
        self::assertStringContainsString('Found on 11 pages and in 2 records', $html);
        self::assertMatchesRegularExpression('#<a class="btn btn-default btn-sm" href="[^"]*/missing-alt-texts[^"]*" aria-label="View: images without alt text">View</a>#', $html);
        // With the tile there, "no open recommendations" would contradict it.
        self::assertStringNotContainsString('No open recommendations', $html);
    }

    #[Test]
    public function theTileIsLeftOutWhileNoImageLacksAnAltText(): void
    {
        $this->login(4);

        $html = $this->recommendations();

        self::assertStringNotContainsString('Images without alt text', $html);
        self::assertStringContainsString('No open recommendations', $html);
    }

    /** Only the parts that apply: no "in 0 records", one page in the singular. */
    #[Test]
    public function theTileNamesOnlyThePartsThatApply(): void
    {
        $this->login(2);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_mcp_agent']['altTextExcludedTables'] = 'tx_alttexttest_item,pages';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_mcp_agent']['altTextExcludedFields'] = 'tt_content.image';

        $html = (string) preg_replace('/\s+/', ' ', $this->recommendations());

        // Left: the carousel item and the "assets" image, both on "Startseite".
        self::assertStringContainsString('<span class="fs-3 fw-bold me-1">2</span>', $html);
        self::assertStringContainsString('Found on 1 page<', $html);
        self::assertStringNotContainsString('record', $html);
    }
}
