<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\Fixtures;

use RuntimeException;

/**
 * Shaped like nr-llm's GuardrailViolationException with its storable form
 * (ADR-288): the message quotes the refused text, the storable form does not.
 */
final class StorableRefusal extends RuntimeException
{
    public function __construct(string $message, private readonly string $storable)
    {
        parent::__construct($message);
    }

    public function storableMessage(): string
    {
        return $this->storable;
    }
}
