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
use Netresearch\NrMcpAgent\Service\OpenPoint\OpenPointVisibility;

/**
 * List the open points of a page (nr-llm ADR-214, item 9; ADR-022): the
 * proposals of guided processes the user skipped there, which stay open until
 * a change to the same record and field is applied.
 *
 * A read: it names records and fields, never a value or a proposal's text.
 * What the acting user may see is decided by {@see OpenPointVisibility}: a
 * page the user may show, points on tables, records and languages the user
 * may read.
 */
final class ListOpenPointsTool extends GuidedTool
{
    public const NAME = 'chat_list_open_points';

    public function __construct(
        private readonly OpenPointVisibility $openPoints,
    ) {}

    public function getSpec(): ToolSpec
    {
        return new ToolSpec(
            self::NAME,
            'List the open points of a page: changes a guided process proposed there and the user skipped, which'
            . ' stay open until a change to the same record and field is applied. Each names the record and the'
            . ' field, and the uid of the process skill that recorded it. It changes nothing in TYPO3.',
            [
                'type' => 'object',
                'properties' => [
                    'pageUid' => ['type' => 'integer', 'description' => 'uid of the page the process is about.'],
                    'skillUid' => ['type' => 'integer', 'description' => 'Only the points of this process skill (optional).'],
                ],
                'required' => ['pageUid'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $context->actingBackendUser();
        if ($user === null) {
            return ToolResult::error(self::NOT_PERMITTED);
        }

        $pageUid = self::int($arguments, 'pageUid');
        $points = $this->openPoints->forPage($user, $pageUid, max(0, self::int($arguments, 'skillUid')));
        if ($points === null) {
            // One answer for "does not exist" and "may not see".
            return ToolResult::error('Error: no page with this uid that you may see.');
        }

        $lines = [];
        foreach ($points as $point) {
            $lines[] = sprintf(
                '- %s %d%s (skill %d, skipped %s)',
                $point['targetTable'],
                $point['targetUid'],
                $point['field'] !== '' ? ', field ' . $point['field'] : ', the record as a whole',
                $point['skillUid'],
                gmdate('Y-m-d', $point['crdate']),
            );
        }

        return ToolResult::text($lines === []
            ? sprintf('Page %d has no open points.', $pageUid)
            : sprintf("Open points of page %d:\n%s", $pageUid, implode("\n", $lines)));
    }
}
