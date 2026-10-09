<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\Exception\InvalidInputSubmissionException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAlreadyResumingException;
use Netresearch\NrLlm\Service\Agent\Exception\RunConfigurationInactiveException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingInputException;
use Netresearch\NrLlm\Service\Agent\Exception\StaleApprovalTurnException;
use Netresearch\NrLlm\Service\Agent\Exception\StaleInputTurnException;
use Netresearch\NrLlm\Service\Agent\Exception\SubmitterNotPermittedException;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrLlm\Service\Agent\InputSubmission;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\DenyReason;
use Netresearch\NrMcpAgent\Enum\InputHandBackReason;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\RunActivityRecorder;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * A run that asks the user something, end to end through the chat service
 * (ADR-018): the pause parks the conversation, the request records the
 * answer, the worker hands it to the runtime, and a refusal brings the
 * question back.
 */
#[CoversClass(ChatService::class)]
final class ChatInputTest extends TestCase
{
    private const RUN = 'run-uuid-1234';

    private ?InputSubmission $submitted = null;

    private ?string $submittedRun = null;

    private bool $approveCalled = false;

    private ?ApprovalDecision $approved = null;

    /** @var array<string, mixed> */
    private const SCHEMA = [
        'type' => 'object',
        'title' => 'Mit welchem Punkt soll ich beginnen?',
        'properties' => [
            'start' => ['oneOf' => [['const' => 'meta', 'title' => 'Meta Description'], ['const' => 'images', 'title' => 'Alternativtexte']]],
            'comment' => ['type' => 'string'],
        ],
    ];

    private function service(
        ?AgentRunResult $runAnswer = null,
        AgentRunResult|RuntimeException|null $submitAnswer = null,
        ?PendingApprovalReaderInterface $reader = null,
        ?AgentRunRepositoryInterface $runRepository = null,
        bool $claimSucceeds = true,
        ?LoggerInterface $logger = null,
    ): ChatService {
        $agentRuntime = $this->createMock(AgentRuntimeInterface::class);
        $agentRuntime->method('run')->willReturn($runAnswer ?? $this->completed());
        $agentRuntime->method('approve')->willReturnCallback(function (mixed $actor, string $runUuid, ApprovalDecision $decision) use ($submitAnswer): AgentRunResult {
            $this->approveCalled = true;
            $this->approved = $decision;
            if ($submitAnswer instanceof RuntimeException) {
                throw $submitAnswer;
            }

            return $this->completed();
        });
        $agentRuntime->method('submitInput')->willReturnCallback(
            function (mixed $actor, string $runUuid, InputSubmission $submission) use ($submitAnswer): AgentRunResult {
                $this->submittedRun = $runUuid;
                $this->submitted = $submission;
                if ($submitAnswer instanceof RuntimeException) {
                    throw $submitAnswer;
                }

                return $submitAnswer ?? $this->completed();
            },
        );

        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('updateIf')->willReturn($claimSucceeds);

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);

