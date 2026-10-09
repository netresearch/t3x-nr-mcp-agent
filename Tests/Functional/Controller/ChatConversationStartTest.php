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
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
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
        $config = $this->createMock(ExtensionConfiguration::class);
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
    private function create(array $body): \Psr\Http\Message\ResponseInterface
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
        $body = json_decode((string) $this->create(['pageUid' => 10, 'languageUid' => 0])->getBody(), true);
        self::assertIsArray($body);
        $stored = $this->repository->findByUid((int) $body['uid']);
        self::assertNotNull($stored);

        $prompt = $this->get(UserContextPrompt::class)->build($stored);

        self::assertStringContainsString('Selected page: uid 10', $prompt);
        self::assertStringContainsString('Language of the selected page: sys_language_uid 0', $prompt);
    }
}
