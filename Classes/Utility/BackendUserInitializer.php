<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Utility;

use Netresearch\NrMcpAgent\Exception\Exception as NrMcpAgentException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class BackendUserInitializer
{
    /**
     * Make the conversation's user the process's backend user for one turn.
     *
     * With a workspace, the user is set up in that workspace — the one the
     * editor worked in when they started the turn — and the request context
     * reads it too, so core's reads inside the turn see what the editor sees
     * (nr-llm ADR-260). Without one (a turn handed over before the workspace
     * was kept) the user works in the workspace their record names, as
     * before.
     *
     * @param int|null $workspace 0 for live, a draft workspace's uid, null when not known
     *
     * @throws NrMcpAgentException when the user is gone, or may no longer work in the workspace
     */
    public static function initialize(int $userUid, ConnectionPool $connectionPool, ?int $workspace = null): void
    {
        $userRecord  = self::userRecord($userUid, $connectionPool);
        $backendUser = self::setUp($userRecord, null);

        if ($workspace !== null && $backendUser->workspace !== $workspace) {
            // Asked first, so core never falls back to another workspace and
            // stores that on the user's record. A user who may no longer work
            // there gets no turn: writing anywhere else than where the turn
            // was started is the one outcome that must not happen.
            if ($backendUser->checkWorkspace($workspace) === false) {
                throw new NrMcpAgentException(sprintf('Backend user %d may no longer work in workspace %d', $userUid, $workspace));
            }

            $backendUser = self::setUp($userRecord, $workspace);
            if ($backendUser->workspace !== $workspace) {
                throw new NrMcpAgentException(sprintf('Backend user %d may no longer work in workspace %d', $userUid, $workspace));
            }
        }

        // Intentional: TYPO3 CLI commands require BE_USER in $GLOBALS for the
        // authentication context. There is no DI-injectable alternative for CLI.
        $GLOBALS['BE_USER'] = $backendUser;
        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect(max(0, $backendUser->workspace)));
    }

    /**
     * End the turn's user: no backend user, the live workspace — what a
     * console process starts with — so nothing leaks into the next turn.
     */
    public static function release(): void
    {
        $GLOBALS['BE_USER'] = null;
        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect(0));
    }

    /**
     * @return array<string, mixed>
     */
    private static function userRecord(int $userUid, ConnectionPool $connectionPool): array
    {
        $qb         = $connectionPool->getQueryBuilderForTable('be_users');
        $userRecord = $qb->select('*')
            ->from('be_users')
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($userUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('disable', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($userRecord === false) {
            throw new NrMcpAgentException(sprintf('Backend user %d not found', $userUid));
        }

        return $userRecord;
    }

    /**
     * @param array<string, mixed> $userRecord
     */
    private static function setUp(array $userRecord, ?int $workspace): BackendUserAuthentication
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        if ($workspace !== null) {
            // Where core's own workspace setup reads it, so the workspace's
            // page tree entry points narrow the user's mounts as in the
            // backend.
            $userRecord['workspace_id'] = $workspace;
        }

        $backendUser->user = $userRecord;
        $backendUser->fetchGroupData();

        return $backendUser;
    }
}