        $configuration = $this->createMock(LlmConfiguration::class);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('getLlmModel')->willReturn($this->createMock(LlmModel::class));
        $task = $this->createMock(Task::class);
        $task->method('getConfiguration')->willReturn($configuration);
        $taskRepository = $this->createMock(TaskRepository::class);
        $taskRepository->method('findByUid')->willReturn($task);
        $adapterRegistry = $this->createMock(ProviderAdapterRegistryInterface::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($this->createMock(ProviderInterface::class));

        return new ChatService(
            $repository,
            $config,
            $agentRuntime,
            $reader ?? $this->createMock(PendingApprovalReaderInterface::class),
            $runRepository ?? $this->createMock(AgentRunRepositoryInterface::class),
            $taskRepository,
            $adapterRegistry,
            $this->createMock(ResourceFactory::class),
            $this->createMock(SiteFinder::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->createMock(UserContextPrompt::class),
            $this->createMock(RunActivityRecorder::class),
            logger: $logger,
        );
    }

    private function completed(string $text = 'Ich beginne mit der Meta Description.'): AgentRunResult
    {
        return new AgentRunResult(
            AgentRunOutcome::COMPLETED,
            self::RUN,
            [],
            new ToolLoopResult($text, [], 1, false, new UsageStatistics(10, 20, 30)),
        );
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function asks(array $schema = self::SCHEMA): AgentRunResult
    {
        return new AgentRunResult(
            AgentRunOutcome::AWAITING_INPUT,
            self::RUN,
            [],
            suspendedState: new SuspendedRunState([], [], 1, 0, 0, inputToolName: 'ask_user', inputSchema: $schema),
        );
    }

    private function asking(): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Prüfe die Seite');
        $conversation->appendMessage(MessageRole::Assistant, 'Mit welchem Punkt soll ich beginnen?');
        $conversation->setStatus(ConversationStatus::AwaitingInput);
        $conversation->setApprovalRunUuid(self::RUN);

        return $conversation;
    }

    private function runWith(AgentRunStatus $status): AgentRun
    {
        $run = (new ReflectionClass(AgentRun::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionClass($run);
        foreach (['uuid' => self::RUN, 'beUser' => 1, 'status' => $status->value] as $name => $value) {
            $reflection->getProperty($name)->setValue($run, $value);
        }

        return $run;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function lastMessages(Conversation $conversation, int $count): array
    {
        return array_slice($conversation->getDecodedMessages(), -$count);
    }

    // ---- the pause ---------------------------------------------------------

    /**
     * It used to end as Failed with "did not complete (awaiting_input)": the
     * one thing a question is not.
     */
    #[Test]
    public function aQuestionParksTheConversationInsteadOfFailingIt(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Prüfe die Seite');
        $conversation->setErrorMessage('an earlier failure');

        $this->service($this->asks())->processConversation($conversation);

        self::assertSame(ConversationStatus::AwaitingInput, $conversation->getStatus());
        self::assertSame(self::RUN, $conversation->getApprovalRunUuid());
        self::assertSame('', $conversation->getErrorMessage());
    }

    #[Test]
    public function theQuestionIsTheAssistantsMessage(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Prüfe die Seite');

        $this->service($this->asks())->processConversation($conversation);

        $last = self::lastMessages($conversation, 1)[0];
        self::assertSame('assistant', $last['role']);
        self::assertSame('Mit welchem Punkt soll ich beginnen?', $last['content']);
    }

    #[Test]
    public function aQuestionWithoutTextAddsNoMessage(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Prüfe die Seite');

        $this->service($this->asks(['properties' => ['ok' => ['type' => 'boolean']]]))->processConversation($conversation);

        self::assertSame(ConversationStatus::AwaitingInput, $conversation->getStatus());
        self::assertSame(1, $conversation->getMessageCount());
    }

    // ---- what the request writes down -------------------------------------

    #[Test]
    public function recordingAnAnswerClaimsTheConversationAndShowsTheAnswer(): void
    {
        $conversation = $this->asking();
        $conversation->setErrorMessage('', InputHandBackReason::StaleTurn->value);

        self::assertTrue($this->service()->recordInput($conversation, ['start' => 'meta'], 'digest-abc', 'Meta Description'));

        self::assertSame(ConversationStatus::Processing, $conversation->getStatus());
        self::assertTrue($conversation->hasPendingInputSubmission());
        self::assertSame(['start' => 'meta'], $conversation->getPendingInputData());
        self::assertSame('digest-abc', $conversation->getApprovalTurnDigest());
        self::assertSame(self::RUN, $conversation->getApprovalRunUuid());
        self::assertSame('', $conversation->getErrorCode(), 'the reason a refusal left belongs to the question just answered');
        $last = self::lastMessages($conversation, 1)[0];
        self::assertSame(['user', 'Meta Description'], [$last['role'], $last['content']]);
        self::assertNull($this->submitted, 'the request must not hand the answer over itself');
    }

    #[Test]
    public function anAnswerToAConversationThatAsksNothingIsNotRecorded(): void
    {
        $conversation = $this->asking();
        $conversation->setStatus(ConversationStatus::AwaitingApproval);

        self::assertFalse($this->service()->recordInput($conversation, ['start' => 'meta'], 'digest-abc', 'Meta Description'));
        self::assertFalse($conversation->hasPendingInputSubmission());
        self::assertSame(2, $conversation->getMessageCount());
    }

    #[Test]
    public function aLostClaimRecordsNothing(): void
    {
        self::assertFalse($this->service(claimSucceeds: false)->recordInput($this->asking(), ['start' => 'meta'], 'digest-abc', 'Meta Description'));
    }

    // ---- what the worker then does ----------------------------------------

    #[Test]
    public function theWorkerHandsTheAnswerToTheRuntime(): void
    {
        $conversation = $this->asking();
        $service = $this->service();
        $service->recordInput($conversation, ['start' => 'meta'], 'digest-abc', 'Meta Description');

        $service->processConversation($conversation);

        self::assertSame(self::RUN, $this->submittedRun);
        self::assertNotNull($this->submitted);
        self::assertSame(['start' => 'meta'], $this->submitted->data);
        self::assertSame('digest-abc', $this->submitted->turnDigest);
        self::assertSame(1, $this->submitted->submittedByBeUser);
        self::assertFalse($this->approveCalled, 'an answer is not an approval');
    }

    #[Test]
    public function theContinuationsAnswerFollowsTheUsersAnswer(): void
    {
        $conversation = $this->asking();
        $service = $this->service();
        $service->recordInput($conversation, ['start' => 'meta'], 'digest-abc', 'Meta Description');

        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertFalse($conversation->hasPendingApprovalDecision());
        self::assertSame(
            [['user', 'Meta Description'], ['assistant', 'Ich beginne mit der Meta Description.']],
            array_map(static fn(array $m): array => [$m['role'], $m['content']], self::lastMessages($conversation, 2)),
        );
    }

    /**
     * A continuation can ask again — the next point of a guided tour.
     */
    #[Test]
    public function theContinuationMayAskTheNextQuestion(): void
    {
        $conversation = $this->asking();
        $service = $this->service(submitAnswer: $this->asks());
        $service->recordInput($conversation, ['start' => 'meta'], 'digest-abc', 'Meta Description');

        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::AwaitingInput, $conversation->getStatus());
        self::assertSame(self::RUN, $conversation->getApprovalRunUuid());
    }

    /**
     * @return iterable<string, array{RuntimeException, InputHandBackReason}>
     */
    public static function releasingRefusals(): iterable
    {
        $secret = 'Run run-uuid-1234 internal state: class Foo\\Bar';
        yield 'stale question' => [new StaleInputTurnException(self::RUN, $secret), InputHandBackReason::StaleTurn];
        yield 'invalid answer' => [new InvalidInputSubmissionException(self::RUN, $secret), InputHandBackReason::InvalidInput];
        yield 'submitter not permitted' => [new SubmitterNotPermittedException(self::RUN, $secret), InputHandBackReason::SubmitterNotPermitted];
        yield 'already resuming' => [new RunAlreadyResumingException(self::RUN, $secret), InputHandBackReason::AlreadyResuming];
        yield 'configuration inactive' => [new RunConfigurationInactiveException(self::RUN, $secret), InputHandBackReason::ConfigurationInactive];
        yield 'not awaiting input' => [new RunNotAwaitingInputException(self::RUN, $secret), InputHandBackReason::NotAwaitingInput];
    }

    /**
     * These leave the run waiting for its answer. The question comes back with
     * a reason of the chat's own; nr-llm's sentence goes to the log, and the
     * answer that was not taken leaves the transcript.
     */
    #[Test]
    #[DataProvider('releasingRefusals')]
    public function aReleasingRefusalBringsTheQuestionBack(RuntimeException $refusal, InputHandBackReason $reason): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::stringContains('still waiting for input'),
            self::callback(static fn(array $context): bool => $context['message'] === $refusal->getMessage() && $context['reason'] === $reason->value),
        );
        $conversation = $this->asking();
        $service = $this->service(submitAnswer: $refusal, logger: $logger);
        $service->recordInput($conversation, ['start' => 'meta'], 'digest-abc', 'Meta Description');

        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::AwaitingInput, $conversation->getStatus());
        self::assertSame(self::RUN, $conversation->getApprovalRunUuid());
        self::assertSame($reason->value, $conversation->getErrorCode());
        self::assertSame('', $conversation->getErrorMessage());
        self::assertFalse($conversation->hasPendingApprovalDecision());
        self::assertSame('Mit welchem Punkt soll ich beginnen?', self::lastMessages($conversation, 1)[0]['content']);
    }

