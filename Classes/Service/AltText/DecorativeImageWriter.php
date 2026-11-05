<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

use Netresearch\NrLlm\Service\Tool\Builtin\ToolDataHandler;
use Netresearch\NrMcpAgent\Domain\Repository\ImageReferenceRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Sets or clears the "decorative image" mark of one image reference
 * (`sys_file_reference.tx_nrmcpagent_decorative`, ADR-026) through nr-llm's
 * ToolDataHandler, the DataHandler every nr-llm writer runs (a hook that fails
 * after the write no longer turns a landed write into a failed one, nr-llm
 * ADR-206), as the given backend user, and reads it back. Asked first, the
 * way nr-llm's alt text writer asks: ReferenceWriteAccess.
 *
 * In live it writes the reference. In a draft workspace it writes there, as
 * nr-llm's alt text writer does (nr-llm ADR-260, ADR-032): the DataHandler
 * stores a version of the reference, or changes a reference new in that
 * workspace, and live stays as it is until the workspace is published. Only
 * where the run recorded that workspace — a run that does not say where it
 * was started would write wherever the user's record points when it
 * resumes — and only where core keeps a draft of the table.
 *
 * The one write behind the tool `set_file_reference_decorative`, so the model's
 * call and anything else that marks an image go through the same checks and
 * leave the same record history.
 */
class DecorativeImageWriter
{
    public const COLUMN = 'tx_nrmcpagent_decorative';

    private const TABLE = 'sys_file_reference';

    public function __construct(
        private readonly ReferenceWriteAccess $access,
        private readonly ImageReferenceRepository $rows,
    ) {}

    /**
     * What the write would do, or the refusal. The workspace is not asked:
     * it describes the process that writes, not the reference.
     */
    public function plan(BackendUserAuthentication $user, int $referenceUid): WritableReference|string
    {
        return $this->access->resolve($user, $referenceUid, self::COLUMN);
    }

    /**
     * Why the mark cannot be written in the workspace the user works in, or
     * null: in live, and in the draft workspace the run recorded
     * (`$runWorkspace`, nr-llm's `AiActorContext::$workspaceId`) where core
     * keeps a draft of the table.
     */
    public function workspaceRefusal(BackendUserAuthentication $user, ?int $runWorkspace): ?string
    {
        if ($user->workspace === 0) {
            return null;
        }

        if ($user->workspace < 0 || $runWorkspace !== $user->workspace) {
            return 'Refused: this run does not record which workspace it was started in, so it writes in the live workspace '
                . 'only. Start the request again from the draft workspace.';
        }

        if (!$this->tableKeepsDrafts()) {
            return sprintf(
                'Refused: the acting backend user works in the draft workspace "%s", and TYPO3 keeps no draft of %s records '
                . 'here: the mark would go live at once, past the workspace. Nothing was written. Tell the user that this '
                . 'change can only be made in the live workspace.',
                self::workspaceTitle($user),
                self::TABLE,
            );
        }

        return null;
    }

    /**
     * The sentence the tool's answer ends with in a draft workspace, '' in
     * live: the mark is a draft there, so the model does not report it live.
     */
    public static function draftNote(BackendUserAuthentication $user): string
    {
        return $user->workspace > 0
            ? sprintf(' Stored as a draft in the workspace "%s"; live stays unchanged until that workspace is published.', self::workspaceTitle($user))
            : '';
    }

    /** The user's workspace as an editor knows it: its title, or `#uid`. */
    public static function workspaceTitle(BackendUserAuthentication $user): string
    {
        $record = is_array($user->workspaceRec) ? $user->workspaceRec : [];
        $title  = is_scalar($record['title'] ?? null) ? trim((string) $record['title']) : '';

        return $title !== '' ? $title : '#' . $user->workspace;
    }

    /**
     * Write the mark; null on success, else why nothing (or not the value)
     * was stored.
     *
     * @param int|null $runWorkspace the workspace the run recorded, null when it recorded none
     */
    public function write(BackendUserAuthentication $user, int $referenceUid, bool $decorative, ?int $runWorkspace): ?string
    {
        $refusal = $this->workspaceRefusal($user, $runWorkspace);
        if ($refusal !== null) {
            return $refusal;
        }

        $plan = $this->plan($user, $referenceUid);
        if (is_string($plan)) {
            return $plan;
        }

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([self::TABLE => [$referenceUid => [self::COLUMN => $decorative ? 1 : 0]]], [], $user);
        $dataHandler->process_datamap();
        if ($dataHandler->errorLog !== []) {
            return 'The update was refused by TYPO3: ' . implode(' / ', array_map(
                static fn (mixed $error): string => mb_substr(is_scalar($error) ? (string) $error : '', 0, 200),
                array_slice($dataHandler->errorLog, 0, 5),
            ));
        }

        // An empty error log is no proof the value landed: a hook can drop
        // it in silence. In a draft workspace the version is read, through
        // the live uid.
        if ($this->rows->decorativeMark($referenceUid, $user->workspace) !== $decorative) {
            return sprintf('The decorative mark did not take on file reference [%d]: the DataHandler dropped or changed the value.', $referenceUid);
        }

        return null;
    }

    /**
     * Whether core keeps a draft of a change to the table in a workspace:
     * `ctrl.versioningWS` of the loaded TCA, and no
     * `versioningWS_alwaysAllowLiveEdit`, which lets TYPO3 13.4 edit the live
     * row of a versioned table in a workspace too.
     */
    private function tableKeepsDrafts(): bool
    {
        $tca  = $GLOBALS['TCA'] ?? null;
        $ctrl = is_array($tca) && is_array($tca[self::TABLE] ?? null) ? ($tca[self::TABLE]['ctrl'] ?? null) : null;

        return is_array($ctrl)
            && (bool) ($ctrl['versioningWS'] ?? false)
            && !(bool) ($ctrl['versioningWS_alwaysAllowLiveEdit'] ?? false);
    }
}
