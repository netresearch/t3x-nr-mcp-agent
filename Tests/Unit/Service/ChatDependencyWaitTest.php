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
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * A run that hands a step to a remote agent and waits for its answer
 * (nr-llm ADR-281): the chat parks the conversation, follows the run while
 * nr-llm's worker continues it, and lets the editor stop the wait.
 */
#[CoversClass(ChatService::class)]
final class ChatDependencyWaitTest extends TestCase
{
    private const RUN = 'run-uuid-dep-1';

    /** @var list<string> */
    private array $cancelled = [];

    /** @var list<ConversationStatus> */
    private array $expectedStatuses = [];

    private function service(
        ?AgentRunResult $runAnswer = null,
        ?AgentRun $run = null,
        bool $cancelSucceeds = true,
        ?PendingApprovalReaderInterface $reader = null,
        bool $claimSucceeds = true,
    ): ChatService {
        $agentRuntime = $this->createMock(AgentRuntimeInterface::class);
        $agentRuntime->method('run')->willReturn($runAnswer ?? $this->waits());
        $agentRuntime->method('cancel')->willReturnCallback(function (mixed $actor, string $runUuid) use ($cancelSucceeds): bool {
            $this->cancelled[] = $runUuid;

            return $cancelSucceeds;
        });

        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('updateIf')->willReturnCallback(function (Conversation $conversation, ConversationStatus $expected) use ($claimSucceeds): bool {
            $this->expectedStatuses[] = $expected;

            return $claimSucceeds;
        });

        $runRepository = $this->createMock(AgentRunRepositoryInterface::class);
        $runRepository->method('findByUuid')->willReturn($run);

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
            $runRepository,
            $taskRepository,
            $adapterRegistry,
            $this->createMock(ResourceFactory::class),
            $this->createMock(SiteFinder::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->createMock(UserContextPrompt::class),
            $this->createMock(RunActivityRecorder::class),
        );
    }

    private function waits(string $runUuid = self::RUN): AgentRunResult
    {
        return new AgentRunResult(
            AgentRunOutcome::AWAITING_DEPENDENCY,
            $runUuid,
            [],
            suspendedState: new SuspendedRunState([], [], 1, 0, 0),
        );
    }

    private function waiting(): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Frag den Übersetzungsagenten');
        $conversation->setStatus(ConversationStatus::AwaitingDependency);
        $conversation->setApprovalRunUuid(self::RUN);

