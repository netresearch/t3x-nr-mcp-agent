<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Error;
use Netresearch\NrMcpAgent\Domain\Repository\ChatWorkerRepository;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;

/**
 * Leaves the turn to a waiting `ai-chat:worker` (ADR-029). Workers pick a turn
 * up by polling its status, so there is nothing to do while one is free.
 *
 * Without a free worker the turn starts a process of its own, as with the
 * `exec` strategy: all workers busy with other turns, restarting after a deploy,
 * or down. A turn then costs the start of a process, and never waits for a
 * worker that is not coming. Both may reach for the turn; one claim wins.
 */
final readonly class WorkerChatProcessor implements ChatProcessorInterface
{
    public function __construct(
        private ChatWorkerRepository $workers,
        private ConversationRepository $repository,
        private ExecChatProcessor $execProcessor,
    ) {}

    public function dispatch(int $conversationUid): void
    {
        if ($this->workers->countIdle() >= $this->repository->countWaitingForWorker()) {
            return;
        }

        try {
            $this->execProcessor->dispatch($conversationUid);
        } catch (Error) {
            // The function is disabled here: the turn stays queued for the next worker.
        }
    }
}
