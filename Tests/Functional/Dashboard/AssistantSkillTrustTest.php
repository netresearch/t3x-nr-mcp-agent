<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Dashboard;

use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Dashboard\ImprovePageWidget;
use Netresearch\NrMcpAgent\Domain\Repository\SkillTrustRepository;
use Netresearch\NrMcpAgent\Service\Assistant\CatalogueSkillResolver;
use Netresearch\NrMcpAgent\Service\Assistant\ChatStartUriBuilder;
use Netresearch\NrMcpAgent\Service\Assistant\ConfiguredGuidedSkillProvider;
use Netresearch\NrMcpAgent\Service\Assistant\SkillLabels;
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
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Which source of a slug the widgets offer, with the trust levels stored in
 * nr-llm's skill table and nr-llm's minimum for instructions set to
 * `verified` (`skills.minTrustLevel`).
 */
final class AssistantSkillTrustTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard', 'filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'nr_mcp_agent' => ['llmTaskUid' => '1', 'dashboardGuidedSkills' => 'seo-optimieren'],
            'nr_llm'       => ['skills' => ['minTrustLevel' => 'verified']],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/dashboard_page_choice.csv');
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['LANG']    = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    /**
     * nr-llm skill rows, and a catalogue that lists them under `<source>:seo-optimieren`.
     *
     * @param array<int, array{source: int, trust: string}> $skills by skill uid
     */
    private function resolver(array $skills): CatalogueSkillResolver
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_skill');
        $entries    = [];
        foreach ($skills as $uid => $skill) {
            $identifier = $skill['source'] . ':seo-optimieren';
            $connection->insert('tx_nrllm_skill', ['uid' => $uid, 'pid' => 0, 'identifier' => $identifier, 'trust_level' => $skill['trust']]);
            $entries[] = ['identifier' => $identifier, 'uid' => $uid];
        }

        return new CatalogueSkillResolver($this->get(SkillTrustRepository::class), new SkillCatalogueStub($entries));
    }

    private function renderImprovePage(CatalogueSkillResolver $resolver): string
    {
        $config = new ExtensionConfiguration();
        $widget = new ImprovePageWidget(
            $this->createMock(WidgetConfigurationInterface::class),
            $this->get(BackendViewFactory::class),
            new ChatToolbarItem($config, $this->get(PageRenderer::class)),
            new ConfiguredGuidedSkillProvider($config, new SkillLabels($this->get(LanguageServiceFactory::class)), $resolver),
            new ChatStartUriBuilder($this->get(UriBuilder::class)),
        );
        $widget->setRequest(
            (new ServerRequest('https://example.com/typo3/module/dashboard'))
                ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
                ->withAttribute('route', new Route('/module/dashboard', [])),
        );

        return $widget->renderWidgetContent();
    }

    /** A level nr-llm does not know ranks as untrusted: it does not outrank `verified`, though its source uid is lower. */
    #[Test]
    public function anUnknownLevelDoesNotOutrankVerified(): void
    {
        $resolver = $this->resolver([1 => ['source' => 2, 'trust' => 'made-up'], 2 => ['source' => 5, 'trust' => 'verified']]);

        self::assertSame('5:seo-optimieren', $resolver->resolve('seo-optimieren'));
    }

    /** Only sources below the minimum carry the slug: no tour, the hint instead. */
    #[Test]
    public function aSlugOnlyBelowTheMinimumShowsTheHint(): void
    {
        $html = $this->renderImprovePage($this->resolver([1 => ['source' => 2, 'trust' => 'community'], 2 => ['source' => 5, 'trust' => 'made-up']]));

        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('seo-optimieren', $html);
        self::assertStringContainsString('No guided tours are set up yet. Your administrator can set them up.', $html);
    }

    /** A source that meets the minimum is offered as the tour. */
    #[Test]
    public function aSlugMeetingTheMinimumIsOffered(): void
    {
        $html = $this->renderImprovePage($this->resolver([1 => ['source' => 2, 'trust' => 'community'], 2 => ['source' => 5, 'trust' => 'verified']]));

        self::assertStringContainsString('data-nr-chat-skill="5:seo-optimieren"', $html);
        self::assertStringContainsString('Optimise SEO', $html);
        self::assertStringNotContainsString('2:seo-optimieren', $html);
    }

    #[Test]
    public function theMinimumIsReadFromNrLlm(): void
    {
        self::assertSame(2, $this->get(SkillTrustRepository::class)->minimumRank());
    }
}
