<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Controller;

use Netresearch\NrMcpAgent\Controller\ChatApiController;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A conversation about a page that has been deleted opens read-only: the
 * chat is told so, and a new message is refused instead of continuing it on
 * a page that is not there. The controller comes from the container, so the
 * page lookup is wired as in production.
 */
final class ChatPageGoneTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'nr_mcp_agent' => ['llmTaskUid' => '1'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_nrmcpagent_conversation.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_view_context.csv');
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['LANG']    = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    private function aboutPage(int $conversation, int $page): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrmcpagent_conversation')
            ->update('tx_nrmcpagent_conversation', ['view_context' => json_encode(['pageId' => $page, 'module' => 'web_layout'])], ['uid' => $conversation]);
    }

    private function deletePage(int $page): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['deleted' => 1], ['uid' => $page]);
    }

    /**
     * @return array<string, mixed>
     */
    private function messages(int $conversation): array
    {
        $response = $this->get(ChatApiController::class)->getMessages((new ServerRequest())->withQueryParams(['conversationUid' => $conversation]));
        self::assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function aConversationAboutAnExistingPageIsNotReadOnly(): void
    {
        $this->aboutPage(1, 10);

        self::assertFalse($this->messages(1)['pageGone']);
    }

    #[Test]
    public function aConversationWithoutAPageIsNotReadOnly(): void
    {
        self::assertFalse($this->messages(1)['pageGone']);
    }

    #[Test]
    public function aConversationAboutADeletedPageIsReadOnly(): void
    {
        $this->aboutPage(1, 10);
        $this->deletePage(10);

        self::assertTrue($this->messages(1)['pageGone']);
    }

    #[Test]
    public function aMessageToAConversationAboutADeletedPageIsRefused(): void
    {
        $this->aboutPage(1, 10);
        $this->deletePage(10);
        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode(['conversationUid' => 1, 'content' => 'Weiter'], JSON_THROW_ON_ERROR));
        $stream->rewind();

        $response = $this->get(ChatApiController::class)->sendMessage((new ServerRequest('/', 'POST'))->withBody($stream));

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('has been deleted', json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)['error']);
        $row = $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrmcpagent_conversation')
            ->select(['message_count', 'status'], 'tx_nrmcpagent_conversation', ['uid' => 1])->fetchAssociative();
        self::assertSame(['message_count' => 0, 'status' => 'idle'], ['message_count' => (int) ($row['message_count'] ?? -1), 'status' => $row['status'] ?? '']);
    }
}
