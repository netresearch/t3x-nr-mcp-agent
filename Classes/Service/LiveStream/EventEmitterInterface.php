<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\LiveStream;

/**
 * Where the events of a conversation's stream go.
 */
interface EventEmitterInterface
{
    /** Start the response. */
    public function open(): void;

    /**
     * @param array<string, mixed> $event
     */
    public function send(array $event): void;

    /** Keep the connection alive and notice a reader that left. */
    public function heartbeat(): void;

    /** Whether the reader has gone. */
    public function aborted(): bool;
}
