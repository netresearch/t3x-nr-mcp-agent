<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Points a guided process left open on a page (ADR-020): skipped or not yet
 * finished, offered again when the process is started on the page later.
 *
 * Scoped to page, language and skill, and keyed within that scope by a key
 * the process chooses, so recording the same point twice — a retry, or the
 * same finding in the next conversation — updates one row instead of adding
 * a second. Not owned by a user: anyone who may show the page sees them; the
 * user and run that last wrote a row are kept on it.
 */
readonly class OpenPointRepository
{
    private const TABLE = 'tx_nrmcpagent_open_point';

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array{pageUid: int, languageUid: int, skill: string} $scope
     */
    public function record(array $scope, string $key, string $title, string $details, string $runUuid, int $beUser): void
    {
        $this->upsert($scope, $key, [
            'title' => $title,
            'details' => $details,
            'status' => self::STATUS_OPEN,
            'run_uuid' => $runUuid,
            'be_user' => $beUser,
        ]);
    }

    /**
     * Mark a point resolved; false when the scope has no point with this key.
     *
     * @param array{pageUid: int, languageUid: int, skill: string} $scope
     */
    public function resolve(array $scope, string $key, string $runUuid, int $beUser): bool
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['status' => self::STATUS_RESOLVED, 'run_uuid' => $runUuid, 'be_user' => $beUser, 'tstamp' => time()],
            $this->identity($scope, $key),
        ) > 0 || $this->exists($scope, $key);
    }

    /**
     * @param array{pageUid: int, languageUid: int, skill: string} $scope
     *
     * @return list<array{key: string, title: string, details: string, status: string}>
     */
    public function findInScope(array $scope, bool $includeResolved = false): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->select('point_key', 'title', 'details', 'status')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('page_uid', $qb->createNamedParameter($scope['pageUid'], Connection::PARAM_INT)),
                $qb->expr()->eq('language_uid', $qb->createNamedParameter($scope['languageUid'], Connection::PARAM_INT)),
                $qb->expr()->eq('skill_identifier', $qb->createNamedParameter($scope['skill'])),
            )
            ->orderBy('crdate')
            ->addOrderBy('uid');
        if (!$includeResolved) {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_OPEN)));
        }

        $points = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $points[] = [
                'key' => $this->string($row['point_key'] ?? null),
                'title' => $this->string($row['title'] ?? null),
                'details' => $this->string($row['details'] ?? null),
                'status' => $this->string($row['status'] ?? null),
            ];
        }

        return $points;
    }

    /**
     * @param array{pageUid: int, languageUid: int, skill: string} $scope
     * @param array<string, int|string>                            $values
     */
    private function upsert(array $scope, string $key, array $values): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $values['tstamp'] = time();
        if ($conn->update(self::TABLE, $values, $this->identity($scope, $key)) > 0) {
            return;
        }

        try {
            $conn->insert(self::TABLE, [...$this->identity($scope, $key), ...$values, 'crdate' => time()]);
        } catch (UniqueConstraintViolationException) {
            // Recorded concurrently: the row exists now, and this write wins.
            $conn->update(self::TABLE, $values, $this->identity($scope, $key));
        }
    }

    /**
     * @param array{pageUid: int, languageUid: int, skill: string} $scope
     */
    private function exists(array $scope, string $key): bool
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE)->count('uid', self::TABLE, $this->identity($scope, $key)) > 0;
    }

    /**
     * @param array{pageUid: int, languageUid: int, skill: string} $scope
     *
     * @return array{page_uid: int, language_uid: int, skill_identifier: string, point_key: string}
     */
    private function identity(array $scope, string $key): array
    {
        return [
            'page_uid' => $scope['pageUid'],
            'language_uid' => $scope['languageUid'],
            'skill_identifier' => $scope['skill'],
            'point_key' => $key,
        ];
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
