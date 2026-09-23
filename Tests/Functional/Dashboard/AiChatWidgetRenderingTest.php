<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Dashboard;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Dashboard\WidgetRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The widget rendered through the dashboard's own registry, as the dashboard
 * does it (NEXT-172).
 */
final class AiChatWidgetRenderingTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'nr_mcp_agent' => ['llmTaskUid' => '1'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_nrmcpagent_conversation.csv');
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    private function request(): ServerRequest
    {
        return (new ServerRequest('https://example.com/typo3/module/dashboard'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/dashboard', []));
    }

    #[Test]
    public function itListsTheConversationsAndOffersTheChat(): void
    {
        self::assertGreaterThan(0, $this->get(ExtensionConfiguration::class)->getLlmTaskUid(), 'precondition: the chat is configured');

        $html = $this->get(WidgetRegistry::class)
            ->getAvailableWidget($this->request(), 'nrMcpAgentAiChat')
            ->renderWidgetContent();

        self::assertStringContainsString('data-nr-chat-conversation="1"', $html);
        self::assertStringContainsString('Conv 1', $html);
        // Another user's conversation.
        self::assertStringNotContainsString('Conv 3', $html);
        self::assertStringContainsString('data-nr-chat-new="1"', $html);
    }
}
