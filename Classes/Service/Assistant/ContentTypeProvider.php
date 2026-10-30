<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

use Netresearch\NrMcpAgent\Service\ToolAvailabilityInterface;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * What "Create content" offers: the kinds of record the chat can create for
 * the current backend user, each with the tool that creates it.
 *
 * A kind is offered only where all of these hold:
 * - its table exists on this installation (News: EXT:news is installed);
 * - the user may change records of the table (`tables_modify`, admins
 *   always);
 * - a run of the user's chat could call the tool that creates it (enabled in
 *   nr-llm, in the configuration's tool groups, ToolAvailabilityInterface);
 * - the tool would not refuse it at once: the user may edit the default
 *   language (both tools refuse otherwise), and for the generic record tool
 *   the table is not declared adminOnly, hideTable or readOnly, has a
 *   "disabled" enable column, and is not on nr-llm's deny-list
 *   `tools.createRecordDraft.deniedTables` (an unreadable list refuses every
 *   table, as the tool does).
 *
 * Landing pages, forms, press releases and events are not offered: no tool
 * the chat has creates them (a form is a YAML definition, not a record; the
 * others need tables this installation does not define as such).
 */
readonly class ContentTypeProvider
{
    /**
     * The kinds, in the order the widget shows them: key => [table, tool].
     *
     * @var array<string, array{string, string}>
     */
    public const TYPES = [
        'page' => ['pages', 'create_page_draft'],
        'news' => ['tx_news_domain_model_news', 'create_record_draft'],
    ];

    /** The generic record tool, whose table rules are checked here as well. */
    private const RECORD_TOOL = 'create_record_draft';

    public function __construct(
        private ToolAvailabilityInterface $tools,
        private ?ExtensionConfiguration $extensionConfiguration = null,
    ) {}

    /**
     * @return list<string> the keys of TYPES the user can create with the chat
     */
    public function available(): array
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication || !$user->checkLanguageAccess(0)) {
            return [];
        }

        $candidates = array_filter(
            self::TYPES,
            fn (array $type): bool => $this->tableExists($type[0])
                && ($user->isAdmin() || $user->check('tables_modify', $type[0]))
                && ($type[1] !== self::RECORD_TOOL || $this->recordToolTakes($type[0])),
        );
        if ($candidates === []) {
            return [];
        }

        $unavailable = $this->tools->unavailableTools(array_values(array_unique(array_column($candidates, 1))));

        return array_keys(array_filter($candidates, static fn (array $type): bool => !in_array($type[1], $unavailable, true)));
    }

    /** Whether the generic record tool would write the table, by its table rules. */
    private function recordToolTakes(string $table): bool
    {
        $tca  = $GLOBALS['TCA'] ?? null;
        $ctrl = is_array($tca) && is_array($tca[$table] ?? null) && is_array($tca[$table]['ctrl'] ?? null) ? $tca[$table]['ctrl'] : [];
        foreach (['adminOnly', 'hideTable', 'readOnly'] as $flag) {
            if ((bool) ($ctrl[$flag] ?? false)) {
                return false;
            }
        }

        $enableColumns = is_array($ctrl['enablecolumns'] ?? null) ? $ctrl['enablecolumns'] : [];
        if (!is_string($enableColumns['disabled'] ?? null) || $enableColumns['disabled'] === '') {
            return false;
        }

        $denied = $this->deniedTables();

        return $denied !== null && !in_array($table, $denied, true);
    }

    /**
     * nr-llm's deny-list of the record tool: no setting is none; a setting
     * that cannot be read is null, which the tool reads as "refuse all".
     *
     * @return list<string>|null
     */
    private function deniedTables(): ?array
    {
        if (!$this->extensionConfiguration instanceof ExtensionConfiguration) {
            return [];
        }

        try {
            $node = $this->extensionConfiguration->get('nr_llm');
        } catch (Throwable) {
            return null;
        }

        foreach (['tools', 'createRecordDraft', 'deniedTables'] as $key) {
            if (!is_array($node)) {
                return null;
            }

            if (!array_key_exists($key, $node)) {
                return [];
            }

            $node = $node[$key];
        }

        return is_string($node) ? array_values(array_filter(array_map(trim(...), explode(',', $node)), static fn (string $table): bool => $table !== '')) : null;
    }

    private function tableExists(string $table): bool
    {
        $tca = $GLOBALS['TCA'] ?? null;

        return is_array($tca) && is_array($tca[$table] ?? null);
    }
}
