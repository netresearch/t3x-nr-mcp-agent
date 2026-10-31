<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\ToolDataClassInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolInterface;

/**
 * What the chat's guided-state tools share (ADR-020): their group, data
 * class and effect, and the argument readers.
 *
 * - Group `nr_mcp_agent`, so an administrator can switch them off together.
 * - Data class EDITOR_CONTENT: they read and name editorial records (a
 *   page, a content element) and keep editorial notes about them. Without a
 *   declaration nr-llm treats an unknown group as SECRET_ADJACENT and
 *   withholds the tools from every cloud provider.
 * - Effect READ_ONLY, as nr-llm ADR-214 classifies progress and highlight:
 *   they change no TYPO3 record; their only writes are the chat's own
 *   bookkeeping, an upsert on the run's uuid.
 * - Enabled by default: a guided skill needs them. nr-llm offers every
 *   enabled tool to every run that does not narrow its tool list, so runs
 *   started outside the chat get them too; what such a run writes to the run
 *   state is never taken over and is removed by `ai-chat:cleanup` after a
 *   day.
 */
abstract class GuidedTool implements ToolInterface, ToolDataClassInterface, ToolEffectInterface
{
    public const GROUP = 'nr_mcp_agent';

    protected const NOT_PERMITTED = 'Error: not permitted — this tool runs only for a logged-in backend user.';

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        return false;
    }

    public function getGroup(): string
    {
        return self::GROUP;
    }

    public function getDataClass(): ToolDataClass
    {
        return ToolDataClass::EDITOR_CONTENT;
    }

    public function getEffect(): ToolEffect
    {
        return ToolEffect::READ_ONLY;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected static function int(array $arguments, string $name): int
    {
        $value = $arguments[$name] ?? null;

        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) ? (int) $value : -1;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected static function text(array $arguments, string $name, int $maxLength): string
    {
        $value = $arguments[$name] ?? null;

        return is_string($value) ? mb_substr(trim($value), 0, $maxLength) : '';
    }
}
