<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Controller;

use Netresearch\NrMcpAgent\Controller\OverviewController;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The rounds of an ordinary chat run with a value of its own (ADR-023,
 * round-cap amendment of 2026-11-04): the overview shows the setting, not
 * nr-llm's default, and a value above nr-llm's maximum as that maximum.
 * OverviewControllerTest covers the run without a value.
 */
final class OverviewRoundsTest extends FunctionalTestCase
{
    /** @var non-empty-string[] nr_mcp_agent depends on filelist. */
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'nr_mcp_agent' => ['llmTaskUid' => '1', 'chatRunMaxIterations' => '8', 'processRunMaxIterations' => '30'],
            'nr_llm'       => ['agent' => ['defaultMaxIterations' => '6', 'maxIterations' => '25']],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users_overview.csv');
    }

    #[Test]
    public function aChatRunWithItsOwnValueShowsItAndAValueAboveTheMaximumShowsTheMaximum(): void
    {
        $body = $this->render(1);

        self::assertStringContainsString('data-nr-rounds="chat">8<', $body);
        self::assertStringContainsString('Setting chatRunMaxIterations', $body);
        self::assertStringContainsString('data-nr-rounds="process">25<', $body);
        self::assertStringContainsString('data-nr-rounds="ceiling">25<', $body);
    }

    private function render(int $userUid): string
    {
        $backendUser     = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $module          = $this->get(ModuleProvider::class)->getModule('nr_mcp_agent_chat');
        self::assertNotNull($module);
        $route   = new Route('path', $module->getDefaultRouteOptions()['_default'] ?? []);
        $request = (new ServerRequest('https://localhost/typo3/module/nr/mcp/agent/chat', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $route)
            ->withAttribute('module', $module)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams([]));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = $this->get(OverviewController::class)->indexAction($request);
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }
}
