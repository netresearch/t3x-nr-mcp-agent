<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Utility;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Exception\Exception as NrMcpAgentException;
use Netresearch\NrMcpAgent\Utility\BackendUserInitializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A turn runs in the workspace the editor handed it over in (nr-llm ADR-260),
 * not in the one their user record names when the worker gets to it.
 */
#[CoversClass(BackendUserInitializer::class)]
#[CoversClass(ConversationRepository::class)]
final class BackendUserInitializerWorkspaceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist', 'workspaces'];

    protected array $testExtensionsToLoad = ['netresearch/nr-vault', 'netresearch/nr-llm', 'netresearch/nr-mcp-agent'];

    private ConnectionPool $pool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $this->pool = $pool;
        $workspaces = $this->pool->getConnectionForTable('sys_workspace');
        $workspaces->insert('sys_workspace', ['uid' => 1, 'pid' => 0, 'title' => 'Entwurf', 'members' => 'be_users_2']);
        $workspaces->insert('sys_workspace', ['uid' => 2, 'pid' => 0, 'title' => 'Other', 'members' => '']);
    }

    protected function tearDown(): void
    {
        BackendUserInitializer::release();
        parent::tearDown();
    }

    #[Test]
    public function theTurnRunsInTheWorkspaceItWasHandedOverIn(): void
    {
        // The editor has switched back to live since starting the turn.
        $this->pool->getConnectionForTable('be_users')->update('be_users', ['workspace_id' => 0], ['uid' => 1]);

        BackendUserInitializer::initialize(1, $this->pool, 1);

        self::assertSame(1, $this->user()->workspace);
        self::assertSame(1, GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('workspace', 'id'));
        self::assertSame(0, $this->storedWorkspace(1), "the editor's own workspace was changed");

        BackendUserInitializer::release();
        self::assertNull($GLOBALS['BE_USER']);
        self::assertSame(0, GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('workspace', 'id'));
    }

    #[Test]
    public function aTurnHandedOverBeforeTheWorkspaceWasKeptRunsWhereTheRecordSays(): void
    {
        $this->pool->getConnectionForTable('be_users')->update('be_users', ['workspace_id' => 2], ['uid' => 1]);

        BackendUserInitializer::initialize(1, $this->pool);

        self::assertSame(2, $this->user()->workspace);
        self::assertSame(2, GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('workspace', 'id'));
    }

    #[Test]
    public function aUserWhoMayNoLongerWorkInTheWorkspaceGetsNoTurn(): void
    {
        $this->pool->getConnectionForTable('be_users')->update('be_users', ['workspace_id' => 1], ['uid' => 2]);

        try {
            BackendUserInitializer::initialize(2, $this->pool, 2);
            self::fail('the turn ran in a workspace the editor may not work in');
        } catch (NrMcpAgentException $exception) {
            self::assertStringContainsString('may no longer work in workspace 2', $exception->getMessage());
        }

        self::assertSame(1, $this->storedWorkspace(2), "the editor's own workspace was changed");
    }

    #[Test]
    public function theOwnersRequestRecordsItsWorkspaceWithTheHandOver(): void
    {
        $repository = $this->get(ConversationRepository::class);
        self::assertInstanceOf(ConversationRepository::class, $repository);

        $owner = $this->setUpBackendUser(1);
        self::assertTrue($owner->setTemporaryWorkspace(2));

        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setStatus(ConversationStatus::Idle);

        $uid = $repository->add($conversation);
        self::assertSame(-1, $this->conversationWorkspace($uid), 'no hand-over yet');

        $conversation = $repository->findOneByUidAndBeUser($uid, 1);
        self::assertInstanceOf(Conversation::class, $conversation);
        // Only the write that hands the turn over records it.
        $repository->update($conversation);
        self::assertSame(-1, $this->conversationWorkspace($uid), 'recorded without a hand-over');

        $conversation->setStatus(ConversationStatus::Processing);
        $repository->update($conversation);
        self::assertSame(2, $this->conversationWorkspace($uid));

        // Another user's request — an approver of the turn, working in
        // workspace 1 — does not move it.
        $other = $this->setUpBackendUser(2);
        self::assertSame(1, $other->workspace, 'positive control: the approver works elsewhere');
        $repository->update($conversation);
        self::assertSame(2, $this->conversationWorkspace($uid));

        // The owner's decision on the run's card, taken from another
        // workspace, carries the run on where it was started.
        $owner = $this->setUpBackendUser(1);
        self::assertTrue($owner->setTemporaryWorkspace(0));
        $conversation->setApprovalRunUuid('run-1');
        $conversation->recordApprovalDecision(true, 'digest');
        $repository->update($conversation);
        self::assertSame(2, $this->conversationWorkspace($uid), 'a decision moved the turn');
    }

    private function user(): BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        self::assertInstanceOf(BackendUserAuthentication::class, $user);

        return $user;
    }

    private function storedWorkspace(int $uid): int
    {
        return (int) $this->pool->getConnectionForTable('be_users')->select(['workspace_id'], 'be_users', ['uid' => $uid])->fetchOne();
    }

    private function conversationWorkspace(int $uid): int
    {
        return (int) $this->pool->getConnectionForTable('tx_nrmcpagent_conversation')
            ->select(['workspace'], 'tx_nrmcpagent_conversation', ['uid' => $uid])->fetchOne();
    }
}
