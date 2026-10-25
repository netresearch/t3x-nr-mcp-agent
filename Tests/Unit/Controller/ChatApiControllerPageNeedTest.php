<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Controller;

use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
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
use Netresearch\NrMcpAgent\Service\SkillInvocation;
use Netresearch\NrMcpAgent\Service\SkillInvocationInterface;
use Netresearch\NrMcpAgent\Service\TourContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * What the page need a skill declares (ADR-025) does to a conversation: the
 * page choice, whether the page stays, and whether one is bound at all. The
 * process marker keeps deciding the process; an undeclared skill behaves as
 * before (ChatApiControllerTourStartTest, ChatApiControllerStartTest).
 */
#[CoversClass(ChatApiController::class)]
final class ChatApiControllerPageNeedTest extends TestCase
{
    private const ENTRIES = [
        'page-needed'   => ['identifier' => 'page-needed', 'name' => 'Seite übersetzen', 'description' => '', 'uid' => 31, 'process' => false, 'page' => 'required'],
        'no-page'       => ['identifier' => 'no-page', 'name' => 'Newsletter', 'description' => '', 'uid' => 32, 'process' => false, 'page' => 'none'],
        'no-page-tour'  => ['identifier' => 'no-page-tour', 'name' => 'Onboarding', 'description' => '', 'uid' => 33, 'process' => true, 'page' => 'none'],
        'optional-tour' => ['identifier' => 'optional-tour', 'name' => 'Rundgang', 'description' => '', 'uid' => 34, 'process' => true, 'page' => 'optional'],
        'old-tour'      => ['identifier' => 'old-tour', 'name' => 'SEO', 'description' => '', 'uid' => 35, 'process' => true],
        'tone-check'    => ['identifier' => 'tone-check', 'name' => 'Tonalität', 'description' => '', 'uid' => 36, 'process' => false, 'page' => null],
    ];

