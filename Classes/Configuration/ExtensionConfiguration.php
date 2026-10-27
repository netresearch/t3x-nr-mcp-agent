<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Configuration;

use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ExtensionConfiguration
{
    /**
     * Rounds a guided-process run may take when nothing else is configured.
     * Sized from the measured tour runs (ADR-023, amendment of 2026-10-22).
     */
    public const DEFAULT_PROCESS_RUN_MAX_ITERATIONS = 12;

    /** Image fields the "missing alt texts" check leaves out unless configured otherwise (ADR-026). */
    public const DEFAULT_ALT_TEXT_EXCLUDED_FIELDS = 'pages.og_image,pages.twitter_image,pages.nav_icon,be_users.avatar,backend_layout.icon';

    /** @var array<string, mixed> */
    private array $config;

    private readonly Typo3ExtensionConfiguration $core;

    public function __construct()
    {
        $this->core = GeneralUtility::makeInstance(Typo3ExtensionConfiguration::class);
        /** @var array<string, mixed> $config */
        $config       = (array) $this->core->get('nr_mcp_agent');
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
     * Callers pass nothing. The parameter exists so the resolution can be
     * tested without a backend user; the groups it is given are trusted as
     * they are, so production code must not pass groups it did not take from
     * the authenticated user.
     *
     * @param list<int>|null $groupIds the user's effective group ids; null reads the current backend user
     *
     * @internal the $groupIds parameter is for tests
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
            $taskUid  = (int) $parts[1];
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
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $backendUser->userGroupsUID,
        ));
    }

    /**
     * How many model rounds a run of a guided process may take (ADR-023,
     * amendment of 2026-10-22).
     *
     * The chat asks nr-llm for this cap on every run that starts or continues
     * a process; nr-llm clamps it to its own ceiling (nr-llm ADR-101). Ordinary
     * runs ask for nothing and keep nr-llm's default. A value below 1, or one
     * that is not a number, falls back to the default: a typo must not leave a
     * tour with one round.
     */
    public function getProcessRunMaxIterations(): int
    {
        $value = $this->getString('processRunMaxIterations', (string) self::DEFAULT_PROCESS_RUN_MAX_ITERATIONS);

        return ctype_digit(trim($value)) && (int) $value > 0 ? (int) $value : self::DEFAULT_PROCESS_RUN_MAX_ITERATIONS;
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
        $folder   = trim($this->getString('attachmentFolder', 'ai-chat'), " \t\n\r/");
        $segments = explode('/', $folder);

        foreach ($segments as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                return 'ai-chat';
            }
        }

        return $folder;
    }

    /**
     * The guided tours the "Improve a page" dashboard widget offers, as skill
     * identifiers in the configured order.
     *
     * An identifier is either the one the chat's skill catalogue uses (nr-llm's
     * `<source uid>:<path>`, e.g. `3:seo-optimieren`) or a bare slug that
     * CatalogueSkillResolver matches against the part after `<source uid>:`. Only what the chat accepts as an identifier is
     * kept — letters, digits and `._:/-`, starting with a letter or digit, at
     * most 100 characters; anything else is dropped rather than passed on.
     *
     * @return list<string>
     */
    public function getDashboardGuidedSkills(): array
    {
        $skills = [];
        foreach (explode(',', $this->getString('dashboardGuidedSkills', 'seo-optimieren,inhalt-verbessern')) as $entry) {
            $skill = trim($entry);
            if ($this->isSkillIdentifier($skill) && !in_array($skill, $skills, true)) {
                $skills[] = $skill;
            }
        }

        return $skills;
    }

    /**
     * The entries of the "Frequent tasks" dashboard widget: comma-separated
     * skill identifiers, each optionally followed by `|page` when the task
     * works on one page and the widget has to ask which.
     *
     * @return list<array{skill: string, needsPage: bool}>
     */
    public function getDashboardQuickTasks(): array
    {
        $tasks = [];
        foreach (explode(',', $this->getString('dashboardQuickTasks', '')) as $entry) {
            $parts = array_map(trim(...), explode('|', $entry));
            $skill = $parts[0];
            if (!$this->isSkillIdentifier($skill) || count($parts) > 2) {
                continue;
            }

            $flag = $parts[1] ?? '';
            if ($flag !== '' && $flag !== 'page') {
                continue;
            }

            $tasks[] = ['skill' => $skill, 'needsPage' => $flag === 'page'];
        }

        return $tasks;
    }

    /**
     * Tables whose image references the "missing alt texts" check leaves out
     * (ADR-026), e.g. records that are never shown in the frontend. None by
     * default. An entry that is not a plain table name is dropped.
     *
     * @return list<string>
     */
    public function getAltTextExcludedTables(): array
    {
        return $this->identifierList('altTextExcludedTables', '', '/^[A-Za-z0-9_]+$/');
    }

    /**
     * Image fields, as `table.field`, the "missing alt texts" check leaves out
     * (ADR-026): fields whose images the frontend does not output as an image
     * with an alt text. The default names the page's social-media images, the
     * navigation icon, the backend user's avatar and the backend layout's
     * icon; a site that sets its own list replaces the default.
     *
     * @return list<string>
     */
    public function getAltTextExcludedFields(): array
    {
        return $this->identifierList('altTextExcludedFields', self::DEFAULT_ALT_TEXT_EXCLUDED_FIELDS, '/^[A-Za-z0-9_]+\.[A-Za-z0-9_]+$/');
    }

    /**
     * The privacy setting "Bildanalyse" (ADR-026, slice 3): whether images go
     * to the AI provider at all. `analyse`, the specification's default, sends
     * a scaled copy; `contextOnly` sends none. An empty value is the default;
     * any other value sends none, so a setting written by hand that is not one
     * of the two never sends an image by mistake.
     */
    public function isImageAnalysisEnabled(): bool
    {
        $value = trim($this->getImageAnalysisValues()['imageAnalysis']);

        return $value === '' || $value === 'analyse';
    }

    /**
     * The two values of the privacy setting as configured now, read on every
     * call rather than from what the constructor read: a decision about an
     * image follows the configuration of the turn it is made in, which a
     * worker overlays per turn with the values the turn was dispatched with
     * (ImageAnalysisTurnSettings). The read is an array lookup in the loaded
     * configuration.
     *
     * @return array{imageAnalysis: string, imageAnalysisExcludedFolders: string}
     */
    public function getImageAnalysisValues(): array
    {
        try {
            $current = $this->core->get('nr_mcp_agent');
        } catch (Throwable) {
            $current = [];
        }

        $current  = is_array($current) ? $current : [];
        $analysis = $current['imageAnalysis'] ?? 'analyse';
        $folders  = $current['imageAnalysisExcludedFolders'] ?? '';

        return [
            'imageAnalysis'                => is_scalar($analysis) ? (string) $analysis : 'contextOnly',
            'imageAnalysisExcludedFolders' => is_scalar($folders) ? (string) $folders : '',
        ];
    }

    /**
     * The folders whose images are never sent while image analysis is on, as
     * written: `fileadmin/team/` (a storage's base path and the folder) or
     * `1:/team/` (the storage uid and the folder). Empty entries and
     * duplicates are dropped; the policy resolves the rest.
     *
     * @return list<string>
     */
    public function getImageAnalysisExcludedFolders(): array
    {
        $folders = [];
        foreach (explode(',', $this->getImageAnalysisValues()['imageAnalysisExcludedFolders']) as $entry) {
            $entry = trim($entry);
            if ($entry !== '' && !in_array($entry, $folders, true)) {
                $folders[] = $entry;
            }
        }

        return $folders;
    }

    /**
     * @return list<string>
     */
    private function identifierList(string $key, string $default, string $pattern): array
    {
        $entries = [];
        foreach (explode(',', $this->getString($key, $default)) as $entry) {
            $entry = trim($entry);
            if (preg_match($pattern, $entry) === 1 && !in_array($entry, $entries, true)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function isSkillIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,99}$/', $value) === 1;
    }

    private function getString(string $key, string $default): string
    {
        $value = $this->config[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }
}
