<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Updates;

use Netresearch\NrMcpAgent\Updates\ClearStoredApprovalNoticeUpdateWizard;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The wizard is built by hand, not fetched from the upgrade-wizard registry:
 * on v13 the registry needs the install extension loaded and on v14 it lives
 * in core, and what is under test is what the wizard does to the rows, not
 * how TYPO3 finds it.
 */
class ClearStoredApprovalNoticeUpdateWizardTest extends FunctionalTestCase
{
    private const TABLE = 'tx_nrmcpagent_conversation';

    /** What the pause stored from 0.13.0 to 0.13.1 (ChatService::describeAwaitingApproval() at d3527ab). */
    private const SENTENCE_SINCE_0_13 = 'This step writes data and needs an approval before it runs. Run: run-uuid-6';

    /** What the pause stored from 0.10.0 to 0.12.x (the same method at 1fea239). */
    private const SENTENCE_BEFORE_0_13 = 'This step writes data, so it is waiting for your approval.'
        . ' Grant it under Web > AI Tasks > Approvals, then the run continues on its own.';

    /** A reason performRecordedDecision() writes back when the runtime refuses a decision; the chat must keep showing it. */
    private const REFUSAL_REASON = 'The run could not be resumed: the turn has moved on since the card was shown.';

    // nr_mcp_agent depends on filelist (the FAL picker's element browser).
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    private ClearStoredApprovalNoticeUpdateWizard $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new ClearStoredApprovalNoticeUpdateWizard($this->get(ConnectionPool::class));
    }

    #[Test]
    public function nothingIsNecessaryOnAnEmptyTable(): void
    {
        self::assertFalse($this->subject->updateNecessary());
    }

    #[Test]
    public function clearsTheSentenceStoredSince013AndLeavesTheRestOfTheRowAlone(): void
    {
        $uid = $this->insertConversation('awaiting_approval', self::SENTENCE_SINCE_0_13);

        self::assertTrue($this->subject->updateNecessary());
        self::assertTrue($this->subject->executeUpdate());

        $row = $this->fetchRow($uid);
        self::assertSame('', $row['error_message']);
        self::assertSame('awaiting_approval', $row['status']);
        self::assertSame('run-uuid-6', $row['approval_run_uuid']);
        self::assertFalse($this->subject->updateNecessary());
    }

    #[Test]
    public function clearsTheSentenceStoredBefore013(): void
    {
        $uid = $this->insertConversation('awaiting_approval', self::SENTENCE_BEFORE_0_13);

        self::assertTrue($this->subject->updateNecessary());
        self::assertTrue($this->subject->executeUpdate());

        self::assertSame('', $this->fetchRow($uid)['error_message']);
        self::assertFalse($this->subject->updateNecessary());
    }

    #[Test]
    public function clearsADeletedConversationToo(): void
    {
        // The necessity check must count exactly what the update touches, and
        // the update carries no deleted restriction; a deleted row is invisible
        // either way, so clearing it is harmless.
        $uid = $this->insertConversation('awaiting_approval', self::SENTENCE_SINCE_0_13, deleted: 1);

        self::assertTrue($this->subject->updateNecessary());
        self::assertTrue($this->subject->executeUpdate());

        self::assertSame('', $this->fetchRow($uid)['error_message']);
        self::assertFalse($this->subject->updateNecessary());
    }

    #[Test]
    public function keepsAReasonTheRuntimeWroteBackWithARefusedDecision(): void
    {
        $uid = $this->insertConversation('awaiting_approval', self::REFUSAL_REASON);

        self::assertFalse($this->subject->updateNecessary());
        self::assertTrue($this->subject->executeUpdate());

        self::assertSame(self::REFUSAL_REASON, $this->fetchRow($uid)['error_message']);
    }

    #[Test]
    public function keepsTheSentenceOnAConversationThatIsNotParked(): void
    {
        $uid = $this->insertConversation('failed', self::SENTENCE_SINCE_0_13);

        self::assertFalse($this->subject->updateNecessary());
        self::assertTrue($this->subject->executeUpdate());

        self::assertSame(self::SENTENCE_SINCE_0_13, $this->fetchRow($uid)['error_message']);
    }

    private function insertConversation(string $status, string $errorMessage, int $deleted = 0): int
    {
        $conn = $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE);
        $conn->insert(self::TABLE, [
            'pid' => 0,
            'be_user' => 1,
            'title' => 'Parked',
            'messages' => '[{"role":"user","content":"hi"}]',
            'message_count' => 1,
            'status' => $status,
            'error_message' => $errorMessage,
            'approval_run_uuid' => 'run-uuid-6',
            'deleted' => $deleted,
            'tstamp' => 1710000000,
            'crdate' => 1710000000,
        ]);

        return (int) $conn->lastInsertId();
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRow(int $uid): array
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('status', 'error_message', 'approval_run_uuid')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);

        return $row;
    }
}
