<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Domain\Repository;

use Netresearch\NrLlm\Service\Skill\SkillComposerFactory;
use Netresearch\NrMcpAgent\Domain\Repository\SkillTrustRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The provenance of nr-llm skill records, read from nr-llm's own table.
 */
final class SkillTrustRepositoryTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    private function repository(): SkillTrustRepository
    {
        return new SkillTrustRepository($this->get(ConnectionPool::class), $this->get(SkillComposerFactory::class));
    }

    #[Test]
    public function eachSkillRanksByItsTrustLevelAndUnknownOnesRankLowest(): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_skill');
        foreach ([1 => 'untrusted', 2 => 'community', 3 => 'verified', 4 => 'first_party', 5 => 'made-up'] as $uid => $level) {
            $connection->insert('tx_nrllm_skill', ['uid' => $uid, 'pid' => 0, 'identifier' => '1:s' . $uid, 'trust_level' => $level]);
        }

        $ranks = $this->repository()->rankByUid([4, 3, 2, 1, 5, 99]);

        self::assertSame([4 => 3, 3 => 2, 2 => 1, 1 => 0, 5 => 0, 99 => 0], $ranks);
    }

    /** Without `skills.minTrustLevel`, nr-llm lets every skill instruct; so does this. */
    #[Test]
    public function withoutAMinimumEverySkillMayInstruct(): void
    {
        self::assertSame(0, $this->repository()->minimumRank());
    }

    #[Test]
    public function noUidsNoQuery(): void
    {
        self::assertSame([], $this->repository()->rankByUid([]));
    }
}
