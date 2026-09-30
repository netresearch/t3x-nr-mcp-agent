<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

/**
 * No-op dispatcher for worker mode.
 * The ChatWorkerCommand polls the DB for conversations with status 'processing'.
 */
final readonly class WorkerChatProcessor implements ChatProcessorInterface
{
    public function dispatch(int $conversationUid): void
    {
        // Worker picks up conversations by polling status field.
        // Nothing to do here.
    }
}
