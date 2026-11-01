<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Domain\Repository;

use Netresearch\NrMcpAgent\Domain\Repository\ChatWorkerRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ChatWorkerRepositoryTest extends FunctionalTestCase
{
    /** @var non-empty-string[] nr_mcp_agent depends on filelist. */
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    private ChatWorkerRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(ChatWorkerRepository::class);
    }

    private function table(): Connection
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrmcpagent_chat_worker');
    }

    #[Test]
    public function aReportCountsAndARepeatIsOneWorker(): void
    {
        $this->subject->reportIdle('a');
        $this->subject->reportIdle('a');
        $this->subject->reportIdle('b');

        self::assertSame(2, $this->subject->countIdle());
    }

    #[Test]
    public function aWorkerWithATurnNoLongerCounts(): void
    {
        $this->subject->reportIdle('a');
        $this->subject->withdraw('a');

        self::assertSame(0, $this->subject->countIdle());
    }

    #[Test]
    public function aWorkerThatStoppedReportingNoLongerCounts(): void
    {
        $this->table()->insert('tx_nrmcpagent_chat_worker', ['worker_id' => 'dead', 'idle_until' => time() - 1]);

        self::assertSame(0, $this->subject->countIdle());
    }

    #[Test]
    public function aNewWorkerClearsWhatACrashLeft(): void
    {
        $this->table()->insert('tx_nrmcpagent_chat_worker', ['worker_id' => 'dead', 'idle_until' => time() - 1]);

        $this->subject->reportIdle('new');

        self::assertSame(1, $this->table()->count('*', 'tx_nrmcpagent_chat_worker', []));
    }
}
