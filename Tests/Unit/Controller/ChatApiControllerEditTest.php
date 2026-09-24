<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Controller;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Controller\ChatApiController;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use stdClass;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Editing a sent message, the conversation's own instructions, and the view
 * context a turn is sent with (NEXT-172).
 */
final class ChatApiControllerEditTest extends TestCase
{
    private ConversationRepository&MockObject $repository;
    private ChatProcessorInterface&MockObject $processor;
    private ChatApiController $subject;

    /** The conversation as the claim wrote it, or null when nothing was claimed. */
    private ?Conversation $claimed = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(ConversationRepository::class);
        $this->repository->method('updateIf')->willReturnCallback(function (Conversation $c): bool {
            $this->claimed = clone $c;
            return true;
        });
        $this->processor = $this->createMock(ChatProcessorInterface::class);
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getMaxMessageLength')->willReturn(50);
        $config->method('getMaxActiveConversationsPerUser')->willReturn(3);
        $chatService = $this->createMock(ChatCapabilitiesInterface::class);

        $this->subject = new ChatApiController(
            $this->repository,
            $this->processor,
            $config,
            $chatService,
            $this->createMock(ChatApprovalInterface::class),
            $this->createMock(ResourceFactory::class),
            $this->createMock(StorageRepository::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->createMock(UriBuilder::class),
        );

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->user = ['uid' => 1, 'usergroup' => ''];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function conversation(array $messages, ConversationStatus $status = ConversationStatus::Idle): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setMessages($messages);
        $conversation->setStatus($status);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        return $conversation;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function transcript(): array
    {
        return [
            ['role' => 'user', 'content' => 'first question', 'createdAt' => '2026-09-23T08:00:00+00:00'],
            ['role' => 'assistant', 'content' => 'first answer'],
            ['role' => 'user', 'content' => 'second question', 'fileUid' => 5, 'fileName' => 'a.pdf', 'fileMimeType' => 'application/pdf'],
            ['role' => 'assistant', 'content' => 'second answer'],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body): ServerRequestInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn(json_encode($body, JSON_THROW_ON_ERROR));
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($stream);
        $request->method('getQueryParams')->willReturn([]);

        return $request;
    }

    #[Test]
    public function editingReplacesTheMessageAndDropsEverythingAfterIt(): void
    {
        $this->conversation($this->transcript());
        $this->processor->expects(self::once())->method('dispatch');

        $response = $this->subject->editMessage($this->request(['conversationUid' => 1, 'index' => 0, 'expectedContent' => 'first question', 'messageCount' => 4, 'content' => '  better question ']));

        self::assertSame(202, $response->getStatusCode());
        self::assertNotNull($this->claimed);
        $messages = $this->claimed->getDecodedMessages();
        self::assertCount(1, $messages);
        self::assertSame('user', $messages[0]['role']);
        self::assertSame('better question', $messages[0]['content']);
        self::assertSame(ConversationStatus::Processing, $this->claimed->getStatus());
    }

    #[Test]
    public function editingKeepsTheAttachmentOfTheEditedMessage(): void
    {
        $this->conversation($this->transcript());

        $this->subject->editMessage($this->request(['conversationUid' => 1, 'index' => 2, 'expectedContent' => 'second question', 'messageCount' => 4, 'content' => 'reworded']));

        self::assertNotNull($this->claimed);
        $messages = $this->claimed->getDecodedMessages();
        self::assertCount(3, $messages);
        self::assertSame('reworded', $messages[2]['content']);
        self::assertSame(5, $messages[2]['fileUid']);
        self::assertSame('first answer', $messages[1]['content']);
    }

    #[Test]
    public function onlyAUserMessageCanBeEdited(): void
    {
        $this->conversation($this->transcript());
        $this->processor->expects(self::never())->method('dispatch');

        foreach ([1, 4, -1] as $index) {
            $response = $this->subject->editMessage($this->request(['conversationUid' => 1, 'index' => $index, 'content' => 'x']));
            self::assertSame(400, $response->getStatusCode(), 'index ' . $index);
        }

        $response = $this->subject->editMessage($this->request(['conversationUid' => 1, 'index' => '0', 'content' => 'x']));
        self::assertSame(400, $response->getStatusCode(), 'a string index');
        self::assertNull($this->claimed);
    }

    #[Test]
    public function aMessageWithStructuredContentCannotBeEdited(): void
    {
        $this->conversation([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]]);

        $response = $this->subject->editMessage($this->request(['conversationUid' => 1, 'index' => 0, 'expectedContent' => 'first question', 'messageCount' => 4, 'content' => 'x']));

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($this->claimed);
    }

    #[Test]
    public function editingIsRefusedWhileATurnIsRunning(): void
    {
        $this->conversation($this->transcript(), ConversationStatus::Processing);
        $this->processor->expects(self::never())->method('dispatch');

        $response = $this->subject->editMessage($this->request(['conversationUid' => 1, 'index' => 0, 'expectedContent' => 'first question', 'messageCount' => 4, 'content' => 'x']));

        self::assertSame(409, $response->getStatusCode());
        self::assertNull($this->claimed);
    }

