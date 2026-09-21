<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Controller;

use Netresearch\NrMcpAgent\Controller\ChatModuleController;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class ChatModuleControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function controllerCanBeInstantiatedFromContainer(): void
    {
        $controller = $this->get(ChatModuleController::class);
        self::assertInstanceOf(ChatModuleController::class, $controller);
    }

    #[Test]
    public function moduleIsRegistered(): void
    {
        $moduleProvider = $this->get(ModuleProvider::class);
        $module = $moduleProvider->getModule('nr_mcp_agent_chat');
        self::assertNotNull($module, 'Module nr_mcp_agent_chat must be registered');
    }

    #[Test]
    public function indexActionRendersResponseWithChatAppElement(): void
    {
        $response = $this->renderModulePage();

        self::assertSame(200, $response->getStatusCode());

        $body = (string) $response->getBody();
        self::assertStringContainsString('<nr-chat-app', $body);
        self::assertStringContainsString('data-max-length=', $body);
    }

    /**
     * The module used to rely on the toolbar item's registration of the label
     * file: lll() falls back to top.TYPO3.lang, and the toolbar had put the
     * labels there. A user for whom ChatToolbarItem::checkAccess() fails — the
     * chat restricted to groups they are not in — saw raw keys in the module
     * (NEXT-159). The module page carries the labels itself.
     */
    #[Test]
    public function indexActionRegistersTheChatLabelsOnTheModulePage(): void
    {
        $body = (string) $this->renderModulePage()->getBody();

        self::assertStringContainsString('"chat.approvalPending":"Waiting for approval"', $body);
        self::assertStringContainsString('"chat.approvalPendingDetail":"This step writes data and needs an approval before it runs."', $body);
    }

    private function renderModulePage(): ResponseInterface
    {
        $moduleProvider = $this->get(ModuleProvider::class);
        $module = $moduleProvider->getModule('nr_mcp_agent_chat');
        self::assertNotNull($module);

        // Build a Route that carries the packageName option so BackendViewFactory
        // can resolve the Fluid template paths from the correct extension.
        $routeOptions = $module->getDefaultRouteOptions();
        $defaultOpts = $routeOptions['_default'] ?? [];
        $route = new Route('path', $defaultOpts);

        $request = (new ServerRequest('https://localhost/typo3/module/tools/nr-mcp-agent-chat', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $route)
            ->withAttribute('module', $module)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams([]));

        $GLOBALS['TYPO3_REQUEST'] = $request;

        $controller = $this->get(ChatModuleController::class);

        return $controller->indexAction($request);
    }
}