    #[Test]
    public function anUnexpectedErrorFailsTheConversation(): void
    {
        $conversation = $this->asking();
        $service = $this->service(submitAnswer: new RuntimeException('provider down'));
        $service->recordInput($conversation, ['start' => 'meta'], 'digest-abc', 'Meta Description');

        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertSame('', $conversation->getApprovalRunUuid());
        self::assertFalse($conversation->hasPendingApprovalDecision());
    }

    // ---- a worker that never came -----------------------------------------

    /**
     * The run still waits for the answer nobody delivered. Before this the
     * branch for a settled run caught it, failed the conversation and said the
     * run had finished outside the chat — which it had not.
     */
    #[Test]
    public function aWorkerThatNeverCameBringsTheQuestionBack(): void
    {
        $runRepository = $this->createMock(AgentRunRepositoryInterface::class);
        $runRepository->method('findByUuid')->willReturn($this->runWith(AgentRunStatus::WAITING_FOR_INPUT));
        $conversation = $this->asking();
        $service = $this->service(runRepository: $runRepository);
        $service->recordInput($conversation, ['start' => 'meta'], 'digest-abc', 'Meta Description');
        (new ReflectionClass($conversation))->getProperty('tstamp')->setValue($conversation, time() - 600);

        self::assertTrue($service->reconcile($conversation));

        self::assertSame(ConversationStatus::AwaitingInput, $conversation->getStatus());
        self::assertSame(self::RUN, $conversation->getApprovalRunUuid());
        self::assertFalse($conversation->hasPendingApprovalDecision());
        self::assertSame('assistant', self::lastMessages($conversation, 1)[0]['role'], 'the undelivered answer leaves the transcript');
    }

