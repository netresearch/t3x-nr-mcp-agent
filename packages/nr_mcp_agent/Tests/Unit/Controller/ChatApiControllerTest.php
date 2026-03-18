<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Controller;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Controller\ChatApiController;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
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
        $this->repository->method('updateIf')->willReturn(true);
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
        $conversation->appendMessage(MessageRole::User, 'Hello');
        $conversation->appendMessage(MessageRole::Assistant, 'Hi');
        $conversation->appendMessage(MessageRole::User, 'How are you?');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        // Fast-path poll check returns message_count > after, so full load is needed
        $this->repository->method('findPollStatus')->willReturn([
            'status' => 'idle',
            'message_count' => 3,
            'error_message' => '',
        ]);

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
        $this->repository->expects(self::once())->method('updateArchived');

        $request = $this->createRequest('POST', '{"conversationUid": 1}');
        $response = $this->subject->archiveConversation($request);

        self::assertSame(200, $response->getStatusCode());
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
        $this->repository->expects(self::once())->method('updateIf')->willReturn(true);

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

    #[Test]
    public function sendMessageReturnsConflictWhenCasFails(): void
    {
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('updateIf')->willReturn(false);
        $repository->method('countActiveByBeUser')->willReturn(0);
        $conversation = new Conversation();
        $repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $subject = new ChatApiController($repository, $this->processor, $this->config);

        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "Hello"}');
        $response = $subject->sendMessage($request);

        self::assertSame(409, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('already processing', $data['error']);
    }

    #[Test]
    public function resumeConversationReturnsConflictWhenCasFails(): void
    {
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('updateIf')->willReturn(false);
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Failed);
        $repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $subject = new ChatApiController($repository, $this->processor, $this->config);

        $request = $this->createRequest('POST', '{"conversationUid": 1}');
        $response = $subject->resumeConversation($request);

        self::assertSame(409, $response->getStatusCode());
    }

    #[Test]
    public function getMessagesWithZeroOffsetReturnsAll(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage(MessageRole::User, 'Q1');
        $conversation->appendMessage(MessageRole::Assistant, 'A1');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $request = $this->createRequest('GET', '', ['conversationUid' => '1', 'after' => '0']);
        $response = $this->subject->getMessages($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertCount(2, $data['messages']);
        self::assertSame(2, $data['totalCount']);
    }

    #[Test]
    public function sendMessageSkipsRateLimitWhenMaxActiveIsZero(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getMaxMessageLength')->willReturn(10000);
        $config->method('getMaxActiveConversationsPerUser')->willReturn(0);

        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('updateIf')->willReturn(true);
        $conversation = new Conversation();
        $repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        // countActiveByBeUser should never be called when maxActive is 0
        $repository->expects(self::never())->method('countActiveByBeUser');

        $subject = new ChatApiController($repository, $this->processor, $config);

        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "Hello"}');
        $response = $subject->sendMessage($request);

        self::assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function resumeConversationDispatchesForProcessingStatus(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Processing);

        // Processing is resumable
        self::assertTrue($conversation->isResumable());

        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->processor->expects(self::once())->method('dispatch');

        $request = $this->createRequest('POST', '{"conversationUid": 1}');
        $response = $this->subject->resumeConversation($request);

        self::assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function resumeConversationDispatchesForToolLoopStatus(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::ToolLoop);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->processor->expects(self::once())->method('dispatch');

        $request = $this->createRequest('POST', '{"conversationUid": 1}');
        $response = $this->subject->resumeConversation($request);

        self::assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function getMessagesFastPathReturnsEarlyWhenNoNewMessages(): void
    {
        $this->repository->method('findPollStatus')->willReturn([
            'status' => 'idle',
            'message_count' => 2,
            'error_message' => '',
        ]);
        // findOneByUidAndBeUser should never be called in the fast path
        $this->repository->expects(self::never())->method('findOneByUidAndBeUser');

        $request = $this->createRequest('GET', '', ['conversationUid' => '1', 'after' => '2']);
        $response = $this->subject->getMessages($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame([], $data['messages']);
        self::assertSame(2, $data['totalCount']);
        self::assertSame('idle', $data['status']);
    }

    #[Test]
    public function getMessagesFastPathReturns404WhenPollStatusNull(): void
    {
        $this->repository->method('findPollStatus')->willReturn(null);

        $request = $this->createRequest('GET', '', ['conversationUid' => '999', 'after' => '1']);
        $response = $this->subject->getMessages($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function archiveConversationCallsUpdateArchivedWithCorrectArguments(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 42,
            'be_user' => 1,
        ]);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->expects(self::once())
            ->method('updateArchived')
            ->with(42, true, 1);

        $request = $this->createRequest('POST', '{"conversationUid": 42}');
        $response = $this->subject->archiveConversation($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function togglePinCallsUpdatePinnedWithCorrectArguments(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 10,
            'be_user' => 1,
            'pinned' => 0,
        ]);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->expects(self::once())
            ->method('updatePinned')
            ->with(10, true, 1);

        $request = $this->createRequest('POST', '{"conversationUid": 10}');
        $response = $this->subject->togglePin($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['pinned']);
    }

    #[Test]
    public function togglePinUnpinsPinnedConversation(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 10,
            'be_user' => 1,
            'pinned' => 1,
        ]);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->expects(self::once())
            ->method('updatePinned')
            ->with(10, false, 1);

        $request = $this->createRequest('POST', '{"conversationUid": 10}');
        $response = $this->subject->togglePin($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['pinned']);
    }

    #[Test]
    public function listConversationsReturnsAllConversationFields(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 5,
            'be_user' => 1,
            'title' => 'Test Chat',
            'status' => 'processing',
            'messages' => json_encode([['role' => 'user', 'content' => 'Hi']]),
            'message_count' => 1,
            'pinned' => 1,
            'error_message' => 'Some error',
            'tstamp' => 1700000000,
        ]);
        $this->repository->method('findByBeUser')->willReturn([$conversation]);

        $request = $this->createRequest('GET', '');
        $response = $this->subject->listConversations($request);

        $data = json_decode((string) $response->getBody(), true);
        $item = $data['conversations'][0];

        self::assertSame(5, $item['uid']);
        self::assertSame('Test Chat', $item['title']);
        self::assertSame('processing', $item['status']);
        self::assertSame(1, $item['messageCount']);
        self::assertTrue($item['pinned']);
        self::assertTrue($item['resumable']);
        self::assertSame('Some error', $item['errorMessage']);
        self::assertSame(1700000000, $item['tstamp']);
    }

    #[Test]
    public function resumeConversationDispatchesWithCorrectUid(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 77,
            'be_user' => 1,
            'status' => 'failed',
        ]);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->processor->expects(self::once())
            ->method('dispatch')
            ->with(77);

        $request = $this->createRequest('POST', '{"conversationUid": 77}');
        $response = $this->subject->resumeConversation($request);

        self::assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function getStatusReturnsAvailableTrueWhenTaskConfiguredWithoutMcp(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getLlmTaskUid')->willReturn(5);
        $config->method('isMcpEnabled')->willReturn(false);
        $config->method('isMcpServerInstalled')->willReturn(false);
        $subject = new ChatApiController($this->repository, $this->processor, $config);

        $request = $this->createRequest('GET', '');
        $response = $subject->getStatus($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['available']);
        self::assertFalse($data['mcpEnabled']);
        self::assertEmpty($data['issues']);
    }

    #[Test]
    public function getStatusReturnsActiveConversationCount(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getLlmTaskUid')->willReturn(1);
        $config->method('isMcpEnabled')->willReturn(false);
        $config->method('isMcpServerInstalled')->willReturn(false);
        $this->repository->method('countActiveByBeUser')->willReturn(2);
        $subject = new ChatApiController($this->repository, $this->processor, $config);

        $request = $this->createRequest('GET', '');
        $response = $subject->getStatus($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('activeConversationCount', $data);
        self::assertSame(2, $data['activeConversationCount']);
    }

    #[Test]
    public function sendMessageReturns404WhenConversationNotFound(): void
    {
        $this->repository->method('findOneByUidAndBeUser')->willReturn(null);

        $request = $this->createRequest('POST', '{"conversationUid": 999, "content": "Hello"}');
        $response = $this->subject->sendMessage($request);

        self::assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('not found', $data['error']);
    }

    #[Test]
    public function sendMessageWithMaxLengthZeroAllowsAnyLength(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getMaxMessageLength')->willReturn(0);
        $config->method('getMaxActiveConversationsPerUser')->willReturn(0);

        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('updateIf')->willReturn(true);
        $conversation = new Conversation();
        $repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $subject = new ChatApiController($repository, $this->processor, $config);

        $longContent = str_repeat('x', 100000);
        $request = $this->createRequest('POST', json_encode(['conversationUid' => 1, 'content' => $longContent]));
        $response = $subject->sendMessage($request);

        self::assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function fileUploadRejectsWhenNoFile(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUploadedFiles')->willReturn([]);

        $response = $this->subject->fileUpload($request);

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('error', $data);
    }

    #[Test]
    public function fileUploadRejectsInvalidMimeType(): void
    {
        $file = $this->createMock(UploadedFileInterface::class);
        $file->method('getError')->willReturn(UPLOAD_ERR_OK);
        $file->method('getClientMediaType')->willReturn('text/plain');
        $file->method('getSize')->willReturn(1024);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUploadedFiles')->willReturn(['file' => $file]);

        $response = $this->subject->fileUpload($request);

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('not supported', $data['error']);
    }

    #[Test]
    public function fileUploadRejectsOversizedFile(): void
    {
        $file = $this->createMock(UploadedFileInterface::class);
        $file->method('getError')->willReturn(UPLOAD_ERR_OK);
        $file->method('getClientMediaType')->willReturn('image/png');
        $file->method('getSize')->willReturn(21 * 1024 * 1024);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUploadedFiles')->willReturn(['file' => $file]);

        $response = $this->subject->fileUpload($request);

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('too large', strtolower($data['error']));
    }

    #[Test]
    public function fileUploadRejectsUploadError(): void
    {
        $file = $this->createMock(UploadedFileInterface::class);
        $file->method('getError')->willReturn(UPLOAD_ERR_PARTIAL);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUploadedFiles')->willReturn(['file' => $file]);

        $response = $this->subject->fileUpload($request);

        self::assertSame(400, $response->getStatusCode());
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
