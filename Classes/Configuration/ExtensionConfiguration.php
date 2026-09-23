<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Configuration;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
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

    /**
     * The nr-llm Task the chat runs on for a backend user (ADR-015).
     *
     * `groupTaskMapping` assigns a Task per backend group; the first pair in
     * the configured order whose group the user belongs to decides. A user in
     * none of the mapped groups — and every caller when nothing is mapped —
     * gets `llmTaskUid`. Group membership is the effective one, subgroups
     * included (`userGroupsUID`), so mapping a parent group covers the groups
     * below it, as a group permission would.
     *
     * Without an explicit list the groups of the current backend user are
     * read: the live `$GLOBALS['BE_USER']` of a backend request, or the owner
     * of the conversation a CLI worker initialised. Every caller — the status
     * endpoint, the toolbar, the turn itself — runs as that user, so the same
     * question gets the same answer everywhere.
     *
     * @param list<int>|null $groupIds the user's effective group ids; null reads the current backend user
     */
    public function getLlmTaskUid(?array $groupIds = null): int
    {
        $default = $this->getDefaultLlmTaskUid();
        $mapping = $this->getGroupTaskMapping();
        if ($mapping === []) {
            return $default;
        }

        $groupIds ??= $this->currentUserGroupIds();
        foreach ($mapping as [$groupUid, $taskUid]) {
            if (in_array($groupUid, $groupIds, true)) {
                return $taskUid;
            }
        }

        return $default;
    }

    /** The `llmTaskUid` setting itself: the Task of users no group mapping applies to. */
    private function getDefaultLlmTaskUid(): int
    {
        return (int) $this->getString('llmTaskUid', '0');
    }

    /**
     * `groupTaskMapping` as ordered pairs [groupUid, taskUid].
     *
     * Written as `groupUid:taskUid` pairs separated by commas. A pair that is
     * not two positive integers is skipped rather than failing the whole
     * setting: one typo should cost one mapping, not the chat.
     *
     * @return list<array{int, int}>
     */
    public function getGroupTaskMapping(): array
    {
        $pairs = [];
        foreach (explode(',', $this->getString('groupTaskMapping', '')) as $pair) {
            $parts = array_map(trim(...), explode(':', $pair));
            if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
                continue;
            }

            $groupUid = (int) $parts[0];
            $taskUid = (int) $parts[1];
            if ($groupUid > 0 && $taskUid > 0) {
                $pairs[] = [$groupUid, $taskUid];
            }
        }

        return $pairs;
    }

    /** @return list<int> */
    private function currentUserGroupIds(): array
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return [];
        }

        return array_values(array_map(
            static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $backendUser->userGroupsUID,
        ));
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
     *
     * A value carrying `.` or `..` segments falls back for the same reason. It
     * cannot escape the storage either way — FAL refuses such an identifier with
     * `InvalidPathException`, and `createFolder()` sanitizes each segment on top
     * — but that refusal reaches the person uploading as a 500 from a setting
     * they cannot see. Falling back keeps a typo in the configuration from
     * looking like a broken chat.
     */
    public function getAttachmentFolder(): string
    {
        $folder = trim($this->getString('attachmentFolder', 'ai-chat'), " \t\n\r/");
        $segments = explode('/', $folder);

        foreach ($segments as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                return 'ai-chat';
            }
        }

        return $folder;
    }

    private function getString(string $key, string $default): string
    {
        $value = $this->config[$key] ?? $default;
        return is_scalar($value) ? (string) $value : $default;
    }
}
