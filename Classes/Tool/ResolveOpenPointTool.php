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

/**
 * Close an open point once it is done (ADR-020). Resolving a resolved point
 * again changes nothing.
 */
final class ResolveOpenPointTool extends OpenPointTool
{
    public const NAME = 'chat_resolve_open_point';

    public function getSpec(): ToolSpec
    {
        return new ToolSpec(
            self::NAME,
            'Mark an open point of a guided process as done, by its key. It changes nothing in TYPO3.',
            [
                'type' => 'object',
                'properties' => [
                    ...self::scopeProperties(),
                    'key' => ['type' => 'string', 'description' => 'Key the point was recorded with.'],
                ],
                'required' => ['pageUid', 'languageUid', 'skill', 'key'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $scope = $this->scope($arguments, $context);
        if ($scope instanceof ToolResult) {
            return $scope;
        }

        $key = self::text($arguments, 'key', 100);
        if ($key === '' || !$this->openPoints->resolve($scope, $key, $context->run->uuid ?? '', $context->actor->backendUserUid)) {
            return ToolResult::error('Error: no open point with this key for this page, language and skill.');
        }

        return ToolResult::text(sprintf('Open point "%s" is resolved.', $key));
    }
}
