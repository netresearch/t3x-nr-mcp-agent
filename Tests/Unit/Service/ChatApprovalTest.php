<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\Exception\StaleApprovalTurnException;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Deciding a pending tool call from the chat, in the two steps it now takes.
 *
 * The request records the decision and claims the conversation; the worker
 * carries it out. That split is the point — approve() drives the whole
 * continuation, which a gateway timeout would kill with the write already done.
 * So the assertions come in pairs: what the request writes down, and what the
 * worker then hands to the runtime.
 */
#[CoversClass(ChatService::class)]
final class ChatApprovalTest extends TestCase
{
    private ?ApprovalDecision $capturedDecision = null;

    private ?string $capturedRunUuid = null;

    private function parkedConversation(string $runUuid = 'run-uuid-1234'): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Set the meta description');
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid($runUuid);

        return $conversation;
    }

    private function createChatService(
        AgentRunResult|RuntimeException $approveAnswer = null,
        ?PendingApprovalReaderInterface $reader = null,
        bool $claimSucceeds = true,
        ?AgentRunRepositoryInterface $runRepository = null,
    ): ChatService {
        $approveAnswer ??= $this->completed();

        $agentRuntime = $this->createMock(AgentRuntimeInterface::class);
        $agentRuntime->method('approve')->willReturnCallback(
            function (mixed $actor, string $runUuid, ApprovalDecision $decision) use ($approveAnswer): AgentRunResult {
                $this->capturedRunUuid = $runUuid;
                $this->capturedDecision = $decision;
                if ($approveAnswer instanceof RuntimeException) {
                    throw $approveAnswer;
                }

                return $approveAnswer;
            },
        );

        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('updateIf')->willReturn($claimSucceeds);

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);

        return new ChatService(
            $repository,
            $config,
            $agentRuntime,
            $reader ?? $this->createMock(PendingApprovalReaderInterface::class),
            $runRepository ?? $this->createMock(AgentRunRepositoryInterface::class),
            $this->createMock(TaskRepository::class),
            $this->createMock(ProviderAdapterRegistryInterface::class),
            $this->createMock(ResourceFactory::class),
            $this->createMock(SiteFinder::class),
            new DocumentExtractorRegistry([]),
        );
    }

    private function completed(string $text = 'Done — the description is set.'): AgentRunResult
    {
        return new AgentRunResult(
            AgentRunOutcome::COMPLETED,
            'run-uuid-1234',
            [],
            new ToolLoopResult($text, [], 1, false, new UsageStatistics(10, 20, 30)),
        );
    }

    private function runWith(AgentRunStatus $status, int $beUser = 1): AgentRun
    {
        $run = (new ReflectionClass(AgentRun::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionClass($run);
        foreach (['uuid' => 'run-uuid-1234', 'beUser' => $beUser, 'status' => $status->value] as $name => $value) {
            $reflection->getProperty($name)->setValue($run, $value);
        }

        return $run;
    }

    // ---- what the request writes down -------------------------------------

    /**
     * The request must not run the continuation, only note the decision and
     * claim the row. The claim is what stops a follow-up message from racing
     * it: sendMessage does not treat AwaitingApproval as busy.
     */
    #[Test]
    public function recordingClaimsTheConversationAndReachesNoRuntime(): void
    {
        $conversation = $this->parkedConversation();

        self::assertTrue($this->createChatService()->recordDecision($conversation, true, 'digest-abc'));

        self::assertSame(ConversationStatus::Processing, $conversation->getStatus());
        self::assertSame('approve', $conversation->getApprovalDecision());
        self::assertSame('digest-abc', $conversation->getApprovalTurnDigest());
        self::assertSame('run-uuid-1234', $conversation->getApprovalRunUuid(), 'the run must survive the claim');
        self::assertNull($this->capturedDecision, 'the request must not decide anything itself');
    }

    #[Test]
    public function recordingADenialStoresTheOppositeDecision(): void
    {
        $conversation = $this->parkedConversation();

        $this->createChatService()->recordDecision($conversation, false, 'digest-abc');

        self::assertSame('deny', $conversation->getApprovalDecision());
    }

    /**
     * A click that arrives after the run moved on is not an error to report.
     */
    #[Test]
    public function recordingOnAConversationThatIsNotWaitingChangesNothing(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setStatus(ConversationStatus::Idle);

        self::assertFalse($this->createChatService()->recordDecision($conversation, true, 'digest-abc'));
        self::assertSame('', $conversation->getApprovalDecision());
    }

    #[Test]
    public function aLostClaimRecordsNothing(): void
    {
        $conversation = $this->parkedConversation();

        self::assertFalse(
            $this->createChatService(null, null, false)->recordDecision($conversation, true, 'digest-abc'),
        );
    }

    // ---- what the worker then does ----------------------------------------

    /**
     * The digest is handed over exactly as it was recorded. Recomputing it now
     * would defeat its purpose: the runtime verifies it against the state it
     * claims (ADR-132), so a decision made against a turn that has since been
     * replaced must be refused, not silently applied to the new one.
     */
    #[Test]
    public function theWorkerHandsTheRecordedDecisionToTheRuntime(): void
    {
        $conversation = $this->parkedConversation();
        $service = $this->createChatService();
        $service->recordDecision($conversation, true, 'digest-abc');

        $service->processConversation($conversation);

        self::assertSame('run-uuid-1234', $this->capturedRunUuid);
        self::assertNotNull($this->capturedDecision);
        self::assertTrue($this->capturedDecision->approved);
        self::assertSame('digest-abc', $this->capturedDecision->turnDigest);
    }

    #[Test]
    public function theWorkerCarriesADenialThrough(): void
    {
        $conversation = $this->parkedConversation();
        $service = $this->createChatService();
        $service->recordDecision($conversation, false, 'digest-abc');

        $service->processConversation($conversation);

        self::assertNotNull($this->capturedDecision);
        self::assertFalse($this->capturedDecision->approved);
    }

    /**
     * The whole reason for deciding here rather than in the module: the
     * continuation lands in this conversation.
     */
    #[Test]
    public function theAnswerArrivesInTheConversation(): void
    {
        $conversation = $this->parkedConversation();
        $service = $this->createChatService($this->completed('Done — the description is set.'));
        $service->recordDecision($conversation, true, 'digest-abc');

        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame('', $conversation->getApprovalRunUuid());
        self::assertSame('', $conversation->getApprovalDecision());

        $messages = $conversation->getDecodedMessages();
        $last = end($messages);
        self::assertIsArray($last);
        self::assertSame('Done — the description is set.', $last['content'] ?? null);
    }

    /**
     * Four of the runtime's refusals RELEASE the run instead of consuming it, so
     * it is still pending and still decidable. Marking the conversation Failed
     * would be wrong twice: the card vanishes, and Failed is resumable, so the
     * UI offers a Retry that starts a SECOND run over the same transcript.
     */
    #[Test]
    public function aReleasingRefusalLeavesTheConversationDecidable(): void
    {
        $conversation = $this->parkedConversation();
        $service = $this->createChatService(
            new StaleApprovalTurnException('run-uuid-1234', 'The review is stale'),
        );
        $service->recordDecision($conversation, true, 'digest-stale');

        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus());
        self::assertSame('run-uuid-1234', $conversation->getApprovalRunUuid());
        self::assertSame('', $conversation->getApprovalDecision(), 'the consumed decision must not be retried');
        self::assertFalse($conversation->isResumable(), 'a parked conversation must not offer Retry');
        self::assertStringContainsString('stale', $conversation->getErrorMessage());
    }

    #[Test]
    public function anUnexpectedErrorStillFailsTheConversation(): void
    {
        $conversation = $this->parkedConversation();
        $service = $this->createChatService(new RuntimeException('the database went away'));
        $service->recordDecision($conversation, true, 'digest-abc');

        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertSame('', $conversation->getApprovalRunUuid());
        self::assertSame('', $conversation->getApprovalDecision());
    }

    // ---- reconciliation, for when the worker never came --------------------

    /**
     * The case the split introduces: the request claimed the row, the worker
     * never started. The run still waits, so hand the card back rather than
     * leave a spinner nobody can interpret.
     */
    #[Test]
    public function aWorkerThatNeverCameHandsTheCardBack(): void
    {
        $conversation = $this->parkedConversation();
        $runRepository = $this->createMock(AgentRunRepositoryInterface::class);
        $runRepository->method('findByUuid')->willReturn($this->runWith(AgentRunStatus::WAITING_FOR_APPROVAL));

        $service = $this->createChatService(null, null, true, $runRepository);
        $service->recordDecision($conversation, true, 'digest-abc');

        self::assertTrue($service->reconcile($conversation));
        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus());
        self::assertSame('run-uuid-1234', $conversation->getApprovalRunUuid());
        self::assertSame('', $conversation->getApprovalDecision());
    }

    /**
     * A worker that IS running must not be interrupted — which is why the run's
     * own status decides this and not a timeout guess.
     */
    #[Test]
    public function aRunningContinuationIsLeftAlone(): void
    {
        $conversation = $this->parkedConversation();
        $runRepository = $this->createMock(AgentRunRepositoryInterface::class);
        $runRepository->method('findByUuid')->willReturn($this->runWith(AgentRunStatus::RUNNING));

        $service = $this->createChatService(null, null, true, $runRepository);
        $service->recordDecision($conversation, true, 'digest-abc');

        self::assertFalse($service->reconcile($conversation));
        self::assertSame(ConversationStatus::Processing, $conversation->getStatus());
        self::assertSame('approve', $conversation->getApprovalDecision());
    }

    /**
     * The run settled without this conversation seeing it — decided in the
     * module, or the worker died after approve() returned. The answer is not
     * recoverable here, so say that instead of spinning.
     */
    #[Test]
    public function aRunThatSettledElsewhereStopsTheSpinnerAndSaysSo(): void
    {
        $conversation = $this->parkedConversation();
        $runRepository = $this->createMock(AgentRunRepositoryInterface::class);
        $runRepository->method('findByUuid')->willReturn($this->runWith(AgentRunStatus::COMPLETED));

        $service = $this->createChatService(null, null, true, $runRepository);
        $service->recordDecision($conversation, true, 'digest-abc');

        self::assertTrue($service->reconcile($conversation));
        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringContainsString('AI Tasks', $conversation->getErrorMessage());
    }

    /**
     * A run belonging to somebody else is not reconciled against — the same
     * per-run check the reader does.
     */
    #[Test]
    public function aRunOfAnotherUserIsNotReconciled(): void
    {
        $conversation = $this->parkedConversation();
        $runRepository = $this->createMock(AgentRunRepositoryInterface::class);
        $runRepository->method('findByUuid')->willReturn($this->runWith(AgentRunStatus::WAITING_FOR_APPROVAL, 99));

        $service = $this->createChatService(null, null, true, $runRepository);
        $service->recordDecision($conversation, true, 'digest-abc');

        self::assertFalse($service->reconcile($conversation));
        self::assertSame(ConversationStatus::Processing, $conversation->getStatus());
    }

    #[Test]
    public function anIdleConversationIsNeverReconciled(): void
    {
        $runRepository = $this->createMock(AgentRunRepositoryInterface::class);
        $runRepository->expects(self::never())->method('findByUuid');

        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setStatus(ConversationStatus::Idle);

        self::assertFalse($this->createChatService(null, null, true, $runRepository)->reconcile($conversation));
    }

    // ---- the card ---------------------------------------------------------

    #[Test]
    public function noCardIsOfferedForAConversationThatIsNotWaiting(): void
    {
        $reader = $this->createMock(PendingApprovalReaderInterface::class);
        $reader->expects(self::never())->method('read');

        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setStatus(ConversationStatus::Idle);

        self::assertNull($this->createChatService(null, $reader)->pendingApproval($conversation));
    }

    #[Test]
    public function theCardComesFromTheReaderForAWaitingConversation(): void
    {
        $view = new WaitingRunView('run-uuid-1234', 'approval', 0, 'Demo agent', 'digest-abc');

        $reader = $this->createMock(PendingApprovalReaderInterface::class);
        $reader->expects(self::once())->method('read')
            ->with(self::anything(), 'run-uuid-1234')
            ->willReturn($view);

        self::assertSame(
            $view,
            $this->createChatService(null, $reader)->pendingApproval($this->parkedConversation()),
        );
    }
}
