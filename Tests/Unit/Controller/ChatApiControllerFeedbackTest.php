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
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * The controller's side of NEXT-167 (ADR-017): a configuration failure phrased
 * per reader, and a "go on" message on a conversation that waits for an
 * approval.
 */
#[CoversClass(ChatApiController::class)]
final class ChatApiControllerFeedbackTest extends TestCase
{
    /** Demo conversation 49, verbatim: what every reader was shown. */
    private const PROVIDER_FAILURE = 'API key identifier is required for provider OpenAI';

    private ChatApiController $subject;

    private ConversationRepository&MockObject $repository;

    private ChatProcessorInterface&MockObject $processor;

    private ChatApprovalInterface&MockObject $chatApproval;

    private UriBuilder&MockObject $uriBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(ConversationRepository::class);
        $this->repository->method('updateIf')->willReturn(true);
        $this->processor = $this->createMock(ChatProcessorInterface::class);
        $this->chatApproval = $this->createMock(ChatApprovalInterface::class);
        $this->uriBuilder = $this->createMock(UriBuilder::class);

        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getMaxMessageLength')->willReturn(10000);
        $config->method('getMaxActiveConversationsPerUser')->willReturn(0);

        $this->subject = new ChatApiController(
            $this->repository,
            $this->processor,
            $config,
            $this->createMock(ChatCapabilitiesInterface::class),
            $this->chatApproval,
            $this->createMock(ResourceFactory::class),
            $this->createMock(StorageRepository::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->uriBuilder,
        );

        $this->setUpBackendUser(isAdmin: false);

        $languageService = $this->createStub(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn(string $input): string => str_replace('LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:', '', $input),
        );
        $GLOBALS['LANG'] = $languageService;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    private function setUpBackendUser(bool $isAdmin): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 2, 'usergroup' => '', 'admin' => $isAdmin ? 1 : 0];
        $backendUser->method('isAdmin')->willReturn($isAdmin);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }

    private function request(string $body, array $query = []): ServerRequestInterface
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($stream);
        $request->method('getQueryParams')->willReturn($query);

        return $request;
    }

    private function failedConversation(string $code): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(2);
        $conversation->appendMessage(MessageRole::User, 'what is the last LLM eror about?');
        $conversation->setStatus(ConversationStatus::Failed);
        $conversation->setErrorMessage(self::PROVIDER_FAILURE, $code);

        return $conversation;
    }

    // ---- 3. configuration failures ------------------------------------------

    #[Test]
    public function anEditorIsToldToAskTheAdministrationInsteadOfSeeingTheProvidersSentence(): void
    {
        $this->repository->method('findOneByUidAndBeUser')->willReturn($this->failedConversation('providerNotConfigured'));
        $this->uriBuilder->expects(self::never())->method('buildUriFromRoute');

        $data = $this->json($this->subject->getMessages($this->request('', ['conversationUid' => '1'])));

        self::assertSame('error.providerNotConfigured', $data['errorMessage']);
        self::assertSame('', $data['errorLink']);
    }

    #[Test]
    public function anAdministratorGetsTheTechnicalTextAndALinkToTheProviders(): void
    {
        $this->setUpBackendUser(isAdmin: true);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($this->failedConversation('providerNotConfigured'));
        $this->uriBuilder->method('buildUriFromRoute')->with('nrllm_providers')
            ->willReturn(new Uri('/typo3/module/nrllm/providers'));

        $data = $this->json($this->subject->getMessages($this->request('', ['conversationUid' => '1'])));

        self::assertSame(self::PROVIDER_FAILURE, $data['errorMessage']);
        self::assertSame('/typo3/module/nrllm/providers', $data['errorLink']);
        self::assertSame('error.openProviders', $data['errorLinkLabel']);
    }

    #[Test]
    public function aChatWithoutATaskLinksAnAdministratorToTheTasks(): void
    {
        $this->setUpBackendUser(isAdmin: true);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($this->failedConversation('chatNotConfigured'));
        $this->uriBuilder->method('buildUriFromRoute')->with('nrllm_tasks')
            ->willReturn(new Uri('/typo3/module/nrllm/tasks'));

        $data = $this->json($this->subject->getMessages($this->request('', ['conversationUid' => '1'])));

        self::assertSame('/typo3/module/nrllm/tasks', $data['errorLink']);
    }

    #[Test]
    public function aFailureWithoutACodeIsShownAsItWasStored(): void
    {
        $this->repository->method('findOneByUidAndBeUser')->willReturn($this->failedConversation(''));

        $data = $this->json($this->subject->getMessages($this->request('', ['conversationUid' => '1'])));

        self::assertSame(self::PROVIDER_FAILURE, $data['errorMessage']);
        self::assertSame('', $data['errorLink']);
    }

    #[Test]
    public function thePollFastPathPhrasesTheFailureToo(): void
    {
        $this->repository->method('findPollStatus')->willReturn([
            'status' => 'failed',
            'message_count' => 1,
            'error_message' => self::PROVIDER_FAILURE,
            'error_code' => 'providerNotConfigured',
            'approval_run_uuid' => '',
            'tstamp' => time(),
            'activity' => [],
        ]);

        $data = $this->json($this->subject->getMessages($this->request('', ['conversationUid' => '1', 'after' => '1'])));

        self::assertSame('error.providerNotConfigured', $data['errorMessage']);
    }

    #[Test]
    public function theConversationListPhrasesTheFailureToo(): void
    {
        $conversation = $this->failedConversation('providerNotConfigured');
        $this->repository->method('findByBeUser')->willReturn([$conversation]);

        $data = $this->json($this->subject->listConversations());

        self::assertIsArray($data['conversations']);
        self::assertSame('error.providerNotConfigured', $data['conversations'][0]['errorMessage'] ?? null);
    }

    // ---- 7. "go on" while an approval is pending -----------------------------

    private function parked(): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(2);
        $conversation->appendMessage(MessageRole::User, 'Lege unter der Seite 10011 eine versteckte Unterseite an.');
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid('ccc12670-2f8d-4595-a89c-d81291ff5820');

        return $conversation;
    }

    /**
     * Demo conversation 84: "weiter" while the card was on screen started a
     * second run, which drafted the page a second time.
     */
    #[Test]
    public function goingOnWhileTheRunStillWaitsStartsNoRunAndKeepsTheCard(): void
    {
        $this->setUpBackendUser(isAdmin: true);
        $conversation = $this->parked();
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->chatApproval->method('inspectPendingRun')->willReturn(['state' => ChatApprovalInterface::PENDING_RUN_WAITING, 'writes' => []]);
        $this->processor->expects(self::never())->method('dispatch');
        $this->repository->expects(self::never())->method('updateIf');

        $response = $this->subject->sendMessage($this->request('{"conversationUid": 1, "content": "weiter"}'));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('error.approvalStillPending', $this->json($response)['error']);
        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus());
        self::assertSame('ccc12670-2f8d-4595-a89c-d81291ff5820', $conversation->getApprovalRunUuid());
        self::assertSame(1, $conversation->getMessageCount());
    }

    /**
     * Demo conversation 79: approved in AI Tasks, finished there, and "habe
     * alles freigegeben" in the chat drafted the page again. Now the chat says
     * what the run wrote, so the next turn knows the page exists.
     */
    #[Test]
    public function goingOnAfterTheRunFinishedElsewhereAddsWhatItWroteAndStartsNoRun(): void
    {
        $conversation = $this->parked();
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->chatApproval->method('inspectPendingRun')->willReturn([
            'state' => ChatApprovalInterface::PENDING_RUN_SETTLED,
            'writes' => ['pages:10073', 'tt_content:10077'],
        ]);
        $this->processor->expects(self::never())->method('dispatch');

        $response = $this->subject->sendMessage($this->request('{"conversationUid": 1, "content": "habe alles freigegeben"}'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame('', $conversation->getApprovalRunUuid());

        $messages = $conversation->getDecodedMessages();
        self::assertCount(3, $messages);
        self::assertSame(['user', 'habe alles freigegeben'], [$messages[1]['role'], $messages[1]['content']]);
        self::assertSame('assistant', $messages[2]['role']);
        // Language-neutral for the model; the reader gets the label from the notice.
        self::assertSame(
            '[The pending step was decided outside this chat and its run has finished. Records it wrote: pages:10073, tt_content:10077.]',
            $messages[2]['content'],
        );
        self::assertSame('runFinishedOutside', $messages[2]['notice'] ?? null);
        self::assertSame(['pages:10073', 'tt_content:10077'], $messages[2]['noticeArgs'] ?? null);
    }

    /**
     * A reader who may not decide approvals gets no card, so the hint cannot
     * send them to one.
     */
    #[Test]
    public function aReaderWithoutTheCardIsSentToAiTasksInstead(): void
    {
        $this->repository->method('findOneByUidAndBeUser')->willReturn($this->parked());
        $this->chatApproval->method('inspectPendingRun')->willReturn(['state' => ChatApprovalInterface::PENDING_RUN_WAITING, 'writes' => []]);

        $response = $this->subject->sendMessage($this->request('{"conversationUid": 1, "content": "weiter"}'));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('error.approvalStillPendingElsewhere', $this->json($response)['error']);
    }

    #[Test]
    public function goingOnWhileTheRunIsBeingCarriedOnElsewhereIsBusy(): void
    {
        $this->repository->method('findOneByUidAndBeUser')->willReturn($this->parked());
        $this->chatApproval->method('inspectPendingRun')->willReturn(['state' => ChatApprovalInterface::PENDING_RUN_BUSY, 'writes' => []]);
        $this->processor->expects(self::never())->method('dispatch');

        self::assertSame(409, $this->subject->sendMessage($this->request('{"conversationUid": 1, "content": "continue"}'))->getStatusCode());
    }

    #[Test]
    public function goingOnWhenTheRunCannotBeReadIsAnOrdinaryTurn(): void
    {
        $this->repository->method('findOneByUidAndBeUser')->willReturn($this->parked());
        $this->chatApproval->method('inspectPendingRun')->willReturn(['state' => ChatApprovalInterface::PENDING_RUN_UNKNOWN, 'writes' => []]);
        $this->processor->expects(self::once())->method('dispatch');

        self::assertSame(202, $this->subject->sendMessage($this->request('{"conversationUid": 1, "content": "weiter"}'))->getStatusCode());
    }

    #[Test]
    public function aNewRequestWhileAnApprovalIsPendingIsNotInspected(): void
    {
        $this->repository->method('findOneByUidAndBeUser')->willReturn($this->parked());
        $this->chatApproval->expects(self::never())->method('inspectPendingRun');
        $this->processor->expects(self::once())->method('dispatch');

        $response = $this->subject->sendMessage($this->request('{"conversationUid": 1, "content": "Es fehlt noch das Element für die neue Unterseite"}'));

        self::assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function goingOnOnAConversationThatWaitsForNothingIsAnOrdinaryTurn(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(2);
        $conversation->appendMessage(MessageRole::User, 'Hallo');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->chatApproval->expects(self::never())->method('inspectPendingRun');

        self::assertSame(202, $this->subject->sendMessage($this->request('{"conversationUid": 1, "content": "weiter"}'))->getStatusCode());
    }
}
