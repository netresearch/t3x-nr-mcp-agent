<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Dashboard;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Dashboard\WidgetRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * With EXT:dashboard installed, the "AI Chat" widget is registered (NEXT-172).
 * Every other functional test runs without EXT:dashboard, so the container
 * building for them is the other half: Classes/Dashboard/ stays out of it.
 */
final class AiChatWidgetRegistrationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    #[Test]
    public function theWidgetIsRegisteredWithTheDashboard(): void
    {
        $widgets = $this->get(WidgetRegistry::class)->getAllWidgets();

        self::assertArrayHasKey('nrMcpAgentAiChat', $widgets);
    }
}