    // ---- reading the question ---------------------------------------------

    #[Test]
    public function theQuestionCarriesNrLlmsDigestAndTheRawSchema(): void
    {
        $reader = $this->createMock(PendingApprovalReaderInterface::class);
        $reader->method('read')->willReturn(new WaitingRunView(self::RUN, WaitingRunView::MODE_INPUT, 0, 'Demo', 'digest-abc'));
        $reader->method('inputSchema')->willReturn(self::SCHEMA);

        $pause = $this->service(reader: $reader)->pendingInput($this->asking());

        self::assertNotNull($pause);
        self::assertSame([self::RUN, 'digest-abc', self::SCHEMA, null], [$pause->runUuid, $pause->turnDigest, $pause->schema, $pause->unreadableReason]);
    }

    #[Test]
    public function anUnreadableQuestionSaysWhy(): void
    {
        $reader = $this->createMock(PendingApprovalReaderInterface::class);
        $reader->method('read')->willReturn(new WaitingRunView(self::RUN, WaitingRunView::MODE_UNREADABLE, 0, 'Demo', unreadableReason: 'schema-not-renderable'));

        $pause = $this->service(reader: $reader)->pendingInput($this->asking());

        self::assertNotNull($pause);
        self::assertSame('schema-not-renderable', $pause->unreadableReason);
    }

