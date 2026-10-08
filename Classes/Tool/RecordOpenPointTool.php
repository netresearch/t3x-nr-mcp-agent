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
 * Keep a point of a guided process open beyond the conversation — skipped,
 * or not finished (ADR-020). Recording the same key again updates the point.
 */
final class RecordOpenPointTool extends OpenPointTool
{
    public const NAME = 'chat_record_open_point';

    public function getSpec(): ToolSpec
    {
        return new ToolSpec(
            self::NAME,
            'Keep a point of a guided process open for later, for example when the user skips it. The point stays'
            . ' with the page, its language and the skill, beyond this conversation, and is listed by'
            . ' ' . ListOpenPointsTool::NAME . '. The key identifies the point; recording it again updates it.'
            . ' It changes nothing in TYPO3.',
            [
                'type' => 'object',
                'properties' => [
                    ...self::scopeProperties(),
                    'key' => ['type' => 'string', 'description' => 'Stable key of the point, e.g. "meta-description".'],
                    'title' => ['type' => 'string', 'description' => "What the point is about, in the user's language."],
                    'details' => ['type' => 'string', 'description' => 'The recommendation, for later.'],
                ],
                'required' => ['pageUid', 'languageUid', 'skill', 'key', 'title'],
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
        $title = self::text($arguments, 'title', 255);
        if ($key === '' || $title === '') {
            return ToolResult::error('Error: a point needs a key and a title.');
        }

        $this->openPoints->record($scope, $key, $title, self::text($arguments, 'details', 2000), $context->run->uuid ?? '', $context->actor->backendUserUid);

        return ToolResult::text(sprintf('Open point "%s" is kept for page %d.', $key, $scope['pageUid']));
    }
}
