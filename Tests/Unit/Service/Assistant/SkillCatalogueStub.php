<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\Assistant;

use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use Netresearch\NrMcpAgent\Service\SkillCatalogueInterface;

/**
 * The chat's skill catalogue with fixed entries, counting how often the
 * catalogue is read. Only what the dashboard's skill resolution asks is
 * answered; find() and augmentationFor() say "no skill".
 */
final class SkillCatalogueStub implements SkillCatalogueInterface
{
    public int $reads = 0;

    /**
     * @param list<mixed> $entries as catalogue() returns them; malformed ones are allowed on purpose
     */
    public function __construct(
        private readonly array $entries,
        private readonly bool $available = true,
        private readonly bool $secondApprover = false,
    ) {}

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function catalogue(): array
    {
        ++$this->reads;

        return $this->entries;
    }

    public function find(string $identifier): ?array
    {
        return null;
    }

    public function augmentationFor(string $identifier): ?RunAugmentation
    {
        return null;
    }

    public function requiresSecondApprover(): bool
    {
        return $this->secondApprover;
    }
}