    #[Test]
    public function editingRefusesAnEmptyOrOverlongMessage(): void
    {
        $this->conversation($this->transcript());

        self::assertSame(400, $this->subject->editMessage($this->request(['conversationUid' => 1, 'index' => 0, 'content' => '  ']))->getStatusCode());
        self::assertSame(400, $this->subject->editMessage($this->request(['conversationUid' => 1, 'index' => 0, 'content' => str_repeat('x', 51)]))->getStatusCode());
        self::assertNull($this->claimed);
    }

    #[Test]
    public function editingAbandonsAPendingApprovalLikeANewMessage(): void
    {
        $conversation = $this->conversation($this->transcript(), ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid('run-1');

        $response = $this->subject->editMessage($this->request(['conversationUid' => 1, 'index' => 0, 'expectedContent' => 'first question', 'messageCount' => 4, 'content' => 'x']));

        self::assertSame(202, $response->getStatusCode());
        self::assertNotNull($this->claimed);
        self::assertSame('', $this->claimed->getApprovalRunUuid());
    }

    #[Test]
    public function aTurnCarriesWhereTheUserIsInTheBackend(): void
    {
        $this->conversation([]);

        $this->subject->sendMessage($this->request([
            'conversationUid' => 1,
            'content' => 'summarise this page',
            'context' => ['pageId' => 12, 'module' => 'web_layout'],
        ]));

        self::assertNotNull($this->claimed);
        self::assertSame(['pageId' => 12, 'module' => 'web_layout'], $this->claimed->getViewContext());
    }

    #[Test]
    public function aMalformedContextIsStoredAsAbsent(): void
    {
        $conversation = $this->conversation([]);
        $conversation->setViewContext(3, 'web_list');

        $this->subject->sendMessage($this->request([
            'conversationUid' => 1,
            'content' => 'hi',
            'context' => ['pageId' => '12; drop', 'module' => '../x'],
        ]));

        self::assertNotNull($this->claimed);
        self::assertSame(['pageId' => 0, 'module' => ''], $this->claimed->getViewContext());
    }

    #[Test]
    public function aTurnWithoutContextClearsTheOneBefore(): void
    {
        $conversation = $this->conversation([]);
        $conversation->setViewContext(3, 'web_list');

        $this->subject->sendMessage($this->request(['conversationUid' => 1, 'content' => 'hi']));

        self::assertNotNull($this->claimed);
        self::assertSame(['pageId' => 0, 'module' => ''], $this->claimed->getViewContext());
    }

    #[Test]
    public function instructionsAreSavedTrimmed(): void
    {
        $this->conversation([]);
        $this->repository->expects(self::once())->method('updateSystemPrompt')->with(0, 'Answer briefly.', 1);

        $response = $this->subject->updateSystemPrompt($this->request(['conversationUid' => 1, 'systemPrompt' => "  Answer briefly.\n"]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['systemPrompt' => 'Answer briefly.'], json_decode((string) $response->getBody(), true));
    }

    #[Test]
    public function emptyInstructionsRemoveThem(): void
    {
        $this->conversation([]);
        $this->repository->expects(self::once())->method('updateSystemPrompt')->with(0, '', 1);

        self::assertSame(200, $this->subject->updateSystemPrompt($this->request(['conversationUid' => 1, 'systemPrompt' => '']))->getStatusCode());
    }

    #[Test]
    public function overlongInstructionsAreRefusedNotCut(): void
    {
        $this->conversation([]);
        $this->repository->expects(self::never())->method('updateSystemPrompt');

        $response = $this->subject->updateSystemPrompt($this->request(['conversationUid' => 1, 'systemPrompt' => str_repeat('x', 10001)]));

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function instructionsCannotChangeWhileATurnIsRunning(): void
    {
        $this->conversation([], ConversationStatus::Processing);
        $this->repository->expects(self::never())->method('updateSystemPrompt');

        $response = $this->subject->updateSystemPrompt($this->request(['conversationUid' => 1, 'systemPrompt' => 'x']));

        self::assertSame(409, $response->getStatusCode());
    }

    #[Test]
    public function theTranscriptCarriesTheInstructions(): void
    {
        $conversation = $this->conversation([]);
        $conversation->setSystemPrompt('Answer briefly.');

        $response = $this->subject->getMessages($this->request([]));

        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertSame('Answer briefly.', $data['systemPrompt']);
    }

    private function subjectWithAllowedGroups(): ChatApiController
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([99]);
        $config->method('getMaxMessageLength')->willReturn(50);
        $config->method('getMaxActiveConversationsPerUser')->willReturn(3);

        return new ChatApiController(
            $this->repository,
            $this->processor,
            $config,
            $this->createMock(ChatCapabilitiesInterface::class),
            $this->createMock(ChatApprovalInterface::class),
            $this->createMock(ResourceFactory::class),
            $this->createMock(StorageRepository::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->createMock(UriBuilder::class),
        );
    }

    #[Test]
    public function bothEndpointsRefuseAUserOutsideTheAllowedGroups(): void
    {
        $this->repository->expects(self::never())->method('findOneByUidAndBeUser');
        $this->repository->expects(self::never())->method('updateSystemPrompt');
        $subject = $this->subjectWithAllowedGroups();

        self::assertSame(403, $subject->editMessage($this->request(['conversationUid' => 1, 'index' => 0, 'content' => 'x']))->getStatusCode());
        self::assertSame(403, $subject->updateSystemPrompt($this->request(['conversationUid' => 1, 'systemPrompt' => 'x']))->getStatusCode());
        self::assertNull($this->claimed);
    }

    #[Test]
    public function bothEndpointsAnswerNotFoundForAnotherUsersConversation(): void
    {
        // The repository only finds conversations of the requesting user.
        $this->repository->method('findOneByUidAndBeUser')->willReturn(null);
        $this->repository->expects(self::never())->method('updateSystemPrompt');

        self::assertSame(404, $this->subject->editMessage($this->request(['conversationUid' => 7, 'index' => 0, 'content' => 'x']))->getStatusCode());
        self::assertSame(404, $this->subject->updateSystemPrompt($this->request(['conversationUid' => 7, 'systemPrompt' => 'x']))->getStatusCode());
        self::assertNull($this->claimed);
    }

    #[Test]
    public function editingRespectsTheLimitOfActiveConversations(): void
    {
        $this->conversation($this->transcript());
        $this->repository->method('countActiveByBeUser')->willReturn(3);
        $this->processor->expects(self::never())->method('dispatch');

        $response = $this->subject->editMessage($this->request(['conversationUid' => 1, 'index' => 0, 'expectedContent' => 'first question', 'messageCount' => 4, 'content' => 'x']));

        self::assertSame(429, $response->getStatusCode());
        self::assertNull($this->claimed);
    }

    #[Test]
    public function anEditThatLosesTheClaimIsNotDispatched(): void
    {
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('updateIf')->willReturn(false);
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setMessages($this->transcript());
        $repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository = $repository;
        $this->processor->expects(self::never())->method('dispatch');

        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getMaxMessageLength')->willReturn(50);
        $config->method('getMaxActiveConversationsPerUser')->willReturn(3);
        $subject = new ChatApiController(
            $repository,
            $this->processor,
            $config,
            $this->createMock(ChatCapabilitiesInterface::class),
            $this->createMock(ChatApprovalInterface::class),
            $this->createMock(ResourceFactory::class),
            $this->createMock(StorageRepository::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->createMock(UriBuilder::class),
        );

        self::assertSame(409, $subject->editMessage($this->request(['conversationUid' => 1, 'index' => 0, 'expectedContent' => 'first question', 'messageCount' => 4, 'content' => 'x']))->getStatusCode());
    }

    /** Another tab changed the transcript: the index may now point at another message. */
    #[Test]
    public function anEditFromAStaleViewIsAConflict(): void
    {
        $this->conversation($this->transcript());
        $this->processor->expects(self::never())->method('dispatch');

        $moved = $this->subject->editMessage($this->request(['conversationUid' => 1, 'index' => 0, 'content' => 'x', 'expectedContent' => 'first question', 'messageCount' => 6]));
        $changed = $this->subject->editMessage($this->request(['conversationUid' => 1, 'index' => 0, 'content' => 'x', 'expectedContent' => 'what I saw', 'messageCount' => 4]));
        $unknown = $this->subject->editMessage($this->request(['conversationUid' => 1, 'index' => 0, 'content' => 'x']));

        self::assertSame(409, $moved->getStatusCode());
        self::assertSame(409, $changed->getStatusCode());
        self::assertSame(409, $unknown->getStatusCode());
        self::assertNull($this->claimed);
    }

    #[Test]
    public function editingTheFirstMessageRenamesAnAutomaticTitle(): void
    {
        $conversation = $this->conversation($this->transcript());
        $conversation->setTitle('first question');

        $this->subject->editMessage($this->request(['conversationUid' => 1, 'index' => 0, 'content' => 'better question', 'expectedContent' => 'first question', 'messageCount' => 4]));

        self::assertNotNull($this->claimed);
        self::assertSame('better question', $this->claimed->getTitle());
    }

    #[Test]
    public function aTitleTheUserChoseStays(): void
    {
        $conversation = $this->conversation($this->transcript());
        $conversation->setTitle('Press release');

        $this->subject->editMessage($this->request(['conversationUid' => 1, 'index' => 0, 'content' => 'better question', 'expectedContent' => 'first question', 'messageCount' => 4]));

        self::assertNotNull($this->claimed);
        self::assertSame('Press release', $this->claimed->getTitle());
    }
}
