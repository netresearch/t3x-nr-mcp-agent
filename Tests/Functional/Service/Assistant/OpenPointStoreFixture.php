<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Service\Assistant;

use Netresearch\NrMcpAgent\Domain\Repository\OpenPointRepository;
use Netresearch\NrMcpAgent\Service\OpenPoint\OpenPointTarget;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Rows in the chat's open-point store and nr-llm's skill table, for tests that
 * read them through the real OpenPointVisibility (ADR-022).
 */
trait OpenPointStoreFixture
{
    private function storeSkill(int $uid, string $identifier): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_skill')->insert('tx_nrllm_skill', [
            'uid' => $uid, 'pid' => 0, 'source' => 3, 'identifier' => $identifier, 'name' => $identifier, 'enabled' => 1,
        ]);
    }

    /**
     * An open point on a field of a record, recorded for $page under $skill;
     * $crdate orders the points (newest first).
     */
    private function storePoint(int $page, int $skill, string $table, int $uid, string $field, int $crdate): void
    {
        $this->get(OpenPointRepository::class)->record($skill, 'pages', $page, new OpenPointTarget($table, $uid, [$field]), 1, 1);
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrmcpagent_open_point')->update(
            'tx_nrmcpagent_open_point',
            ['crdate' => $crdate],
            ['subject_uid' => $page, 'target_table' => $table, 'target_uid' => $uid, 'target_field' => $field, 'skill_uid' => $skill],
        );
    }
}
