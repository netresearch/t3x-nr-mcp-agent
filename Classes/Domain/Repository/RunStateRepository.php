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
 * What the guided-state tools reported for one nr-llm run (ADR-020): the
 * progress and the element to highlight, keyed by the run's uuid.
 *
 * A tool knows the run it belongs to (nr-llm's ToolExecutionContext), not the
 * conversation; the chat knows the conversation and learns the run when the
 * run returns. The run uuid is the only key both sides hold, so the state is
 * written here and the chat takes it over into the conversation.
 *
 * Every write is an upsert on the run uuid: a tool call nr-llm repeats lands
 * on the same row.
 */
readonly class RunStateRepository
{
    private const TABLE = 'tx_nrmcpagent_run_state';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param 'progress'|'highlight'  $field
     * @param array<string, bool|int|string> $value
     */
    public function store(string $runUuid, int $beUser, string $field, array $value): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $updated = $conn->update(self::TABLE, [$field => $json, 'tstamp' => time()], ['run_uuid' => $runUuid, 'be_user' => $beUser]);
        if ($updated > 0) {
            return;
        }

        try {
            $conn->insert(self::TABLE, ['run_uuid' => $runUuid, 'be_user' => $beUser, $field => $json, 'tstamp' => time(), 'crdate' => time()]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent first write of the same run: the row exists now.
            $conn->update(self::TABLE, [$field => $json, 'tstamp' => time()], ['run_uuid' => $runUuid, 'be_user' => $beUser]);
        }
    }

    /**
     * The run's state as the given user's, or null when there is none — a row
     * of another user is never returned.
     *
     * @return array{progress: array<string, mixed>|null, highlight: array<string, mixed>|null}|null
     */
    public function find(string $runUuid, int $beUser): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('progress', 'highlight')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)),
                $qb->expr()->eq('be_user', $qb->createNamedParameter($beUser, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return null;
        }

        return ['progress' => $this->decode($row['progress'] ?? null), 'highlight' => $this->decode($row['highlight'] ?? null)];
    }

    /**
     * Drop the run's state once the chat has taken it over; a later call of
     * the same run writes a new row.
     */
    public function delete(string $runUuid, int $beUser): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->delete(self::TABLE, ['run_uuid' => $runUuid, 'be_user' => $beUser]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(mixed $json): ?array
    {
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($decoded)) {
            return null;
        }

        $clean = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
