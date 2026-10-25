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
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextScanner;
use Netresearch\NrMcpAgent\Service\AltText\ReferenceWriteAccess;
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Netresearch\NrMcpAgent\Service\SkillCatalogueInterface;
use Netresearch\NrMcpAgent\Service\ToolAvailabilityInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A conversation that starts about one image of the list of missing alt
 * texts (ADR-026): the request names the reference, and the server decides
 * everything else from it with the user's rights, against the list's own
 * fixture (missing_alt_texts.csv, users as in MissingAltTextScannerTest).
 */
final class AltTextConversationStartTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
        __DIR__ . '/../Fixtures/Extensions/alt_text_test',
    ];

    private const EDITOR = 2;

    private ConversationRepository $repository;

    /** @var list<string> what the stubbed policy refuses */
    private array $unavailable = [];

    private bool $secondApprover = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/missing_alt_texts.csv');
        $this->repository = $this->get(ConversationRepository::class);
    }

    private function subject(): ChatApiController
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $tools = $this->createMock(ToolAvailabilityInterface::class);
        $tools->method('unavailableTools')->willReturnCallback(fn (array $names): array => array_values(array_intersect($names, $this->unavailable)));
        $skills = $this->createMock(SkillCatalogueInterface::class);
        $skills->method('requiresSecondApprover')->willReturnCallback(fn (): bool => $this->secondApprover);

        return new ChatApiController(
            $this->repository,
            $this->createMock(ChatProcessorInterface::class),
            $config,
            $this->createMock(ChatCapabilitiesInterface::class),
            $this->createMock(ChatApprovalInterface::class),
            $this->get(ResourceFactory::class),
            $this->get(StorageRepository::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            GeneralUtility::makeInstance(UriBuilder::class),
            siteFinder: $this->get(SiteFinder::class),
            skills: $skills,
            altTexts: $this->get(MissingAltTextScanner::class),
            referenceAccess: $this->get(ReferenceWriteAccess::class),
            tools: $tools,
        );
    }

    private function loginAs(int $userUid): void
    {
        $user            = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function create(array $body): array
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode($body, JSON_THROW_ON_ERROR));
        $stream->rewind();

        $response = $this->subject()->createConversation((new ServerRequest('/', 'POST'))->withBody($stream));

        return [$response->getStatusCode(), $this->decode($response)];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function countConversations(): int
    {
        return $this->getConnectionPool()->getConnectionForTable('tx_nrmcpagent_conversation')->count('*', 'tx_nrmcpagent_conversation', []);
    }

    /** An image on a page: the conversation is about the image, on its page, in its language. */
    #[Test]
    public function anOpenHitOnAPageStartsAConversationAboutItsImage(): void
    {
        $this->loginAs(self::EDITOR);

        [$status, $body] = $this->create(['altTextReference' => 1]);

        self::assertSame(201, $status);
        $stored = $this->repository->findByUid((int) $body['uid']);
        self::assertNotNull($stored);
        self::assertSame(['reference' => 1], $stored->getAltTextHit());
        self::assertSame(['pageId' => 10, 'module' => '', 'languageId' => 0], $stored->getViewContext());
        self::assertSame('', $stored->getSkillIdentifier());
    }

    /**
     * The start says whether "Als dekorativ markieren" can work: the editor's
     * group grants the mark, user 3's grants the alt text only.
     *
     * @return iterable<string, array{int, bool}>
     */
    public static function decorativeGrants(): iterable
    {
        yield 'with the grant for the mark' => [self::EDITOR, true];
        yield 'without the grant for the mark' => [3, false];
    }

    #[Test]
    #[DataProvider('decorativeGrants')]
    public function theStartSaysWhetherTheMarkMaySet(int $userUid, bool $allowed): void
    {
        $this->loginAs($userUid);

        [$status, $body] = $this->create(['altTextReference' => 1]);

        self::assertSame(201, $status, (string) json_encode($body));
        self::assertSame($allowed, $body['altTextDecorative'] ?? null);
    }

    /** A record's image binds no page: the record is the subject, not its folder. */
    #[Test]
    public function anOpenHitOfARecordBindsNoPage(): void
    {
        $this->loginAs(self::EDITOR);

        [$status, $body] = $this->create(['altTextReference' => 28]);

        self::assertSame(201, $status);
        $stored = $this->repository->findByUid((int) $body['uid']);
        self::assertNotNull($stored);
        self::assertSame(['reference' => 28], $stored->getAltTextHit());
        self::assertSame(0, $stored->getViewContext()['pageId']);
    }

    /** "Treffer zwischenzeitlich erledigt": told to a user who may edit the image, and the list updates. */
    #[Test]
    public function aHitThatHasAnAltTextByNowIsReportedAsDone(): void
    {
        $this->loginAs(self::EDITOR);

        [$status, $body] = $this->create(['altTextReference' => 3]);

        self::assertSame([409, 'done'], [$status, $body['code'] ?? null]);
        self::assertSame('This image is no longer in your list: it may have an alt text or a decorative mark by now, or you may no longer change it. The list is updated.', $body['error']);
        self::assertSame(0, $this->countConversations());
    }

    /**
     * A reference that is not there and one that is not the user's get the
     * same answer, so a uid alone does not tell whether it exists.
     *
     * @return iterable<string, array{int}>
     */
    public static function notTheUsers(): iterable
    {
        yield 'a reference that does not exist' => [99999];
        yield 'an image on a page outside the web mounts' => [16];
        yield 'an image of a page the editor may not edit as a page' => [46];
        yield 'an image of a page the editor may not edit the content of' => [55];
        yield 'an image on a page locked for editing' => [18];
        yield 'an image in a language the editor may not edit' => [42];
        yield 'an image of a record type the editor may not modify' => [28];
    }

    #[Test]
    #[DataProvider('notTheUsers')]
    public function aHitTheUserMayNotFixStartsNothingAndSaysNothingAboutIt(int $referenceUid): void
    {
        // User 3 may not modify the article records (28); everyone else is
        // the editor with every right the list asks for.
        $this->loginAs($referenceUid === 28 ? 3 : self::EDITOR);

        [$status, $body] = $this->create(['altTextReference' => $referenceUid]);

        self::assertSame(403, $status);
        self::assertSame('This image does not exist, or you may not edit it.', $body['error']);
        self::assertSame(0, $this->countConversations());
    }

    /** The tools write in the live workspace only; in a draft workspace nothing starts, and it says why. */
    #[Test]
    public function aDraftWorkspaceStartsNothing(): void
    {
        $this->loginAs(self::EDITOR);
        $GLOBALS['BE_USER']->workspace = 1;

        [$status, $body] = $this->create(['altTextReference' => 1]);

        self::assertSame([409, 'workspace'], [$status, $body['code'] ?? null]);
        self::assertSame(0, $this->countConversations());
    }

    /**
     * Without either tool the chat could neither save the text nor the mark:
     * "Optimieren" says so instead of starting.
     *
     * @return iterable<string, array{string}>
     */
    public static function tools(): iterable
    {
        yield "nr-llm's alt text writer" => ['set_file_reference_alternative_text'];
        yield 'the decorative mark' => ['set_file_reference_decorative'];
    }

    #[Test]
    #[DataProvider('tools')]
    public function withoutOneOfTheToolsNothingStarts(string $tool): void
    {
        $this->loginAs(self::EDITOR);
        $this->unavailable = [$tool];

        [$status, $body] = $this->create(['altTextReference' => 1]);

        self::assertSame([409, 'tools'], [$status, $body['code'] ?? null]);
        self::assertStringContainsString('set_file_reference_alternative_text', (string) $body['error']);
        self::assertSame(0, $this->countConversations());
    }

    /**
     * The proposal is saved by approving its card. User 7 may fix the image
     * of a "Text & Images" element but may not approve their own changes in
     * the chat.
     */
    #[Test]
    public function aUserWhoMayNotApproveTheirChangesStartsNothing(): void
    {
        $this->loginAs(7);

        [$status, $body] = $this->create(['altTextReference' => 1]);

        self::assertSame([409, 'approval'], [$status, $body['code'] ?? null]);
        self::assertSame(0, $this->countConversations());
    }

    /** Under four-eyes the editor cannot approve the proposal: nothing starts. */
    #[Test]
    public function aChatThatNeedsASecondApproverStartsNothing(): void
    {
        $this->loginAs(self::EDITOR);
        $this->secondApprover = true;

        [$status, $body] = $this->create(['altTextReference' => 1]);

        self::assertSame([409, 'secondApprover'], [$status, $body['code'] ?? null]);
        self::assertSame(0, $this->countConversations());
    }

    /**
     * The image is the whole start context; a page, a language that is not
     * the image's, or a skill sent along is not taken.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function mixedStarts(): iterable
    {
        yield 'with a page' => [['altTextReference' => 1, 'pageUid' => 11]];
        yield 'with a skill' => [['altTextReference' => 1, 'skill' => 'seo']];
        yield 'without a reference' => [['altTextReference' => 0]];
        yield 'with a reference that is no number' => [['altTextReference' => 'eins']];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('mixedStarts')]
    public function aStartThatIsNotJustTheImageIsRefused(array $body): void
    {
        $this->loginAs(self::EDITOR);

        [$status] = $this->create($body);

        self::assertSame(400, $status);
        self::assertSame(0, $this->countConversations());
    }
}
