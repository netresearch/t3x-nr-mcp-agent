<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

/**
 * Whether the chat's runs may use given tools: registered in nr-llm, enabled,
 * in the configuration's tool groups and the trust zone, and not for admins
 * only where the user is none (nr-llm's ToolCallPolicyInterface). Asked for
 * the current backend user and the configuration their chat runs on.
 */
interface ToolAvailabilityInterface
{
    /**
     * The names among `$toolNames` a run of the current user's chat could
     * not call; all of them when that cannot be told.
     *
     * @param list<string> $toolNames
     *
     * @return list<string>
     */
    public function unavailableTools(array $toolNames): array;
}
