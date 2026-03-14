<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ExtensionConfiguration
{
    private array $config;

    public function __construct()
    {
        $this->config = GeneralUtility::makeInstance(Typo3ExtensionConfiguration::class)
            ->get('nr_mcp_agent');
    }

    public function getLlmTaskUid(): int
    {
        return (int)($this->config['llmTaskUid'] ?? 0);
    }

    public function getProcessingStrategy(): string
    {
        return (string)($this->config['processingStrategy'] ?? 'exec');
    }

    public function getAllowedGroupIds(): array
    {
        $groups = (string)($this->config['allowedGroups'] ?? '');
        if ($groups === '') {
            return [];
        }
        return array_map('intval', explode(',', $groups));
    }

    public function isMcpEnabled(): bool
    {
        return (bool)($this->config['enableMcp'] ?? false);
    }

    public function getMaxConversationsPerUser(): int
    {
        return (int)($this->config['maxConversationsPerUser'] ?? 50);
    }

    public function getAutoArchiveDays(): int
    {
        return (int)($this->config['autoArchiveDays'] ?? 30);
    }

    public function getMaxMessageLength(): int
    {
        return (int)($this->config['maxMessageLength'] ?? 10000);
    }

    public function getMaxActiveConversationsPerUser(): int
    {
        return (int)($this->config['maxActiveConversationsPerUser'] ?? 3);
    }

    public function getMcpServerCommand(): string
    {
        return (string)($this->config['mcpServerCommand'] ?? '');
    }

    public function getMcpServerArgs(): array
    {
        $args = (string)($this->config['mcpServerArgs'] ?? '');
        if ($args === '') {
            return [];
        }
        return explode(',', $args);
    }
}
