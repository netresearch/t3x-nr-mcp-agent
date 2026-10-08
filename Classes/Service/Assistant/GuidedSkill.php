<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

/**
 * A guided tour an editor can start on one page: the skill the chat runs, and
 * what the editor reads about it.
 */
final readonly class GuidedSkill
{
    public function __construct(
        public string $identifier,
        public string $title,
        public string $description,
    ) {}
}