        return $conversation;
    }

    private function runWith(string $status, ?string $answer = null, int $beUser = 1): AgentRun
    {
        $run        = (new ReflectionClass(AgentRun::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionClass($run);
        foreach (['uuid' => self::RUN, 'beUser' => $beUser, 'status' => $status, 'answer' => $answer] as $name => $value) {
            $reflection->getProperty($name)->setValue($run, $value);
        }

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    private function lastMessage(Conversation $conversation): array
    {
        $messages = $conversation->getDecodedMessages();

        return $messages[array_key_last($messages)];
    }

    // ---- the pause ---------------------------------------------------------

    /**
     * It used to end as Failed with "did not complete (awaiting_dependency)":
     * the run did exactly what it should and waits for the remote agent.
     */
    #[Test]
    public function aRunWaitingForARemoteAgentParksTheConversationInsteadOfFailingIt(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Frag den Übersetzungsagenten');
        $conversation->setErrorMessage('an earlier failure');

        $this->service($this->waits())->processConversation($conversation);

        self::assertSame(ConversationStatus::AwaitingDependency, $conversation->getStatus());
        self::assertSame(self::RUN, $conversation->getApprovalRunUuid());
        self::assertSame('', $conversation->getErrorMessage());
        self::assertSame(self::RUN, $conversation->getLastRunUuid());
    }

    /** Without a run there is nothing to wait for: the turn failed. */
    #[Test]
    public function aWaitWithoutARecordedRunFails(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Frag den Übersetzungsagenten');

        $this->service($this->waits(''))->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
    }

    // ---- following the run ------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function statusesThatAreNoEnd(): iterable
    {
        yield 'still waiting' => [AgentRunStatus::WAITING_FOR_DEPENDENCY->value];
        yield 'continued by the worker' => [AgentRunStatus::RUNNING->value];
        yield 'queued' => [AgentRunStatus::QUEUED->value];
        yield 'a value nobody knows yet' => ['some_future_status'];
    }

    #[Test]
    #[DataProvider('statusesThatAreNoEnd')]
    public function theConversationKeepsWaitingWhileTheRunHasNotStopped(string $status): void
    {
        $conversation = $this->waiting();

        self::assertFalse($this->service(run: $this->runWith($status))->reconcile($conversation));
        self::assertSame(ConversationStatus::AwaitingDependency, $conversation->getStatus());
        self::assertSame(self::RUN, $conversation->getApprovalRunUuid());
    }

    #[Test]
    public function aRunOfSomebodyElseIsNotFollowed(): void
    {
        $conversation = $this->waiting();

        self::assertFalse($this->service(run: $this->runWith(AgentRunStatus::COMPLETED->value, 'Fertig', 2))->reconcile($conversation));
        self::assertSame(ConversationStatus::AwaitingDependency, $conversation->getStatus());
    }

    #[Test]
    public function theAnswerOfTheContinuedRunIsTheAssistantsMessage(): void
    {
        $conversation = $this->waiting();

        self::assertTrue($this->service(run: $this->runWith(AgentRunStatus::COMPLETED->value, 'Die Übersetzung lautet: Hello world.'))->reconcile($conversation));

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame('', $conversation->getApprovalRunUuid());
        $last = $this->lastMessage($conversation);
        self::assertSame('assistant', $last['role']);
        self::assertSame('Die Übersetzung lautet: Hello world.', $last['content']);
        self::assertArrayNotHasKey('notice', $last);
        self::assertSame([ConversationStatus::AwaitingDependency], $this->expectedStatuses);
    }

    /**
     * An nr-llm that keeps no answer for the run: the chat says so instead of
     * pretending the run said nothing.
     */
    #[Test]
    public function aCompletedRunWithoutAKeptAnswerSaysSo(): void
    {
        $conversation = $this->waiting();

        self::assertTrue($this->service(run: $this->runWith(AgentRunStatus::COMPLETED->value))->reconcile($conversation));

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame(ChatService::NOTICE_ANSWER_NOT_KEPT, $this->lastMessage($conversation)['notice'] ?? null);
    }

    #[Test]
    public function anEmptyKeptAnswerCountsAsNone(): void
    {
        $conversation = $this->waiting();

        $this->service(run: $this->runWith(AgentRunStatus::COMPLETED->value, "  \n"))->reconcile($conversation);

        self::assertSame(ChatService::NOTICE_ANSWER_NOT_KEPT, $this->lastMessage($conversation)['notice'] ?? null);
    }

    #[Test]
    public function aFailedContinuationFailsTheConversation(): void
    {
        $conversation = $this->waiting();

        self::assertTrue($this->service(run: $this->runWith(AgentRunStatus::FAILED->value))->reconcile($conversation));

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringContainsString('remote agent', $conversation->getErrorMessage());
    }

    #[Test]
    public function aRunCancelledElsewhereEndsTheWait(): void
    {
        $conversation = $this->waiting();

        self::assertTrue($this->service(run: $this->runWith(AgentRunStatus::CANCELLED->value))->reconcile($conversation));

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame(ChatService::NOTICE_WAIT_CANCELLED, $this->lastMessage($conversation)['notice'] ?? null);
    }

    #[Test]
    public function aContinuationThatAsksForApprovalShowsTheCard(): void
    {
        $conversation = $this->waiting();

        self::assertTrue($this->service(run: $this->runWith(AgentRunStatus::WAITING_FOR_APPROVAL->value))->reconcile($conversation));

        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus());
        self::assertSame(self::RUN, $conversation->getApprovalRunUuid());
    }

    #[Test]
    public function aContinuationThatAsksAQuestionShowsIt(): void
    {
        $reader = $this->createMock(PendingApprovalReaderInterface::class);
        $reader->method('inputSchema')->willReturn([
            'type'       => 'object',
            'title'      => 'Welche Sprache?',
            'properties' => ['lang' => ['type' => 'string']],
        ]);
        $conversation = $this->waiting();

        self::assertTrue($this->service(run: $this->runWith(AgentRunStatus::WAITING_FOR_INPUT->value), reader: $reader)->reconcile($conversation));

        self::assertSame(ConversationStatus::AwaitingInput, $conversation->getStatus());
        self::assertSame(self::RUN, $conversation->getApprovalRunUuid());
        self::assertSame('Welche Sprache?', $this->lastMessage($conversation)['content']);
    }

    /**
     * A poll that lost the row to another writer changes nothing it reports.
     */
    #[Test]
    public function aLostWriteReportsNoChange(): void
    {
        $conversation = $this->waiting();

        self::assertFalse($this->service(run: $this->runWith(AgentRunStatus::COMPLETED->value, 'Fertig'), claimSucceeds: false)->reconcile($conversation));
    }

    /**
     * Decided in the AI Tasks module while the chat showed the card: the run
     * went on and now waits for a remote agent, and the chat follows it.
     */
    #[Test]
    public function aCardWhoseRunNowWaitsForARemoteAgentBecomesTheWait(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid(self::RUN);

        self::assertTrue($this->service(run: $this->runWith(AgentRunStatus::WAITING_FOR_DEPENDENCY->value))->reconcile($conversation));

        self::assertSame(ConversationStatus::AwaitingDependency, $conversation->getStatus());
        self::assertSame(self::RUN, $conversation->getApprovalRunUuid());
        self::assertSame([ConversationStatus::AwaitingApproval], $this->expectedStatuses);
    }

    // ---- stopping the wait ------------------------------------------------

    #[Test]
    public function cancellingTheWaitCancelsTheRunAndFreesTheConversation(): void
    {
        $conversation = $this->waiting();

        self::assertTrue($this->service(run: $this->runWith(AgentRunStatus::WAITING_FOR_DEPENDENCY->value))->cancelDependencyWait($conversation));

        self::assertSame([self::RUN], $this->cancelled);
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame('', $conversation->getApprovalRunUuid());
        self::assertSame(ChatService::NOTICE_WAIT_CANCELLED, $this->lastMessage($conversation)['notice'] ?? null);
        self::assertSame([ConversationStatus::AwaitingDependency], $this->expectedStatuses);
    }

    /**
     * The worker resumed the run first and it has ended: the cancel loses,
     * and the conversation shows what became of the run instead.
     */
    #[Test]
    public function aCancelThatLostShowsWhatTheRunDid(): void
    {
        $conversation = $this->waiting();

        self::assertFalse($this->service(run: $this->runWith(AgentRunStatus::COMPLETED->value, 'Fertig.'), cancelSucceeds: false)->cancelDependencyWait($conversation));

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame('Fertig.', $this->lastMessage($conversation)['content']);
    }

    #[Test]
    public function aConversationThatDoesNotWaitCancelsNothing(): void
    {
        $conversation = $this->waiting();
        $conversation->setStatus(ConversationStatus::Idle);

        self::assertFalse($this->service()->cancelDependencyWait($conversation));
        self::assertSame([], $this->cancelled);
    }
}
