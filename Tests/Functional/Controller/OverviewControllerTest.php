<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Controller;

use Netresearch\NrMcpAgent\Controller\OverviewController;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The overview module page (ADR-031): every number on it comes from the
 * source named in the test, for the viewer's scope — an administrator sees
 * everyone's figures, an editor their own.
 */
final class OverviewControllerTest extends FunctionalTestCase
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
            'nr_mcp_agent' => ['llmTaskUid' => '1', 'maxLiveStreams' => '5'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users_overview.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/overview_statistics.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_nrllm_model.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_nrllm_configuration.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_nrllm_task.csv');
        $now = time();
        // Usage of this extension by both users, of another extension, and
        // before the period: only the first two may count.
        $this->usage('nr_mcp_agent', 2, 3, 300, 0.5, $now);
        $this->usage('nr_mcp_agent', 3, 4, 700, 1.25, $now);
        $this->usage('t3_cowriter', 2, 50, 5000, 9.0, $now);
        $this->usage('nr_mcp_agent', 2, 90, 9000, 90.0, $now - 40 * 86400);
        $this->call('nr_mcp_agent', 'chatTurn', 2, true, $now);
        $this->call('nr_mcp_agent', 'chatTurn', 2, false, $now);
        $this->call('nr_mcp_agent', 'resumeChatTurn', 3, true, $now);
        $this->call('t3_cowriter', 'translate', 2, false, $now);
        $this->call('nr_mcp_agent', 'chatTurn', 2, false, $now - 40 * 86400);
    }

    #[Test]
    public function anAdministratorSeesTheFiguresOfEveryUser(): void
    {
        $body = $this->render(1);

        self::assertStringContainsString('data-nr-count="failed">2<', $body);
        self::assertStringContainsString('data-nr-count="active">3<', $body);
        self::assertStringContainsString('data-nr-count="awaitingApproval">1<', $body);
        self::assertStringContainsString('data-nr-count="archived">1<', $body);
        self::assertStringContainsString('data-nr-count="openPoints">3<', $body);
        self::assertStringContainsString('data-nr-count="calls">3<', $body);
        self::assertStringContainsString('data-nr-count="failedCalls">1<', $body);
        self::assertStringContainsString('data-nr-count="requests">7<', $body);
        self::assertStringContainsString('data-nr-count="tokens">1,000<', $body);
        self::assertStringContainsString('data-nr-count="cost">$1.75<', $body);
        self::assertStringContainsString('Figures of all users of this installation.', $body);
    }

    #[Test]
    public function anEditorSeesOnlyTheirOwnFigures(): void
    {
        $body = $this->render(2);

        self::assertStringContainsString('data-nr-count="failed">1<', $body);
        self::assertStringContainsString('data-nr-count="active">1<', $body);
        self::assertStringContainsString('data-nr-count="openPoints">1<', $body);
        self::assertStringContainsString('data-nr-count="calls">2<', $body);
        self::assertStringContainsString('data-nr-count="failedCalls">1<', $body);
        self::assertStringContainsString('data-nr-count="requests">3<', $body);
        self::assertStringContainsString('data-nr-count="cost">$0.50<', $body);
        self::assertStringContainsString('Your own figures.', $body);
        // The budget is the editor's own across every AI function: this
        // extension's $0.50 and the cowriter's $9.00 of today.
        self::assertStringContainsString('This month you used $9.50 (all AI functions). No personal limit applies.', $body);
    }

    #[Test]
    public function anEditorSeesNeitherTheConfigurationNorAnotherUsersConversation(): void
    {
        $body = $this->render(2);

        self::assertStringNotContainsString('maxLiveStreams', $body);
        self::assertStringNotContainsString('nr-overview-configuration', $body);
        self::assertStringNotContainsString('other failed', $body);
        self::assertStringNotContainsString('timeout', $body);
        // The editor's own waiting conversations are linked.
        self::assertStringContainsString('own approval', $body);
        self::assertStringContainsString('own input', $body);
    }

    #[Test]
    public function anAdministratorSeesTheConfigurationAndWhetherAValueIsTheDefault(): void
    {
        $body = $this->render(1);

        self::assertMatchesRegularExpression('#<code class="small">maxLiveStreams</code></th>\s*<td><code>5</code></td>\s*<td>Set in the extension configuration</td>#', $body);
        self::assertMatchesRegularExpression('#<code class="small">processingStrategy</code></th>\s*<td><code>exec</code></td>\s*<td>Default</td>#', $body);
        // Read nowhere, so not shown (see the overview's HIDDEN_SETTINGS).
        self::assertStringNotContainsString('maxConversationsPerUser', $body);
        // Another user's conversation title stays out even for an administrator.
        self::assertStringNotContainsString('other failed', $body);
    }

    #[Test]
    public function readinessFollowsTheTaskTheChatRuns(): void
    {
        $body = $this->render(2);

        self::assertStringContainsString('Your chat runs the task &quot;Test Task&quot; with the configuration &quot;Test Config&quot;.', $body);
        self::assertStringContainsString('The model &quot;Claude Sonnet&quot; can call tools', $body);
        self::assertStringContainsString('Each message starts its own background process', $body);
    }

    #[Test]
    public function aModelWithoutToolSupportIsReportedAsNotReady(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_model')->update('tx_nrllm_model', ['capabilities' => 'chat'], ['uid' => 1]);

        $body = $this->render(2);

        self::assertMatchesRegularExpression('#data-nr-readiness="tools.missing">\s*<td>\s*<span class="badge badge-danger">#', $body);
        self::assertStringNotContainsString('data-nr-readiness="tools.ok"', $body);
    }

    #[Test]
    public function aMissingTaskIsReportedAsNotReady(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_task')->update('tx_nrllm_task', ['deleted' => 1], ['uid' => 1]);

        $body = $this->render(2);

        self::assertMatchesRegularExpression('#data-nr-readiness="task.missing">\s*<td>\s*<span class="badge badge-danger">#', $body);
        self::assertStringContainsString('The nr-llm task 1 the chat uses for you does not exist.', $body);
    }

    #[Test]
    public function aMonthlyCostLimitIsShownWithTheSpend(): void
    {
        $this->budget(2, ['max_cost_per_month' => 20.0]);

        self::assertStringContainsString('This month you used $9.50 of your limit of $20.00 (all AI functions).', $this->render(2));
    }

    #[Test]
    public function aLimitWithoutACostCapShowsNoCostLimit(): void
    {
        $this->budget(2, ['max_requests_per_month' => 100]);

        $body = $this->render(2);

        self::assertStringNotContainsString('of your limit of', $body);
        self::assertStringContainsString('This month you used $9.50 (all AI functions). A personal limit applies, but not to the monthly cost.', $body);
        self::assertStringNotContainsString('No personal limit', $body);
    }

    #[Test]
    public function withoutABudgetRecordNoPersonalLimitApplies(): void
    {
        $body = $this->render(2);

        self::assertStringContainsString('This month you used $9.50 (all AI functions). No personal limit applies.', $body);
    }

    #[Test]
    public function thePeriodsAreLinksThatKeepTheRouteToken(): void
    {
        $body = $this->render(2);

        foreach ([7, 30, 90] as $days) {
            self::assertMatchesRegularExpression('#<a class="nav-link[^"]*"[^>]* href="[^"]*/module/nr/mcp/agent/chat\?token=[^"&]+&amp;period=' . $days . '"#', $body);
        }

        self::assertMatchesRegularExpression('#<a class="nav-link active" aria-current="page" href="[^"]*period=30"#', $body);
    }

    #[Test]
    public function aLinkWithChatParametersReachesTheChat(): void
    {
        $response = $this->response(1, ['conversation' => '5', 'skill' => 'seo-optimieren']);

        self::assertSame(303, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringContainsString('/module/nr/mcp/agent/chat/conversation', $location);
        self::assertStringContainsString('conversation=5', $location);
        self::assertStringContainsString('skill=seo-optimieren', $location);
    }

    #[Test]
    public function theModuleOpensTheOverviewAndTheChatHasItsOwnRoute(): void
    {
        $module = $this->get(ModuleProvider::class)->getModule('nr_mcp_agent_chat');
        self::assertNotNull($module);

        self::assertSame('netresearch_ai', $module->getParentIdentifier());
        self::assertSame(OverviewController::class . '::indexAction', $module->getDefaultRouteOptions()['_default']['target'] ?? null);
    }

    private function render(int $userUid): string
    {
        $response = $this->response($userUid, []);
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /**
     * @param array<string, string> $query
     */
    private function response(int $userUid, array $query): ResponseInterface
    {
        $backendUser     = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $module          = $this->get(ModuleProvider::class)->getModule('nr_mcp_agent_chat');
        self::assertNotNull($module);
        $route   = new Route('path', $module->getDefaultRouteOptions()['_default'] ?? []);
        $request = (new ServerRequest('https://localhost/typo3/module/nr/mcp/agent/chat', 'GET'))
            ->withQueryParams($query)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $route)
            ->withAttribute('module', $module)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams([]));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $this->get(OverviewController::class)->indexAction($request);
    }

    /**
     * @param array<string, int|float> $limits
     */
    private function budget(int $beUser, array $limits): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_user_budget')->insert('tx_nrllm_user_budget', array_merge([
            'pid'       => 0,
            'be_user'   => $beUser,
            'is_active' => 1,
            'tstamp'    => time(),
            'crdate'    => time(),
        ], $limits));
    }

    private function usage(string $extension, int $beUser, int $requests, int $tokens, float $cost, int $time): void
    {
        $day = strtotime('today', $time);
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_service_usage')->insert('tx_nrllm_service_usage', [
            'pid'              => 0,
            'service_type'     => 'tools',
            'service_provider' => 'openai',
            'source_extension' => $extension,
            'be_user'          => $beUser,
            'request_count'    => $requests,
            'tokens_used'      => $tokens,
            'estimated_cost'   => $cost,
            'request_date'     => $day,
            'tstamp'           => $day,
            'crdate'           => $day,
        ]);
    }

    private function call(string $extension, string $operation, int $beUser, bool $success, int $time): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_telemetry')->insert('tx_nrllm_telemetry', [
            'pid'              => 0,
            'operation'        => 'tools',
            'provider'         => 'openai',
            'be_user'          => $beUser,
            'source_extension' => $extension,
            'source_operation' => $operation,
            'success'          => $success ? 1 : 0,
            'crdate'           => $time,
        ]);
    }
}
