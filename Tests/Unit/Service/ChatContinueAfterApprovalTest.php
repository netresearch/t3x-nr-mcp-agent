<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\DenyReason;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\RunActivityRecorder;
use Netresearch\NrMcpAgent\Service\SkillCatalogueInterface;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * An approved proposal whose run then ends its step without a choice, a
 * proposal or the end of the process is followed by one turn the chat starts
 * itself, the "Weiter" the editor would have pressed (ADR-023, amendment of
 * 2026-10-23). The turn happens at most once per approval, and not after a
 * denial, at the round cap, or in a process that is finished.
 */
#[CoversClass(ChatService::class)]
final class ChatContinueAfterApprovalTest extends TestCase
{
    private const TOUR = ['identifier' => '9404:inhalt-verbessern', 'name' => 'Inhalt verbessern', 'description' => '', 'uid' => 9423, 'process' => true];

    private const PLAIN = ['identifier' => '9404:tone-check', 'name' => 'Ton', 'description' => '', 'uid' => 18, 'process' => false];

    /** @var list<AgentRunRequest> */
    private array $runs = [];

    /** @var list<string> the status each stored row had, in order, with 'approve' and 'run' markers where nr-llm was called */
    private array $stored = [];

    private int $approvals = 0;

    /**
     * @param AgentRunResult                              $approved  what nr-llm answers the decision with
     * @param array<int, AgentRunResult|RuntimeException> $continued what the turn started after it answers with
     */
    private function service(AgentRunResult $approved, array $continued = []): ChatService
    {
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->method('approve')->willReturnCallback(function () use ($approved): AgentRunResult {
            ++$this->approvals;
            $this->stored[] = 'approve';

            return $approved;
        });
        $queue = $continued;
        $runtime->method('run')->willReturnCallback(function (AgentRunRequest $request) use (&$queue): AgentRunResult {
            $this->runs[]   = $request;
            $this->stored[] = 'run';
            $next           = array_shift($queue);
            if ($next instanceof RuntimeException) {
                throw $next;
            }

            return $next ?? new AgentRunResult(AgentRunOutcome::AWAITING_APPROVAL, 'run-2', []);
        });

        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('updateIf')->willReturn(true);
        $repository->method('update')->willReturnCallback(function (Conversation $conversation): void {
            $this->stored[] = $conversation->getStatus()->value;
        });

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $config->method('getProcessRunMaxIterations')->willReturn(12);

        $configuration = $this->createMock(LlmConfiguration::class);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('getLlmModel')->willReturn($this->createMock(LlmModel::class));
        $task = $this->createMock(Task::class);
        $task->method('getConfiguration')->willReturn($configuration);
        $taskRepository = $this->createMock(TaskRepository::class);
        $taskRepository->method('findByUid')->willReturn($task);
        $adapterRegistry = $this->createMock(ProviderAdapterRegistryInterface::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($this->createMock(ProviderInterface::class));

        $skills = $this->createMock(SkillCatalogueInterface::class);
        $skills->method('find')->willReturnCallback(static fn (string $identifier): ?array => match ($identifier) {
            self::TOUR['identifier']  => self::TOUR,
            self::PLAIN['identifier'] => self::PLAIN,
            default                   => null,
        });

        return new ChatService(
            $repository,
            $config,
            $runtime,
            $this->createMock(PendingApprovalReaderInterface::class),
            $this->createMock(AgentRunRepositoryInterface::class),
            $taskRepository,
            $adapterRegistry,
            $this->createMock(ResourceFactory::class),
            $this->createMock(SiteFinder::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->createMock(UserContextPrompt::class),
            $this->createMock(RunActivityRecorder::class),
            skills: $skills,
        );
    }

    private function completed(string $text, AgentRunTerminationReason $reason = AgentRunTerminationReason::COMPLETED): AgentRunResult
    {
        return new AgentRunResult(
            AgentRunOutcome::COMPLETED,
            'run-1',
            [],
            new ToolLoopResult($text, [], 2, $reason === AgentRunTerminationReason::MAX_ITERATIONS, new UsageStatistics(1, 1, 2), $reason),
        );
    }

    private function waiting(string $skill = self::TOUR['identifier'], bool $completed = false): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        if ($skill !== '') {
            $conversation->setSkillIdentifier($skill, 9423);
        }

        $conversation->setGuidedState(['label' => 'Seite', 'current' => 1, 'total' => 3, 'completed' => $completed], null);
        $conversation->appendMessage(MessageRole::User, 'Diese Seite prüfen');
        $conversation->appendMessage(MessageRole::Assistant, 'Ich schlage eine Beschreibung vor.');
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid('run-1');

        return $conversation;
    }

    /**
     * @return list<string|null>
     */
    private function notices(Conversation $conversation): array
    {
        return array_values(array_filter(
            array_map(static fn (array $message): mixed => $message['notice'] ?? null, $conversation->getDecodedMessages()),
            static fn (mixed $notice): bool => $notice !== null,
        ));
    }

    /**
     * @return list<string>
     */
    private function texts(Conversation $conversation): array
    {
        return array_map(static fn (array $message): string => (string) ($message['content'] ?? ''), $conversation->getDecodedMessages());
    }

    private function approve(ChatService $service, Conversation $conversation): void
    {
        $service->recordDecision($conversation, true, 'digest');
        $service->processConversation($conversation);
    }

    /** The case this is for: the editor approves, the model writes, and the step is open. */
    #[Test]
    public function anApprovalThatLeavesTheStepOpenIsFollowedByOneTurnAndNoLine(): void
    {
        $conversation = $this->waiting();
        $this->approve($this->service($this->completed('Das ist der Stand.')), $conversation);

        self::assertSame(1, $this->approvals);
        self::assertCount(1, $this->runs, 'one continuation turn, started by the chat');
        self::assertSame([], $this->notices($conversation), 'no "step open" line');
        self::assertSame(['Diese Seite prüfen', 'Ich schlage eine Beschreibung vor.', 'Das ist der Stand.'], $this->texts($conversation));
        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus(), 'the continuation proposed the next change');
        self::assertSame('run-2', $conversation->getApprovalRunUuid());
    }

