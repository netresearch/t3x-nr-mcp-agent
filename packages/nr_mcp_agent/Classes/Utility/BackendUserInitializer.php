<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Utility;

use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class BackendUserInitializer
{
    public static function initialize(int $userUid, ConnectionPool $connectionPool): void
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);

        $qb = $connectionPool->getQueryBuilderForTable('be_users');
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
            throw new RuntimeException(sprintf('Backend user %d not found', $userUid));
        }

        $backendUser->user = $userRecord;
        $backendUser->fetchGroupData();
        $GLOBALS['BE_USER'] = $backendUser;
    }
}
