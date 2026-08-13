<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\Exception\StaleApprovalTurnException;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
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
use RuntimeException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Deciding a pending tool call from the chat.
 *
 * The point of the feature is that the decision goes through nr-llm's own
 * approve() — same per-run authorisation, same digest check — and that the
 * answer lands in this conversation instead of in a module the chat cannot see.
 * So the assertions are about what reaches the runtime and what comes back.
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
        AgentRunResult|RuntimeException $approveAnswer,
        ?PendingApprovalReaderInterface $reader = null,
        bool $claimSucceeds = true,
    ): ChatService {
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('updateIf')->willReturn($claimSucceeds);

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

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);

        return new ChatService(
            $repository,
            $config,
            $agentRuntime,
            $reader ?? $this->createMock(PendingApprovalReaderInterface::class),
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

    /**
     * The decision reaches the runtime unchanged — including the digest, which
     * the runtime verifies against the state it claims (ADR-132). Rewriting or
     * dropping it here would turn a refused stale decision into an applied one.
     */
    #[Test]
    public function theDecisionAndItsDigestReachTheRuntime(): void
    {
        $conversation = $this->parkedConversation();

        $this->createChatService($this->completed())
            ->decideApproval($conversation, true, 'digest-abc');

        self::assertSame('run-uuid-1234', $this->capturedRunUuid);
        self::assertNotNull($this->capturedDecision);
        self::assertTrue($this->capturedDecision->approved);
        self::assertSame('digest-abc', $this->capturedDecision->turnDigest);
    }

    /**
     * Denying is a decision, not a cancellation: it goes through the same call
     * with the opposite answer.
     */
    #[Test]
    public function denyingSendsTheOppositeDecision(): void
    {
        $this->createChatService($this->completed())
            ->decideApproval($this->parkedConversation(), false, 'digest-abc');

        self::assertNotNull($this->capturedDecision);
        self::assertFalse($this->capturedDecision->approved);
    }

    /**
     * The whole reason for deciding here: the continuation lands in this
     * conversation. Approving in the module leaves the chat parked forever.
     */
    #[Test]
    public function theAnswerArrivesInTheConversation(): void
    {
        $conversation = $this->parkedConversation();

        $this->createChatService($this->completed('Done — the description is set.'))
            ->decideApproval($conversation, true, 'digest-abc');

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame('', $conversation->getApprovalRunUuid());

        $messages = $conversation->getDecodedMessages();
        $last = end($messages);
        self::assertIsArray($last);
        self::assertSame('Done — the description is set.', $last['content'] ?? null);
    }

    /**
     * Every refusal the runtime can raise — a stale digest, a released run, an
     * approver who may not decide — ends the wait. Leaving the conversation
     * parked would keep offering a decision the runtime has taken away.
     */
    #[Test]
    public function aRefusedDecisionEndsTheWaitInsteadOfLeavingItParked(): void
    {
        $conversation = $this->parkedConversation();

        $this->createChatService(new RuntimeException('The review is stale'))
            ->decideApproval($conversation, true, 'digest-stale');

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertSame('', $conversation->getApprovalRunUuid());
        self::assertStringContainsString('stale', $conversation->getErrorMessage());
    }

    /**
     * A click that arrives after the run moved on is not an error to report, and
     * it must not reach the runtime — approve() on a run that is not waiting
     * would raise where nothing went wrong.
     */
    #[Test]
    public function aDecisionOnAConversationThatIsNotWaitingIsIgnored(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setStatus(ConversationStatus::Idle);

        $this->createChatService($this->completed())
            ->decideApproval($conversation, true, 'digest-abc');

        self::assertNull($this->capturedDecision);
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
    }

    /**
     * Four of the runtime's refusals RELEASE the run instead of consuming it —
     * a stale digest, an already-resuming run, an approver who may not decide,
     * a run that is no longer waiting. The run is still pending afterwards, so
     * the conversation must stay parked.
     *
     * Marking it Failed would be wrong twice: the card vanishes, and Failed is
     * resumable, so the UI offers a Retry that starts a SECOND run over the
     * same transcript while the first still waits for its approval.
     */
    #[Test]
    public function aReleasingRefusalLeavesTheConversationDecidable(): void
    {
        $conversation = $this->parkedConversation();

        $this->createChatService(new StaleApprovalTurnException('run-uuid-1234', 'The review is stale'))
            ->decideApproval($conversation, true, 'digest-stale');

        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus());
        self::assertSame('run-uuid-1234', $conversation->getApprovalRunUuid());
        self::assertFalse($conversation->isResumable(), 'a parked conversation must not offer Retry');
        self::assertStringContainsString('stale', $conversation->getErrorMessage());
    }

    /**
     * A refusal that is not one of the four release cases is a real failure.
     */
    #[Test]
    public function anUnexpectedErrorStillFailsTheConversation(): void
    {
        $conversation = $this->parkedConversation();

        $this->createChatService(new RuntimeException('the database went away'))
            ->decideApproval($conversation, true, 'digest-abc');

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertSame('', $conversation->getApprovalRunUuid());
    }

    /**
     * The claim is what stops a follow-up message from being erased: while
     * approve() runs inline, sendMessage may still accept a message, because
     * AwaitingApproval is not one of its busy states. If the claim is lost, the
     * other writer owns the conversation and this path must not decide on top
     * of it — nor reach the runtime at all.
     */
    #[Test]
    public function aLostClaimDecidesNothing(): void
    {
        $conversation = $this->parkedConversation();

        $this->createChatService($this->completed(), null, false)
            ->decideApproval($conversation, true, 'digest-abc');

        self::assertNull($this->capturedDecision);
        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus());
        self::assertSame('run-uuid-1234', $conversation->getApprovalRunUuid());
    }

    /**
     * The card is only offered while the conversation is actually waiting.
     */
    #[Test]
    public function noCardIsOfferedForAConversationThatIsNotWaiting(): void
    {
        $reader = $this->createMock(PendingApprovalReaderInterface::class);
        $reader->expects(self::never())->method('read');

        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setStatus(ConversationStatus::Idle);

        self::assertNull($this->createChatService($this->completed(), $reader)->pendingApproval($conversation));
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
            $this->createChatService($this->completed(), $reader)->pendingApproval($this->parkedConversation()),
        );
    }
}
