<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Controller;

use Netresearch\NrLlm\Service\Agent\Inbox\PendingCallView;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Controller\ChatApiController;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class ChatApiControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    private ConversationRepository $repository;
    private ChatApiController $subject;
    private ExtensionConfiguration $config;
    private ChatCapabilitiesInterface $capabilities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_nrmcpagent_conversation.csv');

        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['BE_USER'] = $backendUser;

        $this->repository = $this->get(ConversationRepository::class);

        $config = $this->config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getLlmTaskUid')->willReturn(1);
        $config->method('isMcpEnabled')->willReturn(false);
        $config->method('hasLegacyMcpFields')->willReturn(false);
        $config->method('getMaxMessageLength')->willReturn(10000);
        $config->method('getMaxActiveConversationsPerUser')->willReturn(3);

        $capabilities = $this->capabilities = $this->createMock(ChatCapabilitiesInterface::class);
        $capabilities->method('getProviderCapabilities')->willReturn([
            'supportsVision' => false,
            'supportsDocuments' => false,
        ]);

        $this->subject = new ChatApiController(
            $this->repository,
            $this->createMock(ChatProcessorInterface::class),
            $config,
            $capabilities,
            $this->get(ChatApprovalInterface::class),
            $this->get(ResourceFactory::class),
            $this->get(StorageRepository::class),
            new DocumentExtractorRegistry([]),
            GeneralUtility::makeInstance(UriBuilder::class),
        );
    }

    /**
     * The controller under test with the approval source replaced.
     *
     * Reading the suspended state belongs to nr-llm's run store; what is under
     * test here is whether the controller reaches for it at all on this request
     * and puts the result on the wire. Seeding a real AgentRun would test
     * nr-llm.
     */
    private function subjectWithPendingApproval(WaitingRunView $view): ChatApiController
    {
        $approval = $this->createMock(ChatApprovalInterface::class);
        $approval->method('pendingApproval')->willReturn($view);

        return new ChatApiController(
            $this->repository,
            $this->createMock(ChatProcessorInterface::class),
            $this->config,
            $this->capabilities,
            $approval,
            $this->get(ResourceFactory::class),
            $this->get(StorageRepository::class),
            new DocumentExtractorRegistry([]),
            GeneralUtility::makeInstance(UriBuilder::class),
        );
    }

    #[Test]
    public function getStatusReturnsAvailableWhenTaskConfigured(): void
    {
        $response = $this->subject->getStatus();

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['available']);
        self::assertFalse($body['mcpEnabled']);
        self::assertArrayHasKey('activeConversationCount', $body);
    }

    #[Test]
    public function listConversationsReturnsOwnConversations(): void
    {
        $response = $this->subject->listConversations();

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('conversations', $body);
        // User 1 has Conv 1 (idle) and Conv 2 (processing) — not archived/deleted
        self::assertCount(2, $body['conversations']);
    }

    #[Test]
    public function createConversationReturnsNewUid(): void
    {
        $response = $this->subject->createConversation();

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('uid', $body);
        self::assertGreaterThan(0, $body['uid']);
    }

    #[Test]
    public function getMessagesReturnsEmptyForIdleConversation(): void
    {
        $request = (new ServerRequest())->withQueryParams(['conversationUid' => 1]);

        $response = $this->subject->getMessages($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('idle', $body['status']);
        self::assertSame([], $body['messages']);
        self::assertSame(0, $body['totalCount']);
    }

    /**
     * The transition poll. A run that pauses for approval writes no message, so
     * the client polls with `after` already equal to the message count and lands
     * on the fast path — which used to answer without a pendingApproval key at
     * all. Polling then stops, because awaiting_approval is not a processing
     * status, so nothing fetched the card afterwards: the user was left with the
     * notice and the deep link until they reloaded.
     */
    #[Test]
    public function getMessagesAnswersTheTransitionPollWithTheApprovalCard(): void
    {
        // Its own fixture: adding the row to the shared one would move the
        // counts three other tests assert on.
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/conversation_awaiting_approval.csv');

        $this->subject = $this->subjectWithPendingApproval(new WaitingRunView(
            runUuid: 'run-uuid-6',
            mode: WaitingRunView::MODE_APPROVAL,
            createdAt: 1710000000,
            configLabel: 'Demo',
            turnDigest: 'digest-6',
            pendingCalls: [new PendingCallView('update_page_metadata', '{"uid":10002}', true)],
        ));

        // after === message_count: exactly what the client sends on the poll
        // that first observes the pause.
        $request = (new ServerRequest())->withQueryParams(['conversationUid' => 6, 'after' => 1]);

        $response = $this->subject->getMessages($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('awaiting_approval', $body['status']);
        self::assertArrayHasKey('pendingApproval', $body);
        self::assertNotNull($body['pendingApproval']);
        self::assertSame('digest-6', $body['pendingApproval']['turnDigest']);
        self::assertSame('update_page_metadata', $body['pendingApproval']['calls'][0]['name']);
    }

    /**
     * The fast path is what keeps an ordinary poll cheap, so it has to stay in
     * place for every conversation that is not parked.
     */
    #[Test]
    public function getMessagesKeepsTheFastPathForAnOrdinaryPoll(): void
    {
        $request = (new ServerRequest())->withQueryParams(['conversationUid' => 2, 'after' => 5]);

        $response = $this->subject->getMessages($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('processing', $body['status']);
        self::assertArrayNotHasKey('pendingApproval', $body);
    }

    #[Test]
    public function getMessagesReturns404ForUnknownConversation(): void
    {
        $request = (new ServerRequest())->withQueryParams(['conversationUid' => 999]);

        $response = $this->subject->getMessages($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function getMessagesReturns404ForOtherUsersConversation(): void
    {
        // Conv 3 belongs to user 2
        $request = (new ServerRequest())->withQueryParams(['conversationUid' => 3]);

        $response = $this->subject->getMessages($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function sendMessageReturns400ForEmptyContent(): void
    {
        $request = (new ServerRequest('/', 'POST'))
            ->withBody($this->streamFor(json_encode([
                'conversationUid' => 1,
                'content' => '   ',
            ])));

        $response = $this->subject->sendMessage($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('Empty', $body['error']);
    }

    #[Test]
    public function sendMessageReturns400WhenTooLong(): void
    {
        $request = (new ServerRequest('/', 'POST'))
            ->withBody($this->streamFor(json_encode([
                'conversationUid' => 1,
                'content' => str_repeat('x', 10001),
            ])));

        $response = $this->subject->sendMessage($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('too long', $body['error']);
    }

    #[Test]
    public function sendMessageReturns409WhenAlreadyProcessing(): void
    {
        // Conv 2 has status 'processing'
        $request = (new ServerRequest('/', 'POST'))
            ->withBody($this->streamFor(json_encode([
                'conversationUid' => 2,
                'content' => 'Hello',
            ])));

        $response = $this->subject->sendMessage($request);

        self::assertSame(409, $response->getStatusCode());
    }

    #[Test]
    public function sendMessageDispatchesAndReturnsProcessing(): void
    {
        $mockProcessor = $this->createMock(ChatProcessorInterface::class);
        $mockProcessor->expects(self::once())->method('dispatch')->with(1);

        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getMaxMessageLength')->willReturn(10000);
        $config->method('getMaxActiveConversationsPerUser')->willReturn(3);

        $controller = new ChatApiController(
            $this->repository,
            $mockProcessor,
            $config,
            $this->createMock(ChatCapabilitiesInterface::class),
            $this->get(ChatApprovalInterface::class),
            $this->get(ResourceFactory::class),
            $this->get(StorageRepository::class),
            new DocumentExtractorRegistry([]),
            GeneralUtility::makeInstance(UriBuilder::class),
        );

        $request = (new ServerRequest('/', 'POST'))
            ->withBody($this->streamFor(json_encode([
                'conversationUid' => 1,
                'content' => 'Hello, AI!',
            ])));

        $response = $controller->sendMessage($request);

        self::assertSame(202, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('processing', $body['status']);
    }

    #[Test]
    public function archiveConversationArchivesRow(): void
    {
        $request = (new ServerRequest('/', 'POST'))
            ->withBody($this->streamFor(json_encode(['conversationUid' => 1])));

        $response = $this->subject->archiveConversation($request);

        self::assertSame(200, $response->getStatusCode());
        $conversation = $this->repository->findByUid(1);
        self::assertTrue($conversation->isArchived());
    }

    #[Test]
    public function togglePinPinsAndUnpinsConversation(): void
    {
        $request = (new ServerRequest('/', 'POST'))
            ->withBody($this->streamFor(json_encode(['conversationUid' => 1])));

        $response = $this->subject->togglePin($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['pinned']);

        // Toggle again — should unpin
        $response2 = $this->subject->togglePin($request);
        $body2 = json_decode((string) $response2->getBody(), true);
        self::assertFalse($body2['pinned']);
    }

    #[Test]
    public function accessDeniedWhenUserNotInAllowedGroups(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([99]); // group 99 does not exist

        $controller = new ChatApiController(
            $this->repository,
            $this->createMock(ChatProcessorInterface::class),
            $config,
            $this->createMock(ChatCapabilitiesInterface::class),
            $this->get(ChatApprovalInterface::class),
            $this->get(ResourceFactory::class),
            $this->get(StorageRepository::class),
            new DocumentExtractorRegistry([]),
            GeneralUtility::makeInstance(UriBuilder::class),
        );

        $response = $controller->listConversations();

        // Admin user (uid=1) is always allowed regardless of group restrictions
        // because admin=1 bypasses group check
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function resumeConversationReturns400WhenNotResumable(): void
    {
        // Conv 1 is 'idle' — not resumable (only error/timeout states are resumable)
        $request = (new ServerRequest('/', 'POST'))
            ->withBody($this->streamFor(json_encode(['conversationUid' => 1])));

        $response = $this->subject->resumeConversation($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('not resumable', $body['error']);
    }

    /**
     * Creates a PSR-7 stream from a string.
     */
    private function streamFor(string $content): \Psr\Http\Message\StreamInterface
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        return new \TYPO3\CMS\Core\Http\Stream($stream);
    }
}
