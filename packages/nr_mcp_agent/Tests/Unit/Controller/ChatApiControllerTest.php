<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Controller;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Controller\ChatApiController;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use stdClass;

class ChatApiControllerTest extends TestCase
{
    private ChatApiController $subject;
    private ConversationRepository $repository;
    private ChatProcessorInterface $processor;
    private ExtensionConfiguration $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(ConversationRepository::class);
        $this->processor = $this->createMock(ChatProcessorInterface::class);
        $this->config = $this->createMock(ExtensionConfiguration::class);
        $this->config->method('getAllowedGroupIds')->willReturn([]);
        $this->config->method('getMaxMessageLength')->willReturn(10000);
        $this->config->method('getMaxActiveConversationsPerUser')->willReturn(3);
        $this->subject = new ChatApiController($this->repository, $this->processor, $this->config);

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->user = ['uid' => 1, 'usergroup' => '1,2'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function listConversationsReturnsUserConversations(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $this->repository->method('findByBeUser')->willReturn([$conversation]);

        $request = $this->createRequest('GET', '');
        $response = $this->subject->listConversations($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $data['conversations']);
    }

    #[Test]
    public function createConversationReturns201(): void
    {
        $this->repository->method('add')->willReturn(42);

        $request = $this->createRequest('POST', '');
        $response = $this->subject->createConversation($request);

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame(42, $data['uid']);
    }

    #[Test]
    public function sendMessageRejectsEmptyContent(): void
    {
        $conversation = new Conversation();
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "  "}');
        $response = $this->subject->sendMessage($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function sendMessageRejectsMessageExceedingMaxLength(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getMaxMessageLength')->willReturn(10);
        $config->method('getMaxActiveConversationsPerUser')->willReturn(3);
        $subject = new ChatApiController($this->repository, $this->processor, $config);

        $conversation = new Conversation();
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "This message is way too long for the limit"}');
        $response = $subject->sendMessage($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function sendMessageRejectsAlreadyProcessingConversation(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Processing);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "Hello"}');
        $response = $this->subject->sendMessage($request);

        self::assertSame(409, $response->getStatusCode());
    }

    #[Test]
    public function sendMessageDispatchesProcessing(): void
    {
        $conversation = new Conversation();
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->method('countActiveByBeUser')->willReturn(0);
        $this->processor->expects(self::once())->method('dispatch');

        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "Hello AI"}');
        $response = $this->subject->sendMessage($request);

        self::assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function sendMessageEnforcesRateLimit(): void
    {
        $conversation = new Conversation();
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->method('countActiveByBeUser')->willReturn(3);

        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "Hello"}');
        $response = $this->subject->sendMessage($request);

        self::assertSame(429, $response->getStatusCode());
    }

