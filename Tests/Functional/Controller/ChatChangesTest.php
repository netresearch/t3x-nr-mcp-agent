<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Controller;

use Netresearch\NrLlm\Domain\Enum\ChangeAction;
use Netresearch\NrLlm\Service\Agent\Inbox\PendingCallView;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Controller\ChatApiController;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChangeList\ChangeCollection;
use Netresearch\NrMcpAgent\Service\ChangeList\ChangePresenter;
use Netresearch\NrMcpAgent\Service\ChangeList\RecordChange;
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The change view's endpoint and the card's structured fields as the
 * controller answers them (ADR-034), with real conversations and permissions:
 * the list is the owner's, filtered for the viewer; the poll says only whether
 * there is anything to list; and a card whose calls nr-llm refused by a rule
 * offers nothing to approve for them.
 */
#[CoversClass(ChatApiController::class)]
final class ChatChangesTest extends FunctionalTestCase
{
    private const ADMIN = 1;

    private const EDITOR = 11;

    protected array $coreExtensionsToLoad = ['filelist', 'workspaces'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    private ChatApprovalInterface&MockObject $approval;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/change_view.csv');
        $this->approval = $this->createMock(ChatApprovalInterface::class);
    }

    private function subject(): ChatApiController
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);

        return new ChatApiController(
            $this->get(ConversationRepository::class),
            $this->createMock(ChatProcessorInterface::class),
            $config,
            $this->createMock(ChatCapabilitiesInterface::class),
            $this->approval,
            $this->get(ResourceFactory::class),
            $this->get(StorageRepository::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            GeneralUtility::makeInstance(UriBuilder::class),
            changePresenter: $this->get(ChangePresenter::class),
        );
    }

    private function loginAs(int $uid): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser($uid);
        $GLOBALS['LANG']    = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function conversation(int $beUser, array $records = [], bool $waiting = false): int
    {
        $conversation = new Conversation();
        $conversation->setBeUser($beUser);
        $conversation->appendMessage(MessageRole::User, 'Lege eine Seite an');
        foreach ($records as $record) {
            $conversation->appendRunRecord($record);
        }

        if ($waiting) {
            $conversation->setStatus(ConversationStatus::AwaitingApproval);
            $conversation->setApprovalRunUuid('run-uuid-1');
        }

        return $this->get(ConversationRepository::class)->add($conversation);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(): array
    {
        return self::staticEntry('run-1');
    }

    /**
     * @return array<string, mixed>
     */
    private function json(object $response): array
    {
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);

        return $body;
    }

    #[Test]
    public function theOwnerGetsTheListFilteredForThem(): void
    {
        $this->loginAs(self::EDITOR);
        $uid = $this->conversation(self::EDITOR, [$this->entry()]);
        $this->approval->method('changesOf')->willReturn(new ChangeCollection([
            new RecordChange('pages', 10, ChangeAction::UPDATED, isWriteTarget: true),
            new RecordChange('pages', 20, ChangeAction::UPDATED, isWriteTarget: true),
        ], runs: 1));

        $body = $this->json($this->subject()->getChanges((new ServerRequest())->withQueryParams(['conversationUid' => $uid])));

        self::assertSame([10], array_column($body['changes'], 'uid'));
        self::assertSame(1, $body['withheld']);
        self::assertStringNotContainsString('Geheime Seite', json_encode($body, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function aConversationOfAnotherUserIsNotFound(): void
    {
        $this->loginAs(self::ADMIN);
        $uid = $this->conversation(self::EDITOR, [$this->entry()]);
        $this->approval->expects(self::never())->method('changesOf');

        $response = $this->subject()->getChanges((new ServerRequest())->withQueryParams(['conversationUid' => $uid]));

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * @return array<string, array{list<array<string, mixed>>, bool}>
     */
    public static function availabilityProvider(): array
    {
        $write = ['table' => 'pages', 'uid' => 10, 'complete' => true];

        return [
            'a run that wrote a record'                       => [[self::staticEntry('run-1', [$write])], true],
            'a run whose tool failed, which may have written' => [[self::staticEntry('run-1', [], [['tool' => 'copy_record', 'calls' => 1, 'errors' => 1]])], true],
            'a run that only read'                            => [[self::staticEntry('run-1', [], [['tool' => 'get_page', 'calls' => 2, 'errors' => 0]])], false],
            'a record from before the uuid with a write'      => [[['outcome' => 'completed', 'tools' => [], 'writes' => [$write], 'waiting' => []]], true],
            'a record from before the uuid without a write'   => [[['outcome' => 'completed', 'tools' => [['tool' => 'x', 'calls' => 1, 'errors' => 1]], 'writes' => [], 'waiting' => []]], false],
            'no run at all'                                   => [[], false],
        ];
    }

    /**
     * @param list<array<string, mixed>> $writes
     * @param list<array<string, mixed>> $tools
     *
     * @return array<string, mixed>
     */
    private static function staticEntry(string $run, array $writes = [], array $tools = []): array
    {
        return ['outcome' => 'completed', 'run' => $run, 'tools' => $tools, 'writes' => $writes, 'waiting' => []];
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    #[Test]
    #[DataProvider('availabilityProvider')]
    public function thePollSaysWhetherThereIsAnythingToList(array $records, bool $expected): void
    {
        $this->loginAs(self::EDITOR);
        $uid = $this->conversation(self::EDITOR, $records);

        $body = $this->json($this->subject()->getMessages((new ServerRequest())->withQueryParams(['conversationUid' => $uid])));

        self::assertSame($expected, $body['changesAvailable']);
    }

    /**
     * @param list<PendingCallView> $calls
     */
    private function waitingWith(array $calls, bool $draftRequired = false): int
    {
        $this->approval->method('pendingApproval')->willReturn(new WaitingRunView(
            runUuid: 'run-uuid-1',
            mode: WaitingRunView::MODE_APPROVAL,
            createdAt: 1710000000,
            configLabel: 'Demo',
            turnDigest: 'digest-1',
            pendingCalls: $calls,
        ));
        $this->approval->method('requiresDraftWorkspace')->willReturn($draftRequired);

        return $this->conversation(self::ADMIN, waiting: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(int $uid): array
    {
        $body = $this->json($this->subject()->getMessages((new ServerRequest())->withQueryParams(['conversationUid' => $uid])));
        self::assertIsArray($body['pendingApproval']);

        return $body['pendingApproval'];
    }

    #[Test]
    public function aCardWhoseCallsAreAllRefusedOffersNothingToApprove(): void
    {
        $this->loginAs(self::ADMIN);
        $uid  = $this->waitingWith([new PendingCallView('create_page_draft', '{}', true, ['Regel: Entwurfs-Workspace nötig.'], refusedByRule: true)], draftRequired: true);
        $card = $this->card($uid);

        self::assertSame('allRefused', $card['approveBlocked']);
        self::assertTrue($card['calls'][0]['refused']);
        self::assertTrue($card['draftWorkspaceRequired']);
    }

    /** One refused call beside one that waits: the approval stays possible, and the refused call is marked. */
    #[Test]
    public function aRefusedCallBesideAWaitingOneDoesNotBlockTheApproval(): void
    {
        $this->loginAs(self::ADMIN);
        $uid = $this->waitingWith([
            new PendingCallView('create_page_draft', '{}', true, ['Regel: nicht ausgeführt.'], refusedByRule: true),
            new PendingCallView('update_page_metadata', '{}', true, ['Titel: „Neu“'], workspaceId: 1, workspaceTitle: 'Entwurf'),
        ]);
        $card = $this->card($uid);

        self::assertSame('', $card['approveBlocked']);
        self::assertSame([true, false], array_column($card['calls'], 'refused'));
        self::assertSame([0, 1], array_column($card['calls'], 'workspaceId'));
        self::assertFalse($card['draftWorkspaceRequired']);
    }

    /** A client that still shows an old card can post an approval; nothing is recorded and no worker is started. */
    #[Test]
    public function aStaleApprovalOfACardWhoseCallsAreAllRefusedIsRejected(): void
    {
        $this->loginAs(self::ADMIN);
        $uid = $this->waitingWith([new PendingCallView('create_page_draft', '{}', true, ['Regel: Entwurfs-Workspace nötig.'], refusedByRule: true)], draftRequired: true);
        $this->approval->expects(self::never())->method('recordDecision');

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, json_encode(['conversationUid' => $uid, 'approve' => true, 'turnDigest' => 'digest-1'], JSON_THROW_ON_ERROR));
        rewind($stream);
        $response = $this->subject()->decideApproval((new ServerRequest('/', 'POST'))->withBody(new Stream($stream)));

        self::assertSame(409, $response->getStatusCode());
        self::assertArrayHasKey('error', $this->json($response));
    }
}
