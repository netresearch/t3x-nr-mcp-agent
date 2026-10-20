<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\DroppedSourceReason;
use Netresearch\NrLlm\Domain\Enum\ProcessInvocationRefusal;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\DroppedSource;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SkillInvocation as NrLlmInvocation;
use Netresearch\NrLlm\Domain\ValueObject\SubjectRecord;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\Exception\ProcessInvocationRefusedException;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\NrLlmSkillInvocation;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\RunActivityRecorder;
use Netresearch\NrMcpAgent\Service\SkillCatalogueInterface;
use Netresearch\NrMcpAgent\Service\SkillInvocation;
use Netresearch\NrMcpAgent\Service\SkillInvocationInterface;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Every run of a conversation carries its skill (ADR-019): a process skill as
 * an invocation once and its predecessor afterwards (nr-llm ADR-228), any
 * other skill as a forced skill on every turn.
 */
#[CoversClass(ChatService::class)]
#[CoversClass(NrLlmSkillInvocation::class)]
final class ChatServiceSkillTest extends TestCase
{
    private const PROCESS = ['identifier' => 'seo-page-tour', 'name' => 'SEO', 'description' => '', 'uid' => 42, 'process' => true];

    private const PLAIN = ['identifier' => 'tone-check', 'name' => 'Ton', 'description' => '', 'uid' => 18, 'process' => false];

    /** @var list<AgentRunRequest> */
    private array $requests = [];

    /** @var list<AgentRunResult> the results the runtime answers with, in order; the last one repeats */
    private array $outcomes = [];

