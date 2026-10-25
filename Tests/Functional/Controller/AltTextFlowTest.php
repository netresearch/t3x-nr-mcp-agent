<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Controller;

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
use Netresearch\NrMcpAgent\Service\AltText\AltTextConversation;
use Netresearch\NrMcpAgent\Service\AltText\AltTextGate;
use Netresearch\NrMcpAgent\Service\AltText\AltTextImage;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltText;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextList;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextScan;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextScanner;
use Netresearch\NrMcpAgent\Service\AltText\ReferenceWriteAccess;
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Netresearch\NrMcpAgent\Service\ToolAvailabilityInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * "Optimieren" in the chat (ADR-026), against the fixture of the list of
 * missing alt texts (missing_alt_texts.csv) with a German site on "Wurzel":
 * the first message, the move to another image and to the next one, "Überspringen"
 * and "Als dekorativ markieren", what the model reads, and the card the
 * conversation waits on. The run itself is nr-llm's; the processor is a
 * stub that counts the dispatches.
 *
 * Editor 2 has 26 open hits; in list order the pages' hits end with 52, the
 * test articles' begin with 28.
 */
final class AltTextFlowTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
        __DIR__ . '/../Fixtures/Extensions/alt_text_test',
    ];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/nr_mcp_agent/Tests/Functional/Fixtures/Frontend/pattern.png' => 'fileadmin/team.jpg',
    ];

    protected array $configurationToUseInTestInstance = [
        'GFX' => ['processor_enabled' => false],
    ];

    private const EDITOR = 2;

    private ConversationRepository $repository;

    private ChatProcessorInterface&MockObject $processor;

    private ChatApprovalInterface&MockObject $approval;

    private int $dispatched = 0;

    /** The run the conversation waits on, as the next controller reads it. */
    private ?WaitingRunView $pendingView = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/missing_alt_texts.csv');
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['is_siteroot' => 1], ['uid' => 1]);
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base'       => 'https://example.com/',
            'languages'  => [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/', 'flag' => 'de'],
                ['languageId' => 1, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/en/', 'flag' => 'us'],
            ],
        ]);
        $this->repository = $this->get(ConversationRepository::class);
        $this->processor  = $this->createMock(ChatProcessorInterface::class);
        $this->processor->method('dispatch')->willReturnCallback(function (): void {
            ++$this->dispatched;
        });
        $this->approval = $this->createMock(ChatApprovalInterface::class);
        $this->approval->method('releasePendingRun')->willReturn(true);
        $this->approval->method('pendingApproval')->willReturnCallback(fn (): ?WaitingRunView => $this->pendingView);
        $this->loginAs(self::EDITOR);
    }

    private function loginAs(int $userUid): void
    {
        $user            = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
    }

    private function subject(?WaitingRunView $pending = null): ChatApiController
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $tools = $this->createMock(ToolAvailabilityInterface::class);
        $tools->method('unavailableTools')->willReturn([]);
        $capabilities = $this->createMock(ChatCapabilitiesInterface::class);
        $capabilities->method('getProviderCapabilities')->willReturn(['visionSupported' => false, 'maxFileSize' => 0, 'supportedFormats' => []]);
        $this->pendingView = $pending;

        return new ChatApiController(
            $this->repository,
            $this->processor,
            $config,
            $capabilities,
            $this->approval,
            $this->get(ResourceFactory::class),
            $this->get(StorageRepository::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            GeneralUtility::makeInstance(UriBuilder::class),
            siteFinder: $this->get(SiteFinder::class),
            altTextGate: new AltTextGate($this->get(MissingAltTextScanner::class), $this->get(ReferenceWriteAccess::class), $tools),
            altTextConversation: $this->get(AltTextConversation::class),
            altTextImage: $this->get(AltTextImage::class),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $action, array $body, ?WaitingRunView $pending = null): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode($body, JSON_THROW_ON_ERROR));
        $stream->rewind();

        $request = (new ServerRequest('/', 'POST'))->withBody($stream);
        $subject = $this->subject($pending);

        return match ($action) {
            'create'     => $subject->createConversation($request),
            'switch'     => $subject->switchAltText($request),
            'next'       => $subject->nextAltText($request),
            'skip'       => $subject->skipAltText($request),
            'decorative' => $subject->markAltTextDecorative($request),
            'retry'      => $subject->retryAltText($request),
            'approve'    => $subject->decideApproval($request),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function start(int $referenceUid): Conversation
    {
        $response = $this->post('create', ['altTextReference' => $referenceUid]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->conversation((int) $this->json($response)['uid']);
    }

    private function conversation(int $uid): Conversation
    {
        $conversation = $this->repository->findByUid($uid);
        self::assertNotNull($conversation);

        return $conversation;
    }

    /** Waiting for the editor, as after a run that ended. */
    private function settle(Conversation $conversation, ConversationStatus $status = ConversationStatus::Idle): Conversation
    {
        $conversation->setStatus($status);
        if ($status === ConversationStatus::AwaitingApproval) {
            $conversation->setApprovalRunUuid('7f0c6a52-6d1e-4b8f-9a35-0c2f7b1e4d10');
        }

        $this->repository->update($conversation);

        return $this->conversation($conversation->getUid());
    }

    /**
     * @return list<string>
     */
    private function notices(Conversation $conversation): array
    {
        return array_map(static fn (array $message): string => is_string($message['notice'] ?? null) ? $message['notice'] : '', $conversation->getDecodedMessages());
    }

    private function mark(int $referenceUid): int
    {
        $value = $this->getConnectionPool()->getConnectionForTable('sys_file_reference')
            ->select(['tx_nrmcpagent_decorative'], 'sys_file_reference', ['uid' => $referenceUid])->fetchOne();

        return is_numeric($value) ? (int) $value : -1;
    }

    /**
     * The first message is the server's: the card with the image, where it
     * is used, its language and that it has no alt text, then the run that
     * proposes the text.
     */
    #[Test]
    public function theConversationStartsWithTheImageCardAndTheProposingRun(): void
    {
        $conversation = $this->start(1);

        $messages = $conversation->getDecodedMessages();
        self::assertCount(1, $messages);
        self::assertSame(['assistant', AltTextConversation::NOTICE_HIT], [$messages[0]['role'], $messages[0]['notice']]);
        self::assertSame([
            'fileName'    => 'team.jpg',
            'subjectType' => '',
            'subject'     => 'Startseite',
            'partType'    => 'element',
            'part'        => 'Text & Images – “Unser Team”',
            'language'    => 'Deutsch',
            'section'     => 'Unser Team',
            'reference'   => 1,
            'imageNote'   => AltTextImage::NOTE_NO_VISION,
        ], $messages[0]['data']);
        self::assertSame(ConversationStatus::Processing, $conversation->getStatus());
        self::assertSame(1, $this->dispatched);
        self::assertSame('Alt text: team.jpg', $conversation->getTitle());
    }

    /** A record's image: its type and title, and the image field. */
    #[Test]
    public function aRecordsCardNamesTheRecordAndTheField(): void
    {
        $this->loginAs(1);

        $data = $this->start(28)->getDecodedMessages()[0]['data'] ?? [];

        self::assertIsArray($data);
        self::assertSame(['Test articles', 'Erster Artikel', 'field', 'Images'], [$data['subjectType'], $data['subject'], $data['partType'], $data['part']]);
    }

    /**
     * "Optimieren" on another row: no new conversation, the divider, the new
     * card and a new run; the conversation is on the new image now.
     */
    #[Test]
    public function anotherRowMovesTheConversationToItsImage(): void
    {
        $conversation = $this->settle($this->start(1));

        $response = $this->post('switch', ['conversationUid' => $conversation->getUid(), 'reference' => 10]);

        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['status' => 'processing', 'reference' => 10, 'tab' => 'pages', 'page' => 1], $this->json($response));
        $moved = $this->conversation($conversation->getUid());
        self::assertSame(10, $moved->getAltTextHit()['reference']);
        self::assertSame([AltTextConversation::NOTICE_HIT, AltTextConversation::NOTICE_SWITCHED, AltTextConversation::NOTICE_HIT], $this->notices($moved));
        self::assertSame(['team.jpg', 'Startseite'], $moved->getDecodedMessages()[1]['noticeArgs']);
        self::assertSame(2, $this->dispatched);
        self::assertSame(1, $this->getConnectionPool()->getConnectionForTable('tx_nrmcpagent_conversation')->count('*', 'tx_nrmcpagent_conversation', []));
    }

    /** While a turn runs, the image does not change under it. */
    #[Test]
    public function aWorkingConversationDoesNotMove(): void
    {
        $conversation = $this->start(1);

        $response = $this->post('switch', ['conversationUid' => $conversation->getUid(), 'reference' => 10]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(1, $this->conversation($conversation->getUid())->getAltTextHit()['reference']);
    }

    /** A proposal nobody took over is withdrawn when the conversation moves on. */
    #[Test]
    public function movingOnWithdrawsTheWaitingProposal(): void
    {
        $conversation = $this->settle($this->start(1), ConversationStatus::AwaitingApproval);
        $this->approval->expects(self::once())->method('releasePendingRun')->willReturn(true);

        $response = $this->post('switch', ['conversationUid' => $conversation->getUid(), 'reference' => 10]);

        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('', $this->conversation($conversation->getUid())->getApprovalRunUuid());
    }

    /** An image the editor may not fix is refused like at the start, and nothing changes. */
    #[Test]
    public function anotherRowTheEditorMayNotFixIsRefused(): void
    {
        $conversation = $this->settle($this->start(1));

        $response = $this->post('switch', ['conversationUid' => $conversation->getUid(), 'reference' => 16]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([AltTextConversation::NOTICE_HIT], $this->notices($this->conversation($conversation->getUid())));
    }

    /**
     * "Nächsten Treffer optimieren": the open hit after the conversation's in
     * list order, from the pages' last into the next tab, and from the last
     * back to the first.
     *
     * @return iterable<string, array{int, int, string}>
     */
    public static function nextHits(): iterable
    {
        yield 'the next hit of the same page' => [1, 2, 'pages'];
        yield 'from the pages into the record type' => [52, 28, 'tx_alttexttest_item'];
        yield 'after the last, the first' => [29, 13, 'pages'];
    }

    #[Test]
    #[DataProvider('nextHits')]
    public function theNextHitFollowsTheListOrderAcrossTheTabs(int $from, int $next, string $tab): void
    {
        $conversation = $this->settle($this->start($from));

        $response = $this->post('next', ['conversationUid' => $conversation->getUid()]);

        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([$next, $tab], [$this->json($response)['reference'], $this->json($response)['tab']]);
        self::assertSame($next, $this->conversation($conversation->getUid())->getAltTextHit()['reference']);
    }

    /**
     * The poll that first sees the turn ended says whether the image is still
     * open and whether another one is: polling stops with that answer, so
     * "Nächsten Treffer optimieren" would otherwise never show.
     */
    #[Test]
    public function thePollThatSeesTheTurnEndedSaysWhetherAnotherImageIsOpen(): void
    {
        $conversation = $this->start(1);
        $conversation->setStatus(ConversationStatus::Failed);
        $conversation->setErrorMessage('API key identifier is required for provider OpenAI');

        $this->repository->update($conversation);
        $request = (new ServerRequest('/', 'GET'))->withQueryParams(['conversationUid' => (string) $conversation->getUid(), 'after' => '1']);

        $data = $this->json($this->subject()->getMessages($request));

        self::assertSame('failed', $data['status']);
        self::assertSame(['reference' => 1, 'open' => true, 'next' => true, 'decorative' => true], $data['altText'] ?? null);
    }

    /** Once the image is fixed, the next hit is the one that followed it, not the first. */
    #[Test]
    public function theNextHitAfterAFixedImageIsTheOneThatFollowedIt(): void
    {
        $conversation = $this->settle($this->start(7));
        $this->getConnectionPool()->getConnectionForTable('sys_file_reference')->update('sys_file_reference', ['alternative' => 'Ein Team'], ['uid' => 7]);

        $response = $this->post('next', ['conversationUid' => $conversation->getUid()]);

        self::assertSame(32, $this->json($response)['reference'] ?? null);
    }

    /**
     * The record tabs follow their labels, as the list shows them, not their
     * table names: "Alpha things" (tx_zeta) comes before "Zulu things"
     * (tx_alpha).
     */
    #[Test]
    public function theNextHitFollowsTheTabsLabelsNotTheirTables(): void
    {
        $GLOBALS['TCA']['tx_zeta']['ctrl']['title']  = 'Alpha things';
        $GLOBALS['TCA']['tx_alpha']['ctrl']['title'] = 'Zulu things';
        $hit                                         = static fn (int $uid, string $type, array $key): MissingAltText => new MissingAltText(
            referenceUid: $uid,
            fileUid: 1,
            fileName: 'team.jpg',
            crop: '',
            type: $type,
            table: $type,
            recordUid: $uid,
            field: 'image',
            languageUid: 0,
            pageUid: 1,
            pageTitle: 'Startseite',
            groupUid: $uid,
            groupTitle: 'Record ' . $uid,
            sortKey: $key,
        );
        $page  = $hit(1, MissingAltTextScan::PAGES, [0, '1', 0, 0]);
        $alpha = $hit(2, 'tx_alpha', [1, 't/tx_alpha', 'a']);
        $zeta  = $hit(3, 'tx_zeta', [1, 't/tx_zeta', 'a']);
        $scan  = new MissingAltTextScan([$page, $alpha, $zeta]);
        $list  = $this->get(MissingAltTextList::class);

        self::assertSame(3, $list->next($scan, $list->position($page), 1)?->referenceUid);
        self::assertSame(2, $list->next($scan, $list->position($zeta), 3)?->referenceUid);
    }

    /**
     * While a turn runs, "Überspringen" and "Als dekorativ markieren" are
     * refused, and the conversation stays as the run left it.
     *
     * @return iterable<string, array{string}>
     */
    public static function chatActions(): iterable
    {
        yield 'Überspringen' => ['skip'];
        yield 'Als dekorativ markieren' => ['decorative'];
    }

    #[Test]
    #[DataProvider('chatActions')]
    public function aWorkingConversationTakesNoChatAction(string $action): void
    {
        $conversation = $this->start(10);
        $this->approval->expects(self::never())->method('releasePendingRun');

        $response = $this->post($action, ['conversationUid' => $conversation->getUid()]);

        self::assertSame(409, $response->getStatusCode());
        $unchanged = $this->conversation($conversation->getUid());
        self::assertSame(ConversationStatus::Processing, $unchanged->getStatus());
        self::assertSame([AltTextConversation::NOTICE_HIT], $this->notices($unchanged));
        self::assertSame(0, $this->mark(10));
    }

    /**
     * "Überspringen": the editor's chip and the specification's answer, no
     * run, nothing saved, the waiting proposal withdrawn.
     */
    #[Test]
    public function skippingSavesNothingAndSaysTheHitStaysOpen(): void
    {
        $conversation = $this->settle($this->start(1), ConversationStatus::AwaitingApproval);
        $this->approval->expects(self::once())->method('releasePendingRun')->willReturn(true);

        $response = $this->post('skip', ['conversationUid' => $conversation->getUid()]);

        self::assertSame(['status' => 'idle'], $this->json($response));
        $skipped = $this->conversation($conversation->getUid());
        self::assertSame([AltTextConversation::NOTICE_HIT, AltTextConversation::NOTICE_ACTION, AltTextConversation::NOTICE_SKIPPED], $this->notices($skipped));
        self::assertSame(['skip'], $skipped->getDecodedMessages()[1]['noticeArgs']);
        self::assertSame(ConversationStatus::Idle, $skipped->getStatus());
        self::assertSame(1, $this->dispatched);
        self::assertNull($this->getConnectionPool()->getConnectionForTable('sys_file_reference')->select(['alternative'], 'sys_file_reference', ['uid' => 1])->fetchOne());
    }

    /**
     * "Als dekorativ markieren" asks for the mark: the proposal is withdrawn,
     * nothing is saved, and a turn starts whose request the model reads, to
     * answer with the tool's approval card — the one confirmation.
     */
    #[Test]
    public function theDecorativeChipAsksForTheCardAndSavesNothing(): void
    {
        $conversation = $this->settle($this->start(1), ConversationStatus::AwaitingApproval);
        $this->approval->expects(self::once())->method('releasePendingRun')->willReturn(true);

        $response = $this->post('decorative', ['conversationUid' => $conversation->getUid()]);

        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, $this->mark(1));
        $asked = $this->conversation($conversation->getUid());
        self::assertSame([AltTextConversation::NOTICE_HIT, AltTextConversation::NOTICE_ASK_DECORATIVE], $this->notices($asked));
        self::assertSame(ConversationStatus::Processing, $asked->getStatus());
        self::assertSame(2, $this->dispatched);
        $transcript = $this->get(AltTextConversation::class)->transcript($asked, $asked->getDecodedMessages(), false);
        self::assertStringContainsString('call set_file_reference_decorative with that reference and decorative true', (string) ($transcript[1]['content'] ?? ''));
    }

    /**
     * Without the grant for the mark the chip is not offered (the state says
     * so), and a request for it is refused before any turn.
     */
    #[Test]
    public function withoutTheGrantForTheMarkTheChipIsNeitherOfferedNorTaken(): void
    {
        // User 3's group may change alt texts, but not the decorative mark.
        $this->loginAs(3);
        $conversation = $this->settle($this->start(10));
        $request      = (new ServerRequest('/', 'GET'))->withQueryParams(['conversationUid' => (string) $conversation->getUid()]);

        self::assertFalse($this->json($this->subject()->getMessages($request))['altText']['decorative'] ?? null);

        $response = $this->post('decorative', ['conversationUid' => $conversation->getUid()]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([AltTextConversation::NOTICE_HIT], $this->notices($this->conversation($conversation->getUid())));
        self::assertSame(1, $this->dispatched);
    }

    /**
     * A card of one call, waiting for an approval, as nr-llm's inbox reads it.
     *
     * @param array<string, mixed> $arguments
     * @param list<string>         $previewLines
     */
    private function waiting(string $tool, array $arguments, array $previewLines, bool $previewFailed = false): WaitingRunView
    {
        return new WaitingRunView(
            '7f0c6a52-6d1e-4b8f-9a35-0c2f7b1e4d10',
            WaitingRunView::MODE_APPROVAL,
            0,
            'Chat',
            'digest',
            [new PendingCallView($tool, json_encode($arguments, JSON_THROW_ON_ERROR), true, $previewLines, $previewFailed)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingCard(Conversation $conversation, WaitingRunView $pending): array
    {
        $request = (new ServerRequest('/', 'GET'))->withQueryParams(['conversationUid' => (string) $conversation->getUid()]);
        $card    = $this->json($this->subject($pending)->getMessages($request))['pendingApproval'] ?? null;
        self::assertIsArray($card);

        return $card;
    }

    /**
     * The model's own decorative mark (case 1) reaches a user without the
     * grant for it as a card that cannot be approved and says why in the
     * reader's language, without the tool's refusal, a sentence for
     * developers; approving it is refused. The model is told the mark is not
     * available, so it proposes an alt text.
     */
    #[Test]
    public function withoutTheGrantForTheMarkTheModelsMarkCannotBeApproved(): void
    {
        $this->loginAs(3);
        $conversation = $this->settle($this->start(10), ConversationStatus::AwaitingApproval);
        $refusal      = 'Refused: the acting backend user holds no field-level ("exclude field") grant for sys_file_reference:tx_nrmcpagent_decorative. Nothing was written.';
        $pending      = $this->waiting('set_file_reference_decorative', ['reference' => 10, 'decorative' => true], [$refusal], true);

        $card = $this->pendingCard($conversation, $pending);

        self::assertSame('altTextMarkNotPermitted', $card['approveBlocked']);
        self::assertSame('altTextDecorative', $card['answers']);
        self::assertStringNotContainsString('Refused', json_encode($card, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('exclude field', json_encode($card, JSON_THROW_ON_ERROR));

        $response = $this->post('approve', ['conversationUid' => $conversation->getUid(), 'approve' => true], $pending);
        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('You may not mark this image as decorative. You can have an alt text proposed instead.', $this->json($response)['error'] ?? null);

        $transcript = $this->get(AltTextConversation::class)->transcript($conversation, $conversation->getDecodedMessages(), false);
        self::assertStringContainsString('The editor may not mark this image as decorative', (string) ($transcript[0]['content'] ?? ''));
    }

    /**
     * The same holds when the model passes the reference as a string, and
     * when the mark is one of several calls the run waits on.
     */
    #[Test]
    public function withoutTheGrantTheMarkIsBlockedAsAStringAndAmongSeveralCalls(): void
    {
        $this->loginAs(3);
        $conversation = $this->settle($this->start(10), ConversationStatus::AwaitingApproval);

        $asString = $this->pendingCard($conversation, $this->waiting('set_file_reference_decorative', ['reference' => '10', 'decorative' => true], ['Bild: team.jpg']));
        self::assertSame(['altTextMarkNotPermitted', 'altTextDecorative'], [$asString['approveBlocked'], $asString['answers']]);

        $several = new WaitingRunView(
            '7f0c6a52-6d1e-4b8f-9a35-0c2f7b1e4d10',
            WaitingRunView::MODE_APPROVAL,
            0,
            'Chat',
            'digest',
            [
                new PendingCallView('set_file_reference_alternative_text', '{"reference":10,"alternative":"Ein Team"}', true, ['Bild: team.jpg']),
                new PendingCallView('set_file_reference_decorative', '{"reference":10,"decorative":true}', true, ['Bild: team.jpg']),
            ],
        );
        self::assertSame('altTextMarkNotPermitted', $this->pendingCard($conversation, $several)['approveBlocked']);

        $response = $this->post('approve', ['conversationUid' => $conversation->getUid(), 'approve' => true], $several);
        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());

        // Clearing the mark is not setting it: that block is not this one.
        $clearing = $this->pendingCard($conversation, $this->waiting('set_file_reference_decorative', ['reference' => 10, 'decorative' => false], ['Bild: team.jpg']));
        self::assertNotSame('altTextMarkNotPermitted', $clearing['approveBlocked']);
    }

    /** With the grant, the model's mark is approvable and the model may propose it. */
    #[Test]
    public function withTheGrantForTheMarkTheModelsMarkCanBeApproved(): void
    {
        $conversation = $this->settle($this->start(10), ConversationStatus::AwaitingApproval);

        $card = $this->pendingCard($conversation, $this->waiting('set_file_reference_decorative', ['reference' => 10, 'decorative' => true], ['Als dekorativ markieren']));

        self::assertSame('', $card['approveBlocked']);
        $transcript = $this->get(AltTextConversation::class)->transcript($conversation, $conversation->getDecodedMessages(), false);
        self::assertStringNotContainsString('may not mark this image as decorative', (string) ($transcript[0]['content'] ?? ''));
    }

    /**
     * A call of the flow's tools on another image than the conversation's
     * is not approved here: the card says so, and approving is refused. The
     * same call on the conversation's image is approvable.
     */
    #[Test]
    public function aCallOnAnotherImageCannotBeApproved(): void
    {
        $conversation = $this->settle($this->start(1), ConversationStatus::AwaitingApproval);
        foreach (['set_file_reference_alternative_text' => ['alternative' => 'x'], 'set_file_reference_decorative' => ['decorative' => true]] as $tool => $arguments) {
            $other = $this->waiting($tool, ['reference' => 2, ...$arguments], ['Image: logo.png']);

            self::assertSame('altTextOtherImage', $this->pendingCard($conversation, $other)['approveBlocked'], $tool);

            $response = $this->post('approve', ['conversationUid' => $conversation->getUid(), 'approve' => true], $other);
            self::assertSame(409, $response->getStatusCode(), $tool . ': ' . $response->getBody());
            self::assertSame('This proposal concerns a different image than the one in this chat and cannot be applied here. You can deny it.', $this->json($response)['error'] ?? null);
        }

        $own = $this->waiting('set_file_reference_alternative_text', ['reference' => 1, 'alternative' => 'x'], ['Image: team.jpg']);
        self::assertSame('', $this->pendingCard($conversation, $own)['approveBlocked']);
    }

    /** With the grant, the state offers the chip. */
    #[Test]
    public function withTheGrantForTheMarkTheChipIsOffered(): void
    {
        $conversation = $this->settle($this->start(10));
        $request      = (new ServerRequest('/', 'GET'))->withQueryParams(['conversationUid' => (string) $conversation->getUid()]);

        self::assertTrue($this->json($this->subject()->getMessages($request))['altText']['decorative'] ?? null);
    }

    /**
     * "Erneut anbieten": the proposal whose approved write did not land is
     * offered again as a new card. The text is the conversation's, never the
     * browser's.
     */
    #[Test]
    public function aProposalThatWasNotSavedIsOfferedAgain(): void
    {
        $conversation = $this->start(1);
        $conversation->appendMessage(MessageRole::Assistant, 'Die Seite ist gesperrt.');
        $conversation->appendMessage(MessageRole::Assistant, '[The alt text was not saved.]', AltTextConversation::NOTICE_NOT_SAVED, [], ['proposal' => 'Ein Team am Tisch']);
        $conversation = $this->settle($conversation);

        $response = $this->post('retry', ['conversationUid' => $conversation->getUid(), 'proposal' => 'Something else']);

        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());
        $retried = $this->conversation($conversation->getUid());
        self::assertSame(AltTextConversation::NOTICE_RETRY, $this->notices($retried)[3] ?? null);
        $request = (string) ($retried->getDecodedMessages()[3]['content'] ?? '');
        self::assertStringContainsString('"Ein Team am Tisch"', $request);
        self::assertStringNotContainsString('Something else', $request);
        self::assertSame(2, $this->dispatched);
    }

    /** Without a proposal that was not saved as the last word, there is nothing to offer again. */
    #[Test]
    public function withoutAProposalThatWasNotSavedNothingIsOfferedAgain(): void
    {
        $conversation = $this->settle($this->start(1));

        $response = $this->post('retry', ['conversationUid' => $conversation->getUid()]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('nothingToRetry', $this->json($response)['code'] ?? null);
        self::assertSame(1, $this->dispatched);
    }

    /** These answers belong to a conversation about an image only. */
    #[Test]
    public function anOrdinaryConversationTakesNoneOfThem(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(self::EDITOR);

        $uid = $this->repository->add($conversation);

        foreach (['skip', 'decorative', 'next'] as $action) {
            self::assertSame(400, $this->post($action, ['conversationUid' => $uid])->getStatusCode(), $action);
        }
    }

    /**
     * What the model reads: the chat's own lines left out, the latest card
     * of the current image as the image's context, read again, fenced as
     * data; an earlier card as a line that the editor moved on.
     */
    #[Test]
    public function theModelReadsTheCurrentImagesContextAndNotTheChatsLines(): void
    {
        $conversation = $this->settle($this->start(1));
        $this->post('skip', ['conversationUid' => $conversation->getUid()]);
        $conversation = $this->settle($this->conversation($conversation->getUid()));
        $this->post('switch', ['conversationUid' => $conversation->getUid(), 'reference' => 10]);
        $conversation = $this->conversation($conversation->getUid());

        $transcript = $this->get(AltTextConversation::class)->transcript($conversation, $conversation->getDecodedMessages(), false);

        self::assertCount(2, $transcript);
        self::assertSame('user', $transcript[0]['role']);
        self::assertStringContainsString('earlier image', (string) $transcript[0]['content']);
        $context = (string) $transcript[1]['content'];
        self::assertStringStartsWith('<image_context>', $context);
        self::assertStringContainsString('Reference to write (pass it as "reference"): 10', $context);
        self::assertStringContainsString('Language of this use, write the alt text in it: Deutsch (de)', $context);
        self::assertStringContainsString('Content after it: "Unser Team"', $context);
        self::assertStringContainsString('The image itself is NOT attached', $context);
        self::assertStringNotContainsString('Skipped', json_encode($transcript, JSON_THROW_ON_ERROR));
    }

    /**
     * The card a conversation about an image waits on: a proposal of nr-llm's
     * writer for its image, the model's decorative mark (case 1), and nothing
     * else — another reference, another tool.
     */
    #[Test]
    public function theCardIsOneOfTheFlowOnlyForTheConversationsImage(): void
    {
        $conversation = $this->start(1);
        $flow         = $this->get(AltTextConversation::class);
        $view         = static fn (string $tool, array $arguments): WaitingRunView => new WaitingRunView(
            'run',
            WaitingRunView::MODE_APPROVAL,
            0,
            'Chat',
            'digest',
            [new PendingCallView($tool, json_encode($arguments, JSON_THROW_ON_ERROR), true)],
        );

        self::assertSame(['kind' => 'proposal', 'proposal' => 'Ein Team am Tisch'], $flow->card($conversation, $view('set_file_reference_alternative_text', ['reference' => 1, 'alternative' => ' Ein Team am Tisch '])));
        self::assertSame(['kind' => 'decorative', 'proposal' => ''], $flow->card($conversation, $view('set_file_reference_decorative', ['reference' => 1, 'decorative' => true])));
        self::assertSame(['kind' => 'decorative', 'proposal' => ''], $flow->card($conversation, $view('set_file_reference_decorative', ['reference' => '1', 'decorative' => true])));
        self::assertSame(['kind' => 'proposal', 'proposal' => 'x'], $flow->card($conversation, $view('set_file_reference_alternative_text', ['reference' => '1', 'alternative' => 'x'])));
        self::assertNull($flow->card($conversation, $view('set_file_reference_alternative_text', ['reference' => 2, 'alternative' => 'x'])));
        self::assertNull($flow->card($conversation, $view('set_file_alternative_text', ['file' => 1, 'alternative' => 'x'])));
        self::assertNull($flow->card($conversation, $view('set_file_reference_decorative', ['reference' => 1, 'decorative' => false])));
    }
}
