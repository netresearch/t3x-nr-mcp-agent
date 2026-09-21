<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Controller;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Controller\ChatApiController;
use Netresearch\NrMcpAgent\Document\DocumentExtractorInterface;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use stdClass;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;

class ChatApiControllerTest extends TestCase
{
    private ChatApiController $subject;
    private ConversationRepository $repository;
    private ChatProcessorInterface $processor;
    private ExtensionConfiguration $config;
    private ChatCapabilitiesInterface $chatService;
    private ResourceFactory $resourceFactory;
    private StorageRepository $storageRepository;
    private UriBuilder $uriBuilder;
    private ChatApprovalInterface $chatApproval;

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
        $this->config->method('getAttachmentFolder')->willReturn('ai-chat');
        $this->chatService = $this->createMock(ChatCapabilitiesInterface::class);
        $this->chatService->method('getProviderCapabilities')->willReturn([
            'visionSupported' => false,
            'maxFileSize' => 0,
            'supportedFormats' => [],
        ]);
        $this->resourceFactory = $this->createMock(ResourceFactory::class);
        $this->storageRepository = $this->createMock(StorageRepository::class);
        $this->uriBuilder = $this->createMock(UriBuilder::class);
        $this->chatApproval = $this->createMock(ChatApprovalInterface::class);
        $this->subject = new ChatApiController(
            $this->repository,
            $this->processor,
            $this->config,
            $this->chatService,
            $this->chatApproval,
            $this->resourceFactory,
            $this->storageRepository,
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->uriBuilder,
        );

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->user = ['uid' => 1, 'usergroup' => '1,2'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
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
        $subject = new ChatApiController($this->repository, $this->processor, $config, $this->chatService, $this->chatApproval, $this->resourceFactory, $this->storageRepository, new DocumentExtractorRegistry([]), new UploadMimeTypeMap(), $this->uriBuilder);

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
        $subject = new ChatApiController($this->repository, $this->processor, $config, $this->chatService, $this->chatApproval, $this->resourceFactory, $this->storageRepository, new DocumentExtractorRegistry([]), new UploadMimeTypeMap(), $this->uriBuilder);

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
        $subject = new ChatApiController($this->repository, $this->processor, $config, $this->chatService, $this->chatApproval, $this->resourceFactory, $this->storageRepository, new DocumentExtractorRegistry([]), new UploadMimeTypeMap(), $this->uriBuilder);

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
            'approval_run_uuid' => '',
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
        $subject = new ChatApiController($this->repository, $this->processor, $config, $this->chatService, $this->chatApproval, $this->resourceFactory, $this->storageRepository, new DocumentExtractorRegistry([]), new UploadMimeTypeMap(), $this->uriBuilder);

        $request = $this->createRequest('GET', '');
        $response = $subject->getStatus($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['available']);
        self::assertNotEmpty($data['issues']);
        self::assertStringContainsString('No nr-llm Task', $data['issues'][0]);
    }



    #[Test]
    public function getStatusReturnsCleanWhenFullyConfigured(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getLlmTaskUid')->willReturn(1);
        $subject = new ChatApiController($this->repository, $this->processor, $config, $this->chatService, $this->chatApproval, $this->resourceFactory, $this->storageRepository, new DocumentExtractorRegistry([]), new UploadMimeTypeMap(), $this->uriBuilder);

        $request = $this->createRequest('GET', '');
        $response = $subject->getStatus($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['available']);
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
        $subject = new ChatApiController($this->repository, $this->processor, $config, $this->chatService, $this->chatApproval, $this->resourceFactory, $this->storageRepository, new DocumentExtractorRegistry([]), new UploadMimeTypeMap(), $this->uriBuilder);

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

        $subject = new ChatApiController($repository, $this->processor, $this->config, $this->chatService, $this->chatApproval, $this->resourceFactory, $this->storageRepository, new DocumentExtractorRegistry([]), new UploadMimeTypeMap(), $this->uriBuilder);

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

        $subject = new ChatApiController($repository, $this->processor, $this->config, $this->chatService, $this->chatApproval, $this->resourceFactory, $this->storageRepository, new DocumentExtractorRegistry([]), new UploadMimeTypeMap(), $this->uriBuilder);

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

        $subject = new ChatApiController($repository, $this->processor, $config, $this->chatService, $this->chatApproval, $this->resourceFactory, $this->storageRepository, new DocumentExtractorRegistry([]), new UploadMimeTypeMap(), $this->uriBuilder);

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

    /**
     * Retry is offered on a Processing conversation because a worker can fail to
     * start. A conversation carrying a recorded approval decision is not that
     * case: the worker holds the decision from the moment the request writes it
     * until the continuation settles, and resume() clears the decision and runs
     * the turn again — so a Retry landing in that window creates a second run
     * over the same transcript while the first is carrying out the approved
     * write. That is the duplicated page and content element in NEXT-156.
     *
     * A decision no worker ever picked up is not stranded by this: reconcile()
     * reads the run, sees it still waiting, clears the decision and hands the
     * card back — and then Retry is available again.
     */
    #[Test]
    public function resumeConversationRefusesWhileAnApprovalDecisionIsInFlight(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Processing);
        $conversation->setApprovalRunUuid('run-uuid-1234');
        $conversation->recordApprovalDecision(true, 'digest-abc');
        self::assertTrue($conversation->isResumable(), 'precondition: Processing is resumable');

        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->processor->expects(self::never())->method('dispatch');

        $request = $this->createRequest('POST', '{"conversationUid": 1}');
        $response = $this->subject->resumeConversation($request);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('approve', $conversation->getApprovalDecision(), 'the decision must survive the refusal');
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

    /**
     * The chat must not become a second, unscoped way to release the write
     * fence. nrllm_aitasks is `access: user`, so a group can be given the chat
     * without it — and such a user could previously start a run that suspends
     * on a write but never decide it.
     */
    #[Test]
    public function decidingAnApprovalRequiresTheModuleTheInboxLivesIn(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'usergroup' => '1,2'];
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('check')->with('modules', 'nrllm_aitasks')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;

        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid('run-uuid-1234');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $this->chatApproval->expects(self::never())->method('recordDecision');

        $request = $this->createRequest('POST', '{"conversationUid": 1, "approve": true, "turnDigest": "d"}');

        self::assertSame(403, $this->subject->decideApproval($request)->getStatusCode());
    }

