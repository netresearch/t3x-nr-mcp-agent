<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Command;

use Doctrine\DBAL\Result;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrMcpAgent\Command\ChatWorkerCommand;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Domain\Repository\LlmTaskRepository;
use Netresearch\NrMcpAgent\Domain\Repository\McpServerRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Mcp\McpToolProviderInterface;
use Netresearch\NrMcpAgent\Service\ChatService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tests ChatWorkerCommand::execute() behaviour.
 *
 * The execute() method has an infinite while(true) loop. The test strategy is:
 *   1. dequeueForWorker() returns a real Conversation with be_user=1
 *   2. BackendUserInitializer::initialize() runs via GeneralUtility::addInstance
 *      — it will throw RuntimeException('Backend user 1 not found') because the
 *      mock ConnectionPool returns fetchAssociative=false
 *   3. The catch block handles the error (sets conversation status=Failed) and
 *      calls $this->repository->update($conversation)
 *   4. We make update() throw RuntimeException('break') to exit the loop
 *   5. The test catches the 'break' exception and verifies the side effects
 */
class ChatWorkerCommandExecuteTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    private function buildConnectionPoolMockForUserNotFound(): ConnectionPool
    {
        $exprBuilder = $this->createMock(ExpressionBuilder::class);
        $exprBuilder->method('eq')->willReturn('1 = 1');

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(false); // user not found

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('expr')->willReturn($exprBuilder);
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($qb);

        return $connectionPool;
    }

    private function createChatService(): ChatService
    {
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $config->method('isMcpEnabled')->willReturn(false);

        $llmTaskRepository = $this->createMock(LlmTaskRepository::class);
        $llmTaskRepository->method('resolveModelByTaskUid')->willReturn([
            'model' => $this->createMock(LlmModel::class),
            'systemPrompt' => '',
            'promptTemplate' => '',
        ]);

        $adapterRegistry = $this->createMock(ProviderAdapterRegistry::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($this->createMock(ProviderInterface::class));

        $mcpServerRepository = $this->createMock(McpServerRepository::class);
        $mcpServerRepository->method('findAllActive')->willReturn([]);

        return new ChatService(
            $this->createMock(ConversationRepository::class),
            $config,
            $this->createMock(McpToolProviderInterface::class),
            $llmTaskRepository,
            $adapterRegistry,
            $this->createMock(ResourceFactory::class),
            $this->createMock(SiteFinder::class),
            new DocumentExtractorRegistry([]),
            $mcpServerRepository,
        );
    }

    #[Test]
    public function executeSetsConversationFailedWhenBackendUserNotFound(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 1, 'be_user' => 1, 'status' => 'processing',
            'messages' => '[{"role":"user","content":"Hello"}]', 'message_count' => 1,
        ]);

        // Register a BackendUserAuthentication mock so GeneralUtility::makeInstance works
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        GeneralUtility::addInstance(BackendUserAuthentication::class, $backendUser);

        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('dequeueForWorker')->willReturn($conversation);
        // update() throws to break the infinite loop
        $repository->method('update')->willThrowException(new RuntimeException('break'));

        $connectionPool = $this->buildConnectionPoolMockForUserNotFound();
        $chatService = $this->createChatService();
        $command = new ChatWorkerCommand($chatService, $repository, $connectionPool);

        $input = new ArrayInput(['--poll-interval' => '0']);
        $input->bind($command->getDefinition());

        try {
            $command->run($input, new BufferedOutput());
        } catch (RuntimeException $e) {
            // 'break' exception escapes through the finally block
            self::assertSame('break', $e->getMessage());
        }

        // Conversation status must have been set to Failed by the catch handler
        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertNotEmpty($conversation->getErrorMessage());
    }

    #[Test]
    public function executeCleansBEUserGlobalInFinally(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 2, 'be_user' => 2, 'status' => 'processing',
            'messages' => '[{"role":"user","content":"Hello"}]', 'message_count' => 1,
        ]);

        $backendUser = $this->createMock(BackendUserAuthentication::class);
        GeneralUtility::addInstance(BackendUserAuthentication::class, $backendUser);

        // Set BE_USER to something non-null before the command runs
        $GLOBALS['BE_USER'] = new stdClass();

        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('dequeueForWorker')->willReturn($conversation);
        $repository->method('update')->willThrowException(new RuntimeException('break'));

        $command = new ChatWorkerCommand(
            $this->createChatService(),
            $repository,
            $this->buildConnectionPoolMockForUserNotFound(),
        );

        $input = new ArrayInput(['--poll-interval' => '0']);
        $input->bind($command->getDefinition());

        try {
            $command->run($input, new BufferedOutput());
        } catch (RuntimeException) {
            // expected break
        }

        // finally block must have set GLOBALS['BE_USER'] = null
        self::assertNull($GLOBALS['BE_USER']);
    }

    #[Test]
    public function executeWritesWorkerStartedMessageToOutput(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 10, 'be_user' => 10, 'status' => 'processing',
            'messages' => '[{"role":"user","content":"Hello"}]', 'message_count' => 1,
        ]);

        $backendUser = $this->createMock(BackendUserAuthentication::class);
        GeneralUtility::addInstance(BackendUserAuthentication::class, $backendUser);

        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('dequeueForWorker')->willReturn($conversation);
        $repository->method('update')->willThrowException(new RuntimeException('break'));

        $command = new ChatWorkerCommand(
            $this->createChatService(),
            $repository,
            $this->buildConnectionPoolMockForUserNotFound(),
        );

        $output = new BufferedOutput();
        $input = new ArrayInput(['--poll-interval' => '0']);
        $input->bind($command->getDefinition());

        try {
            $command->run($input, $output);
        } catch (RuntimeException) {
        }

        self::assertStringContainsString('worker', strtolower($output->fetch()));
    }
}
