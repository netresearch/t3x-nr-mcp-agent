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

    /**
     * Where a chat attachment is stored in the default storage, relative to its
     * root and without surrounding slashes.
     *
     * An attachment is a managed file from the moment it is uploaded — it is
     * indexed in `sys_file` and can be referenced from a content element — so
     * where it lands is an editorial decision about someone's `fileadmin`, not
     * an implementation detail. Hence a setting rather than the constant this
     * used to be (NEXT-157).
     *
     * An empty or slash-only value falls back to the default rather than writing
     * into the storage root: a chat that scatters uploads across the top of
     * `fileadmin` is worse than one that ignores a broken setting.
     */
    public function getAttachmentFolder(): string
    {
        $folder = trim($this->getString('attachmentFolder', 'ai-chat'), " \t\n\r/");

        return $folder !== '' ? $folder : 'ai-chat';
    }

    private function getString(string $key, string $default): string
    {
        $value = $this->config[$key] ?? $default;
        return is_scalar($value) ? (string) $value : $default;
    }
}
