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
 * With a Task only for one backend group and none for everyone else, a user
 * outside that group is told so, not that nobody gets the chat.
 */
final class OverviewControllerGroupMappingTest extends FunctionalTestCase
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
            'nr_mcp_agent' => ['llmTaskUid' => '0', 'groupTaskMapping' => '7:1'],
        ],
    ];

    #[Test]
    public function aUserOutsideTheMappedGroupsIsToldItIsTheirGroups(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users_overview.csv');
        $backendUser     = $this->setUpBackendUser(2);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $module          = $this->get(ModuleProvider::class)->getModule('nr_mcp_agent_chat');
        self::assertNotNull($module);
        $request = (new ServerRequest('https://localhost/typo3/module/nr/mcp/agent/chat', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('path', $module->getDefaultRouteOptions()['_default'] ?? []))
            ->withAttribute('module', $module)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams([]));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $body = (string) $this->get(OverviewController::class)->indexAction($request)->getBody();

        self::assertStringContainsString('data-nr-readiness="access.noTaskForYourGroups"', $body);
        self::assertStringNotContainsString('so nobody gets it', $body);
    }
}
