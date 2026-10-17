<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

/**
 * An entry of the "Frequent tasks" dashboard widget: what the editor reads,
 * the skill the chat starts, and whether the task works on one page the
 * widget has to ask for.
 */
final readonly class QuickTask
{
    public function __construct(
        public string $label,
        public string $skill,
        public bool $needsPage,
    ) {}
}
