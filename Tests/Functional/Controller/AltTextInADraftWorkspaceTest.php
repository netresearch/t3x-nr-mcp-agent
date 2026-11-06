<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Controller;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Controller\ChatApiController;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Domain\Repository\ImageReferenceRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Service\AltText\AltTextContext;
use Netresearch\NrMcpAgent\Service\AltText\AltTextConversation;
use Netresearch\NrMcpAgent\Service\AltText\AltTextGate;
use Netresearch\NrMcpAgent\Service\AltText\AltTextImage;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltText;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextScanner;
use Netresearch\NrMcpAgent\Service\AltText\ReferenceWriteAccess;
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Netresearch\NrMcpAgent\Service\ToolAvailabilityInterface;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * "Optimieren" for an editor in a draft workspace (ADR-032): the conversation
 * starts and records the workspace, so its run writes there; the image is
 * decided on as that workspace shows it. Only a user who may work in no
 * workspace is refused. Real workspaces, the list's fixture
 * (missing_alt_texts.csv).
 */
final class AltTextInADraftWorkspaceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist', 'workspaces'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
        __DIR__ . '/../Fixtures/Extensions/alt_text_test',
    ];

    private const EDITOR = 2;

    private const DRAFT = 1;

    private const OTHER_DRAFT = 2;

    private ConversationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/missing_alt_texts.csv');
        $workspaces = $this->getConnectionPool()->getConnectionForTable('sys_workspace');
        $workspaces->insert('sys_workspace', ['uid' => self::DRAFT, 'pid' => 0, 'title' => 'Entwurf', 'members' => 'be_users_2']);
        $workspaces->insert('sys_workspace', ['uid' => self::OTHER_DRAFT, 'pid' => 0, 'title' => 'Andere', 'members' => 'be_users_2']);

        $this->repository = $this->get(ConversationRepository::class);
    }

    private function gate(): AltTextGate
    {
        $tools = $this->createMock(ToolAvailabilityInterface::class);
        $tools->method('unavailableTools')->willReturn([]);

        return new AltTextGate($this->get(MissingAltTextScanner::class), $this->get(ReferenceWriteAccess::class), $tools);
    }

    private function subject(): ChatApiController
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $capabilities = $this->createMock(ChatCapabilitiesInterface::class);
        $capabilities->method('getProviderCapabilities')->willReturn(['visionSupported' => false, 'maxFileSize' => 0, 'supportedFormats' => []]);

        return new ChatApiController(
            $this->repository,
            $this->createMock(ChatProcessorInterface::class),
            $config,
            $capabilities,
            $this->createMock(ChatApprovalInterface::class),
            $this->get(ResourceFactory::class),
            $this->get(StorageRepository::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            GeneralUtility::makeInstance(UriBuilder::class),
            siteFinder: $this->get(SiteFinder::class),
            altTextGate: $this->gate(),
            altTextConversation: $this->get(AltTextConversation::class),
            altTextImage: $this->get(AltTextImage::class),
        );
    }

    /** The editor, set up in the workspace — asserted, so a test never passes in live by accident. */
    private function editorIn(int $workspace): BackendUserAuthentication
    {
        $user = $this->setUpBackendUser(self::EDITOR);
        self::assertTrue($user->setTemporaryWorkspace($workspace));
        self::assertSame($workspace, $user->workspace);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);

        return $user;
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function start(int $referenceUid): array
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode(['altTextReference' => $referenceUid], JSON_THROW_ON_ERROR));
        $stream->rewind();

        $response = $this->subject()->createConversation((new ServerRequest('/', 'POST'))->withBody($stream));
        $decoded  = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return [$response->getStatusCode(), $decoded];
    }

    /**
     * @param array<string, int|string|null> $values
     */
    private function insertReference(array $values): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'pid'         => 10,
            'uid_local'   => 1,
            'uid_foreign' => 100,
            'tablenames'  => 'tt_content',
            'fieldname'   => 'image',
            ...$values,
        ]);
    }

    /** "Optimieren" starts in the draft workspace, and the conversation records it for its run. */
    #[Test]
    public function aStartInTheDraftWorkspaceRecordsIt(): void
    {
        $this->editorIn(self::DRAFT);

        [$status, $body] = $this->start(1);

        self::assertSame(201, $status, (string) json_encode($body));
        $stored = $this->repository->findByUid((int) $body['uid']);
        self::assertNotNull($stored);
        self::assertSame(1, $stored->getAltTextHit()['reference']);
        self::assertSame(ConversationStatus::Processing, $stored->getStatus());
        self::assertSame(self::DRAFT, $stored->getWorkspace());
    }

    /** The list's "Optimieren" is offered in the draft workspace. */
    #[Test]
    public function theListOffersOptimiseInTheDraftWorkspace(): void
    {
        self::assertNull($this->gate()->refusal($this->editorIn(self::DRAFT)));
    }

    /** An image whose alt text was set in the workspace is done there, while live still lacks it. */
    #[Test]
    public function anImageWhoseAltTextWasSetInTheWorkspaceIsDoneThere(): void
    {
        $this->insertReference(['uid' => 900, 'alternative' => 'Das Team im Entwurf', 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 1, 't3ver_state' => 0]);

        $this->setUpBackendUser(self::EDITOR);
        self::assertInstanceOf(MissingAltText::class, $this->gate()->hit($GLOBALS['BE_USER'], 1), 'open in live');

        $this->editorIn(self::DRAFT);
        [$status, $body] = $this->start(1);

        self::assertSame([409, 'done'], [$status, $body['code'] ?? null]);
    }

    /** An image new in the workspace is listed there and can be optimised. */
    #[Test]
    public function anImageNewInTheWorkspaceStarts(): void
    {
        $this->insertReference(['uid' => 901, 'alternative' => null, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 0, 't3ver_state' => 1]);
        $this->editorIn(self::DRAFT);

        [$status, $body] = $this->start(901);

        self::assertSame(201, $status, (string) json_encode($body));
    }

    /** Another workspace's image, and a version row by its own uid, are not there. */
    #[Test]
    public function anotherWorkspacesImageIsNotThere(): void
    {
        $this->insertReference(['uid' => 902, 'alternative' => null, 't3ver_wsid' => self::OTHER_DRAFT, 't3ver_oid' => 0, 't3ver_state' => 1]);
        $this->insertReference(['uid' => 903, 'alternative' => null, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 1, 't3ver_state' => 0]);
        $this->editorIn(self::DRAFT);

        foreach ([902, 903] as $uid) {
            [$status] = $this->start($uid);
            self::assertSame(403, $status, (string) $uid);
        }
    }

    /**
     * The model reads the page, the element and the elements around it as
     * the workspace shows them: versions, an element new there, and not one
     * deleted there, moved to another column there or new in another
     * workspace.
     */
    #[Test]
    public function theModelReadsTheTextsAsTheWorkspaceShowsThem(): void
    {
        $content = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $base    = ['pid' => 10, 'colPos' => 0, 'sys_language_uid' => 0];
        $content->insert('tt_content', [...$base, 'uid' => 600, 'CType' => 'textpic', 'header' => 'Unser Team', 'bodytext' => 'Text im Entwurf', 'sorting' => 256, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 100, 't3ver_state' => 0]);
        $content->insert('tt_content', [...$base, 'uid' => 601, 'CType' => 'image', 'header' => 'Nachbar im Entwurf', 'sorting' => 128, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 101, 't3ver_state' => 0]);
        $content->insert('tt_content', [...$base, 'uid' => 602, 'CType' => 'text', 'header' => 'Neu im Entwurf', 'sorting' => 290, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 0, 't3ver_state' => 1]);
        $content->insert('tt_content', [...$base, 'uid' => 603, 'CType' => 'text', 'header' => 'Neu woanders', 'sorting' => 310, 't3ver_wsid' => self::OTHER_DRAFT, 't3ver_oid' => 0, 't3ver_state' => 1]);
        // In live, "Text und Medien" (124) and "Versteckt" (102) follow the element.
        $content->insert('tt_content', [...$base, 'uid' => 604, 'CType' => 'textmedia', 'header' => 'Text und Medien', 'sorting' => 300, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 124, 't3ver_state' => 2]);
        $content->insert('tt_content', [...$base, 'uid' => 605, 'CType' => 'image', 'header' => 'Versteckt', 'hidden' => 1, 'sorting' => 384, 'colPos' => 1, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 102, 't3ver_state' => 0]);
        // Live after "Versteckt"; moved to another page in the workspace.
        $content->insert('tt_content', [...$base, 'uid' => 606, 'CType' => 'text', 'header' => 'Weggezogen', 'sorting' => 390]);
        $content->insert('tt_content', [...$base, 'uid' => 607, 'CType' => 'text', 'header' => 'Weggezogen', 'sorting' => 390, 'pid' => 11, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 606, 't3ver_state' => 4]);
        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', [
            'uid'          => 700, 'pid' => 1, 'title' => 'Startseite', 'description' => 'Beschreibung im Entwurf', 'doktype' => 1, 'sorting' => 100,
            'perms_userid' => 1, 'perms_user' => 31, 'perms_everybody' => 31, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 10, 't3ver_state' => 0,
        ]);

        $user = $this->editorIn(self::DRAFT);
        $hit  = $this->gate()->hit($user, 1);
        self::assertInstanceOf(MissingAltText::class, $hit, is_string($hit) ? $hit : '');

        $live  = $this->get(AltTextContext::class)->describe($hit, false, true, 0);
        $draft = $this->get(AltTextContext::class)->describe($hit, false, true, self::DRAFT);

        self::assertStringNotContainsString('im Entwurf', $live, 'live reads live');
        self::assertStringContainsString('Content after it: "Text und Medien"', $live, 'live control');
        self::assertStringContainsString('Content after it: "Versteckt"', $live, 'live control');
        self::assertStringContainsString('Page description: "Beschreibung im Entwurf"', $draft);
        self::assertStringContainsString('Text of the element: "Text im Entwurf"', $draft);
        self::assertStringContainsString('Content before it: "Nachbar im Entwurf"', $draft);
        self::assertStringContainsString('Content after it: "Neu im Entwurf"', $draft);
        self::assertStringNotContainsString('Neu woanders', $draft);
        self::assertStringNotContainsString('Text und Medien', $draft, 'deleted in the workspace');
        self::assertStringNotContainsString('Versteckt', $draft, 'moved to another column in the workspace');
        self::assertStringNotContainsString('Weggezogen', $draft, 'moved to another page in the workspace');

        // The transcript the run reads, built with the turn's user (the worker
        // sets that user up in the recorded workspace, BackendUserInitializer).
        [$status, $body] = $this->start(1);
        self::assertSame(201, $status);
        $conversation = $this->repository->findByUid((int) $body['uid']);
        self::assertNotNull($conversation);
        $transcript = json_encode($this->get(AltTextConversation::class)->transcript($conversation, $conversation->getDecodedMessages(), false), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('Text im Entwurf', $transcript);
    }

    /**
     * A record's text fields are read as the workspace shows the record. The
     * fixture's record type has no text field, so its title is made one.
     */
    #[Test]
    public function theModelReadsARecordsTextsAsTheWorkspaceShowsThem(): void
    {
        $GLOBALS['TCA']['tx_alttexttest_item']['columns']['title']['config']['type'] = 'text';
        $this->getConnectionPool()->getConnectionForTable('tx_alttexttest_item')->insert('tx_alttexttest_item', [
            'uid' => 600, 'pid' => 19, 'title' => 'Artikel im Entwurf', 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 1, 't3ver_state' => 0,
        ]);

        $user = $this->editorIn(self::DRAFT);
        $hit  = $this->gate()->hit($user, 28);
        self::assertInstanceOf(MissingAltText::class, $hit, is_string($hit) ? $hit : '');

        $live  = $this->get(AltTextContext::class)->describe($hit, false, true, 0);
        $draft = $this->get(AltTextContext::class)->describe($hit, false, true, self::DRAFT);

        self::assertStringContainsString('title: "Erster Artikel"', $live, 'live control');
        self::assertStringContainsString('title: "Artikel im Entwurf"', $draft);
        self::assertStringNotContainsString('title: "Erster Artikel"', $draft);
    }

    /**
     * On a page with more elements than the workspace's neighbours are
     * picked from, the candidates are the first ones of the element's
     * language by sorting: neither another language's elements nor elements
     * far after it crowd out the one before it. With more elements before it
     * than that, its neighbours are not among them (ADR-032).
     */
    #[Test]
    public function theElementBeforeIsFoundOnABusyPage(): void
    {
        $rows = [];
        for ($i = 0; $i < 500; ++$i) {
            // Inserted first, so they come first by uid.
            $rows[] = [2000 + $i, 50, 0, 1, 1 + $i, 'Andere Sprache', 0, 0, 0];
            $rows[] = [2500 + $i, 50, 0, 0, 5000 + $i, 'Weit danach', 0, 0, 0];
        }

        $rows[]  = [5001, 50, 0, 0, 1000, 'Davor', 0, 0, 0];
        $columns = ['uid', 'pid', 'colPos', 'sys_language_uid', 'sorting', 'header', 't3ver_wsid', 't3ver_oid', 't3ver_state'];
        $this->getConnectionPool()->getConnectionForTable('tt_content')->bulkInsert('tt_content', $rows, $columns);

        $neighbours = $this->get(ImageReferenceRepository::class)->neighbourContent(50, 0, 0, 1100, 5000, 1, self::DRAFT);

        self::assertSame(['Davor'], array_column($neighbours['before'], 'header'));
        self::assertSame(['Weit danach'], array_column($neighbours['after'], 'header'));
    }

    /**
     * Where the installation's TCA lets image references be edited live,
     * neither writer writes in a draft workspace: "Optimieren" is
     * refused there and says so, and stays available in live.
     */
    #[Test]
    public function withoutDraftsOfImageReferencesADraftWorkspaceStartsNothing(): void
    {
        $GLOBALS['TCA']['sys_file_reference']['ctrl']['versioningWS_alwaysAllowLiveEdit'] = true;
        $this->editorIn(self::DRAFT);

        [$status, $body] = $this->start(1);

        self::assertSame([409, 'liveOnly'], [$status, $body['code'] ?? null]);
        self::assertStringContainsString('image uses are not versioned or may be edited live', (string) $body['error']);
        $live            = $this->editorIn(self::DRAFT);
        $live->workspace = 0;
        self::assertNull($this->gate()->refusal($live));
    }

    /** A user who may work in no workspace has nowhere to save: nothing starts, and it says why. */
    #[Test]
    public function aUserWhoMayWorkInNoWorkspaceStartsNothing(): void
    {
        $user            = $this->editorIn(self::DRAFT);
        $user->workspace = -99;

        [$status, $body] = $this->start(1);

        self::assertSame([409, 'workspace'], [$status, $body['code'] ?? null]);
        self::assertStringContainsString('may not work in any workspace', (string) $body['error']);
    }
}
