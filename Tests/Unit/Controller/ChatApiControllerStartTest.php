<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Controller;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Controller\ChatApiController;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Service\Assistant\SkillLabels;
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Netresearch\NrMcpAgent\Service\SkillCatalogueInterface;
use Netresearch\NrMcpAgent\Tests\Unit\Service\Assistant\SkillLabelsStub;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use stdClass;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Starting a conversation with a skill, and picking one with "/" (ADR-019).
 * The page and language checks need TYPO3's page access and are covered by
 * the functional ChatConversationStartTest.
 */
#[CoversClass(ChatApiController::class)]
final class ChatApiControllerStartTest extends TestCase
{
    use SkillLabelsStub;

    private const SKILL = ['identifier' => 'seo-page-tour', 'name' => 'SEO einer Seite', 'description' => 'Geführt', 'uid' => 17, 'process' => null];

    private const PLAIN_SKILL = ['identifier' => 'tone-check', 'name' => 'Tonalität', 'description' => 'Kein Ablauf', 'uid' => 18, 'process' => false];

    private const PROCESS_SKILL = ['identifier' => 'content-tour', 'name' => 'Inhalte verbessern', 'description' => 'Geführt', 'uid' => 19, 'process' => true];

    private ConversationRepository&MockObject $repository;

    private ?Conversation $added = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(ConversationRepository::class);
        $this->repository->method('add')->willReturnCallback(function (Conversation $conversation): int {
            $this->added = $conversation;

            return 42;
        });
        $GLOBALS['BE_USER']       = new stdClass();
        $GLOBALS['BE_USER']->user = ['uid' => 1, 'usergroup' => '1'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    private function subject(?SkillCatalogueInterface $skills, ?SkillLabels $labels = null): ChatApiController
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);

        return new ChatApiController(
            $this->repository,
            $this->createMock(ChatProcessorInterface::class),
            $config,
            $this->createMock(ChatCapabilitiesInterface::class),
            $this->createMock(ChatApprovalInterface::class),
            $this->createMock(ResourceFactory::class),
            $this->createMock(StorageRepository::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->createMock(UriBuilder::class),
            skills: $skills,
            skillLabels: $labels,
        );
    }

    private function catalogue(bool $available = true, bool $secondApprover = false): SkillCatalogueInterface&MockObject
    {
        $skills = $this->createMock(SkillCatalogueInterface::class);
        $skills->method('isAvailable')->willReturn($available);
        $skills->method('requiresSecondApprover')->willReturn($secondApprover);
        $skills->method('catalogue')->willReturn($available ? [self::SKILL, self::PLAIN_SKILL, self::PROCESS_SKILL] : []);
        $skills->method('find')->willReturnCallback(
            static fn (string $identifier): ?array => $available ? ([self::SKILL['identifier'] => self::SKILL, self::PLAIN_SKILL['identifier'] => self::PLAIN_SKILL, self::PROCESS_SKILL['identifier'] => self::PROCESS_SKILL][$identifier] ?? null) : null,
        );

        return $skills;
    }

    private function request(string $body): ServerRequestInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($stream);
        $request->method('getQueryParams')->willReturn([]);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    #[Test]
    public function aConversationStartsWithASkillFromTheCatalogue(): void
    {
        $response = $this->subject($this->catalogue())->createConversation($this->request('{"skill": "seo-page-tour"}'));

        self::assertSame(201, $response->getStatusCode());
        self::assertNotNull($this->added);
        self::assertSame('seo-page-tour', $this->added->getSkillIdentifier());
        self::assertSame(self::SKILL['uid'], $this->added->getSkillUid(), 'the uid the catalogue knows is kept beside the identifier');
    }

    /**
     * A guided process is decided on the chat card only (nr-llm ADR-214);
     * under four-eyes the owner cannot release their own write there, so no
     * skill starts, and the chat shows why. A plain conversation still does.
     */
    #[Test]
    public function noSkillStartsOnAConfigurationWithASecondApprover(): void
    {
        $subject = $this->subject($this->catalogue(secondApprover: true));

        $start = $subject->createConversation($this->request('{"skill": "seo-page-tour"}'));
        self::assertSame(409, $start->getStatusCode());
        self::assertSame('error.skillSecondApprover', $this->json($start)['error'] ?? null);
        self::assertNull($this->added);

        self::assertSame(201, $subject->createConversation($this->request('{}'))->getStatusCode());
    }

