<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\RunActivityRecorder;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A claimed turn stays claimed while it runs.
 *
 * The chat service runs against the real conversation table; only nr-llm is
 * replaced. While the (fake) agent runtime is inside the turn, a worker and a
 * second ai-chat:process try to take the same conversation, as they would in
 * production between the turn's start and its end.
 */
final class ChatServiceTurnClaimTest extends FunctionalTestCase
{
    // nr_mcp_agent depends on filelist (the FAL picker's element browser).
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        // Conversation 2 is the one turn waiting in 'processing'.
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_nrmcpagent_conversation.csv');
    }

    #[Test]
    public function noOtherConsumerCanTakeATurnWhileItRuns(): void
    {
        $repository = $this->get(ConversationRepository::class);
        $conversation = $repository->claimForProcess(2, 'process_1');
        self::assertNotNull($conversation);

        $duringTurn = [];
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->expects(self::once())->method('run')->willReturnCallback(
            function () use ($repository, &$duringTurn): AgentRunResult {
                $duringTurn = [
                    'status' => $repository->findByUid(2)?->getStatus(),
                    'worker' => $repository->dequeueForWorker('worker_1'),
                    'process' => $repository->claimForProcess(2, 'process_2'),
                ];

                return new AgentRunResult(
                    AgentRunOutcome::COMPLETED,
                    'run-uuid',
                    [],
                    new ToolLoopResult('Hi there!', [], 1, false, new UsageStatistics(10, 20, 30)),
                );
            },
        );

        $this->chatService($repository, $runtime)->processConversation($conversation);

        self::assertSame(ConversationStatus::Locked, $duringTurn['status']);
        self::assertNull($duringTurn['worker'], 'a worker dequeued a turn that was still running');
        self::assertNull($duringTurn['process'], 'a second ai-chat:process claimed a turn that was still running');
        self::assertSame(ConversationStatus::Idle, $repository->findByUid(2)?->getStatus());
    }

    private function chatService(ConversationRepository $repository, AgentRuntimeInterface $runtime): ChatService
    {
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);

        $configuration = $this->createMock(LlmConfiguration::class);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('getSystemPrompt')->willReturn('');
        $configuration->method('getLlmModel')->willReturn($this->createMock(LlmModel::class));
        $task = $this->createMock(Task::class);
        $task->method('getConfiguration')->willReturn($configuration);
        $task->method('getPromptTemplate')->willReturn('');
        $taskRepository = $this->createMock(TaskRepository::class);
        $taskRepository->method('findByUid')->willReturn($task);

        $adapterRegistry = $this->createMock(ProviderAdapterRegistryInterface::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($this->createMock(ProviderInterface::class));

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
        );
    }
}
