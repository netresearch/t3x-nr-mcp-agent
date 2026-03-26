<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ExtensionConfiguration
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct()
    {
        /** @var array<string, mixed> $config */
        $config = (array) GeneralUtility::makeInstance(Typo3ExtensionConfiguration::class)
            ->get('nr_mcp_agent');
        $this->config = $config;
    }

    public function getLlmTaskUid(): int
    {
        return (int) $this->getString('llmTaskUid', '0');
    }

    public function getProcessingStrategy(): string
    {
        return $this->getString('processingStrategy', 'exec');
    }

    /** @return list<int> */
    public function getAllowedGroupIds(): array
    {
        $groups = $this->getString('allowedGroups', '');
        if ($groups === '') {
            return [];
        }
        return array_map(intval(...), explode(',', $groups));
    }

    public function isMcpEnabled(): bool
    {
        return $this->getString('enableMcp', '0') === '1';
    }

    /**
     * Checks whether removed legacy fields (mcpServerCommand, mcpServerArgs) are still
     * present in the extension configuration. Used to show a migration FlashMessage.
     */
    public function hasLegacyMcpFields(): bool
    {
        return isset($this->config['mcpServerCommand']) && $this->config['mcpServerCommand'] !== ''
            || isset($this->config['mcpServerArgs']) && $this->config['mcpServerArgs'] !== '';
    }

    public function getMaxConversationsPerUser(): int
    {
        return (int) $this->getString('maxConversationsPerUser', '50');
    }

    public function getAutoArchiveDays(): int
    {
        return (int) $this->getString('autoArchiveDays', '30');
    }

    public function getMaxMessageLength(): int
    {
        return (int) $this->getString('maxMessageLength', '10000');
    }

    public function getMaxActiveConversationsPerUser(): int
    {
        return (int) $this->getString('maxActiveConversationsPerUser', '3');
    }

    private function getString(string $key, string $default): string
    {
        $value = $this->config[$key] ?? $default;
        return is_scalar($value) ? (string) $value : $default;
    }
}
