<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Domain\Model\Skill;
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
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\RunActivityRecorder;
use Netresearch\NrMcpAgent\Service\SkillCatalogueInterface;
use Netresearch\NrMcpAgent\Service\SkillInvocation;
use Netresearch\NrMcpAgent\Service\SkillInvocationInterface;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Every run of a conversation carries its skill (ADR-019).
 */
#[CoversClass(ChatService::class)]
final class ChatServiceSkillTest extends TestCase
{
    private ?AgentRunRequest $request = null;

    private function service(?SkillCatalogueInterface $skills, ?SkillInvocationInterface $invocation = null): ChatService
    {
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->method('run')->willReturnCallback(function (AgentRunRequest $request): AgentRunResult {
            $this->request = $request;

            return new AgentRunResult(AgentRunOutcome::COMPLETED, 'run', [], new ToolLoopResult('ok', [], 1, false, new UsageStatistics(1, 1, 2)));
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
            $this->createMock(ConversationRepository::class),
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

    private static function conversation(string $skill, int $skillUid = 0): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setSkillIdentifier($skill, $skillUid);
        $conversation->appendMessage(MessageRole::User, 'Los geht es');

        return $conversation;
    }

    #[Test]
    public function theRunCarriesTheConversationsSkill(): void
    {
        $augmentation = new RunAugmentation(forcedSkills: [new Skill()]);
        $skills = $this->createMock(SkillCatalogueInterface::class);
        $skills->expects(self::once())->method('augmentationFor')->with('seo-page-tour')->willReturn($augmentation);

        $this->service($skills)->processConversation(self::conversation('seo-page-tour'));

        self::assertSame($augmentation, $this->request?->augmentation);
    }

    #[Test]
    public function aSkillTheAdapterCannotPassIsNoSkill(): void
    {
        $skills = $this->createMock(SkillCatalogueInterface::class);
        $skills->method('augmentationFor')->willReturn(null);

        $this->service($skills)->processConversation(self::conversation('gone'));

        self::assertNotNull($this->request);
        self::assertNull($this->request->augmentation);
    }

    /**
     * nr-llm ADR-214: a process skill starts as an invocation with the
     * record it is about — the conversation's page — not as a forced skill.
     */
    #[Test]
    public function anInvocationCarriesTheSkillAndThePage(): void
    {
        $invoked = null;
        $invocation = $this->createMock(SkillInvocationInterface::class);
        $invocation->expects(self::once())->method('withInvocation')->willReturnCallback(
            static function (AgentRunRequest $request, SkillInvocation $given) use (&$invoked): AgentRunRequest {
                $invoked = $given;

                return $request;
            },
        );
        $skills = $this->createMock(SkillCatalogueInterface::class);
        $skills->expects(self::never())->method('augmentationFor');
        $conversation = self::conversation('seo-page-tour', 42);
        $conversation->setViewContext(10, 'web_layout');

        $this->service($skills, $invocation)->processConversation($conversation);

        self::assertEquals(new SkillInvocation('seo-page-tour', 42, 'pages', 10), $invoked);
        self::assertNull($this->request?->augmentation, 'not also a forced skill');
    }

    /** Until nr-llm can take an invocation, the skill goes as a forced skill. */
    #[Test]
    public function withoutAnInvocationTheSkillIsForced(): void
    {
        $augmentation = new RunAugmentation(forcedSkills: [new Skill()]);
        $invocation = $this->createMock(SkillInvocationInterface::class);
        $invocation->method('withInvocation')->willReturn(null);
        $skills = $this->createMock(SkillCatalogueInterface::class);
        $skills->method('augmentationFor')->willReturn($augmentation);

        $this->service($skills, $invocation)->processConversation(self::conversation('seo-page-tour'));

        self::assertSame($augmentation, $this->request?->augmentation);
    }

    #[Test]
    public function withoutTheAdapterTheRunGoesWithoutASkill(): void
    {
        $this->service(null)->processConversation(self::conversation('seo-page-tour'));

        self::assertNotNull($this->request);
        self::assertNull($this->request->augmentation);
    }
}
