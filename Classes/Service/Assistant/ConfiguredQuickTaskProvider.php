<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Service\SkillCatalogueInterface;
use Netresearch\NrMcpAgent\Service\SkillPageNeed;

/**
 * The tasks named in the extension setting `dashboardQuickTasks`, labelled
 * from locallang_dashboard.xlf, that the chat can start for the current user.
 * Each task carries the identifier the chat accepts (SkillResolverInterface),
 * its label comes from the configured one.
 *
 * Whether a task asks for its page is the skill's declaration (ADR-025):
 * `page: required` asks, `optional` and `none` do not. For a skill that
 * declares nothing the `|page` suffix of the setting decides, as before.
 */
final readonly class ConfiguredQuickTaskProvider implements QuickTaskProviderInterface
{
    public function __construct(
        private ExtensionConfiguration $config,
        private SkillLabels $labels,
        private SkillResolverInterface $resolver,
        private ?SkillCatalogueInterface $catalogue = null,
    ) {}

    public function getQuickTasks(): array
    {
        $tasks = [];
        foreach ($this->config->getDashboardQuickTasks() as $entry) {
            $label    = $this->labels->taskLabel($entry['skill']);
            $resolved = $label === null ? null : $this->resolver->resolve($entry['skill']);
            if ($label === null || $resolved === null) {
                continue;
            }

            $declared = SkillPageNeed::declaredBy($this->catalogue?->find($resolved));
            $tasks[]  = new QuickTask($label, $resolved, $declared instanceof SkillPageNeed ? $declared === SkillPageNeed::Required : $entry['needsPage']);
        }

        return $tasks;
    }
}
