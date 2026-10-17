<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\Exception\StaleApprovalTurnException;
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
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The worker records what became of a proposal of a guided process once
 * nr-llm has accepted the decision (ADR-023), where the transcript stands
 * before the model's answer, and only for a process card.
 */
#[CoversClass(ChatService::class)]
final class ChatProposalOutcomeTest extends TestCase
{
    private const CARD = ['tool' => 'update_page_metadata', 'table' => 'pages', 'uid' => 3, 'fields' => ['description']];

    private function waiting(): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Seite prüfen');
        $conversation->appendMessage(MessageRole::Assistant, 'Ich schlage eine Beschreibung vor.');
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid('run-1');

        return $conversation;
    }

    /**
     * @param list<RunStep> $steps
     */
    private static function answered(array $steps = []): AgentRunResult
    {
        return new AgentRunResult(AgentRunOutcome::COMPLETED, 'run-1', $steps, new ToolLoopResult('Nächster Punkt.', [], 1, false, new UsageStatistics(1, 1, 2)));
    }

    private function service(AgentRunResult|RuntimeException $answer): ChatService
    {
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->method('approve')->willReturnCallback(static function () use ($answer): AgentRunResult {
            if ($answer instanceof RuntimeException) {
                throw $answer;
            }

            return $answer;
        });
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('updateIf')->willReturn(true);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);

        return new ChatService(
            $repository,
            $config,
            $runtime,
            $this->createMock(PendingApprovalReaderInterface::class),
            $this->createMock(AgentRunRepositoryInterface::class),
            $this->createMock(TaskRepository::class),
            $this->createMock(ProviderAdapterRegistryInterface::class),
            $this->createMock(ResourceFactory::class),
            $this->createMock(SiteFinder::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->createMock(UserContextPrompt::class),
            $this->createMock(RunActivityRecorder::class),
        );
    }

    /** The approved call's tool step and write step, as nr-llm before 0.41 writes them. */
    private static function writeSteps(): array
    {
        return [
            new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 1.0, toolName: 'update_page_metadata', toolIsError: false),
            new RunStep(kind: RunStep::KIND_WRITE, round: 1, durationMs: 1.0, writeTarget: new RecordReference('pages', 3)),
        ];
    }

    #[Test]
    public function anApprovedProposalIsRecordedBeforeTheModelsAnswer(): void
    {
        $conversation = $this->waiting();
        $service = $this->service(self::answered(self::writeSteps()));
        $service->recordDecision($conversation, true, 'digest', card: self::CARD);
        $service->processConversation($conversation);

        $outcomes = $conversation->getCardOutcomes();
        self::assertCount(1, $outcomes);
        // nr-llm before 0.41 states no completeness: "check"; 0.41 with an
        // unstated completeness reads the same (ApprovedCallReadingTest).
        self::assertSame('check', $outcomes[0]['outcome']);
        self::assertSame(2, $outcomes[0]['after'], 'before the model\'s answer, which is message 3');
        self::assertSame('pages', $outcomes[0]['table']);
        self::assertSame(3, $conversation->getMessageCount());
        self::assertNull($conversation->getApprovalCard(), 'the card goes with the decision');
    }

    #[Test]
    public function aSkippedProposalIsRecordedAsSkippedAfterTheReadersAnswer(): void
    {
        $conversation = $this->waiting();
        $service = $this->service(self::answered());
        $service->recordDecision($conversation, false, 'digest', DenyReason::Skip, 'Überspringen', self::CARD);
        $service->processConversation($conversation);

        self::assertSame([['outcome' => 'skipped', 'after' => 3] + self::CARD], $conversation->getCardOutcomes());
    }

    #[Test]
    public function aVariantIsRecordedAsSuch(): void
    {
        $conversation = $this->waiting();
        $service = $this->service(self::answered());
        $service->recordDecision($conversation, false, 'digest', DenyReason::Variant, 'Andere Variante', self::CARD);
        $service->processConversation($conversation);

        self::assertSame('variant', $conversation->getCardOutcomes()[0]['outcome'] ?? null);
    }

    /** An ordinary card has no card snapshot and records nothing. */
    #[Test]
    public function anOrdinaryCardRecordsNothing(): void
    {
        $conversation = $this->waiting();
        $service = $this->service(self::answered(self::writeSteps()));
        $service->recordDecision($conversation, true, 'digest');
        $service->processConversation($conversation);

        self::assertSame([], $conversation->getCardOutcomes());
    }

    /** An approval whose call did not run records nothing: there is nothing to say about it. */
    #[Test]
    public function anApprovalWithoutAToolStepRecordsNothing(): void
    {
        $conversation = $this->waiting();
        $service = $this->service(self::answered());
        $service->recordDecision($conversation, true, 'digest', card: self::CARD);
        $service->processConversation($conversation);

        self::assertSame([], $conversation->getCardOutcomes());
    }

    #[Test]
    public function aRefusedDecisionRecordsNothing(): void
    {
        $conversation = $this->waiting();
        $service = $this->service(new StaleApprovalTurnException('run-1', 'The review is stale'));
        $service->recordDecision($conversation, false, 'digest', DenyReason::Skip, 'Überspringen', self::CARD);
        $service->processConversation($conversation);

        self::assertSame([], $conversation->getCardOutcomes());
        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus());
    }
}
