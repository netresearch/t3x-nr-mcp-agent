<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

interface ChatProcessorInterface
{
    /**
     * Dispatch conversation processing.
     * The conversation must already be saved with status 'processing'.
     */
    public function dispatch(int $conversationUid): void;
}
