<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Closure;
use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\Exception\RunConfigurationInactiveException;
use Netresearch\NrLlm\Service\Agent\Exception\StaleApprovalTurnException;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\RunActivityRecorder;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
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

    private mixed $capturedOnStep = null;

    private ?string $capturedRunUuid = null;

    private function parkedConversation(string $runUuid = 'run-uuid-1234'): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Set the meta description');
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid($runUuid);
        // What performRecordedDecision() writes back when the runtime refuses a
        // decision and hands the run back: the reason, in the same field a
        // failure lives in, which is the whole of NEXT-156. (The pause itself
        // stores nothing since NEXT-159; the chat renders that notice from the
        // status.)
        $conversation->setErrorMessage('The turn moved on — decide again.');

        return $conversation;
    }

    private function createChatService(
        AgentRunResult|RuntimeException $approveAnswer = null,
        ?PendingApprovalReaderInterface $reader = null,
        bool $claimSucceeds = true,
        ?AgentRunRepositoryInterface $runRepository = null,
        ?RunActivityRecorder $activityRecorder = null,
        bool $approveFiresStep = false,
    ): ChatService {
        $approveAnswer ??= $this->completed();

        $agentRuntime = $this->createMock(AgentRuntimeInterface::class);
        $agentRuntime->method('approve')->willReturnCallback(
            function (mixed $actor, string $runUuid, ApprovalDecision $decision, mixed $onStep = null) use ($approveAnswer, $approveFiresStep): AgentRunResult {
                $this->capturedRunUuid = $runUuid;
                $this->capturedOnStep = $onStep;
                $this->capturedDecision = $decision;
                if ($approveAnswer instanceof RuntimeException) {
                    throw $approveAnswer;
                }
                if ($approveFiresStep && $onStep instanceof Closure) {
                    $onStep(new RunStep(kind: RunStep::KIND_LLM, round: 1, durationMs: 5.0));
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
            new UploadMimeTypeMap(),
            $this->createMock(UserContextPrompt::class),
            $activityRecorder ?? $this->createMock(RunActivityRecorder::class),
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

    /**
     * A conversation whose row was last written long enough ago that the grace
     * period has passed — i.e. one where no worker showed up.
     */
    private function staleClaim(Conversation $conversation): Conversation
    {
        $reflection = new ReflectionClass($conversation);
        $reflection->getProperty('tstamp')->setValue($conversation, time() - 600);

        return $conversation;
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

    /**
     * The field says why the run is waiting. One click later that is no longer
     * true, and the field it sits in is the one the chat renders as an error —
     * over a Processing conversation, which is resumable, so a Retry button
     * appeared next to it. Pressing it started a second run over the same
     * transcript while the first was carrying out the approved write: the
     * duplicated pages in NEXT-156. The claim has to take the reason with it.
     */
    #[Test]
    public function recordingClearsTheNoticeItSupersedes(): void
    {
        $conversation = $this->parkedConversation();
        self::assertNotSame('', $conversation->getErrorMessage(), 'precondition: the notice is there to clear');

        $this->createChatService()->recordDecision($conversation, true, 'digest-abc');

        self::assertSame('', $conversation->getErrorMessage());
    }

    /**
     * persist() writes the whole row, so a message from an earlier state of this
     * turn survives the run that resolved it unless success clears it. It then
     * renders as an error over a conversation that finished successfully — the
     * state the demo was in when NEXT-155 was run.
     */
    #[Test]
    public function aCompletedContinuationLeavesNoError(): void
    {
        $conversation = $this->parkedConversation();
        $service = $this->createChatService($this->completed('Done — the page is created.'));
        $service->recordDecision($conversation, true, 'digest-abc');
        // Whatever the claim did, put a reason back: this asserts about the
        // completion, not about recordDecision().
        $conversation->setErrorMessage('The turn moved on — decide again.');

        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame('', $conversation->getErrorMessage());
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

    /**
     * The decision appears in the activity of the run it belongs to, before
     * the continuation's first step (NEXT-172).
     */
    #[Test]
    public function theDecisionIsListedBeforeTheContinuationsFirstStep(): void
    {
        $conversation = $this->parkedConversation();
        $recorder = $this->loggingRecorder();
        $service = $this->createChatService(activityRecorder: $recorder, approveFiresStep: true);
        $service->recordDecision($conversation, false, 'digest-abc');

        $service->processConversation($conversation);

        self::assertSame(['decision:denied', 'step:llm'], $recorder->log);
    }

    #[Test]
    public function aContinuationWithoutStepsStillListsTheDecision(): void
    {
        $conversation = $this->parkedConversation();
        $recorder = $this->loggingRecorder();
        $service = $this->createChatService(activityRecorder: $recorder);
        $service->recordDecision($conversation, true, 'digest-abc');

        $service->processConversation($conversation);

        self::assertSame(['decision:approved'], $recorder->log);
    }

    /**
     * A decision the runtime refuses was not acted on, so the list does not
     * claim it was (NEXT-172).
     */
    #[Test]
    public function aRefusedDecisionIsNotListed(): void
    {
        $conversation = $this->parkedConversation();
        $recorder = $this->loggingRecorder();
        $service = $this->createChatService(new StaleApprovalTurnException('run-uuid-1234', 'The review is stale'), activityRecorder: $recorder);
        $service->recordDecision($conversation, true, 'digest-abc');

        $service->processConversation($conversation);

        self::assertSame([], $recorder->log);
    }

    /**
     * A recorder that writes nothing and logs what it was asked to record.
     */
    private function loggingRecorder(): RunActivityRecorder
    {
        return new class ($this->createMock(ConversationRepository::class)) extends RunActivityRecorder {
            /** @var list<string> */
            public array $log = [];

            public function start(Conversation $conversation): void
            {
                $this->log[] = 'start';
            }

            public function onStep(Conversation $conversation): Closure
            {
                return function (RunStep $step): void {
                    $this->log[] = 'step:' . $step->kind;
                };
            }

            public function recordDecision(Conversation $conversation, bool $approved): void
            {
                $this->log[] = 'decision:' . ($approved ? 'approved' : 'denied');
            }
        };
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

    /**
     * An administrator deactivated the configuration while the run waited.
     * nr-llm refuses the decision before claiming the run, so the run is still
     * pending, and the conversation must stay decidable for when the
     * configuration is active again.
     */
    #[Test]
    public function aDeactivatedConfigurationLeavesTheConversationDecidable(): void
    {
        $conversation = $this->parkedConversation();
        $service = $this->createChatService(RunConfigurationInactiveException::forRun('run-uuid-1234'));
        $service->recordDecision($conversation, true, 'digest-abc');

        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus());
        self::assertSame('run-uuid-1234', $conversation->getApprovalRunUuid());
        self::assertFalse($conversation->isResumable(), 'a parked conversation must not offer Retry');
        self::assertStringContainsString('deactivated', $conversation->getErrorMessage());
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
        $this->staleClaim($conversation);

        self::assertTrue($service->reconcile($conversation));
        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus());
        self::assertSame('run-uuid-1234', $conversation->getApprovalRunUuid());
        self::assertSame('', $conversation->getApprovalDecision());
    }

    /**
     * The defect this guard exists for: ExecChatProcessor returns as soon as the
     * shell forks, so the poll that follows the decision by milliseconds sees a
     * run that still waits — because nr-llm only leaves WAITING_FOR_APPROVAL
     * when the continuation claims it. Reverting there would take the decision
     * away from a worker that is merely still booting, and the worker would then
     * find a conversation that is no longer claimed and do nothing at all.
     */
    #[Test]
    public function aFreshlyClaimedConversationIsNotReverted(): void
    {
        $conversation = $this->parkedConversation();
        $runRepository = $this->createMock(AgentRunRepositoryInterface::class);
        $runRepository->expects(self::never())->method('findByUuid');

        $service = $this->createChatService(null, null, true, $runRepository);
        $service->recordDecision($conversation, true, 'digest-abc');
        // A row written just now — which is what the claim leaves behind.
        (new ReflectionClass($conversation))->getProperty('tstamp')->setValue($conversation, time());

        self::assertFalse($service->reconcile($conversation));
        self::assertSame(ConversationStatus::Processing, $conversation->getStatus());
        self::assertSame('approve', $conversation->getApprovalDecision());
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
        $this->staleClaim($conversation);

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
        $this->staleClaim($conversation);

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
        $this->staleClaim($conversation);

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
