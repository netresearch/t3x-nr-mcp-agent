<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

/**
 * The entries of the "Frequent tasks" dashboard widget.
 *
 * Bound to ConfiguredQuickTaskProvider in Configuration/Services.yaml.
 */
interface QuickTaskProviderInterface
{
    /**
     * In the order they are shown. Every entry carries a label an editor can
     * read; a task without one is not returned.
     *
     * @return list<QuickTask>
     */
    public function getQuickTasks(): array;
}
