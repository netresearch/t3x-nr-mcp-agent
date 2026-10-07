<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Command;

use Doctrine\DBAL\Result;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrMcpAgent\Command\ProcessChatCommand;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\RunActivityRecorder;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ProcessChatCommandTest extends TestCase
{
    private function createChatService(?RunActivityRecorder $activityRecorder = null): ChatService
    {
        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(0);

        $configuration = $this->createMock(LlmConfiguration::class);
        // The chat checks the Configuration is active before it runs it.
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('getLlmModel')->willReturn($this->createMock(LlmModel::class));
        $task = $this->createMock(Task::class);
        $task->method('getConfiguration')->willReturn($configuration);
        $taskRepository = $this->createMock(TaskRepository::class);
        $taskRepository->method('findByUid')->willReturn($task);

        $adapterRegistry = $this->createMock(ProviderAdapterRegistryInterface::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($this->createMock(ProviderInterface::class));

        return new ChatService($repository, $config, $this->createMock(AgentRuntimeInterface::class), $this->createMock(PendingApprovalReaderInterface::class), $this->createMock(AgentRunRepositoryInterface::class), $taskRepository, $adapterRegistry, $this->createMock(ResourceFactory::class), $this->createMock(SiteFinder::class), new DocumentExtractorRegistry([]), new UploadMimeTypeMap(), $this->createMock(UserContextPrompt::class), $activityRecorder ?? $this->createMock(RunActivityRecorder::class));
    }

    #[Test]
    public function executeFailsWhenConversationNotFound(): void
    {
        $chatService = $this->createChatService();
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('findByUid')->willReturn(null);
        $connectionPool = $this->createMock(ConnectionPool::class);

        $command = new ProcessChatCommand($chatService, $repository, $connectionPool);

        $input = new ArrayInput(['conversationUid' => '999']);
        $input->bind($command->getDefinition());

        $output = new BufferedOutput();
        $result = $command->run($input, $output);

        self::assertSame(1, $result);
        self::assertStringContainsString('not found', $output->fetch());
    }

    #[Test]
    public function executeLeavesAConversationNotInProcessingStateAlone(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 1,
            'be_user' => 1,
            'status' => 'idle',
            'messages' => '[]',
            'message_count' => 0,
        ]);

        $chatService = $this->createChatService();
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('findByUid')->willReturn($conversation);
        $connectionPool = $this->createMock(ConnectionPool::class);

        $command = new ProcessChatCommand($chatService, $repository, $connectionPool);

        $input = new ArrayInput(['conversationUid' => '1']);
        $input->bind($command->getDefinition());

        $output = new BufferedOutput();
        $result = $command->run($input, $output);

        self::assertSame(0, $result);
        self::assertStringContainsString('not in processing state', $output->fetch());
    }

    #[Test]
    public function executeLeavesAFailedConversationAlone(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 2,
            'be_user' => 1,
            'status' => 'failed',
            'messages' => '[]',
            'message_count' => 0,
        ]);

        $chatService = $this->createChatService();
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('findByUid')->willReturn($conversation);
        $connectionPool = $this->createMock(ConnectionPool::class);

        $command = new ProcessChatCommand($chatService, $repository, $connectionPool);

        $input = new ArrayInput(['conversationUid' => '2']);
        $input->bind($command->getDefinition());

        $output = new BufferedOutput();
        $result = $command->run($input, $output);

        self::assertSame(0, $result);
        self::assertStringContainsString('not in processing state', $output->fetch());
    }

    #[Test]
    public function executeLeavesALockedConversationAlone(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 3,
            'be_user' => 1,
            'status' => 'locked',
            'messages' => '[]',
            'message_count' => 0,
        ]);

        $chatService = $this->createChatService();
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('findByUid')->willReturn($conversation);
        $connectionPool = $this->createMock(ConnectionPool::class);

        $command = new ProcessChatCommand($chatService, $repository, $connectionPool);

        $input = new ArrayInput(['conversationUid' => '3']);
        $input->bind($command->getDefinition());

        $output = new BufferedOutput();
        $result = $command->run($input, $output);

        self::assertSame(0, $result);
    }

    #[Test]
    public function aLostClaimDoesNotProcessTheTurnEvenIfTheRowStillReadsProcessing(): void
    {
        // The race: the row read a moment ago said processing, but a worker
        // (or a second ai-chat:process) claimed it in between.
        $conversation = Conversation::fromRow([
            'uid' => 5,
            'be_user' => 1,
            'status' => 'processing',
            'messages' => '[{"role":"user","content":"Hello"}]',
            'message_count' => 1,
        ]);

        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('findByUid')->willReturn($conversation);
        $repository->expects(self::once())
            ->method('claimForProcess')
            ->with(5, self::stringStartsWith('process_'))
            ->willReturn(null);
        $repository->expects(self::never())->method('update');
        $repository->expects(self::never())->method('updateIf');

        $command = new ProcessChatCommand($this->createChatService(), $repository, $this->createMock(ConnectionPool::class));
        $input = new ArrayInput(['conversationUid' => '5']);
        $input->bind($command->getDefinition());
        $output = new BufferedOutput();

        self::assertSame(0, $command->run($input, $output));
        self::assertStringContainsString('not in processing state', $output->fetch());
    }

    #[Test]
    public function aFailedTurnWritesTheSanitisedMessageToTheOutput(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 6,
            'be_user' => 1,
            'status' => 'processing',
            'messages' => '[{"role":"user","content":"Hello"}]',
            'message_count' => 1,
        ]);
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('claimForProcess')->willReturn($conversation);
        // The turn fails once it has started, with a message that carries
        // provider details.
        $activityRecorder = $this->createMock(RunActivityRecorder::class);
        $activityRecorder->method('start')->willThrowException(new RuntimeException('Provider said: Incorrect API key provided: sk-proj-abcdefghijklmnop for https://api.example.test/v1/chat?key=raw-secret-value <info>'));
        GeneralUtility::addInstance(BackendUserAuthentication::class, $this->createMock(BackendUserAuthentication::class));

        $command = new ProcessChatCommand($this->createChatService($activityRecorder), $repository, $this->connectionPoolWithBackendUser());
        $input = new ArrayInput(['conversationUid' => '6']);
        $input->bind($command->getDefinition());
        $output = new BufferedOutput();

        try {
            self::assertSame(1, $command->run($input, $output));
        } finally {
            GeneralUtility::purgeInstances();
            unset($GLOBALS['BE_USER']);
        }

        $written = $output->fetch();
        self::assertStringContainsString('Error: Provider said: Incorrect API key provided: [REDACTED] for [URL]', $written);
        self::assertStringNotContainsString('sk-proj-abcdefghijklmnop', $written);
        self::assertStringNotContainsString('raw-secret-value', $written);
        self::assertSame('Provider said: Incorrect API key provided: [REDACTED] for [URL] <info>', $conversation->getErrorMessage());
    }

    private function connectionPoolWithBackendUser(): ConnectionPool
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1 = 1');
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['uid' => 1, 'username' => 'editor']);
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn('?');
        $queryBuilder->method('executeQuery')->willReturn($result);
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        return $connectionPool;
    }

    #[Test]
    public function classHasAsCommandAttribute(): void
    {
        $reflection = new ReflectionClass(ProcessChatCommand::class);
        $attributes = $reflection->getAttributes(AsCommand::class);

        self::assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        self::assertSame('ai-chat:process', $instance->name);
        self::assertSame('Process a single chat conversation', $instance->description);
    }

    #[Test]
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(ProcessChatCommand::class);
        self::assertTrue($reflection->isFinal());
    }

    #[Test]
    public function configureAddsConversationUidArgument(): void
    {
        $chatService = $this->createChatService();
        $repository = $this->createMock(ConversationRepository::class);
        $connectionPool = $this->createMock(ConnectionPool::class);

        $command = new ProcessChatCommand($chatService, $repository, $connectionPool);
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasArgument('conversationUid'));
        self::assertTrue($definition->getArgument('conversationUid')->isRequired());
    }

    #[Test]
    public function constructorAcceptsCorrectDependencies(): void
    {
        $reflection = new ReflectionClass(ProcessChatCommand::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);
        $parameters = $constructor->getParameters();
        self::assertCount(3, $parameters);
        self::assertSame('chatService', $parameters[0]->getName());
        self::assertSame('repository', $parameters[1]->getName());
        self::assertSame('connectionPool', $parameters[2]->getName());
    }

    #[Test]
    public function executeLeavesAToolLoopConversationAlone(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 4,
            'be_user' => 1,
            'status' => 'tool_loop',
            'messages' => '[]',
            'message_count' => 0,
        ]);

        $chatService = $this->createChatService();
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('findByUid')->willReturn($conversation);
        $connectionPool = $this->createMock(ConnectionPool::class);

        $command = new ProcessChatCommand($chatService, $repository, $connectionPool);

        $input = new ArrayInput(['conversationUid' => '4']);
        $input->bind($command->getDefinition());

        $output = new BufferedOutput();
        $result = $command->run($input, $output);

        self::assertSame(0, $result);
        self::assertStringContainsString('not in processing state', $output->fetch());
    }

    #[Test]
    public function conversationWithPendingToolCallsIsDetected(): void
    {
        // Verify the hasPendingToolCalls logic that execute() uses for branching
        $toolCallMessages = json_encode([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'tc_1', 'function' => ['name' => 'test']]]],
        ]);

        $conversation = Conversation::fromRow([
            'uid' => 30,
            'be_user' => 1,
            'status' => 'processing',
            'messages' => $toolCallMessages,
            'message_count' => 2,
        ]);

        self::assertTrue($conversation->hasPendingToolCalls());
    }

    #[Test]
    public function conversationWithoutToolCallsIsNotDetectedAsPending(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 31,
            'be_user' => 1,
            'status' => 'processing',
            'messages' => '[{"role":"user","content":"Hello"}]',
            'message_count' => 1,
        ]);

        self::assertFalse($conversation->hasPendingToolCalls());
    }
}
