<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\ChatTask;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Task;

/** The nr-llm Task the chat runs and the Configuration it names, usable by the actor. */
final readonly class ResolvedChatTask
{
    public function __construct(
        public Task $task,
        public LlmConfiguration $configuration,
    ) {}
}
