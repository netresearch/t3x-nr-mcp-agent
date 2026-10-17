<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

/**
 * The guided tours the "Improve a page" dashboard widget offers.
 *
 * Bound to ConfiguredGuidedSkillProvider in Configuration/Services.yaml; an
 * installation that lists its tours elsewhere (nr-llm's skill records, say)
 * re-points that alias.
 */
interface GuidedSkillProviderInterface
{
    /**
     * In the order they are offered. Every entry carries a title an editor can
     * read; a skill without one is not returned.
     *
     * @return list<GuidedSkill>
     */
    public function getGuidedSkills(): array;
}
