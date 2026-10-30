<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

use Netresearch\NrMcpAgent\Service\ToolAvailabilityInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * What "Create content" offers: the kinds of record the chat can create for
 * the current backend user, each with the tool that creates it.
 *
 * A kind is offered only where all of these hold:
 * - its table exists on this installation (News: EXT:news is installed);
 * - the user may change records of the table (`tables_modify`, admins
 *   always);
 * - a run of the user's chat could call the tool that creates it (enabled in
 *   nr-llm, in the configuration's tool groups, ToolAvailabilityInterface).
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

    public function __construct(
        private ToolAvailabilityInterface $tools,
    ) {}

    /**
     * @return list<string> the keys of TYPES the user can create with the chat
     */
    public function available(): array
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            return [];
        }

        $candidates = array_filter(
            self::TYPES,
            fn (array $type): bool => $this->tableExists($type[0]) && ($user->isAdmin() || $user->check('tables_modify', $type[0])),
        );
        if ($candidates === []) {
            return [];
        }

        $unavailable = $this->tools->unavailableTools(array_values(array_unique(array_column($candidates, 1))));

        return array_keys(array_filter($candidates, static fn (array $type): bool => !in_array($type[1], $unavailable, true)));
    }

    private function tableExists(string $table): bool
    {
        $tca = $GLOBALS['TCA'] ?? null;

        return is_array($tca) && is_array($tca[$table] ?? null);
    }
}
