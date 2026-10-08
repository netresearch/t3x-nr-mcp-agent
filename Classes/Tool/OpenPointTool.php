<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tool;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrMcpAgent\Domain\Repository\OpenPointRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The scope the open-point tools share (ADR-020): a page the acting user may
 * show, a language of it they may edit, and the skill the process runs. The
 * model supplies all three, so all three are checked on every call — reading
 * included.
 */
abstract class OpenPointTool extends GuidedTool
{
    public function __construct(
        protected readonly OpenPointRepository $openPoints,
    ) {}

    /**
     * The scope properties of every open-point tool's parameters.
     *
     * @return array<string, array<string, string>>
     */
    protected static function scopeProperties(): array
    {
        return [
            'pageUid' => ['type' => 'integer', 'description' => 'uid of the page the process works on.'],
            'languageUid' => ['type' => 'integer', 'description' => 'sys_language_uid of the page version, 0 for the default language.'],
            'skill' => ['type' => 'string', 'description' => 'Identifier of the skill that runs the process.'],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{pageUid: int, languageUid: int, skill: string}|ToolResult the scope, or the refusal
     */
    protected function scope(array $arguments, ToolExecutionContext $context): array|ToolResult
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return ToolResult::error(self::NOT_PERMITTED);
        }

        $scope = [
            'pageUid' => self::int($arguments, 'pageUid'),
            'languageUid' => self::int($arguments, 'languageUid'),
            'skill' => self::text($arguments, 'skill', 100),
        ];

        if ($scope['skill'] === '' || $scope['languageUid'] < 0
            || !$user->checkLanguageAccess($scope['languageUid'])
            || !$this->mayShowPage($user, $scope['pageUid'])
        ) {
            return ToolResult::error('Error: no page with this uid and language that you may see, or no skill given.');
        }

        return $scope;
    }
}
