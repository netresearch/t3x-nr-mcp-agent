<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Service\RunActivityRecorder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The activity of the running turn, written step by step (NEXT-172).
 */
final class RunActivityRecorderTest extends TestCase
{
    /** @var list<string> what reached the activity column, in order */
    private array $written = [];

    private function recorder(): RunActivityRecorder
    {
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('updateActivity')->willReturnCallback(function (int $uid, string $json): void {
            $this->written[] = $json;
        });
        // The recorder must never write the whole row: the turn's final write owns it.
        $repository->expects(self::never())->method('update');
        $repository->expects(self::never())->method('updateIf');

        return new RunActivityRecorder($repository);
    }

    private function conversation(): Conversation
    {
        return Conversation::fromRow(['uid' => 7, 'be_user' => 1, 'activity' => '[{"kind":"llm","round":1,"ms":5}]']);
    }

    #[Test]
    public function aNewTurnClearsThePreviousActivity(): void
    {
        $conversation = $this->conversation();

        $this->recorder()->start($conversation);

        self::assertSame([], $conversation->getActivity());
        self::assertSame([''], $this->written);
    }

    #[Test]
    public function modelRoundsAndToolCallsAreRecordedAsTheyHappen(): void
    {
        $conversation = $this->conversation();
        $recorder = $this->recorder();
        $recorder->start($conversation);
        $onStep = $recorder->onStep($conversation);

        $onStep(new RunStep(kind: RunStep::KIND_LLM, round: 1, durationMs: 812.6));
        $onStep(new RunStep(
            kind: RunStep::KIND_TOOL,
            round: 1,
            durationMs: 40.2,
            toolName: 'read_records',
            toolArguments: ['table' => 'be_users', 'fields' => 'password'],
            toolResult: 'secret-hash',
            toolIsError: false,
        ));
        $onStep(new RunStep(kind: RunStep::KIND_TOOL, round: 2, durationMs: 3, toolName: 'create_page', toolIsError: true));

        self::assertSame([
            ['kind' => 'llm', 'round' => 1, 'ms' => 813],
            ['kind' => 'tool', 'round' => 1, 'ms' => 40, 'tool' => 'read_records', 'error' => false],
            ['kind' => 'tool', 'round' => 2, 'ms' => 3, 'tool' => 'create_page', 'error' => true],
        ], $conversation->getActivity());
        // Written once per step, so the poll sees it while the turn runs.
        self::assertCount(4, $this->written);
    }

    #[Test]
    public function argumentsAndResultsNeverReachTheColumn(): void
    {
        $conversation = $this->conversation();
        $recorder = $this->recorder();
        $onStep = $recorder->onStep($conversation);

        $onStep(new RunStep(
            kind: RunStep::KIND_TOOL,
            round: 1,
            durationMs: 1,
            toolName: 'read_records',
            toolArguments: ['table' => 'be_users'],
            toolResult: 'secret-hash',
        ));

        $last = end($this->written);
        self::assertIsString($last);
        self::assertStringNotContainsString('secret-hash', $last);
        self::assertStringNotContainsString('be_users', $last);
    }

    #[Test]
    public function stepsThatAreNotModelRoundsOrToolCallsAreLeftOut(): void
    {
        $conversation = $this->conversation();
        $recorder = $this->recorder();
        $recorder->start($conversation);
        $onStep = $recorder->onStep($conversation);

        $onStep(new RunStep(kind: RunStep::KIND_REQUEST, round: 0, durationMs: 0, messagesSent: [['role' => 'user', 'content' => 'x']]));
        $onStep(new RunStep(kind: RunStep::KIND_ASSEMBLED, round: 1, durationMs: 0));

        self::assertSame([], $conversation->getActivity());
    }

    #[Test]
    public function aDecisionContinuesTheListOfTheRunItBelongsTo(): void
    {
        $conversation = $this->conversation();

        $this->recorder()->recordDecision($conversation, true);

        self::assertSame([
            ['kind' => 'llm', 'round' => 1, 'ms' => 5],
            ['kind' => 'approval', 'approved' => true],
        ], $conversation->getActivity());
    }

    #[Test]
    public function theListIsCapped(): void
    {
        $conversation = $this->conversation();
        $recorder = $this->recorder();
        $onStep = $recorder->onStep($conversation);

        for ($i = 1; $i <= RunActivityRecorder::MAX_ENTRIES + 5; $i++) {
            $onStep(new RunStep(kind: RunStep::KIND_LLM, round: $i, durationMs: 1));
        }

        $activity = $conversation->getActivity();
        self::assertCount(RunActivityRecorder::MAX_ENTRIES, $activity);
        self::assertSame(RunActivityRecorder::MAX_ENTRIES + 5, $activity[RunActivityRecorder::MAX_ENTRIES - 1]['round']);
    }

    #[Test]
    public function aStoredListThatIsNotFlatIsReadDefensively(): void
    {
        self::assertSame([], Conversation::decodeActivity('not json'));
        self::assertSame([['kind' => 'tool']], Conversation::decodeActivity('[{"kind":"tool","args":{"a":1}}, 5]'));
    }

    /**
     * nr-llm calls the callback before it persists the step. An exception
     * from here would abort the run after the tool had run.
     */
    #[Test]
    public function aFailedWriteNeverReachesTheRuntime(): void
    {
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('updateActivity')->willThrowException(new RuntimeException('Lock wait timeout exceeded'));
        $recorder = new RunActivityRecorder($repository);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $recorder->setLogger($logger);
        $conversation = $this->conversation();

        $recorder->onStep($conversation)(new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 1, toolName: 'create_page'));

        // Still in memory: the next successful write carries it.
        self::assertSame('create_page', $conversation->getActivity()[1]['tool'] ?? null);
    }
}