    private ConversationRepository&MockObject $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(ConversationRepository::class);
        $this->repository->method('updateIf')->willReturn(true);
        $user               = $this->createStub(BackendUserAuthentication::class);
        $user->user         = ['uid' => 2, 'usergroup' => '', 'admin' => 0];
        $GLOBALS['BE_USER'] = $user;
        $GLOBALS['LANG']    = $this->createStub(LanguageService::class);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    private function subject(bool $invocation = true): ChatApiController
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $skills = $this->createMock(SkillCatalogueInterface::class);
        $skills->method('isAvailable')->willReturn(true);
        $skills->method('requiresSecondApprover')->willReturn(false);
        $skills->method('catalogue')->willReturn(array_values(self::ENTRIES));
        $skills->method('find')->willReturnCallback(static fn (string $identifier): ?array => self::ENTRIES[$identifier] ?? null);
        $tour = $this->createMock(TourContext::class);
        $tour->method('of')->willReturn(null);

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
            tourContext: $tour,
            skillInvocation: $invocation ? new class implements SkillInvocationInterface {
                public function withInvocation(AgentRunRequest $request, SkillInvocation $invocation): AgentRunRequest
                {
                    return $request;
                }
            } : null,
        );
    }

    private function conversation(string $skill, int $pageUid): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(2);
        $conversation->setSkillIdentifier($skill, self::ENTRIES[$skill]['uid']);
        if ($pageUid > 0) {
            $conversation->setViewContext($pageUid, 'web_layout', 0);
        }

        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        return $conversation;
    }

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
    private function poll(ChatApiController $subject): array
    {
        $data = json_decode((string) $subject->getMessages($this->request('', ['conversationUid' => '1']))->getBody(), true);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function send(ChatApiController $subject, Conversation $conversation, string $context): void
    {
        $conversation->setStatus(ConversationStatus::Idle);
        $subject->sendMessage($this->request('{"conversationUid": 7, "content": "weiter", "context": ' . $context . '}'));
    }

    /**
     * A skill that is no process but needs a page asks for one without
     * nr-llm's invocation: its turns carry the skill as a forced skill.
     */
    #[Test]
    public function aPlainSkillThatNeedsAPageAsksForOne(): void
    {
        $this->conversation('page-needed', 0);

        self::assertSame(['skill' => 'page-needed', 'choosePage' => true], $this->poll($this->subject(invocation: false))['tourStart'] ?? 'missing');
    }

    /** Once it has its page it is offered no new start: ending it would withdraw no waiting run. */
    #[Test]
    public function aPlainSkillThatHasItsPageIsOfferedNoNewStart(): void
    {
        $this->conversation('page-needed', 20);

        $data = $this->poll($this->subject());

        self::assertArrayHasKey('tourStart', $data);
        self::assertNull($data['tourStart']);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function noPageChoice(): iterable
    {
        yield 'no process, page none' => ['no-page', 0];
        yield 'process, page none' => ['no-page-tour', 0];
        yield 'process, page optional' => ['optional-tour', 0];
        yield 'no process, nothing declared' => ['tone-check', 0];
    }

    #[Test]
    #[DataProvider('noPageChoice')]
    public function aSkillThatDoesNotNeedAPageIsOfferedNoPageChoice(string $skill, int $pageUid): void
    {
        $this->conversation($skill, $pageUid);

        $data = $this->poll($this->subject());

        self::assertArrayHasKey('tourStart', $data);
        self::assertNull($data['tourStart']);
    }

    /** A process that declares nothing still needs its page, as before. */
    #[Test]
    public function aProcessThatDeclaresNothingStillAsksForItsPage(): void
    {
        $this->conversation('old-tour', 0);

        self::assertSame(['skill' => 'old-tour', 'choosePage' => true], $this->poll($this->subject())['tourStart'] ?? 'missing');
    }

    /** A skill that needs a page keeps it, whatever page the browser shows (ADR-023). */
    #[Test]
    public function aPlainSkillThatNeedsAPageKeepsIt(): void
    {
        $conversation = $this->conversation('page-needed', 10);

        $this->send($this->subject(), $conversation, '{"pageId": 11, "module": "web_layout", "languageId": 1}');

        self::assertSame(['pageId' => 10, 'module' => 'web_layout', 'languageId' => 0], $conversation->getViewContext());
    }

    /** A process that may work on a page follows the browser, as a plain skill does. */
    #[Test]
    public function aProcessThatMayWorkOnAPageFollowsTheBrowser(): void
    {
        $conversation = $this->conversation('optional-tour', 10);

        $this->send($this->subject(), $conversation, '{"pageId": 11, "module": "web_layout"}');

        self::assertSame(['pageId' => 11, 'module' => 'web_layout', 'languageId' => -1], $conversation->getViewContext());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function worksOnNoPage(): iterable
    {
        yield 'no process' => ['no-page'];
        yield 'process' => ['no-page-tour'];
    }

    /** A skill that works on no page binds none: only the module follows. */
    #[Test]
    #[DataProvider('worksOnNoPage')]
    public function aSkillThatWorksOnNoPageBindsNoneWhenAMessageIsSent(string $skill): void
    {
        $conversation = $this->conversation($skill, 0);

        $this->send($this->subject(), $conversation, '{"pageId": 11, "module": "web_layout", "languageId": 1}');

        self::assertSame(['pageId' => 0, 'module' => 'web_layout', 'languageId' => -1], $conversation->getViewContext());
    }

    /** Picking a skill that works on no page drops the conversation's page. */
    #[Test]
    #[DataProvider('worksOnNoPage')]
    public function pickingASkillThatWorksOnNoPageDropsThePage(string $skill): void
    {
        $conversation = $this->conversation('tone-check', 10);
        $this->repository->expects(self::once())->method('updateViewContext')->with($conversation, 2);

        $response = $this->subject()->updateSkill($this->request('{"conversationUid": 7, "skill": "' . $skill . '"}'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['pageId' => 0, 'module' => 'web_layout', 'languageId' => -1], $conversation->getViewContext());
    }

    /** Picking any other skill leaves the page where it is. */
    #[Test]
    public function pickingASkillThatNeedsAPageKeepsThePage(): void
    {
        $conversation = $this->conversation('tone-check', 10);
        $this->repository->expects(self::never())->method('updateViewContext');

        $this->subject()->updateSkill($this->request('{"conversationUid": 7, "skill": "page-needed"}'));

        self::assertSame(10, $conversation->getViewContext()['pageId']);
    }
}
