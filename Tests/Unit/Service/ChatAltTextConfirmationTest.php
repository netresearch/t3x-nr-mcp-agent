<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\Inbox\PendingCallView;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\AltText\AltTextContext;
use Netresearch\NrMcpAgent\Service\AltText\AltTextConversation;
use Netresearch\NrMcpAgent\Service\AltText\AltTextImage;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextList;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextScanner;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\RunActivityRecorder;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * After the card of a conversation about a missing alt text was approved,
 * the chat confirms the write itself (ADR-026): "Alt-Text gespeichert." or
 * "Als dekorativ markiert.", in place of what the model answered, when the
 * run recorded the write; a failed write keeps the model's answer, which says
 * why.
 */
#[CoversClass(ChatService::class)]
final class ChatAltTextConfirmationTest extends TestCase
{
    private function waiting(bool $aboutAnImage = true): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        if ($aboutAnImage) {
            $conversation->setAltTextHit(7);
        }

        $conversation->appendMessage(MessageRole::Assistant, '[Image card]', AltTextConversation::NOTICE_HIT, [], ['reference' => 7]);
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid('run-1');

        return $conversation;
    }

    /**
     * @param list<RunStep> $steps
     */
    private function answered(array $steps, string $answer = 'Alt-Text gespeichert.'): AgentRunResult
    {
        return new AgentRunResult(AgentRunOutcome::COMPLETED, 'run-1', $steps, new ToolLoopResult($answer, [], 1, false, new UsageStatistics(1, 1, 2)));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function service(AgentRunResult $answer, string $tool, array $arguments, bool $readsTheWaitingRun = true): ChatService
    {
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->method('approve')->willReturn($answer);
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('updateIf')->willReturn(true);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $reader = $this->createMock(PendingApprovalReaderInterface::class);
        $reader->expects($readsTheWaitingRun ? self::atLeastOnce() : self::never())->method('read')->willReturn(new WaitingRunView('run-1', WaitingRunView::MODE_APPROVAL, 0, 'Chat', 'digest', [
            new PendingCallView($tool, json_encode($arguments, JSON_THROW_ON_ERROR), true),
        ]));
        $flow = new AltTextConversation(
            $this->createMock(MissingAltTextScanner::class),
            $this->createMock(MissingAltTextList::class),
            $this->createMock(AltTextContext::class),
            $this->createMock(AltTextImage::class),
        );

        return new ChatService(
            $repository,
            $config,
            $runtime,
            $reader,
            $this->createMock(AgentRunRepositoryInterface::class),
            $this->createMock(TaskRepository::class),
            $this->createMock(ProviderAdapterRegistryInterface::class),
            $this->createMock(ResourceFactory::class),
            $this->createMock(SiteFinder::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->createMock(UserContextPrompt::class),
            $this->createMock(RunActivityRecorder::class),
            altText: $flow,
        );
    }

    /**
     * The approved call's tool step and its write.
     *
     * @return list<RunStep>
     */
    private function written(string $tool, bool $failed = false): array
    {
        return [
            new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 1.0, toolName: $tool, toolIsError: $failed),
            ...($failed ? [] : [new RunStep(kind: RunStep::KIND_WRITE, round: 1, durationMs: 1.0, writeTarget: new RecordReference('sys_file_reference', 7), writeCompleteness: WriteCompleteness::COMPLETE)]),
        ];
    }

    /**
     * @return list<string>
     */
    private function notices(Conversation $conversation): array
    {
        return array_map(static fn (array $message): string => is_string($message['notice'] ?? null) ? $message['notice'] : '', $conversation->getDecodedMessages());
    }

    #[Test]
    public function aSavedTextIsConfirmedByTheChatInPlaceOfTheModelsAnswer(): void
    {
        $conversation = $this->waiting();
        $service      = $this->service($this->answered($this->written('set_file_reference_alternative_text')), 'set_file_reference_alternative_text', ['reference' => 7, 'alternative' => 'Ein Team']);
        $service->recordDecision($conversation, true, 'digest');
        $service->processConversation($conversation);

        self::assertSame([AltTextConversation::NOTICE_HIT, AltTextConversation::NOTICE_SAVED], $this->notices($conversation));
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
    }

    #[Test]
    public function anApprovedDecorativeMarkIsConfirmedAsSuch(): void
    {
        $conversation = $this->waiting();
        $service      = $this->service($this->answered($this->written('set_file_reference_decorative')), 'set_file_reference_decorative', ['reference' => 7, 'decorative' => true]);
        $service->recordDecision($conversation, true, 'digest');
        $service->processConversation($conversation);

        self::assertSame([AltTextConversation::NOTICE_HIT, AltTextConversation::NOTICE_DECORATIVE], $this->notices($conversation));
    }

    /** "Speichern fehlgeschlagen": the model's sentence with the reason stays, the chat claims nothing. */
    #[Test]
    public function aFailedWriteKeepsTheModelsAnswer(): void
    {
        $conversation = $this->waiting();
        $service      = $this->service($this->answered($this->written('set_file_reference_alternative_text', failed: true), 'Das Bild liegt auf einer gesperrten Seite.'), 'set_file_reference_alternative_text', ['reference' => 7, 'alternative' => 'Ein Team']);
        $service->recordDecision($conversation, true, 'digest');
        $service->processConversation($conversation);

        self::assertSame([AltTextConversation::NOTICE_HIT, ''], $this->notices($conversation));
        self::assertSame('Das Bild liegt auf einer gesperrten Seite.', $conversation->getDecodedMessages()[1]['content']);
    }

    /** Any other conversation keeps the model's answer, as before, and its waiting run is not read for it. */
    #[Test]
    public function anOrdinaryConversationKeepsTheModelsAnswer(): void
    {
        $conversation = $this->waiting(aboutAnImage: false);
        $service      = $this->service($this->answered($this->written('set_file_reference_alternative_text'), 'Erledigt.'), 'set_file_reference_alternative_text', ['reference' => 7, 'alternative' => 'Ein Team'], readsTheWaitingRun: false);
        $service->recordDecision($conversation, true, 'digest');
        $service->processConversation($conversation);

        self::assertCount(2, $conversation->getDecodedMessages());
        self::assertNotContains(AltTextConversation::NOTICE_SAVED, $this->notices($conversation));
        self::assertSame('Erledigt.', $conversation->getDecodedMessages()[1]['content']);
    }
}
