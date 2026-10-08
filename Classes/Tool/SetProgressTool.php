<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tool;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrMcpAgent\Domain\Repository\RunStateRepository;

/**
 * Where a guided process stands, for the chat header: a label and "point 2
 * of 5" (ADR-020). Setting the same progress twice changes nothing.
 */
final class SetProgressTool extends GuidedTool
{
    public const NAME = 'chat_set_progress';

    public function __construct(
        private readonly RunStateRepository $runState,
    ) {}

    public function getSpec(): ToolSpec
    {
        return new ToolSpec(
            self::NAME,
            'Show the user where a guided process stands, in the chat header: a short label (for example the page'
            . ' and language) and the current point of the total. Call it whenever the current point changes.'
            . ' It changes nothing in TYPO3.',
            [
                'type' => 'object',
                'properties' => [
                    'label' => ['type' => 'string', 'description' => 'Short label, at most 120 characters, e.g. "Über uns · Deutsch".'],
                    'current' => ['type' => 'integer', 'description' => 'The current point, 0 before the first.'],
                    'total' => ['type' => 'integer', 'description' => 'The number of points, at least 1.'],
                ],
                'required' => ['label', 'current', 'total'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $context->actingBackendUser();
        if ($user === null || $context->run === null) {
            return ToolResult::error(self::NOT_PERMITTED);
        }

        $label = self::text($arguments, 'label', 120);
        $current = self::int($arguments, 'current');
        $total = self::int($arguments, 'total');
        if ($label === '' || $total < 1 || $current < 0 || $current > $total) {
            return ToolResult::error('Error: invalid progress — label must not be empty, total at least 1, and current between 0 and total.');
        }

        $this->runState->store($context->run->uuid, $context->actor->backendUserUid, 'progress', [
            'label' => $label,
            'current' => $current,
            'total' => $total,
        ]);

        return ToolResult::text(sprintf('Progress shown: %s, point %d of %d.', $label, $current, $total));
    }
}
