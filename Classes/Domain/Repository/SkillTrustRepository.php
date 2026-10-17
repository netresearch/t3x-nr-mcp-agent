<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Repository;

use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Service\Skill\SkillComposerFactory;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The provenance of nr-llm skill records: their publisher trust
 * (`trust_level`, nr-llm ADR-061) as SkillTrustLevel::rank(), higher meaning
 * more trusted.
 *
 * A skill whose row cannot be read, or whose value nr-llm does not know,
 * ranks lowest: SkillTrustLevel fails closed, and so does this.
 *
 * minimumRank() is the floor nr-llm applies before a skill may instruct a
 * run (`skills.minTrustLevel`, read through nr-llm's own SkillComposerFactory
 * so both sides apply the same value): a skill below it is dropped from the
 * prompt, so it cannot guide a tour.
 */
readonly class SkillTrustRepository
{
    private const TABLE = 'tx_nrllm_skill';

    public function __construct(
        private ConnectionPool $connectionPool,
        private SkillComposerFactory $skillComposerFactory,
    ) {}

    /** The lowest rank a skill needs to instruct a run on this installation. */
    public function minimumRank(): int
    {
        return $this->skillComposerFactory->minTrustLevel()->rank();
    }

    /**
     * @param list<int> $uids skill record uids
     * @return array<int, int> rank per uid, for every uid asked for
     */
    public function rankByUid(array $uids): array
    {
        $ranks = array_fill_keys($uids, SkillTrustLevel::UNTRUSTED->rank());
        $uids = array_values(array_filter($uids, static fn(int $uid): bool => $uid > 0));
        if ($uids === []) {
            return $ranks;
        }

        try {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $rows = $qb->select('uid', 'trust_level')
                ->from(self::TABLE)
                ->where($qb->expr()->in('uid', $qb->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (Throwable) {
            return $ranks;
        }

        foreach ($rows as $row) {
            $uid = is_numeric($row['uid'] ?? null) ? (int) $row['uid'] : 0;
            $level = is_string($row['trust_level'] ?? null) ? $row['trust_level'] : '';
            if (array_key_exists($uid, $ranks)) {
                $ranks[$uid] = SkillTrustLevel::fromStringOrUntrusted($level)->rank();
            }
        }

        return $ranks;
    }
}
