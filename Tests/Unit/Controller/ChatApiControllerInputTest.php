<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Controller;

use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Controller\ChatApiController;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\DenyReason;
use Netresearch\NrMcpAgent\Enum\InputHandBackReason;
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Netresearch\NrMcpAgent\Service\InputPause;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use stdClass;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * The two endpoints a question touches (ADR-018): the poll that delivers the
 * reply buttons, and the one that takes the answer.
 */
#[CoversClass(ChatApiController::class)]
final class ChatApiControllerInputTest extends TestCase
{
    private const LLL = 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:';

    /** @var array<string, mixed> */
    private const SCHEMA = [
        'type' => 'object',
        'title' => 'Mit welchem Punkt soll ich beginnen?',
        'properties' => [
            'start' => ['oneOf' => [['const' => 'meta', 'title' => 'Meta Description'], ['const' => 'images', 'title' => 'Alternativtexte']]],
            'comment' => ['type' => 'string'],
        ],
    ];

    private ConversationRepository&MockObject $repository;

    private ChatProcessorInterface&MockObject $processor;

    private ChatApprovalInterface&MockObject $chatApproval;

    private ChatApiController $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(ConversationRepository::class);
        $this->processor = $this->createMock(ChatProcessorInterface::class);
        $this->chatApproval = $this->createMock(ChatApprovalInterface::class);
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getMaxMessageLength')->willReturn(20);
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
            $this->createMock(UriBuilder::class),
        );

        // An editor without the AI Tasks module: an answer is no approval, so
        // nothing here may depend on that module.
        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->user = ['uid' => 1, 'usergroup' => '1,2'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    private function asking(): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setStatus(ConversationStatus::AwaitingInput);
        $conversation->setApprovalRunUuid('run-uuid-1234');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        return $conversation;
    }

    private function question(string $digest = 'digest-abc'): void
    {
        $this->chatApproval->method('pendingInput')->willReturn(new InputPause('run-uuid-1234', $digest, self::SCHEMA));
    }

    /**
     * @param array<string, string> $query
     */
    private function request(string $body, array $query = []): ServerRequestInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($stream);
        $request->method('getQueryParams')->willReturn($query);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, string> $labels
     */
    private function language(array $labels): void
    {
        $languageService = $this->createStub(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn(mixed $input): string => $labels[is_string($input) ? $input : ''] ?? '',
        );
        $GLOBALS['LANG'] = $languageService;
    }

    // ---- the poll ----------------------------------------------------------

    #[Test]
    public function theMessagesCarryTheReplyButtons(): void
    {
        $this->asking();
        $this->question();

        $data = self::json($this->subject->getMessages($this->request('', ['conversationUid' => '1'])));

        self::assertSame('awaiting_input', $data['status']);
        self::assertIsArray($data['pendingInput']);
        self::assertSame('digest-abc', $data['pendingInput']['turnDigest']);
        self::assertSame('choice', $data['pendingInput']['kind']);
        self::assertSame(['Meta Description', 'Alternativtexte'], array_column($data['pendingInput']['options'], 'label'));
        self::assertTrue($data['pendingInput']['freeText']);
        self::assertNull($data['pendingApproval'], 'a question is not an approval card');
    }

    /**
     * A run that asks writes the question into the transcript, but the poll
     * that sees it may already have counted that message. Like the approval
     * card, the buttons must not wait for a reload.
     */
    #[Test]
    public function thePollThatFirstSeesTheQuestionAnswersWithTheButtons(): void
    {
        $this->repository->method('findPollStatus')->willReturn([
            'status' => 'awaiting_input',
            'message_count' => 2,
            'error_message' => '',
            'error_code' => '',
            'approval_run_uuid' => 'run-uuid-1234',
            'tstamp' => time(),
            'activity' => [],
        ]);
        $this->asking();
        $this->question();

        $data = self::json($this->subject->getMessages($this->request('', ['conversationUid' => '1', 'after' => '2'])));

        self::assertArrayHasKey('pendingInput', $data);
        self::assertIsArray($data['pendingInput']);
    }

    #[Test]
    public function aHandedBackAnswerIsExplainedInTheReadersLanguage(): void
    {
        $this->language([self::LLL . 'error.handBack.inputStale' => 'Die Rückfrage hat sich geändert.']);
        $conversation = $this->asking();
        $conversation->setErrorMessage('', InputHandBackReason::StaleTurn->value);
        $this->question();

        $data = self::json($this->subject->getMessages($this->request('', ['conversationUid' => '1'])));

        self::assertSame('Die Rückfrage hat sich geändert.', $data['errorMessage']);
    }

    // ---- the answer --------------------------------------------------------

    #[Test]
    public function aButtonAnswerIsRecordedAndHandedToTheWorker(): void
    {
        $this->asking();
        $this->question();
        $this->chatApproval->expects(self::once())->method('recordInput')
            ->with(self::isInstanceOf(Conversation::class), ['start' => 'meta'], 'digest-abc', 'Meta Description')
            ->willReturn(true);
        $this->processor->expects(self::once())->method('dispatch');

        $response = $this->subject->submitInput($this->request('{"conversationUid": 1, "turnDigest": "digest-abc", "choice": "meta"}'));

        self::assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function typedTextIsRecordedAsTheFreeTextAnswer(): void
    {
        $this->asking();
        $this->question();
        $this->chatApproval->expects(self::once())->method('recordInput')
            ->with(self::anything(), ['comment' => 'Erst die Bilder.'], 'digest-abc', 'Erst die Bilder.')
            ->willReturn(true);

        $response = $this->subject->submitInput($this->request('{"conversationUid": 1, "turnDigest": "digest-abc", "freeText": "Erst die Bilder."}'));

        self::assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function aFormAnswerArrivesWithItsNestedFields(): void
    {
        $this->asking();
        $this->chatApproval->method('pendingInput')->willReturn(new InputPause('run-uuid-1234', 'digest-abc', [
            'properties' => ['title' => ['type' => 'string'], 'count' => ['type' => 'integer']],
        ]));
        $this->chatApproval->expects(self::once())->method('recordInput')
            ->with(self::anything(), ['title' => 'Über uns', 'count' => 2], 'digest-abc', "Title: Über uns\nCount: 2")
            ->willReturn(true);

        $response = $this->subject->submitInput($this->request('{"conversationUid": 1, "turnDigest": "digest-abc", "fields": {"title": "Über uns", "count": "2"}}'));

        self::assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function anAnswerThatWasNotOfferedIsRefusedInTheReadersLanguage(): void
    {
        $this->language([self::LLL . 'error.inputNotOffered' => 'Diese Antwort passt nicht zur Rückfrage.']);
        $this->asking();
        $this->question();
        $this->chatApproval->expects(self::never())->method('recordInput');
        $this->processor->expects(self::never())->method('dispatch');

        $response = $this->subject->submitInput($this->request('{"conversationUid": 1, "turnDigest": "digest-abc", "choice": "publish everything"}'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'Diese Antwort passt nicht zur Rückfrage.'], self::json($response));
    }

    #[Test]
    public function anAnswerToAConversationThatAsksNothingIsRefused(): void
    {
        $this->language([self::LLL . 'error.notAwaitingInput' => 'Diese Rückfrage ist nicht mehr offen.']);
        $conversation = $this->asking();
        $conversation->setStatus(ConversationStatus::Idle);
        $this->chatApproval->expects(self::never())->method('recordInput');

        $response = $this->subject->submitInput($this->request('{"conversationUid": 1, "turnDigest": "d", "choice": "meta"}'));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['error' => 'Diese Rückfrage ist nicht mehr offen.'], self::json($response));
    }

    #[Test]
    public function anAnswerWhoseQuestionCannotBeReadIsRefused(): void
    {
        $this->asking();
        $this->chatApproval->method('pendingInput')->willReturn(null);
        $this->chatApproval->expects(self::never())->method('recordInput');

        self::assertSame(409, $this->subject->submitInput($this->request('{"conversationUid": 1, "turnDigest": "d", "choice": "meta"}'))->getStatusCode());
    }

    #[Test]
    public function freeTextLongerThanAMessageMayBeIsRefused(): void
    {
        $this->asking();
        $this->question();
        $this->chatApproval->expects(self::never())->method('recordInput');

        $response = $this->subject->submitInput($this->request('{"conversationUid": 1, "turnDigest": "d", "freeText": "' . str_repeat('x', 21) . '"}'));

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function anAnswerThatLostTheRaceIsRefusedAsBusy(): void
    {
        $this->asking();
        $this->question();
        $this->chatApproval->method('recordInput')->willReturn(false);
        $this->processor->expects(self::never())->method('dispatch');

        self::assertSame(409, $this->subject->submitInput($this->request('{"conversationUid": 1, "turnDigest": "d", "choice": "meta"}'))->getStatusCode());
    }

    #[Test]
    public function anAnswerToAnotherUsersConversationIsNotFound(): void
    {
        $this->repository->method('findOneByUidAndBeUser')->willReturn(null);
        $this->chatApproval->expects(self::never())->method('recordInput');

        self::assertSame(404, $this->subject->submitInput($this->request('{"conversationUid": 7, "turnDigest": "d", "choice": "meta"}'))->getStatusCode());
    }

    // ---- the approval card's two denials ---------------------------------

    private function approver(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'usergroup' => '1,2'];
        $backendUser->method('isAdmin')->willReturn(true);
        $GLOBALS['BE_USER'] = $backendUser;
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid('run-uuid-1234');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->chatApproval->method('pendingApproval')->willReturn(new WaitingRunView('run-uuid-1234', WaitingRunView::MODE_APPROVAL, 0, 'Chat'));
    }

    #[Test]
    public function aDenialCarriesItsReasonAndTheButtonsLabel(): void
    {
        $this->language([self::LLL . 'chat.approvalVariant' => 'Andere Variante']);
        $this->approver();
        $this->chatApproval->method('offersProcessAnswers')->willReturn(true);
        $this->chatApproval->expects(self::once())->method('recordDecision')
            ->with(self::anything(), false, 'digest-abc', DenyReason::Variant, 'Andere Variante')
            ->willReturn(true);

        $response = $this->subject->decideApproval($this->request('{"conversationUid": 1, "approve": false, "turnDigest": "digest-abc", "reason": "variant"}'));

        self::assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function anUnknownReasonIsAPlainDenialAndAnApprovalHasNone(): void
    {
        $this->approver();
        $this->chatApproval->expects(self::exactly(2))->method('recordDecision')
            ->with(self::anything(), self::anything(), 'digest-abc', null, '')
            ->willReturn(true);

        $this->subject->decideApproval($this->request('{"conversationUid": 1, "approve": false, "turnDigest": "digest-abc", "reason": "drop table"}'));
        $this->subject->decideApproval($this->request('{"conversationUid": 1, "approve": true, "turnDigest": "digest-abc", "reason": "skip"}'));
    }

    /**
     * The server says which answers a card has; the browser only renders them.
     */
    #[Test]
    public function theCardSaysWhichAnswersItOffers(): void
    {
        $this->approver();
        $this->chatApproval->method('offersProcessAnswers')->willReturnOnConsecutiveCalls(true, false);

        $process = self::json($this->subject->getMessages($this->request('', ['conversationUid' => '1'])));
        $plain = self::json($this->subject->getMessages($this->request('', ['conversationUid' => '1'])));

        self::assertSame('process', $process['pendingApproval']['answers'] ?? null);
        self::assertSame('plain', $plain['pendingApproval']['answers'] ?? null);
    }

    /**
     * Outside a process run, or for a card that writes nothing, the card has
     * only approve and cancel (nr-llm ADR-214): a reason sent anyway is a
     * plain denial, and the transcript gets no reason line.
     */
    #[Test]
    public function aReasonOnACardWithoutTheTwoDenialsIsAPlainDenial(): void
    {
        $this->approver();
        $this->chatApproval->method('offersProcessAnswers')->willReturn(false);
        $this->chatApproval->expects(self::once())->method('recordDecision')
            ->with(self::anything(), false, 'digest-abc', null, '')
            ->willReturn(true);

        $this->subject->decideApproval($this->request('{"conversationUid": 1, "approve": false, "turnDigest": "digest-abc", "reason": "skip"}'));
    }
}