    #[Test]
    public function nothingIsAskedOfAConversationThatDoesNotWait(): void
    {
        $reader = $this->createMock(PendingApprovalReaderInterface::class);
        $reader->expects(self::never())->method('read');
        $conversation = $this->asking();
        $conversation->setStatus(ConversationStatus::AwaitingApproval);

        self::assertNull($this->service(reader: $reader)->pendingInput($conversation));
    }

    #[Test]
    public function anApprovalViewIsNoQuestion(): void
    {
        $reader = $this->createMock(PendingApprovalReaderInterface::class);
        $reader->method('read')->willReturn(new WaitingRunView(self::RUN, WaitingRunView::MODE_APPROVAL, 0, 'Demo', 'digest-abc'));

        self::assertNull($this->service(reader: $reader)->pendingInput($this->asking()));
    }

    // ---- a denial with a reason (the approval card's two denial buttons) ---

    private function parked(): Conversation
    {
        $conversation = $this->asking();
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid(self::RUN);

        return $conversation;
    }

    /**
     * "Andere Variante" is recorded as a denial with its reason, and the label
     * goes into the transcript so the next turn knows what was asked for.
     */
    #[Test]
    public function aDenialKeepsItsReasonAndShowsTheButtonsLabel(): void
    {
        $conversation = $this->parked();

        self::assertTrue($this->service()->recordDecision($conversation, false, 'digest-abc', DenyReason::Variant, 'Andere Variante'));

        self::assertSame('deny', $conversation->getApprovalDecision());
        self::assertSame(DenyReason::Variant, $conversation->getApprovalDenyReason());
        self::assertSame(['user', 'Andere Variante'], [self::lastMessages($conversation, 1)[0]['role'], self::lastMessages($conversation, 1)[0]['content']]);
    }

    #[Test]
    public function anApprovalCarriesNoReasonAndAddsNoLine(): void
    {
        $conversation = $this->parked();

        $this->service()->recordDecision($conversation, true, 'digest-abc', DenyReason::Skip, 'Überspringen');

        self::assertNull($conversation->getApprovalDenyReason());
        self::assertSame(2, $conversation->getMessageCount());
    }

    /**
     * nr-llm's decision does not take a reason yet: the worker hands over a
     * plain denial, which is what keeps the chat working on today's nr-llm.
     */
    #[Test]
    public function withoutAReasonInNrLlmTheWorkerSendsAPlainDenial(): void
    {
        $conversation = $this->parked();
        $service = $this->service();
        $service->recordDecision($conversation, false, 'digest-abc', DenyReason::Skip, 'Überspringen');

        $service->processConversation($conversation);

        self::assertNotNull($this->approved);
        self::assertFalse($this->approved->approved);
        self::assertSame('digest-abc', $this->approved->turnDigest);
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
    }

    /** A refusal brings the card back, and the reason line goes with the decision. */
    #[Test]
    public function aHandedBackDenialTakesItsLineBackOut(): void
    {
        $conversation = $this->parked();
        $service = $this->service(submitAnswer: new StaleApprovalTurnException(self::RUN, 'stale'));
        $service->recordDecision($conversation, false, 'digest-abc', DenyReason::Variant, 'Andere Variante');

        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus());
        self::assertSame(2, $conversation->getMessageCount());
        self::assertNull($conversation->getApprovalDenyReason());
    }

    #[Test]
    public function settlingTheConversationForgetsTheReason(): void
    {
        $conversation = $this->parked();
        $conversation->recordApprovalDecision(false, 'digest-abc', DenyReason::Skip);

        $conversation->setStatus(ConversationStatus::Idle);

        self::assertNull($conversation->getApprovalDenyReason());
        self::assertArrayHasKey('approval_deny_reason', $conversation->toRow());
    }
}
