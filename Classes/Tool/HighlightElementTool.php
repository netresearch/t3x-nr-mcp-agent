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
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Point the editor at the content element a guided point is about: the page
 * module beside the chat highlights it (ADR-020).
 *
 * The model names a record, never markup: one tt_content uid, which must
 * exist and be readable by the acting user. Whether it is on the
 * conversation's page is decided when the chat takes the state over; the
 * page module then finds the element by TYPO3's own id for it.
 */
final class HighlightElementTool extends GuidedTool
{
    public const NAME = 'chat_highlight_element';

    private const TABLE = 'tt_content';

    public function __construct(
        private readonly RunStateRepository $runState,
    ) {}

    public function getSpec(): ToolSpec
    {
        return new ToolSpec(
            self::NAME,
            'Highlight a content element of the page the conversation is about in the page module, so the user sees'
            . ' which element the current point concerns. Takes the uid of a tt_content record on that page.'
            . ' It changes nothing in TYPO3.',
            [
                'type' => 'object',
                'properties' => [
                    'contentUid' => ['type' => 'integer', 'description' => 'uid of the tt_content record.'],
                ],
                'required' => ['contentUid'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $context->actingBackendUser();
        if ($user === null || $context->run === null) {
            return ToolResult::error(self::NOT_PERMITTED);
        }

        $uid = self::int($arguments, 'contentUid');
        $record = $uid > 0 ? BackendUtility::getRecord(self::TABLE, $uid, 'uid,pid,sys_language_uid') : null;
        $pid = is_array($record) && is_numeric($record['pid'] ?? null) ? (int) $record['pid'] : 0;
        $language = is_array($record) && is_numeric($record['sys_language_uid'] ?? null) ? (int) $record['sys_language_uid'] : 0;
        if ($pid <= 0
            || !$user->check('tables_select', self::TABLE)
            || !$user->checkLanguageAccess($language)
            || !$this->mayShowPage($user, $pid)
        ) {
            // One answer for "does not exist" and "may not see": knowing a
            // uid is not enough to learn whether it exists.
            return ToolResult::error('Error: no content element with this uid that you may see.');
        }

        $this->runState->store($context->run->uuid, $context->actor->backendUserUid, 'highlight', [
            'table' => self::TABLE,
            'uid' => $uid,
            'pid' => $pid,
        ]);

        return ToolResult::text(sprintf('Content element %d on page %d is highlighted for the user.', $uid, $pid));
    }
}