    private ConversationRepository&MockObject $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ConversationRepository::class);
        $this->outcomes   = [self::completed('run-1')];
    }

    private static function completed(string $runUuid, array $steps = []): AgentRunResult
    {
        return new AgentRunResult(AgentRunOutcome::COMPLETED, $runUuid, $steps, new ToolLoopResult('ok', [], 1, false, new UsageStatistics(1, 1, 2)));
    }

    private function service(?SkillCatalogueInterface $skills, ?SkillInvocationInterface $invocation = null): ChatService
    {
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->method('run')->willReturnCallback(function (AgentRunRequest $request): AgentRunResult {
            $this->requests[] = $request;

            return count($this->outcomes) > 1 ? array_shift($this->outcomes) : $this->outcomes[0];
        });
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $configuration = $this->createMock(LlmConfiguration::class);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('getLlmModel')->willReturn($this->createMock(LlmModel::class));
        $task = $this->createMock(Task::class);
        $task->method('getConfiguration')->willReturn($configuration);
        $tasks = $this->createMock(TaskRepository::class);
        $tasks->method('findByUid')->willReturn($task);
        $adapters = $this->createMock(ProviderAdapterRegistryInterface::class);
        $adapters->method('createAdapterFromModel')->willReturn($this->createMock(ProviderInterface::class));

        return new ChatService(
            $this->repository,
            $config,
            $runtime,
            $this->createMock(PendingApprovalReaderInterface::class),
            $this->createMock(AgentRunRepositoryInterface::class),
            $tasks,
            $adapters,
            $this->createMock(ResourceFactory::class),
            $this->createMock(SiteFinder::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->createMock(UserContextPrompt::class),
            $this->createMock(RunActivityRecorder::class),
            skills: $skills,
            skillInvocation: $invocation,
        );
    }

    /**
     * @param list<array{identifier: string, name: string, description: string, uid: int, process: bool|null}> $entries
     */
    private function catalogue(array $entries = [self::PROCESS, self::PLAIN], ?RunAugmentation $augmentation = null): SkillCatalogueInterface&MockObject
    {
        $skills = $this->createMock(SkillCatalogueInterface::class);
        $skills->method('find')->willReturnCallback(static function (string $identifier) use ($entries): ?array {
            foreach ($entries as $entry) {
                if ($entry['identifier'] === $identifier) {
                    return $entry;
                }
            }

            return null;
        });
        $skills->method('augmentationFor')->willReturn($augmentation);

        return $skills;
    }

    private function conversation(string $skill, string $lastRun = ''): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setSkillIdentifier($skill, 42);
        $conversation->setLastRunUuid($lastRun);
        $conversation->appendMessage(MessageRole::User, 'Los geht es');

        return $conversation;
    }

    private function lastRequest(): AgentRunRequest
    {
        self::assertNotSame([], $this->requests, 'a run was started');

        return $this->requests[array_key_last($this->requests)];
    }

    #[Test]
    public function aPlainSkillIsForcedOnEveryTurnWithoutAPredecessor(): void
    {
        $augmentation = new RunAugmentation(forcedSkills: [new Skill()]);
        $invocation   = $this->createMock(SkillInvocationInterface::class);
        $invocation->expects(self::never())->method('withInvocation');

        $this->service($this->catalogue(augmentation: $augmentation), $invocation)->processConversation($this->conversation('tone-check', 'run-0'));

        self::assertSame($augmentation, $this->lastRequest()->augmentation);
        self::assertSame('', $this->lastRequest()->predecessorRunUuid);
        self::assertNull($this->lastRequest()->invocation);
    }

    #[Test]
    public function aSkillTheCatalogueCannotPassIsNoSkill(): void
    {
        $this->service($this->catalogue())->processConversation($this->conversation('gone'));

        self::assertNull($this->lastRequest()->augmentation);
        self::assertSame('', $this->lastRequest()->predecessorRunUuid);
    }

    /**
     * nr-llm ADR-214: the first run of a process conversation carries the
     * invocation — the skill's uid and the page in its language — and no
     * predecessor, and the skill is not also forced.
     */
    #[Test]
    public function theFirstTurnCarriesTheInvocationAndNoPredecessor(): void
    {
        $conversation = $this->conversation('seo-page-tour');
        $conversation->setViewContext(10, 'web_layout', 1);

        $this->service($this->catalogue(augmentation: new RunAugmentation(forcedSkills: [new Skill()])), new NrLlmSkillInvocation())->processConversation($conversation);

        self::assertEquals(new NrLlmInvocation(42, new SubjectRecord(new RecordReference('pages', 10), 1)), $this->lastRequest()->invocation);
        self::assertSame('', $this->lastRequest()->predecessorRunUuid);
        self::assertNull($this->lastRequest()->augmentation, 'not also a forced skill');
    }

    #[Test]
    public function theInvocationIsBuiltFromTheCataloguesUidAndThePage(): void
    {
        $invoked    = null;
        $invocation = $this->createMock(SkillInvocationInterface::class);
        $invocation->expects(self::once())->method('withInvocation')->willReturnCallback(
            static function (AgentRunRequest $request, SkillInvocation $given) use (&$invoked): AgentRunRequest {
                $invoked = $given;

                return $request;
            },
        );
        $conversation = $this->conversation('seo-page-tour');
        $conversation->setViewContext(10, 'web_layout');

        $this->service($this->catalogue(), $invocation)->processConversation($conversation);

        self::assertEquals(new SkillInvocation('seo-page-tour', 42, 'pages', 10, 0), $invoked);
    }

    /**
     * nr-llm ADR-228: a later turn names the previous run and nr-llm derives
     * the process from it; the invocation does not go out again.
     */
    #[Test]
    public function aLaterTurnNamesItsPredecessorAndCarriesNoInvocation(): void
    {
        $invocation = $this->createMock(SkillInvocationInterface::class);
        $invocation->expects(self::never())->method('withInvocation');

        $this->service($this->catalogue(), $invocation)->processConversation($this->conversation('seo-page-tour', 'run-0'));

        self::assertSame('run-0', $this->lastRequest()->predecessorRunUuid);
        self::assertNull($this->lastRequest()->invocation);
        self::assertNull($this->lastRequest()->augmentation);
    }

    /** A skill that left the catalogue still names the predecessor, so nr-llm can say it dropped the process. */
    #[Test]
    public function aSkillThatLeftTheCatalogueStillNamesThePredecessor(): void
    {
        $this->service($this->catalogue([]), new NrLlmSkillInvocation())->processConversation($this->conversation('seo-page-tour', 'run-0'));

        self::assertSame('run-0', $this->lastRequest()->predecessorRunUuid);
        self::assertNull($this->lastRequest()->invocation);
    }

    #[Test]
    public function aConversationWithoutASkillNamesNoPredecessor(): void
    {
        $this->service($this->catalogue(), new NrLlmSkillInvocation())->processConversation($this->conversation('', 'run-0'));

        self::assertSame('', $this->lastRequest()->predecessorRunUuid);
        self::assertNull($this->lastRequest()->invocation);
    }

    /**
     * @return iterable<string, array{AgentRunResult}>
     */
    public static function recordedRuns(): iterable
    {
        yield 'completed' => [self::completed('run-7')];
        yield 'failed' => [new AgentRunResult(AgentRunOutcome::FAILED, 'run-7', [], error: new RuntimeException('provider down'))];
        yield 'stopped' => [new AgentRunResult(AgentRunOutcome::CANCELLED, 'run-7', [])];
        yield 'refused by the process checks' => [new AgentRunResult(AgentRunOutcome::FAILED, 'run-7', [], error: ProcessInvocationRefusedException::forRun('run-7', ProcessInvocationRefusal::PIN_WITHDRAWN))];
        yield 'blocked by a guardrail' => [new AgentRunResult(AgentRunOutcome::GUARDRAIL_BLOCKED, 'run-7', [], guardrailClass: 'x', error: new RuntimeException('blocked'))];
        yield 'waiting' => [new AgentRunResult(AgentRunOutcome::AWAITING_APPROVAL, 'run-7', [])];
    }

    /**
     * Whatever the outcome, the run is the one the next turn follows: nr-llm
     * decides from its lineage whether the process goes on.
     */
    #[Test]
    #[DataProvider('recordedRuns')]
    public function everyRecordedRunIsStoredAndNamedByTheNextTurn(AgentRunResult $first): void
    {
        $this->outcomes = [$first, self::completed('run-8')];
        $conversation   = $this->conversation('seo-page-tour');
        $service        = $this->service($this->catalogue(), new NrLlmSkillInvocation());

        $service->processConversation($conversation);
        self::assertSame('run-7', $conversation->getLastRunUuid());

        $service->processConversation($conversation);
        self::assertSame('run-7', $this->lastRequest()->predecessorRunUuid);
        self::assertNull($this->lastRequest()->invocation);
        self::assertSame('run-8', $conversation->getLastRunUuid());
    }

    #[Test]
    public function aRunNrLlmCouldNotRecordLeavesThePreviousOne(): void
    {
        $this->outcomes = [new AgentRunResult(AgentRunOutcome::FAILED, '', [], error: new RuntimeException('not recorded'))];
        $conversation   = $this->conversation('seo-page-tour', 'run-0');

        $this->service($this->catalogue(), new NrLlmSkillInvocation())->processConversation($conversation);

        self::assertSame('run-0', $conversation->getLastRunUuid());
    }

    /**
     * A process skill picked mid-conversation starts on the next turn, as a
     * new start without a predecessor, exactly once.
     */
    #[Test]
    public function aPickedProcessSkillStartsOnTheNextTurnOnce(): void
    {
        $this->outcomes = [self::completed('run-1'), self::completed('run-2')];
        $conversation   = $this->conversation('seo-page-tour', 'run-0');
        $conversation->setSkillStartPending(true);
        $this->repository->expects(self::once())->method('clearSkillStartPending');
        $service = $this->service($this->catalogue(), new NrLlmSkillInvocation());

        $service->processConversation($conversation);
        self::assertNotNull($this->lastRequest()->invocation);
        self::assertSame('', $this->lastRequest()->predecessorRunUuid);
        self::assertFalse($conversation->isSkillStartPending());

        $service->processConversation($conversation);
        self::assertNull($this->lastRequest()->invocation);
        self::assertSame('run-1', $this->lastRequest()->predecessorRunUuid);
    }

    #[Test]
    public function theStartMarkerStaysWhenTheRunWasNotRecorded(): void
    {
        $this->outcomes = [new AgentRunResult(AgentRunOutcome::FAILED, '', [], error: new RuntimeException('not recorded'))];
        $conversation   = $this->conversation('seo-page-tour', 'run-0');
        $conversation->setSkillStartPending(true);
        $this->repository->expects(self::never())->method('clearSkillStartPending');

        $this->service($this->catalogue(), new NrLlmSkillInvocation())->processConversation($conversation);

        self::assertTrue($conversation->isSkillStartPending());
    }

    /**
     * nr-llm ADR-228: a detached skill drops the process with a `dropped`
     * step. The chat says so once, before the answer, and keeps the line out
     * of the model's transcript.
     */
    #[Test]
    public function aDroppedProcessIsSaidOnceBeforeTheAnswer(): void
    {
        $dropped = new RunStep(RunStep::KIND_DROPPED, 0, 0.0, droppedSources: [
            new DroppedSource('skill', 3, DroppedSourceReason::GONE),
            new DroppedSource('process', 42, DroppedSourceReason::DETACHED),
        ]);
        $this->outcomes = [self::completed('run-1', [$dropped]), self::completed('run-2')];
        $conversation   = $this->conversation('seo-page-tour', 'run-0');
        $service        = $this->service($this->catalogue(), new NrLlmSkillInvocation());

        $service->processConversation($conversation);

        $messages = $conversation->getDecodedMessages();
        self::assertCount(3, $messages);
        self::assertSame('processDropped', $messages[1]['notice'] ?? null);
        self::assertSame(['detached'], $messages[1]['noticeArgs'] ?? null);
        self::assertSame('ok', $messages[2]['content']);

        $service->processConversation($conversation);
        $sent = array_map(
            static fn (mixed $message): string => is_array($message) && is_string($message['content'] ?? null) ? $message['content'] : '',
            $this->lastRequest()->messages,
        );
        self::assertNotContains($messages[1]['content'], $sent, 'the model is not sent the line');
        $lines = array_filter($conversation->getDecodedMessages(), static fn (array $message): bool => ($message['notice'] ?? null) === 'processDropped');
        self::assertCount(1, $lines, 'the next turn adds no second line');
    }

    #[Test]
    public function aDroppedSourceThatIsNoProcessAddsNoLine(): void
    {
        $dropped        = new RunStep(RunStep::KIND_DROPPED, 0, 0.0, droppedSources: [new DroppedSource('skill', 3, DroppedSourceReason::DEACTIVATED)]);
        $this->outcomes = [self::completed('run-1', [$dropped])];
        $conversation   = $this->conversation('seo-page-tour', 'run-0');

        $this->service($this->catalogue(), new NrLlmSkillInvocation())->processConversation($conversation);

        self::assertCount(2, $conversation->getDecodedMessages());
    }

    #[Test]
    public function withoutTheAdapterTheRunGoesWithoutASkill(): void
    {
        $this->service(null)->processConversation($this->conversation('seo-page-tour'));

        self::assertNull($this->lastRequest()->augmentation);
        self::assertNull($this->lastRequest()->invocation);
    }
}
