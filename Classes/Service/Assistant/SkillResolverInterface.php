<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

/**
 * Which skill the chat would start for a configured identifier, for the
 * current user, right now.
 *
 * The dashboard widgets offer only skills that resolve, and send the resolved
 * identifier to the chat: an entry for a skill that does not exist, is not
 * attached to the chat, or cannot run on this installation is left out rather
 * than offered as a link the chat would refuse. Bound to
 * CatalogueSkillResolver in Configuration/Services.yaml.
 */
interface SkillResolverInterface
{
    /**
     * The identifier the chat accepts for this skill (its catalogue
     * identifier), or null when the chat cannot start it.
     */
    public function resolve(string $skill): ?string;
}
