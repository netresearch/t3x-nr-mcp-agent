<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Configuration;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ExtensionConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Provide a mock for GeneralUtility::makeInstance
        $mock = $this->createMock(Typo3ExtensionConfiguration::class);
        $mock->method('get')->with('nr_mcp_agent')->willReturn([
            'llmTaskUid' => '42',
            'processingStrategy' => 'worker',
            'allowedGroups' => '1,3,5',
            'enableMcp' => '1',
            'maxMessageLength' => '5000',
            'maxActiveConversationsPerUser' => '2',
            'mcpServerCommand' => '/usr/bin/typo3',
            'mcpServerArgs' => 'mcp:server,--verbose',
        ]);
        GeneralUtility::addInstance(Typo3ExtensionConfiguration::class, $mock);
    }

    #[Test]
    public function getLlmTaskUidReturnsCastedInt(): void
    {
        $config = new ExtensionConfiguration();
        self::assertSame(42, $config->getLlmTaskUid());
    }

    #[Test]
    public function getAllowedGroupIdsParsesCommaList(): void
    {
        $config = new ExtensionConfiguration();
        self::assertSame([1, 3, 5], $config->getAllowedGroupIds());
    }

    #[Test]
    public function getMcpServerArgsSplitsCommaList(): void
    {
        $config = new ExtensionConfiguration();
        self::assertSame(['mcp:server', '--verbose'], $config->getMcpServerArgs());
    }

    #[Test]
    public function defaultsAreUsedForMissingKeys(): void
    {
        $mock = $this->createMock(Typo3ExtensionConfiguration::class);
        $mock->method('get')->with('nr_mcp_agent')->willReturn([]);
        GeneralUtility::addInstance(Typo3ExtensionConfiguration::class, $mock);

        $config = new ExtensionConfiguration();
        self::assertSame(0, $config->getLlmTaskUid());
        self::assertSame('exec', $config->getProcessingStrategy());
        self::assertSame([], $config->getAllowedGroupIds());
        self::assertFalse($config->isMcpEnabled());
        self::assertSame(10000, $config->getMaxMessageLength());
        self::assertSame(3, $config->getMaxActiveConversationsPerUser());
    }
}
