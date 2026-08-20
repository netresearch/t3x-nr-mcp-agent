<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Configuration;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
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
            'maxMessageLength' => '5000',
            'maxActiveConversationsPerUser' => '2',
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
    public function getMaxConversationsPerUserReturnsConfiguredValue(): void
    {
        // Consume the setUp mock first
        GeneralUtility::makeInstance(Typo3ExtensionConfiguration::class);

        $mock = $this->createMock(Typo3ExtensionConfiguration::class);
        $mock->method('get')->with('nr_mcp_agent')->willReturn([
            'maxConversationsPerUser' => '100',
        ]);
        GeneralUtility::addInstance(Typo3ExtensionConfiguration::class, $mock);

        $config = new ExtensionConfiguration();
        self::assertSame(100, $config->getMaxConversationsPerUser());
    }

    #[Test]
    public function getAutoArchiveDaysReturnsConfiguredValue(): void
    {
        // Consume the setUp mock first
        GeneralUtility::makeInstance(Typo3ExtensionConfiguration::class);

        $mock = $this->createMock(Typo3ExtensionConfiguration::class);
        $mock->method('get')->with('nr_mcp_agent')->willReturn([
            'autoArchiveDays' => '60',
        ]);
        GeneralUtility::addInstance(Typo3ExtensionConfiguration::class, $mock);

        $config = new ExtensionConfiguration();
        self::assertSame(60, $config->getAutoArchiveDays());
    }

    #[Test]
    public function getAutoArchiveDaysDefaultsTo30(): void
    {
        // Consume the setUp mock first
        GeneralUtility::makeInstance(Typo3ExtensionConfiguration::class);

        $mock = $this->createMock(Typo3ExtensionConfiguration::class);
        $mock->method('get')->with('nr_mcp_agent')->willReturn([]);
        GeneralUtility::addInstance(Typo3ExtensionConfiguration::class, $mock);

        $config = new ExtensionConfiguration();
        self::assertSame(30, $config->getAutoArchiveDays());
    }

    #[Test]
    public function getMaxConversationsPerUserDefaultsTo50(): void
    {
        // Consume the setUp mock first
        GeneralUtility::makeInstance(Typo3ExtensionConfiguration::class);

        $mock = $this->createMock(Typo3ExtensionConfiguration::class);
        $mock->method('get')->with('nr_mcp_agent')->willReturn([]);
        GeneralUtility::addInstance(Typo3ExtensionConfiguration::class, $mock);

        $config = new ExtensionConfiguration();
        self::assertSame(50, $config->getMaxConversationsPerUser());
    }


    #[Test]
    public function getProcessingStrategyReturnsWorkerWhenConfigured(): void
    {
        $config = new ExtensionConfiguration();
        self::assertSame('worker', $config->getProcessingStrategy());
    }

    #[Test]
    public function defaultsAreUsedForMissingKeys(): void
    {
        // Consume the setUp mock first (FIFO queue)
        GeneralUtility::makeInstance(Typo3ExtensionConfiguration::class);

        $mock = $this->createMock(Typo3ExtensionConfiguration::class);
        $mock->method('get')->with('nr_mcp_agent')->willReturn([]);
        GeneralUtility::addInstance(Typo3ExtensionConfiguration::class, $mock);

        $config = new ExtensionConfiguration();
        self::assertSame(0, $config->getLlmTaskUid());
        self::assertSame('exec', $config->getProcessingStrategy());
        self::assertSame([], $config->getAllowedGroupIds());
        self::assertSame(10000, $config->getMaxMessageLength());
        self::assertSame(3, $config->getMaxActiveConversationsPerUser());
    }

    #[Test]
    public function getMaxMessageLengthReturnsConfiguredValue(): void
    {
        $config = new ExtensionConfiguration();
        self::assertSame(5000, $config->getMaxMessageLength());
    }

    #[Test]
    public function getMaxActiveConversationsPerUserReturnsConfiguredValue(): void
    {
        $config = new ExtensionConfiguration();
        self::assertSame(2, $config->getMaxActiveConversationsPerUser());
    }


    #[Test]
    public function getAllowedGroupIdsReturnsSingleValue(): void
    {
        // Consume the setUp mock first
        GeneralUtility::makeInstance(Typo3ExtensionConfiguration::class);

        $mock = $this->createMock(Typo3ExtensionConfiguration::class);
        $mock->method('get')->with('nr_mcp_agent')->willReturn([
            'allowedGroups' => '42',
        ]);
        GeneralUtility::addInstance(Typo3ExtensionConfiguration::class, $mock);

        $config = new ExtensionConfiguration();
        self::assertSame([42], $config->getAllowedGroupIds());
    }


    #[Test]
    public function nonScalarConfigValueFallsBackToDefault(): void
    {
        // Consume the setUp mock first
        GeneralUtility::makeInstance(Typo3ExtensionConfiguration::class);

        $mock = $this->createMock(Typo3ExtensionConfiguration::class);
        $mock->method('get')->with('nr_mcp_agent')->willReturn([
            'llmTaskUid' => ['nested' => 'array'],
            'maxMessageLength' => new stdClass(),
        ]);
        GeneralUtility::addInstance(Typo3ExtensionConfiguration::class, $mock);

        $config = new ExtensionConfiguration();
        self::assertSame(0, $config->getLlmTaskUid());
        self::assertSame(10000, $config->getMaxMessageLength());
    }
}
