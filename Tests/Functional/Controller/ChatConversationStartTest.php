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
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Netresearch\NrMcpAgent\Service\SkillCatalogueInterface;
use Netresearch\NrMcpAgent\Service\SkillInvocationInterface;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
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
 * Starting a conversation about a page, with TYPO3's real page access and
 * site lookup (ADR-019): the page must be one the user may show, and the
 * language one of the page's site.
 */
final class ChatConversationStartTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    private ConversationRepository $repository;

    private ChatApiController $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        // Page 10, readable by its owner (uid 1, an admin) only.
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_view_context.csv');

        $this->repository = $this->get(ConversationRepository::class);
        $config           = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);

        $this->subject = new ChatApiController(
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
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function create(array $body): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode($body, JSON_THROW_ON_ERROR));
        $stream->rewind();

        return $this->subject->createConversation((new ServerRequest('/', 'POST'))->withBody($stream));
    }

    private function countConversations(): int
    {
        return $this->getConnectionPool()->getConnectionForTable('tx_nrmcpagent_conversation')->count('*', 'tx_nrmcpagent_conversation', []);
    }

    #[Test]
    public function aConversationAboutAPageTheUserMayShowRemembersPageAndLanguage(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);

        $response = $this->create(['pageUid' => 10, 'languageUid' => 0]);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        $stored = $this->repository->findByUid((int) $body['uid']);
        self::assertNotNull($stored);
        self::assertSame(['pageId' => 10, 'module' => '', 'languageId' => 0], $stored->getViewContext());
    }

    #[Test]
    public function aPageTheUserMayNotShowStartsNothing(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(2);

        $response = $this->create(['pageUid' => 10]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->countConversations());
    }

    #[Test]
    public function aPageThatDoesNotExistStartsNothing(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);

        self::assertSame(403, $this->create(['pageUid' => 4711])->getStatusCode());
        self::assertSame(0, $this->countConversations());
    }

    /** Page 10 belongs to no site, so it has its default language and no other. */
    #[Test]
    public function aLanguageThePagesSiteDoesNotHaveStartsNothing(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);

        self::assertSame(403, $this->create(['pageUid' => 10, 'languageUid' => 3])->getStatusCode());
        self::assertSame(0, $this->countConversations());
    }

    /** The page's language reaches the system prompt with the page. */
    #[Test]
    public function thePromptNamesThePagesLanguage(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $body               = json_decode((string) $this->create(['pageUid' => 10, 'languageUid' => 0])->getBody(), true);
        self::assertIsArray($body);
        $stored = $this->repository->findByUid((int) $body['uid']);
        self::assertNotNull($stored);

        $prompt = $this->get(UserContextPrompt::class)->build($stored);

        self::assertStringContainsString('Selected page: uid 10', $prompt);
        self::assertStringContainsString('Language of the selected page: sys_language_uid 0', $prompt);
    }

    /**
     * A subject that can start a guided process: a catalogue with one process
     * skill and one plain skill, and nr-llm's invocation.
     */
    private function tourSubject(ChatProcessorInterface $processor, int $maxActive = 0): ChatApiController
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getMaxActiveConversationsPerUser')->willReturn($maxActive);

        $entries = [
            '9404:seo-optimieren' => ['identifier' => '9404:seo-optimieren', 'name' => 'seo-optimieren', 'description' => '', 'uid' => 9422, 'process' => true],
            '9403:tone-check'     => ['identifier' => '9403:tone-check', 'name' => 'tone-check', 'description' => '', 'uid' => 9421, 'process' => false],
        ];
        $skills = $this->createMock(SkillCatalogueInterface::class);
        $skills->method('isAvailable')->willReturn(true);
        $skills->method('requiresSecondApprover')->willReturn(false);
        $skills->method('catalogue')->willReturn(array_values($entries));
        $skills->method('find')->willReturnCallback(static fn (string $identifier): ?array => $entries[$identifier] ?? null);

        return new ChatApiController(
            $this->repository,
            $processor,
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
            skillInvocation: $this->createMock(SkillInvocationInterface::class),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createWith(ChatApiController $subject, array $body): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode($body, JSON_THROW_ON_ERROR));
        $stream->rewind();

        return $subject->createConversation((new ServerRequest('/', 'POST'))->withBody($stream));
    }

    private function loginWithLanguage(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['LANG']    = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    /**
     * The dashboard's "SEO optimieren" opens a conversation about a page with
     * a process skill. Its first run starts with it (ADR-023, amendment): the
     * editor types no prompt. The turn is the page choice's own "Check this
     * page", shown in the transcript, and the worker is handed the
     * conversation, whose first run carries the invocation.
     */
    #[Test]
    public function aGuidedProcessAboutAPageStartsItsFirstRunWithTheConversation(): void
    {
        $this->loginWithLanguage();
        $processor  = $this->createMock(ChatProcessorInterface::class);
        $dispatched = [];
        $processor->expects(self::once())->method('dispatch')->willReturnCallback(static function (int $uid) use (&$dispatched): void {
            $dispatched[] = $uid;
        });

        $response = $this->createWith($this->tourSubject($processor), ['pageUid' => 10, 'languageUid' => 0, 'skill' => '9404:seo-optimieren']);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        $uid = (int) $body['uid'];
        self::assertSame([$uid], $dispatched);
        $stored = $this->repository->findByUid($uid);
        self::assertNotNull($stored);
        self::assertSame(ConversationStatus::Processing, $stored->getStatus());
        $messages = $stored->getDecodedMessages();
        self::assertCount(1, $messages);
        self::assertSame(MessageRole::User->value, $messages[0]['role']);
        self::assertSame('Check this page', $messages[0]['content']);
        self::assertSame(['pageId' => 10, 'module' => '', 'languageId' => 0], $stored->getViewContext());
    }

    /** Without a page the chat asks for one (ADR-023); nothing runs before the editor picks it. */
    #[Test]
    public function aGuidedProcessWithoutAPageWaitsForThePageChoice(): void
    {
        $this->loginWithLanguage();
        $processor = $this->createMock(ChatProcessorInterface::class);
        $processor->expects(self::never())->method('dispatch');

        $body = json_decode((string) $this->createWith($this->tourSubject($processor), ['skill' => '9404:seo-optimieren'])->getBody(), true);
        self::assertIsArray($body);
        $stored = $this->repository->findByUid((int) $body['uid']);

        self::assertNotNull($stored);
        self::assertSame(ConversationStatus::Idle, $stored->getStatus());
        self::assertSame([], $stored->getDecodedMessages());
    }

    /** A skill that is no process waits for the editor's message, as before. */
    #[Test]
    public function aPlainSkillAboutAPageStartsNoRun(): void
    {
        $this->loginWithLanguage();
        $processor = $this->createMock(ChatProcessorInterface::class);
        $processor->expects(self::never())->method('dispatch');

        $body = json_decode((string) $this->createWith($this->tourSubject($processor), ['pageUid' => 10, 'skill' => '9403:tone-check'])->getBody(), true);
        self::assertIsArray($body);
        $stored = $this->repository->findByUid((int) $body['uid']);

        self::assertNotNull($stored);
        self::assertSame(ConversationStatus::Idle, $stored->getStatus());
        self::assertSame([], $stored->getDecodedMessages());
    }

    /**
     * The start is a turn, so the limit on running conversations applies; a
     * refused start creates nothing, as every refused start (ADR-019).
     */
    #[Test]
    public function aGuidedProcessOverTheLimitOfRunningConversationsStartsNothing(): void
    {
        $this->loginWithLanguage();
        $running = new Conversation();
        $running->setBeUser(1);
        $running->setStatus(ConversationStatus::Processing);

        $this->repository->add($running);
        $processor = $this->createMock(ChatProcessorInterface::class);
        $processor->expects(self::never())->method('dispatch');

        $response = $this->createWith($this->tourSubject($processor, maxActive: 1), ['pageUid' => 10, 'skill' => '9404:seo-optimieren']);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame(1, $this->countConversations());
    }
}
