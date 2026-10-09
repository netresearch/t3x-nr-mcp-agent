<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;

/**
 * The guided tours named in the extension setting `dashboardGuidedSkills`,
 * labelled from locallang_dashboard.xlf, that the chat can start for the
 * current user. Each tour carries the identifier the chat accepts
 * (SkillResolverInterface), its labels come from the configured one.
 */
final readonly class ConfiguredGuidedSkillProvider implements GuidedSkillProviderInterface
{
    public function __construct(
        private ExtensionConfiguration $config,
        private SkillLabels $labels,
        private SkillResolverInterface $resolver,
    ) {}

    public function getGuidedSkills(): array
    {
        $skills = [];
        foreach ($this->config->getDashboardGuidedSkills() as $identifier) {
            $title = $this->labels->title($identifier);
            $resolved = $title === null ? null : $this->resolver->resolve($identifier);
            if ($title === null || $resolved === null) {
                continue;
            }

            $skills[] = new GuidedSkill($resolved, $title, $this->labels->description($identifier) ?? '');
        }

        return $skills;
    }
}
