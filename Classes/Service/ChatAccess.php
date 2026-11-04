<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Enum\ChatAccessDecision;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Whether a backend user gets the chat: the rule the toolbar item applies
 * (ChatToolbarItem::checkAccess()) and the overview module explains.
 *
 * A Task must be configured for the user (llmTaskUid or a groupTaskMapping
 * row, ADR-015). With `allowedGroups` empty everyone gets the chat; otherwise
 * administrators and members of a listed group do — direct membership only,
 * subgroups do not count (ADR-009).
 */
final readonly class ChatAccess
{
    public function __construct(
        private ExtensionConfiguration $config,
    ) {}

    public function decide(?BackendUserAuthentication $user): ChatAccessDecision
    {
        if ($this->config->getLlmTaskUid() === 0) {
            return ChatAccessDecision::NoTask;
        }

        $allowed = $this->config->getAllowedGroupIds();
        if ($allowed === []) {
            return ChatAccessDecision::Allowed;
        }

        if (!$user instanceof BackendUserAuthentication) {
            return ChatAccessDecision::NotInAllowedGroup;
        }

        if ($user->isAdmin()) {
            return ChatAccessDecision::Allowed;
        }

        $usergroup  = $user->user['usergroup'] ?? null;
        $userGroups = array_map(intval(...), explode(',', is_string($usergroup) ? $usergroup : ''));

        return array_intersect($allowed, $userGroups) !== []
            ? ChatAccessDecision::Allowed
            : ChatAccessDecision::NotInAllowedGroup;
    }
}
