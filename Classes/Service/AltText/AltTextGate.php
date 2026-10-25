<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

use Netresearch\NrMcpAgent\Service\SkillCatalogueInterface;
use Netresearch\NrMcpAgent\Service\ToolAvailabilityInterface;
use Netresearch\NrMcpAgent\Tool\SetDecorativeImageTool;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Whether the chat may work on one image of the list of missing alt texts
 * for the current user (ADR-026), asked when a conversation starts about it
 * and when the chat moves to another one. Everything is read from the
 * reference uid with the user's rights; nothing the browser says about the
 * image is taken.
 *
 * The order of the refusals is the order of what the user can do about
 * them: the workspace and the chat's set-up first, which hold for every
 * image, then the image itself.
 */
class AltTextGate
{
    /** The tools a conversation about a missing alt text writes with. */
    public const TOOLS = [AltTextFlowPrompt::ALT_TEXT_TOOL, SetDecorativeImageTool::NAME];

    /** The backend group permission to approve own changes in the chat (ADR-021). */
    private const PERMISSION_APPROVE_OWN = 'tx_nrmcpagent:approve_own_changes';

    public function __construct(
        private readonly MissingAltTextScanner $scanner,
        private readonly ReferenceWriteAccess $access,
        private readonly ToolAvailabilityInterface $tools,
        private readonly ?SkillCatalogueInterface $skills = null,
    ) {}

    /**
     * The HTTP status and the label key of a refusal code, for the endpoints
     * that answer with it.
     *
     * @return array{0: int, 1: string}
     */
    public static function answer(string $code): array
    {
        return match ($code) {
            'workspace'      => [409, 'error.altTextWorkspace'],
            'tools'          => [409, 'error.altTextToolsUnavailable'],
            'approval'       => [409, 'error.altTextApprovalNotAllowed'],
            'secondApprover' => [409, 'error.altTextSecondApprover'],
            'done'           => [409, 'error.altTextHitDone'],
            default          => [403, 'error.altTextNotPermitted'],
        };
    }

    /**
     * Why the chat cannot work on any image for this user now, as a code
     * (`workspace`, `tools`, `approval`, `secondApprover`), or null.
     */
    public function refusal(BackendUserAuthentication $user): ?string
    {
        if ($user->workspace !== 0) {
            return 'workspace';
        }

        if ($this->tools->unavailableTools(self::TOOLS) !== []) {
            return 'tools';
        }

        // The proposal is saved by approving its card: a user who may not
        // approve their own changes in the chat, or a chat whose
        // configuration requires a second approver, could only look at it.
        if (!$user->isAdmin() && !$user->check('modules', 'nrllm_aitasks') && !$user->check('custom_options', self::PERMISSION_APPROVE_OWN)) {
            return 'approval';
        }

        return ($this->skills?->requiresSecondApprover() ?? false) ? 'secondApprover' : null;
    }

    /**
     * The open hit of the reference as the list shows it to the user, or why
     * not: one of refusal()'s codes, `done` for a hit the user may edit that
     * is no longer open, `notPermitted` for a reference that is not there or
     * not theirs.
     */
    public function hit(BackendUserAuthentication $user, int $referenceUid): MissingAltText|string
    {
        $refusal = $this->refusal($user);
        if ($refusal !== null) {
            return $refusal;
        }

        // The list's own filter, and the question nr-llm's writer asks before
        // its card: both must let the image through, or the chat would offer
        // a proposal the writer then refuses.
        if (is_string($this->access->resolve($user, $referenceUid, 'alternative'))) {
            return 'notPermitted';
        }

        return $this->scanner->scan($user, $referenceUid)->find($referenceUid) ?? 'done';
    }

    /**
     * Whether "Als dekorativ markieren" can work on the image for the user:
     * the rights for the mark, with its exclude-field grant, asked as the
     * tool `set_file_reference_decorative` asks them before its card.
     */
    public function mayMarkDecorative(BackendUserAuthentication $user, int $referenceUid): bool
    {
        return !is_string($this->access->resolve($user, $referenceUid, DecorativeImageWriter::COLUMN));
    }
}