    /**
     * The refusals of the approval endpoints reach the chat notice verbatim, so
     * they are labels resolved with the request's LanguageService — the one
     * BackendUserAuthenticator creates from the user's preferences (NEXT-159).
     * The stub answers per label reference, so what is asserted is which unit
     * of which file each refusal asks for. The functional test does the real
     * round trip through the XLF.
     */
    #[Test]
    public function decidingWithoutTheModuleIsRefusedInTheUsersLanguage(): void
    {
        $this->setUpLanguageServiceAnswering([
            'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:error.approvalNotAllowed' => 'Keine Berechtigung, Freigaben zu entscheiden',
        ]);
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'usergroup' => '1,2'];
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('check')->with('modules', 'nrllm_aitasks')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;

        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid('run-uuid-1234');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $request = $this->createRequest('POST', '{"conversationUid": 1, "approve": true, "turnDigest": "d"}');
        $response = $this->subject->decideApproval($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['error' => 'Keine Berechtigung, Freigaben zu entscheiden'], json_decode((string) $response->getBody(), true));
    }

    #[Test]
    public function decidingOnAConversationThatIsNotWaitingIsRefusedInTheUsersLanguage(): void
    {
        $this->setUpLanguageServiceAnswering([
            'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:error.notAwaitingApproval' => 'Der Chat wartet nicht auf eine Freigabe',
        ]);
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'usergroup' => '1,2'];
        $backendUser->method('isAdmin')->willReturn(true);
        $GLOBALS['BE_USER'] = $backendUser;

        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Idle);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $request = $this->createRequest('POST', '{"conversationUid": 1, "approve": true, "turnDigest": "d"}');
        $response = $this->subject->decideApproval($request);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['error' => 'Der Chat wartet nicht auf eine Freigabe'], json_decode((string) $response->getBody(), true));
    }

    #[Test]
    public function retryingWhileADecisionIsInFlightIsRefusedInTheUsersLanguage(): void
    {
        $this->setUpLanguageServiceAnswering([
            'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:error.decisionInFlight' => 'Eine Freigabeentscheidung für diesen Chat wird noch ausgeführt',
        ]);
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Processing);
        $conversation->setApprovalRunUuid('run-uuid-1234');
        $conversation->recordApprovalDecision(true, 'digest-abc');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $request = $this->createRequest('POST', '{"conversationUid": 1}');
        $response = $this->subject->resumeConversation($request);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['error' => 'Eine Freigabeentscheidung für diesen Chat wird noch ausgeführt'], json_decode((string) $response->getBody(), true));
    }

    /**
     * sL() answers an empty string for a unit it cannot resolve, and an empty
     * error explains nothing: the key is returned in its place, so the notice
     * at least names what was asked for.
     */
    #[Test]
    public function aRefusalWhoseLabelCannotBeResolvedCarriesTheKey(): void
    {
        $this->setUpLanguageServiceAnswering([]);
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Processing);
        $conversation->setApprovalRunUuid('run-uuid-1234');
        $conversation->recordApprovalDecision(true, 'digest-abc');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $request = $this->createRequest('POST', '{"conversationUid": 1}');
        $response = $this->subject->resumeConversation($request);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['error' => 'error.decisionInFlight'], json_decode((string) $response->getBody(), true));
    }

    /**
     * @param array<string, string> $labels translation per full LLL reference
     */
    private function setUpLanguageServiceAnswering(array $labels): void
    {
        $languageService = $this->createStub(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn(mixed $input): string => $labels[is_string($input) ? $input : ''] ?? '',
        );
        $GLOBALS['LANG'] = $languageService;
    }

    /**
     * The stuck case writes no message, so it lives in exactly the branch that
     * used to return before the repair could run: every poll asks with
     * `after > 0`, sees no new messages, and answers. A conversation whose
     * worker never came would have spun there forever.
     */
    #[Test]
    public function aPollOverAClaimedApprovalStillReachesTheRepair(): void
    {
        $this->repository->method('findPollStatus')->willReturn([
            'status' => 'processing',
            'message_count' => 2,
            'error_message' => '',
            'approval_run_uuid' => 'run-uuid-1234',
            'tstamp' => time() - 600,
        ]);
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Processing);
        $conversation->setApprovalRunUuid('run-uuid-1234');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $this->chatApproval->expects(self::once())->method('reconcile');

        $request = $this->createRequest('GET', '', ['conversationUid' => '1', 'after' => '2']);
        $this->subject->getMessages($request);
    }

    /**
     * And an ordinary poll must not pay for it: without an approval on the row
     * the fast path still answers from the metadata alone.
     */
    #[Test]
    public function anOrdinaryPollDoesNotLoadTheConversation(): void
    {
        $this->repository->method('findPollStatus')->willReturn([
            'status' => 'processing',
            'message_count' => 2,
            'error_message' => '',
            'approval_run_uuid' => '',
            'tstamp' => time() - 600,
        ]);
        $this->repository->expects(self::never())->method('findOneByUidAndBeUser');
        $this->chatApproval->expects(self::never())->method('reconcile');

        $request = $this->createRequest('GET', '', ['conversationUid' => '1', 'after' => '2']);

        self::assertSame(200, $this->subject->getMessages($request)->getStatusCode());
    }

    /**
     * A new turn abandons a pending approval. Otherwise the reference survives
     * into Processing, where the approval link still reads it and the repair
     * would hand the card back in the middle of the new turn.
     */
    #[Test]
    public function sendingAMessageAbandonsAPendingApproval(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid('run-uuid-1234');
        $conversation->recordApprovalDecision(true, 'digest-abc');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "never mind, do something else"}');
        $this->subject->sendMessage($request);

        self::assertSame('', $conversation->getApprovalRunUuid());
        self::assertSame('', $conversation->getApprovalDecision());
    }

    #[Test]
    public function getMessagesFastPathReturnsEarlyWhenNoNewMessages(): void
    {
        $this->repository->method('findPollStatus')->willReturn([
            'status' => 'idle',
            'message_count' => 2,
            'error_message' => '',
            'approval_run_uuid' => '',
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
    public function renameConversationCallsUpdateTitleWithCorrectArguments(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 7,
            'be_user' => 1,
        ]);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->expects(self::once())
            ->method('updateTitle')
            ->with(7, 'My new title', 1);

        $request = $this->createRequest('POST', '{"conversationUid": 7, "title": "My new title"}');
        $response = $this->subject->renameConversation($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('My new title', $data['title']);
    }

    #[Test]
    public function renameConversationReturns404WhenConversationNotFound(): void
    {
        $this->repository->method('findOneByUidAndBeUser')->willReturn(null);

        $request = $this->createRequest('POST', '{"conversationUid": 99, "title": "X"}');
        $response = $this->subject->renameConversation($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function renameConversationReturns400WhenTitleIsEmpty(): void
    {
        $conversation = Conversation::fromRow(['uid' => 7, 'be_user' => 1]);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $request = $this->createRequest('POST', '{"conversationUid": 7, "title": "   "}');
        $response = $this->subject->renameConversation($request);

        self::assertSame(400, $response->getStatusCode());
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
    public function getStatusReturnsActiveConversationCount(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getLlmTaskUid')->willReturn(1);
        $this->repository->method('countActiveByBeUser')->willReturn(2);
        $subject = new ChatApiController($this->repository, $this->processor, $config, $this->chatService, $this->chatApproval, $this->resourceFactory, $this->storageRepository, new DocumentExtractorRegistry([]), new UploadMimeTypeMap(), $this->uriBuilder);

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

        $subject = new ChatApiController($repository, $this->processor, $config, $this->chatService, $this->chatApproval, $this->resourceFactory, $this->storageRepository, new DocumentExtractorRegistry([]), new UploadMimeTypeMap(), $this->uriBuilder);

        $longContent = str_repeat('x', 100000);
        $request = $this->createRequest('POST', json_encode(['conversationUid' => 1, 'content' => $longContent]));
        $response = $subject->sendMessage($request);

        self::assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function getStatusIncludesVisionCapabilitiesFromChatService(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getLlmTaskUid')->willReturn(1);

        $chatService = $this->createMock(ChatCapabilitiesInterface::class);
        $chatService->method('getProviderCapabilities')->willReturn([
            'visionSupported' => true,
            'maxFileSize' => 20971520,
            'supportedFormats' => ['png', 'jpeg', 'webp', 'pdf'],
        ]);

        $subject = new ChatApiController($this->repository, $this->processor, $config, $chatService, $this->chatApproval, $this->resourceFactory, $this->storageRepository, new DocumentExtractorRegistry([]), new UploadMimeTypeMap(), $this->uriBuilder);
        $request = $this->createRequest('GET', '');
        $response = $subject->getStatus($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['visionSupported']);
        self::assertSame(20971520, $data['maxFileSize']);
        self::assertContains('png', $data['supportedFormats']);
        self::assertContains('pdf', $data['supportedFormats']);
    }

    #[Test]
    public function sendMessageWithFileUidStoresFileMetadata(): void
    {
        $conversation = new Conversation();
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->method('countActiveByBeUser')->willReturn(0);

        $mockFile = $this->createMock(File::class);
        $mockFile->method('checkActionPermission')->with('read')->willReturn(true);
        $mockFile->method('getName')->willReturn('photo.png');
        $mockFile->method('getMimeType')->willReturn('image/png');
        $this->resourceFactory->method('getFileObject')->with(42)->willReturn($mockFile);

        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "Look at this", "fileUid": 42}');
        $response = $this->subject->sendMessage($request);

        self::assertSame(202, $response->getStatusCode());
        $messages = $conversation->getDecodedMessages();
        self::assertCount(1, $messages);
        self::assertSame(42, $messages[0]['fileUid']);
        self::assertSame('photo.png', $messages[0]['fileName']);
        self::assertSame('image/png', $messages[0]['fileMimeType']);
    }

    #[Test]
    public function sendMessageRejects404WhenFileIsNotReadable(): void
    {
        $conversation = new Conversation();
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->method('countActiveByBeUser')->willReturn(0);

        $mockFile = $this->createMock(File::class);
        $mockFile->method('checkActionPermission')->with('read')->willReturn(false);
        $this->resourceFactory->method('getFileObject')->willReturn($mockFile);

        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "Hi", "fileUid": 77}');
        $response = $this->subject->sendMessage($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function sendMessageAcceptsFalFileOutsideUploadFolder(): void
    {
        $conversation = new Conversation();
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->method('countActiveByBeUser')->willReturn(0);

        $file = $this->createMock(File::class);
        $file->method('checkActionPermission')->with('read')->willReturn(true);
        $file->method('getName')->willReturn('document.pdf');
        $file->method('getMimeType')->willReturn('application/pdf');
        $this->resourceFactory->method('getFileObject')->willReturn($file);

        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "Check this", "fileUid": 42}');
        $response = $this->subject->sendMessage($request);

        self::assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function sendMessageRejectsWhenFileLimitExceeded(): void
    {
        $conversation = new Conversation();
        $messages = [];
        for ($i = 1; $i <= 5; $i++) {
            $messages[] = ['role' => 'user', 'content' => "Message $i", 'fileUid' => $i];
        }
        $conversation->setMessages($messages);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->method('countActiveByBeUser')->willReturn(0);

        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "Hello", "fileUid": 6}');
        $response = $this->subject->sendMessage($request);

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('5 files', $data['error']);
    }

    #[Test]
    public function sendMessageReturns404ForMissingFile(): void
    {
        $conversation = new Conversation();
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->method('countActiveByBeUser')->willReturn(0);

        $this->resourceFactory->method('getFileObject')->willThrowException(new RuntimeException('File not found'));

        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "Hello", "fileUid": 999}');
        $response = $this->subject->sendMessage($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function fileUploadStoresFileAndReturnsMetadata(): void
    {
        // Real temp file with PDF magic bytes so finfo detects application/pdf
        $tmpPath = tempnam(sys_get_temp_dir(), 'nr_test_');
        file_put_contents($tmpPath, '%PDF-1.4 fake content');

        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('getMetadata')->with('uri')->willReturn($tmpPath);

        $uploadedFile = $this->createMock(UploadedFileInterface::class);
        $uploadedFile->method('getError')->willReturn(UPLOAD_ERR_OK);
        $uploadedFile->method('getSize')->willReturn(1024);
        $uploadedFile->method('getStream')->willReturn($stream);
        $uploadedFile->method('getClientFilename')->willReturn('report.pdf');

        $falFile = $this->createMock(File::class);
        $falFile->method('getUid')->willReturn(77);
        $falFile->method('getName')->willReturn('report.pdf');
        $falFile->method('getMimeType')->willReturn('application/pdf');
        $falFile->method('getSize')->willReturn(1024);

        $folder = $this->createMock(\TYPO3\CMS\Core\Resource\Folder::class);

        $storage = $this->createMock(\TYPO3\CMS\Core\Resource\ResourceStorage::class);
        $storage->method('hasFolder')->willReturn(true);
        $storage->method('getFolder')->willReturn($folder);
        $storage->method('addFile')->willReturn($falFile);

        $this->storageRepository->method('getDefaultStorage')->willReturn($storage);

        // Build a subject that has application/pdf in the allowlist via registry extractor
        $pdfExtractor = $this->createMock(DocumentExtractorInterface::class);
        $pdfExtractor->method('getSupportedMimeTypes')->willReturn(['application/pdf']);
        $pdfExtractor->method('isAvailable')->willReturn(true);

        $subject = new ChatApiController(
            $this->repository,
            $this->processor,
            $this->config,
            $this->chatService,
            $this->chatApproval,
            $this->resourceFactory,
            $this->storageRepository,
            new DocumentExtractorRegistry([$pdfExtractor]),
            new UploadMimeTypeMap(),
            $this->uriBuilder,
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUploadedFiles')->willReturn(['file' => $uploadedFile]);

        try {
            $response = $subject->fileUpload($request);
        } finally {
            @unlink($tmpPath);
        }

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame(77, $data['fileUid']);
        self::assertSame('report.pdf', $data['name']);
        self::assertSame('application/pdf', $data['mimeType']);
        self::assertSame(1024, $data['size']);
    }

    /**
     * Attaching the same picture to a second conversation is an ordinary thing
     * to do. RENAME alone would answer it with report_01.pdf, a second sys_file
     * row and a second set of metadata for one document; the folder fills up
     * with copies nobody asked for and the alternative text has to be written
     * again for each. Same name, same sha1 — hand back the file that is there.
     */
    #[Test]
    public function fileUploadReturnsTheFileAlreadyThereWhenTheContentIsIdentical(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'nr_test_');
        file_put_contents($tmpPath, '%PDF-1.4 fake content');

        $existing = $this->createMock(File::class);
        $existing->method('getUid')->willReturn(42);
        $existing->method('getName')->willReturn('report.pdf');
        $existing->method('getMimeType')->willReturn('application/pdf');
        $existing->method('getSha1')->willReturn(sha1_file($tmpPath));

        $storage = $this->createMock(\TYPO3\CMS\Core\Resource\ResourceStorage::class);
        $storage->method('hasFolder')->willReturn(true);
        $storage->method('getFolder')->willReturn($this->createMock(\TYPO3\CMS\Core\Resource\Folder::class));
        $storage->method('sanitizeFileName')->willReturn('report.pdf');
        $storage->method('hasFileInFolder')->willReturn(true);
        $storage->method('getFileInFolder')->willReturn($existing);
        $storage->expects(self::never())->method('addFile');

        $response = $this->uploadPdf($storage, $tmpPath);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(42, json_decode((string) $response->getBody(), true)['fileUid']);
    }

    /**
     * Same name, different content is a different file and must not be silently
     * treated as the one that is there — nor may it replace it. RENAME keeps
     * both.
     */
    #[Test]
    public function fileUploadKeepsBothWhenTheNameCollidesWithOtherContent(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'nr_test_');
        file_put_contents($tmpPath, '%PDF-1.4 fake content');

        $existing = $this->createMock(File::class);
        // Any digest that is not the upload's. Written as a literal rather than
        // computed: the test needs a value that differs, and hashing something
        // here only raises a weak-hash finding over a line that hashes nothing
        // anybody relies on.
        $existing->method('getSha1')->willReturn('da39a3ee5e6b4b0d3255bfef95601890afd80709');

        $stored = $this->createMock(File::class);
        $stored->method('getUid')->willReturn(78);
        $stored->method('getName')->willReturn('report_01.pdf');
        $stored->method('getMimeType')->willReturn('application/pdf');

        $storage = $this->createMock(\TYPO3\CMS\Core\Resource\ResourceStorage::class);
        $storage->method('hasFolder')->willReturn(true);
        $storage->method('getFolder')->willReturn($this->createMock(\TYPO3\CMS\Core\Resource\Folder::class));
        $storage->method('sanitizeFileName')->willReturn('report.pdf');
        $storage->method('hasFileInFolder')->willReturn(true);
        $storage->method('getFileInFolder')->willReturn($existing);
        $storage->expects(self::once())->method('addFile')->with(
            self::anything(),
            self::anything(),
            'report.pdf',
            DuplicationBehavior::RENAME,
        )->willReturn($stored);

        $response = $this->uploadPdf($storage, $tmpPath);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(78, json_decode((string) $response->getBody(), true)['fileUid']);
    }

    /**
     * The file mounts and permissions of the logged-in user decide where the
     * chat may write, and core makes that decision inside addFile(). Without
     * catching it the refusal reached the browser as a 500 and read like a
     * broken chat rather than a missing permission.
     */
    #[Test]
    public function fileUploadAnswers403WhenTheUserMayNotWriteThere(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'nr_test_');
        file_put_contents($tmpPath, '%PDF-1.4 fake content');

        $storage = $this->createMock(\TYPO3\CMS\Core\Resource\ResourceStorage::class);
        $storage->method('hasFolder')->willReturn(true);
        $storage->method('getFolder')->willReturn($this->createMock(\TYPO3\CMS\Core\Resource\Folder::class));
        $storage->method('sanitizeFileName')->willReturn('report.pdf');
        $storage->method('hasFileInFolder')->willReturn(false);
        $storage->method('addFile')->willThrowException(
            new InsufficientFolderWritePermissionsException('no write permission', 1234567890),
        );

        $response = $this->uploadPdf($storage, $tmpPath);

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * The attachment folder is a setting, because an attachment is a managed
     * file in someone's fileadmin from the moment it is uploaded. The per-user
     * subfolder below it is not configurable: it is what keeps one user's
     * attachments out of another's.
     */
    #[Test]
    public function fileUploadCreatesTheConfiguredFolderPerUser(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'nr_test_');
        file_put_contents($tmpPath, '%PDF-1.4 fake content');

        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getAttachmentFolder')->willReturn('agent-uploads');

        $stored = $this->createMock(File::class);
        $stored->method('getUid')->willReturn(5);
        $stored->method('getName')->willReturn('report.pdf');
        $stored->method('getMimeType')->willReturn('application/pdf');

        $storage = $this->createMock(\TYPO3\CMS\Core\Resource\ResourceStorage::class);
        $storage->method('hasFolder')->with('agent-uploads/1')->willReturn(false);
        $storage->expects(self::once())->method('createFolder')->with('agent-uploads/1')
            ->willReturn($this->createMock(\TYPO3\CMS\Core\Resource\Folder::class));
        $storage->method('sanitizeFileName')->willReturn('report.pdf');
        $storage->method('hasFileInFolder')->willReturn(false);
        $storage->method('addFile')->willReturn($stored);

        $response = $this->uploadPdf($storage, $tmpPath, $config);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * One PDF upload against a given storage. The allowlist comes from a
     * registered extractor, as it does in an installation without a
     * document-capable provider.
     */
    private function uploadPdf(
        \TYPO3\CMS\Core\Resource\ResourceStorage $storage,
        string $tmpPath,
        ?ExtensionConfiguration $config = null,
    ): \Psr\Http\Message\ResponseInterface {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getMetadata')->with('uri')->willReturn($tmpPath);

        $uploadedFile = $this->createMock(UploadedFileInterface::class);
        $uploadedFile->method('getError')->willReturn(UPLOAD_ERR_OK);
        $uploadedFile->method('getSize')->willReturn(1024);
        $uploadedFile->method('getStream')->willReturn($stream);
        $uploadedFile->method('getClientFilename')->willReturn('report.pdf');

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getDefaultStorage')->willReturn($storage);

        $pdfExtractor = $this->createMock(DocumentExtractorInterface::class);
        $pdfExtractor->method('getSupportedMimeTypes')->willReturn(['application/pdf']);
        $pdfExtractor->method('isAvailable')->willReturn(true);

        $subject = new ChatApiController(
            $this->repository,
            $this->processor,
            $config ?? $this->config,
            $this->chatService,
            $this->chatApproval,
            $this->resourceFactory,
            $storageRepository,
            new DocumentExtractorRegistry([$pdfExtractor]),
            new UploadMimeTypeMap(),
            $this->uriBuilder,
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUploadedFiles')->willReturn(['file' => $uploadedFile]);

        try {
            return $subject->fileUpload($request);
        } finally {
            @unlink($tmpPath);
        }
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
        // Create a real temp file with plain-text content so finfo detects it as text/plain
        $tmpPath = tempnam(sys_get_temp_dir(), 'nr_test_');
        file_put_contents($tmpPath, 'Hello, this is plain text content.');

        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('getMetadata')->with('uri')->willReturn($tmpPath);

        $file = $this->createMock(UploadedFileInterface::class);
        $file->method('getError')->willReturn(UPLOAD_ERR_OK);
        $file->method('getSize')->willReturn(1024);
        $file->method('getStream')->willReturn($stream);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUploadedFiles')->willReturn(['file' => $file]);

        try {
            $response = $this->subject->fileUpload($request);
        } finally {
            @unlink($tmpPath);
        }

        self::assertSame(422, $response->getStatusCode());
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

    #[Test]
    public function fileUploadAcceptsExtractionBackedMimeType(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'nr_test_');
        file_put_contents($tmpPath, 'Hello TXT');

        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('getMetadata')->with('uri')->willReturn($tmpPath);

        $uploadedFile = $this->createMock(UploadedFileInterface::class);
        $uploadedFile->method('getError')->willReturn(UPLOAD_ERR_OK);
        $uploadedFile->method('getSize')->willReturn(9);
        $uploadedFile->method('getStream')->willReturn($stream);
        $uploadedFile->method('getClientFilename')->willReturn('test.txt');

        $extractor = $this->createMock(DocumentExtractorInterface::class);
        $extractor->method('getSupportedMimeTypes')->willReturn(['text/plain']);
        $extractor->method('isAvailable')->willReturn(true);

        $falFile = $this->createMock(\TYPO3\CMS\Core\Resource\File::class);
        $falFile->method('getUid')->willReturn(99);
        $falFile->method('getName')->willReturn('test.txt');
        $falFile->method('getMimeType')->willReturn('text/plain');
        $falFile->method('getSize')->willReturn(9);

        $folder = $this->createMock(\TYPO3\CMS\Core\Resource\Folder::class);
        $storage = $this->createMock(\TYPO3\CMS\Core\Resource\ResourceStorage::class);
        $storage->method('addFile')->willReturn($falFile);
        $storage->method('getFolder')->willReturn($folder);
        $storage->method('hasFolder')->willReturn(true);
        $this->storageRepository->method('getDefaultStorage')->willReturn($storage);

        $subject = new ChatApiController(
            $this->repository,
            $this->processor,
            $this->config,
            $this->chatService,
            $this->chatApproval,
            $this->resourceFactory,
            $this->storageRepository,
            new DocumentExtractorRegistry([$extractor]),
            new UploadMimeTypeMap(),
            $this->uriBuilder,
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUploadedFiles')->willReturn(['file' => $uploadedFile]);

        try {
            $response = $subject->fileUpload($request);
        } finally {
            @unlink($tmpPath);
        }

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function fileUploadAcceptsHeicWhenTheProviderAdvertisesIt(): void
    {
        // The regression this guards: getProviderCapabilities() put Gemini's
        // heic/heif into supportedFormats, so the picker offered .heic — but the
        // endpoint's own extension→MIME map listed neither, so the detected
        // image/heic missed the allow-list and the upload came back 422. Both
        // sides now read UploadMimeTypeMap, so the picker cannot offer a type
        // this check rejects.
        $tmpPath = tempnam(sys_get_temp_dir(), 'nr_test_');
        // A minimal ISO-BMFF box with the `heic` brand — finfo reports image/heic.
        file_put_contents($tmpPath, "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00heicmif1" . str_repeat("\x00", 64));

        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('getMetadata')->with('uri')->willReturn($tmpPath);

        $uploadedFile = $this->createMock(UploadedFileInterface::class);
        $uploadedFile->method('getError')->willReturn(UPLOAD_ERR_OK);
        $uploadedFile->method('getSize')->willReturn(88);
        $uploadedFile->method('getStream')->willReturn($stream);
        $uploadedFile->method('getClientFilename')->willReturn('photo.heic');

        // No extractor handles image/heic — it can only pass through the provider path.
        $registry = new DocumentExtractorRegistry([]);

        $chatService = $this->createMock(ChatCapabilitiesInterface::class);
        $chatService->method('getProviderCapabilities')->willReturn([
            'visionSupported' => true,
            'maxFileSize' => 0,
            'supportedFormats' => ['png', 'jpeg', 'jpg', 'gif', 'webp', 'heic', 'heif'],
        ]);

        $falFile = $this->createMock(\TYPO3\CMS\Core\Resource\File::class);
        $falFile->method('getUid')->willReturn(43);
        $falFile->method('getName')->willReturn('photo.heic');
        $falFile->method('getMimeType')->willReturn('image/heic');
        $falFile->method('getSize')->willReturn(88);

        $folder = $this->createMock(\TYPO3\CMS\Core\Resource\Folder::class);
        $storage = $this->createMock(\TYPO3\CMS\Core\Resource\ResourceStorage::class);
        $storage->method('addFile')->willReturn($falFile);
        $storage->method('getFolder')->willReturn($folder);
        $storage->method('hasFolder')->willReturn(true);
        $this->storageRepository->method('getDefaultStorage')->willReturn($storage);

        $subject = new ChatApiController(
            $this->repository,
            $this->processor,
            $this->config,
            $chatService,
            $this->chatApproval,
            $this->resourceFactory,
            $this->storageRepository,
            $registry,
            new UploadMimeTypeMap(),
            $this->uriBuilder,
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUploadedFiles')->willReturn(['file' => $uploadedFile]);

        try {
            $response = $subject->fileUpload($request);
        } finally {
            @unlink($tmpPath);
        }

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function fileUploadDoesNotCallRegistryValidateForProviderNativeMimeType(): void
    {
        // A JPEG file is provider-native (reported by chatService->getProviderCapabilities()).
        // The registry has NO image/jpeg extractor — only a PDF extractor.
        // Therefore canExtract('image/jpeg') returns false and validate() must never be called.
        $tmpPath = tempnam(sys_get_temp_dir(), 'nr_test_');

        // Write a minimal valid JPEG (FFD8FF header)
        file_put_contents($tmpPath, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9");

        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('getMetadata')->with('uri')->willReturn($tmpPath);

        $uploadedFile = $this->createMock(UploadedFileInterface::class);
        $uploadedFile->method('getError')->willReturn(UPLOAD_ERR_OK);
        $uploadedFile->method('getSize')->willReturn(20);
        $uploadedFile->method('getStream')->willReturn($stream);
        $uploadedFile->method('getClientFilename')->willReturn('photo.jpg');

        // Registry only knows about PDF — not JPEG
        $extractor = $this->createMock(DocumentExtractorInterface::class);
        $extractor->method('getSupportedMimeTypes')->willReturn(['application/pdf']);
        $extractor->method('isAvailable')->willReturn(true);
        $extractor->expects(self::never())->method('validate'); // MUST NOT be called

        $registry = new DocumentExtractorRegistry([$extractor]);

        // chatService reports jpeg as provider-native (supportedFormats contains extensions, not MIME types)
        $chatService = $this->createMock(ChatCapabilitiesInterface::class);
        $chatService->method('getProviderCapabilities')->willReturn([
            'visionSupported' => true,
            'maxFileSize' => 0,
            'supportedFormats' => ['jpg', 'jpeg'],
        ]);

        $falFile = $this->createMock(\TYPO3\CMS\Core\Resource\File::class);
        $falFile->method('getUid')->willReturn(42);
        $falFile->method('getName')->willReturn('photo.jpg');
        $falFile->method('getMimeType')->willReturn('image/jpeg');
        $falFile->method('getSize')->willReturn(20);

        $folder = $this->createMock(\TYPO3\CMS\Core\Resource\Folder::class);
        $storage = $this->createMock(\TYPO3\CMS\Core\Resource\ResourceStorage::class);
        $storage->method('addFile')->willReturn($falFile);
        $storage->method('getFolder')->willReturn($folder);
        $storage->method('hasFolder')->willReturn(true);
        $this->storageRepository->method('getDefaultStorage')->willReturn($storage);

        $subject = new ChatApiController(
            $this->repository,
            $this->processor,
            $this->config,
            $chatService,
            $this->chatApproval,
            $this->resourceFactory,
            $this->storageRepository,
            $registry,
            new UploadMimeTypeMap(),
            $this->uriBuilder,
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUploadedFiles')->willReturn(['file' => $uploadedFile]);

        try {
            $response = $subject->fileUpload($request);
        } finally {
            @unlink($tmpPath);
        }

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function fileUploadReturns422ForUnsupportedMimeType(): void
    {
        // Registry with no extractors → all MIME types rejected
        $tmpPath = tempnam(sys_get_temp_dir(), 'nr_test_');
        file_put_contents($tmpPath, 'Hello, this is plain text content for mime type test.');

        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('getMetadata')->with('uri')->willReturn($tmpPath);

        $file = $this->createMock(UploadedFileInterface::class);
        $file->method('getError')->willReturn(UPLOAD_ERR_OK);
        $file->method('getSize')->willReturn(50);
        $file->method('getStream')->willReturn($stream);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUploadedFiles')->willReturn(['file' => $file]);

        try {
            $response = $this->subject->fileUpload($request);
        } finally {
            @unlink($tmpPath);
        }

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function fileUploadReturns422WhenValidationFails(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'nr_test_');
        file_put_contents($tmpPath, 'Hello TXT');

        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('getMetadata')->with('uri')->willReturn($tmpPath);

        $uploadedFile = $this->createMock(UploadedFileInterface::class);
        $uploadedFile->method('getError')->willReturn(UPLOAD_ERR_OK);
        $uploadedFile->method('getSize')->willReturn(9);
        $uploadedFile->method('getStream')->willReturn($stream);

        $extractor = $this->createMock(DocumentExtractorInterface::class);
        $extractor->method('getSupportedMimeTypes')->willReturn(['text/plain']);
        $extractor->method('isAvailable')->willReturn(true);
        $extractor->method('validate')->willThrowException(new RuntimeException('corrupt'));

        $subject = new ChatApiController(
            $this->repository,
            $this->processor,
            $this->config,
            $this->chatService,
            $this->chatApproval,
            $this->resourceFactory,
            $this->storageRepository,
            new DocumentExtractorRegistry([$extractor]),
            new UploadMimeTypeMap(),
            $this->uriBuilder,
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUploadedFiles')->willReturn(['file' => $uploadedFile]);

        try {
            $response = $subject->fileUpload($request);
        } finally {
            @unlink($tmpPath);
        }

        self::assertSame(422, $response->getStatusCode());
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