    #[Test]
    public function checkAccessDeniesUnauthorizedGroup(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([99]);
        $config->method('getLlmTaskUid')->willReturn(1);
        $subject = new ChatApiController($this->repository, $this->processor, $config);

        $request = $this->createRequest('GET', '');
        $response = $subject->getStatus($request);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function checkAccessAllowsMatchingGroup(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([2]);
        $config->method('getLlmTaskUid')->willReturn(1);
        $config->method('isMcpEnabled')->willReturn(false);
        $config->method('isMcpServerInstalled')->willReturn(false);
        $subject = new ChatApiController($this->repository, $this->processor, $config);

        $request = $this->createRequest('GET', '');
        $response = $subject->getStatus($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function getMessagesReturnsSlicedMessages(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage('user', 'Hello');
        $conversation->appendMessage('assistant', 'Hi');
        $conversation->appendMessage('user', 'How are you?');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $request = $this->createRequest('GET', '', ['conversationUid' => '1', 'after' => '1']);
        $response = $this->subject->getMessages($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertCount(2, $data['messages']);
        self::assertSame(3, $data['totalCount']);
    }

    #[Test]
    public function archiveConversationSetsArchived(): void
    {
        $conversation = new Conversation();
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->expects(self::once())->method('update');

        $request = $this->createRequest('POST', '{"conversationUid": 1}');
        $response = $this->subject->archiveConversation($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($conversation->isArchived());
    }

    #[Test]
    public function togglePinFlipsState(): void
    {
        $conversation = new Conversation();
        self::assertFalse($conversation->isPinned());
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $request = $this->createRequest('POST', '{"conversationUid": 1}');
        $response = $this->subject->togglePin($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['pinned']);
    }

    #[Test]
    public function resumeConversationRejectsNonResumable(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Idle);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $request = $this->createRequest('POST', '{"conversationUid": 1}');
        $response = $this->subject->resumeConversation($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function resumeConversationDispatchesForFailedConversation(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Failed);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->processor->expects(self::once())->method('dispatch');

        $request = $this->createRequest('POST', '{"conversationUid": 1}');
        $response = $this->subject->resumeConversation($request);

        self::assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function findConversationOrFailReturns404ForUnknown(): void
    {
        $this->repository->method('findOneByUidAndBeUser')->willReturn(null);

        $request = $this->createRequest('GET', '', ['conversationUid' => '999']);
        $response = $this->subject->getMessages($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function getStatusReportsNoTaskConfigured(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getLlmTaskUid')->willReturn(0);
        $config->method('isMcpEnabled')->willReturn(false);
        $config->method('isMcpServerInstalled')->willReturn(false);
        $subject = new ChatApiController($this->repository, $this->processor, $config);

        $request = $this->createRequest('GET', '');
        $response = $subject->getStatus($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['available']);
        self::assertNotEmpty($data['issues']);
        self::assertStringContainsString('No nr-llm Task', $data['issues'][0]);
    }

    #[Test]
    public function getStatusReportsMcpEnabledButNotInstalled(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getLlmTaskUid')->willReturn(1);
        $config->method('isMcpEnabled')->willReturn(true);
        $config->method('isMcpServerInstalled')->willReturn(false);
        $subject = new ChatApiController($this->repository, $this->processor, $config);

        $request = $this->createRequest('GET', '');
        $response = $subject->getStatus($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['available']);
        self::assertTrue($data['mcpEnabled']);
        self::assertStringContainsString('not installed', $data['issues'][0]);
    }

    #[Test]
    public function getStatusReportsMcpInstalledButDisabled(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getLlmTaskUid')->willReturn(1);
        $config->method('isMcpEnabled')->willReturn(false);
        $config->method('isMcpServerInstalled')->willReturn(true);
        $subject = new ChatApiController($this->repository, $this->processor, $config);

        $request = $this->createRequest('GET', '');
        $response = $subject->getStatus($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('MCP is not enabled', $data['issues'][0]);
    }

    #[Test]
    public function getStatusReturnsCleanWhenFullyConfigured(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getLlmTaskUid')->willReturn(1);
        $config->method('isMcpEnabled')->willReturn(true);
        $config->method('isMcpServerInstalled')->willReturn(true);
        $subject = new ChatApiController($this->repository, $this->processor, $config);

        $request = $this->createRequest('GET', '');
        $response = $subject->getStatus($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['available']);
        self::assertTrue($data['mcpEnabled']);
        self::assertEmpty($data['issues']);
    }

    #[Test]
    public function sendMessageRejectsLockedConversation(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Locked);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "Hello"}');
        $response = $this->subject->sendMessage($request);

        self::assertSame(409, $response->getStatusCode());
    }

    #[Test]
    public function sendMessageRejectsToolLoopConversation(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::ToolLoop);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "Hello"}');
        $response = $this->subject->sendMessage($request);

        self::assertSame(409, $response->getStatusCode());
    }

    #[Test]
    public function sendMessageClearsErrorMessage(): void
    {
        $conversation = new Conversation();
        $conversation->setErrorMessage('Previous error');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->method('countActiveByBeUser')->willReturn(0);

        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "Hello"}');
        $this->subject->sendMessage($request);

        self::assertSame('', $conversation->getErrorMessage());
        self::assertSame(ConversationStatus::Processing, $conversation->getStatus());
    }

    #[Test]
    public function sendMessageAppendsUserMessage(): void
    {
        $conversation = new Conversation();
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->method('countActiveByBeUser')->willReturn(0);

        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "Hello AI"}');
        $this->subject->sendMessage($request);

        $messages = $conversation->getDecodedMessages();
        self::assertCount(1, $messages);
        self::assertSame('user', $messages[0]['role']);
        self::assertSame('Hello AI', $messages[0]['content']);
    }

    #[Test]
    public function checkAccessAllowsAdminDespiteGroupRestriction(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([99]);
        $config->method('getLlmTaskUid')->willReturn(1);
        $config->method('isMcpEnabled')->willReturn(false);
        $config->method('isMcpServerInstalled')->willReturn(false);
        $subject = new ChatApiController($this->repository, $this->processor, $config);

        $GLOBALS['BE_USER']->user = ['uid' => 1, 'usergroup' => '', 'admin' => 1];

        $request = $this->createRequest('GET', '');
        $response = $subject->getStatus($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function listConversationsReturnsEmptyArrayWhenNone(): void
    {
        $this->repository->method('findByBeUser')->willReturn([]);

        $request = $this->createRequest('GET', '');
        $response = $this->subject->listConversations($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame([], $data['conversations']);
    }

    #[Test]
    public function resumeConversationSetsStatusToProcessing(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Failed);
        $conversation->setErrorMessage('Some error');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->expects(self::once())->method('update');

        $request = $this->createRequest('POST', '{"conversationUid": 1}');
        $this->subject->resumeConversation($request);

        self::assertSame(ConversationStatus::Processing, $conversation->getStatus());
        self::assertSame('', $conversation->getErrorMessage());
    }

    #[Test]
    public function getMessagesReturnsStatusAndErrorMessage(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Failed);
        $conversation->setErrorMessage('LLM timeout');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $request = $this->createRequest('GET', '', ['conversationUid' => '1']);
        $response = $this->subject->getMessages($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('failed', $data['status']);
        self::assertSame('LLM timeout', $data['errorMessage']);
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function createRequest(string $method, string $body, array $queryParams = []): ServerRequestInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getBody')->willReturn($stream);
        $request->method('getQueryParams')->willReturn($queryParams);
        return $request;
    }
}
