<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tool;

use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrMcpAgent\Domain\Repository\RunStateRepository;
use Netresearch\NrMcpAgent\Service\PageProperty;
use Netresearch\NrMcpAgent\Service\VisibleContentElement;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Point the editor at what a guided point is about (ADR-020): a content
 * element, which the page module beside the chat highlights, or a property of
 * the conversation's page (NEXT-221), which the chip above the input names.
 *
 * The model names a record or a column, never markup or a label. A content
 * element is one tt_content uid, which must exist and be readable by the
 * acting user; whether it is on the conversation's page is decided when the
 * chat takes the state over. A page property is a column of `pages` the user
 * may edit; the tool does not know the conversation, so the page is the
 * conversation's own, filled in when the chat takes the state over.
 */
final class HighlightElementTool extends GuidedTool
{
    public const NAME = 'chat_highlight_element';

    private const TABLE = 'tt_content';

    private const PAGE_FIELD_REFUSED = 'Error: no page property with this name that you may edit.';

    public function __construct(
        private readonly RunStateRepository $runState,
    ) {}

    public function getSpec(): ToolSpec
    {
        return new ToolSpec(
            self::NAME,
            'Show the user what the current point of a guided process is about, on the page the conversation is about.'
            . ' Give exactly one of the two parameters: contentUid when the point is about a content element (the page'
            . ' module beside the chat highlights it), pageField when the point is about a property of the page itself,'
            . ' such as its meta description, title or SEO title (the chat names that property). It changes nothing in TYPO3.'
            . ' When you propose a change to one content element or one page property, the chat shows that target by itself;'
            . ' call this tool only for a point without such a proposal, for example beside a question or before a new element.',
            [
                'type'       => 'object',
                'properties' => [
                    'contentUid' => [
                        'type'        => 'integer',
                        'description' => 'uid of the tt_content record on the page, when the point is about a content element. Leave out with pageField.',
                    ],
                    'pageField' => [
                        'type'        => 'string',
                        'description' => 'Column name in the pages table, when the point is about a page property rather than a content element:'
                            . ' for example "description" (meta description), "title", "seo_title", "og_title". Leave out with contentUid.',
                    ],
                ],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication || !$context->run instanceof AgentRunReference) {
            return ToolResult::error(self::NOT_PERMITTED);
        }

        $hasContent = array_key_exists('contentUid', $arguments) && $arguments['contentUid'] !== null;
        $hasField   = array_key_exists('pageField', $arguments) && $arguments['pageField'] !== null;
        if ($hasContent === $hasField) {
            return ToolResult::error('Error: give exactly one of contentUid and pageField.');
        }

        return $hasField
            ? $this->highlightPageField($arguments, $context->run, $context->actor->backendUserUid, $user)
            : $this->highlightContent($arguments, $context->run, $context->actor->backendUserUid, $user);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function highlightContent(array $arguments, AgentRunReference $run, int $owner, BackendUserAuthentication $user): ToolResult
    {
        $element = VisibleContentElement::resolve($user, self::int($arguments, 'contentUid'));
        if ($element === null) {
            // One answer for "does not exist" and "may not see": knowing a
            // uid is not enough to learn whether it exists.
            return ToolResult::error('Error: no content element with this uid that you may see.');
        }

        $this->runState->store($run->uuid, $owner, 'highlight', [
            'table' => self::TABLE,
            'uid'   => $element['uid'],
            'pid'   => $element['pid'],
        ]);

        return ToolResult::text(sprintf('Content element %d on page %d is highlighted for the user.', $element['uid'], $element['pid']));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function highlightPageField(array $arguments, AgentRunReference $run, int $owner, BackendUserAuthentication $user): ToolResult
    {
        $field = $arguments['pageField'];
        if (!is_string($field) || !PageProperty::isAllowedField($field, $user)) {
            // One answer for "no such column" and "excluded for you".
            return ToolResult::error(self::PAGE_FIELD_REFUSED);
        }

        $this->runState->store($run->uuid, $owner, 'highlight', [
            'table' => 'pages',
            'field' => $field,
        ]);

        return ToolResult::text(sprintf('The page property "%s" of the conversation\'s page is named for the user.', $field));
    }
}
