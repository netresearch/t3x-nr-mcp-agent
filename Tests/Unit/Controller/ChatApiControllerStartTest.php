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
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Netresearch\NrMcpAgent\Service\SkillCatalogueInterface;
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
    private const SKILL = ['identifier' => 'seo-page-tour', 'name' => 'SEO einer Seite', 'description' => 'Geführt', 'uid' => 17];

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
        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->user = ['uid' => 1, 'usergroup' => '1'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    private function subject(?SkillCatalogueInterface $skills): ChatApiController
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
        );
    }

    private function catalogue(bool $available = true, bool $secondApprover = false): SkillCatalogueInterface&MockObject
    {
        $skills = $this->createMock(SkillCatalogueInterface::class);
        $skills->method('isAvailable')->willReturn($available);
        $skills->method('requiresSecondApprover')->willReturn($secondApprover);
        $skills->method('catalogue')->willReturn($available ? [self::SKILL] : []);
        $skills->method('find')->willReturnCallback(
            static fn(string $identifier): ?array => $available && $identifier === self::SKILL['identifier'] ? self::SKILL : null,
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
    private static function json(ResponseInterface $response): array
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
        self::assertSame('error.skillSecondApprover', self::json($start)['error'] ?? null);
        self::assertNull($this->added);

        self::assertSame(201, $subject->createConversation($this->request('{}'))->getStatusCode());
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
        self::assertSame(['available' => true, 'skills' => [self::SKILL]], self::json($this->subject($this->catalogue())->listSkills()));
        self::assertSame(['available' => false, 'skills' => []], self::json($this->subject($this->catalogue(false))->listSkills()));
        self::assertSame(['available' => false, 'skills' => []], self::json($this->subject(null)->listSkills()));
    }

    #[Test]
    public function pickingASkillWritesItsColumnAndAnswersWithItsName(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->expects(self::once())->method('updateSkillIdentifier')->with(0, 'seo-page-tour', 1, 17);

        $response = $this->subject($this->catalogue())->updateSkill($this->request('{"conversationUid": 7, "skill": "seo-page-tour"}'));

        self::assertSame(['skill' => ['identifier' => 'seo-page-tour', 'name' => 'SEO einer Seite']], self::json($response));
    }

    #[Test]
    public function removingTheSkillClearsItsColumn(): void
    {
        $conversation = new Conversation();
        $conversation->setSkillIdentifier('seo-page-tour');
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $this->repository->expects(self::once())->method('updateSkillIdentifier')->with(0, '', 1);

        self::assertSame(['skill' => null], self::json($this->subject($this->catalogue())->updateSkill($this->request('{"conversationUid": 7, "skill": ""}'))));
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

        $data = self::json($this->subject($this->catalogue())->getMessages($request));

        self::assertSame(['identifier' => 'seo-page-tour', 'name' => 'SEO einer Seite'], $data['skill']);
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
}
