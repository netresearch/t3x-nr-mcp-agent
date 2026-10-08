<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;

/**
 * The tasks named in the extension setting `dashboardQuickTasks`, labelled
 * from locallang_dashboard.xlf, that the chat can start for the current user.
 * Each task carries the identifier the chat accepts (SkillResolverInterface),
 * its label comes from the configured one.
 */
final readonly class ConfiguredQuickTaskProvider implements QuickTaskProviderInterface
{
    public function __construct(
        private ExtensionConfiguration $config,
        private SkillLabels $labels,
        private SkillResolverInterface $resolver,
    ) {}

    public function getQuickTasks(): array
    {
        $tasks = [];
        foreach ($this->config->getDashboardQuickTasks() as $entry) {
            $label = $this->labels->taskLabel($entry['skill']);
            $resolved = $label === null ? null : $this->resolver->resolve($entry['skill']);
            if ($label === null || $resolved === null) {
                continue;
            }

            $tasks[] = new QuickTask($label, $resolved, $entry['needsPage']);
        }

        return $tasks;
    }
}
