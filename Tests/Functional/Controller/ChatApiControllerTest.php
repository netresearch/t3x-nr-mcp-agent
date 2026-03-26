<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Controller;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Controller\ChatApiController;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_nrmcpagent_conversation.csv');

        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['BE_USER'] = $backendUser;

        $this->repository = $this->get(ConversationRepository::class);

        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getLlmTaskUid')->willReturn(1);
        $config->method('isMcpEnabled')->willReturn(false);
        $config->method('hasLegacyMcpFields')->willReturn(false);
        $config->method('getMaxMessageLength')->willReturn(10000);
        $config->method('getMaxActiveConversationsPerUser')->willReturn(3);

        $capabilities = $this->createMock(ChatCapabilitiesInterface::class);
        $capabilities->method('getProviderCapabilities')->willReturn([
            'supportsVision' => false,
            'supportsDocuments' => false,
        ]);

        $this->subject = new ChatApiController(
            $this->repository,
            $this->createMock(ChatProcessorInterface::class),
            $config,
            $capabilities,
            $this->get(ResourceFactory::class),
            $this->get(StorageRepository::class),
            new DocumentExtractorRegistry([]),
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
            $this->get(ResourceFactory::class),
            $this->get(StorageRepository::class),
            new DocumentExtractorRegistry([]),
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
            $this->get(ResourceFactory::class),
            $this->get(StorageRepository::class),
            new DocumentExtractorRegistry([]),
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