    /** The "Weiter" is the model's instruction for that turn only: the transcript stays the editor's. */
    #[Test]
    public function theModelIsToldToContinueForThatTurnAndNothingIsStored(): void
    {
        $conversation = $this->waiting();
        $this->approve($this->service($this->completed('Das ist der Stand.')), $conversation);

        $wire = array_values(array_filter($this->runs[0]->messages, is_array(...)));
        $last = $wire[array_key_last($wire)];
        self::assertSame(['user', 'Continue with the process.'], [$last['role'], $last['content']]);
        self::assertNotContains('Continue with the process.', $this->texts($conversation));
        self::assertNotContains(MessageRole::User->value . ':Continue with the process.', array_map(static fn (array $m): string => ($m['role'] ?? '') . ':' . ($m['content'] ?? ''), $conversation->getDecodedMessages()));
    }

    /** No idle moment between the two runs: the chat's poll stops on an idle conversation and would miss the turn. */
    #[Test]
    public function theConversationIsNeverIdleBetweenTheApprovalAndTheContinuation(): void
    {
        $conversation = $this->waiting();
        $this->approve($this->service($this->completed('Das ist der Stand.')), $conversation);

        $run = array_search('run', $this->stored, true);
        self::assertIsInt($run, 'the continuation ran');
        self::assertNotContains(ConversationStatus::Idle->value, array_slice($this->stored, 0, $run));
        self::assertSame(ConversationStatus::AwaitingApproval->value, $this->stored[array_key_last($this->stored)]);
    }

    /** At most once per approval: a second open step shows the line and the button. */
    #[Test]
    public function aContinuationThatLeavesTheStepOpenAgainShowsTheLineAndStartsNothingMore(): void
    {
        $conversation = $this->waiting();
        $this->approve($this->service($this->completed('Das ist der Stand.'), [$this->completed('Noch offen.')]), $conversation);

        self::assertSame(1, $this->approvals);
        self::assertCount(1, $this->runs, 'no second continuation');
        self::assertSame([ChatService::NOTICE_STEP_OPEN], $this->notices($conversation));
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
    }

    /** A continuation that fails ends as any failed turn: nothing is retried by the chat. */
    #[Test]
    public function aFailingContinuationFailsTheConversationAndIsNotRepeated(): void
    {
        $conversation = $this->waiting();
        $this->approve($this->service($this->completed('Das ist der Stand.'), [new RuntimeException('provider down')]), $conversation);

        self::assertCount(1, $this->runs);
        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertSame([], $this->notices($conversation));
        self::assertContains('Das ist der Stand.', $this->texts($conversation), 'what the approval produced stays');
    }

    /**
     * @return iterable<string, array{DenyReason|null}>
     */
    public static function denials(): iterable
    {
        yield 'Andere Variante' => [DenyReason::Variant];
        yield 'Überspringen' => [DenyReason::Skip];
        yield 'plain denial' => [null];
    }

    /** A denial is the editor's answer: nothing follows by itself, and the open step is reported as before. */
    #[Test]
    #[DataProvider('denials')]
    public function aDenialStartsNothingAndKeepsTheLine(?DenyReason $reason): void
    {
        $conversation = $this->waiting();
        $service      = $this->service($this->completed('Gut.'));
        $service->recordDecision($conversation, false, 'digest', $reason, $reason instanceof DenyReason ? 'Antwort' : '');
        $service->processConversation($conversation);

        self::assertSame([], $this->runs);
        // The reader's answer is stored with its own notice, the open step is reported once.
        self::assertCount(1, array_keys($this->notices($conversation), ChatService::NOTICE_STEP_OPEN, true));
    }

    /** The round cap has its own line and its own button. */
    #[Test]
    public function anApprovalThatStoppedAtTheRoundCapStartsNothing(): void
    {
        $conversation = $this->waiting();
        $this->approve($this->service($this->completed('', AgentRunTerminationReason::MAX_ITERATIONS)), $conversation);

        self::assertSame([], $this->runs);
        self::assertSame([ChatService::NOTICE_ROUND_LIMIT], $this->notices($conversation));
    }

    /** A finished process, and a skill that is no process, have no step to continue. */
    #[Test]
    public function aFinishedOrPlainConversationStartsNothing(): void
    {
        foreach ([[self::TOUR['identifier'], true], [self::PLAIN['identifier'], false], ['', false]] as [$skill, $completed]) {
            $this->runs   = [];
            $conversation = $this->waiting($skill, $completed);
            $this->approve($this->service($this->completed('Fertig.')), $conversation);

            self::assertSame([], $this->runs, 'skill "' . $skill . '"');
            self::assertSame([], $this->notices($conversation), 'skill "' . $skill . '"');
        }
    }
}
