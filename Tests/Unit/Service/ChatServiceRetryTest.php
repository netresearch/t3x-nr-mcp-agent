<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Domain\Repository\LlmTaskRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Mcp\McpToolProviderInterface;
use Netresearch\NrMcpAgent\Service\ChatService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use TYPO3\CMS\Core\Resource\ResourceFactory;

class ChatServiceRetryTest extends TestCase
{
    private function createChatService(ProviderInterface $provider): ChatService
    {
        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $config->method('isMcpEnabled')->willReturn(false);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);

        $model = $this->createMock(LlmModel::class);
        $llmTaskRepository = $this->createMock(LlmTaskRepository::class);
        $llmTaskRepository->method('resolveModelByTaskUid')->willReturn([
            'model' => $model,
            'systemPrompt' => '',
            'promptTemplate' => '',
        ]);

        $adapterRegistry = $this->createMock(ProviderAdapterRegistry::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($provider);

        return new ChatService($repository, $config, $mcpProvider, $llmTaskRepository, $adapterRegistry, $this->createMock(ResourceFactory::class));
    }

    #[Test]
    public function retriesOnTransient429Error(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $callCount = 0;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new RuntimeException('429 Too Many Requests');
            }
            return new CompletionResponse(
                content: 'Hi!',
                model: 'test',
                usage: new UsageStatistics(10, 20, 30),
            );
        });

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame(2, $callCount);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function doesNotRetryOnNonTransientError(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willThrowException(
            new RuntimeException('Invalid API key'),
        );

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function errorMessageIsSanitized(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willThrowException(
            new RuntimeException('Error calling https://api.anthropic.com/v1/messages with Bearer sk-ant-api03-secretkey123: 500'),
        );

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider);
        $service->processConversation($conversation);

        self::assertStringNotContainsString('sk-ant', $conversation->getErrorMessage());
        self::assertStringNotContainsString('anthropic.com', $conversation->getErrorMessage());
        self::assertStringContainsString('[REDACTED]', $conversation->getErrorMessage());

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function retriesOnOverloadedError(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $callCount = 0;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new RuntimeException('The server is overloaded');
            }
            return new CompletionResponse(
                content: 'Hi!',
                model: 'test',
                usage: new UsageStatistics(10, 20, 30),
            );
        });

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame(2, $callCount);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function retriesOn503Error(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $callCount = 0;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new RuntimeException('503 Service Unavailable');
            }
            return new CompletionResponse(
                content: 'Hi!',
                model: 'test',
                usage: new UsageStatistics(10, 20, 30),
            );
        });

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame(2, $callCount);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function exhaustsAllRetriesOnPersistentTransientError(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $callCount = 0;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            throw new RuntimeException('429 Too Many Requests');
        });

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        // MAX_LLM_RETRIES = 2, so 3 attempts total (0, 1, 2)
        self::assertSame(3, $callCount);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function sanitizesKeyPrefixPattern(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willThrowException(
            new RuntimeException('Error with key-abc123def456 token'),
        );

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider);
        $service->processConversation($conversation);

        self::assertStringNotContainsString('key-abc123def456', $conversation->getErrorMessage());
        self::assertStringContainsString('[REDACTED]', $conversation->getErrorMessage());

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function errorMessageTruncatedAt500Chars(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $longError = str_repeat('a', 600);
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willThrowException(new RuntimeException($longError));

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertLessThanOrEqual(500, mb_strlen($conversation->getErrorMessage()));

        unset($GLOBALS['BE_USER']);
    }

    // -------------------------------------------------------------------------
    // isTransientError(): LogicalOr subexpressions (line 316)
    // Each keyword must independently trigger a retry
    // -------------------------------------------------------------------------

    #[Test]
    public function retriesOnRateLimitKeyword(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $callCount = 0;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new RuntimeException('rate limit exceeded');
            }
            return new CompletionResponse(content: 'ok', model: 'test', usage: new UsageStatistics(1, 2, 3));
        });

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame(2, $callCount);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function doesNotRetryWhenErrorKeywordIsMissing(): void
    {
        // 'api error' contains no retry keyword — must fail immediately
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $callCount = 0;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            throw new RuntimeException('api error');
        });

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertSame(1, $callCount);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function exhaustsRetriesAfterMaxAttemptsEvenForTransientErrors(): void
    {
        // Kills GreaterThanOrEqualTo / IncrementInteger mutations on the retry counter
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $callCount = 0;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            throw new RuntimeException('503 Service Unavailable');
        });

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        // MAX_LLM_RETRIES = 2 → exactly 3 attempts
        self::assertSame(3, $callCount);

        unset($GLOBALS['BE_USER']);
    }
}