    /**
     * Where nr-llm marks process skills (nr-llm PR 1025), a skill marked as
     * no process is no tour and starts on a four-eyes configuration; one with
     * no marker counts as a process.
     */
    #[Test]
    public function aSkillMarkedAsNoProcessStartsUnderFourEyes(): void
    {
        $response = $this->subject($this->catalogue(secondApprover: true))->createConversation($this->request('{"skill": "tone-check"}'));

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function aSkillOutsideTheCatalogueIsRefused(): void
    {
        $response = $this->subject($this->catalogue())->createConversation($this->request('{"skill": "someone-elses-skill"}'));

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($this->added, 'nothing is created for a refused start');
    }

    /**
     * Where nr-llm cannot take a skill, there is nothing to check against: the
     * identifier is kept and the runs go without it.
     */
    #[Test]
    public function withoutSkillsInNrLlmTheIdentifierIsKeptAndDegrades(): void
    {
        $response = $this->subject($this->catalogue(false))->createConversation($this->request('{"skill": "seo-page-tour"}'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('seo-page-tour', $this->added?->getSkillIdentifier());
    }

    #[Test]
    public function aMalformedIdentifierIsRefusedEvenWithoutACatalogue(): void
    {
        $response = $this->subject(null)->createConversation($this->request('{"skill": "<script>"}'));

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function aPlainStartIsUnchanged(): void
    {
        self::assertSame(201, $this->subject($this->catalogue())->createConversation()->getStatusCode());
        self::assertSame('', $this->added?->getSkillIdentifier());
        self::assertSame(201, $this->subject($this->catalogue())->createConversation($this->request(''))->getStatusCode());
    }

    #[Test]
    public function theSlashListShowsTheCatalogue(): void
    {
        self::assertSame(['available' => true, 'skills' => [self::SKILL, self::PLAIN_SKILL, self::PROCESS_SKILL]], $this->json($this->subject($this->catalogue())->listSkills()));
        self::assertSame(['available' => false, 'skills' => []], $this->json($this->subject($this->catalogue(false))->listSkills()));
        self::assertSame(['available' => false, 'skills' => []], $this->json($this->subject(null)->listSkills()));
    }

    #[Test]
    public function pickingASkillWritesItsColumnAndAnswersWithItsName(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->expects(self::once())->method('updateSkillIdentifier')->with(0, 'seo-page-tour', 1, 17);

        $response = $this->subject($this->catalogue())->updateSkill($this->request('{"conversationUid": 7, "skill": "seo-page-tour"}'));

        // tourStart: null, as nr-llm cannot start a run with an invocation here.
        self::assertSame(['skill' => ['identifier' => 'seo-page-tour', 'name' => 'SEO einer Seite', 'title' => null, 'bindsPage' => true], 'tourStart' => null], $this->json($response));
    }

    /**
     * A process skill picked in a running conversation starts on the next
     * turn (nr-llm ADR-214 item 10): the pick sets the start marker.
     */
    #[Test]
    public function pickingAProcessSkillMarksTheNextTurnAsItsStart(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setLastRunUuid('run-1');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->expects(self::once())->method('updateSkillIdentifier')->with(0, 'content-tour', 1, 19, true);

        $this->subject($this->catalogue())->updateSkill($this->request('{"conversationUid": 7, "skill": "content-tour"}'));

        self::assertTrue($conversation->isSkillStartPending());
    }

    /** A skill that is no process — marked so, or unmarked — goes on every turn and needs no start. */
    #[Test]
    public function pickingAPlainSkillSetsNoStartMarker(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->expects(self::exactly(2))->method('updateSkillIdentifier')
            ->with(0, self::anything(), 1, self::anything(), false);

        $this->subject($this->catalogue())->updateSkill($this->request('{"conversationUid": 7, "skill": "tone-check"}'));
        $this->subject($this->catalogue())->updateSkill($this->request('{"conversationUid": 7, "skill": "seo-page-tour"}'));

        self::assertFalse($conversation->isSkillStartPending());
    }

    #[Test]
    public function removingTheSkillClearsItsColumn(): void
    {
        $conversation = new Conversation();
        $conversation->setSkillIdentifier('seo-page-tour');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->expects(self::once())->method('updateSkillIdentifier')->with(0, '', 1);

        self::assertSame(['skill' => null, 'tourStart' => null], $this->json($this->subject($this->catalogue())->updateSkill($this->request('{"conversationUid": 7, "skill": ""}'))));
    }

    #[Test]
    public function pickingASkillWhileATurnRunsIsRefused(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Locked);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->expects(self::never())->method('updateSkillIdentifier');

        self::assertSame(409, $this->subject($this->catalogue())->updateSkill($this->request('{"conversationUid": 7, "skill": "seo-page-tour"}'))->getStatusCode());
    }

    #[Test]
    public function pickingAnUnknownSkillIsRefused(): void
    {
        $this->repository->method('findOneByUidAndBeUser')->willReturn(new Conversation());
        $this->repository->expects(self::never())->method('updateSkillIdentifier');

        self::assertSame(400, $this->subject($this->catalogue())->updateSkill($this->request('{"conversationUid": 7, "skill": "nope"}'))->getStatusCode());
    }

    #[Test]
    public function pickingASkillOnAConfigurationWithASecondApproverIsRefused(): void
    {
        $this->repository->method('findOneByUidAndBeUser')->willReturn(new Conversation());
        $this->repository->expects(self::never())->method('updateSkillIdentifier');

        self::assertSame(409, $this->subject($this->catalogue(secondApprover: true))->updateSkill($this->request('{"conversationUid": 7, "skill": "seo-page-tour"}'))->getStatusCode());
    }

    #[Test]
    public function theMessagesNameTheConversationsSkill(): void
    {
        $conversation = new Conversation();
        $conversation->setSkillIdentifier('seo-page-tour');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($stream);
        $request->method('getQueryParams')->willReturn(['conversationUid' => '7']);

        $data = $this->json($this->subject($this->catalogue())->getMessages($request));

        self::assertSame(['identifier' => 'seo-page-tour', 'name' => 'SEO einer Seite', 'title' => null, 'bindsPage' => true], $data['skill'], 'a skill without a title has none');
    }

    /** The chip reads "Current task: <title>": the editor-facing title travels with the name. */
    #[Test]
    public function theMessagesCarryTheSkillsHumanTitle(): void
    {
        $conversation = new Conversation();
        $conversation->setSkillIdentifier('seo-page-tour');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($stream);
        $request->method('getQueryParams')->willReturn(['conversationUid' => '7']);

        $data = $this->json($this->subject($this->catalogue(), $this->skillLabels(['skill.seo-page-tour.title' => 'SEO optimieren']))->getMessages($request));

        self::assertSame(['identifier' => 'seo-page-tour', 'name' => 'SEO einer Seite', 'title' => 'SEO optimieren', 'bindsPage' => true], $data['skill']);
    }

    /**
     * The browser often cannot see the page's language. A turn on the same
     * page keeps the language the conversation started with; another page
     * drops it.
     */
    #[Test]
    public function aTurnOnTheSamePageKeepsTheStartingLanguage(): void
    {
        $conversation = new Conversation();
        $conversation->setViewContext(10, '', 1);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->method('updateIf')->willReturn(true);
        $subject = $this->subject(null);

        $subject->sendMessage($this->request('{"conversationUid": 7, "content": "weiter", "context": {"pageId": 10, "module": "web_layout"}}'));
        self::assertSame(['pageId' => 10, 'module' => 'web_layout', 'languageId' => 1], $conversation->getViewContext());

        $conversation->setStatus(ConversationStatus::Idle);
        $subject->sendMessage($this->request('{"conversationUid": 7, "content": "und hier", "context": {"pageId": 11, "module": "web_layout"}}'));
        self::assertSame(['pageId' => 11, 'module' => 'web_layout', 'languageId' => -1], $conversation->getViewContext());
    }

    /**
     * A guided process stays on the page it started with (ADR-023). The chat
     * module shows no page beside it, so its messages carry page 0; the page
     * module may show another page. Neither moves the process.
     */
    #[Test]
    public function aTurnOfAGuidedProcessKeepsItsPageAndLanguage(): void
    {
        $conversation = new Conversation();
        $conversation->setSkillIdentifier('content-tour', 19);
        $conversation->setViewContext(10001, '', 0);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->method('updateIf')->willReturn(true);
        $subject = $this->subject($this->catalogue());

        $subject->sendMessage($this->request('{"conversationUid": 146, "content": "Bitte prüfe diese Seite.", "context": {"pageId": 0, "module": "nrmcpagent_chat"}}'));
        self::assertSame(['pageId' => 10001, 'module' => 'nrmcpagent_chat', 'languageId' => 0], $conversation->getViewContext());

        $conversation->setStatus(ConversationStatus::Idle);
        $subject->sendMessage($this->request('{"conversationUid": 146, "content": "weiter", "context": {"pageId": 11, "module": "web_layout", "languageId": 1}}'));
        self::assertSame(['pageId' => 10001, 'module' => 'web_layout', 'languageId' => 0], $conversation->getViewContext());

        $conversation->setStatus(ConversationStatus::Idle);
        $subject->sendMessage($this->request('{"conversationUid": 146, "content": "und ohne Kontext"}'));
        self::assertSame(['pageId' => 10001, 'module' => '', 'languageId' => 0], $conversation->getViewContext());
    }

    /** A process without a page takes the one its page choice sends (ADR-023). */
    #[Test]
    public function aGuidedProcessWithoutAPageTakesTheSentOne(): void
    {
        $conversation = new Conversation();
        $conversation->setSkillIdentifier('content-tour', 19);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->method('updateIf')->willReturn(true);

        $this->subject($this->catalogue())->sendMessage($this->request('{"conversationUid": 7, "content": "guided.checkPage", "context": {"pageId": 20, "module": "web_layout", "languageId": 1}}'));

        self::assertSame(['pageId' => 20, 'module' => 'web_layout', 'languageId' => 1], $conversation->getViewContext());
    }

    /** A skill marked as no process is no tour: the page follows the browser, as without a skill. */
    #[Test]
    public function aPlainSkillFollowsTheBrowsersPage(): void
    {
        $conversation = new Conversation();
        $conversation->setSkillIdentifier('tone-check', 18);
        $conversation->setViewContext(10, '', 0);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->method('updateIf')->willReturn(true);

        $this->subject($this->catalogue())->sendMessage($this->request('{"conversationUid": 7, "content": "und hier", "context": {"pageId": 11, "module": "web_layout"}}'));

        self::assertSame(['pageId' => 11, 'module' => 'web_layout', 'languageId' => -1], $conversation->getViewContext());
    }
}
