<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Controller;

use Netresearch\NrMcpAgent\Controller\ChatApiController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A guided process whose page has been deleted opens read-only: the chat is
 * told so, and every request that would continue it is refused instead of
 * continuing it on a page that is not there; ending the tour stays open. An
 * ordinary chat only stores the page the browser last showed and is not
 * affected. The controller comes from the container, so the page lookup and
 * the skill catalogue are wired as in production.
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

    /** A guided process bound to the page (`seo-optimieren` is not in the catalogue, so it counts as a process). */
    private function aboutPage(int $conversation, int $page, string $skill = 'seo-optimieren'): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrmcpagent_conversation')
            ->update('tx_nrmcpagent_conversation', ['view_context' => json_encode(['pageId' => $page, 'module' => 'web_layout']), 'skill_identifier' => $skill], ['uid' => $conversation]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): ServerRequest
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode($body, JSON_THROW_ON_ERROR));
        $stream->rewind();

        return (new ServerRequest('/', 'POST'))->withBody($stream)->withParsedBody($body);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $conversation): array
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrmcpagent_conversation')
            ->select(['message_count', 'status', 'skill_identifier', 'approval_run_uuid'], 'tx_nrmcpagent_conversation', ['uid' => $conversation])->fetchAssociative() ?: [];
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
    public function aGuidedProcessOnAnExistingPageIsNotReadOnly(): void
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
    public function aGuidedProcessOnADeletedPageIsReadOnly(): void
    {
        $this->aboutPage(1, 10);
        $this->deletePage(10);

        self::assertTrue($this->messages(1)['pageGone']);
    }

    /**
     * An ordinary chat takes the page the browser shows with every message:
     * the deleted page is only where the editor last was, and the next
     * message moves on.
     */
    #[Test]
    public function anOrdinaryChatWhoseLastPageWasDeletedIsNotReadOnly(): void
    {
        $this->aboutPage(1, 10, skill: '');
        $this->deletePage(10);

        self::assertFalse($this->messages(1)['pageGone']);
        // Refused for being read-only would be 409 before the content is read.
        $response = $this->get(ChatApiController::class)->sendMessage($this->post(['conversationUid' => 1, 'content' => '']));
        self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * A tour waiting for a decision when its page is deleted: nothing can be
     * decided any more, and ending the tour withdraws the waiting run and
     * leaves an ordinary chat that can be continued.
     */
    #[Test]
    public function aTourWaitingForApprovalThenPageDeletedCanBeEnded(): void
    {
        $this->aboutPage(1, 10);
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrmcpagent_conversation')
            ->update('tx_nrmcpagent_conversation', ['status' => 'awaiting_approval', 'approval_run_uuid' => 'e2e00000-0000-4000-8000-0000000000ff'], ['uid' => 1]);
        $this->deletePage(10);
        $controller = $this->get(ChatApiController::class);

        self::assertSame(409, $controller->decideApproval($this->post(['conversationUid' => 1, 'approve' => true, 'turnDigest' => 'digest']))->getStatusCode());

        $ended = $controller->endTour($this->post(['conversationUid' => 1]));

        self::assertSame(200, $ended->getStatusCode(), (string) $ended->getBody());
        self::assertSame(['status' => 'idle', 'skill_identifier' => '', 'approval_run_uuid' => ''], array_intersect_key($this->row(1), ['status' => 1, 'skill_identifier' => 1, 'approval_run_uuid' => 1]));
        self::assertFalse($this->messages(1)['pageGone']);
    }

    /**
     * Every request that would continue the conversation, with a body that
     * would otherwise get past the request's own checks.
     *
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function continuations(): iterable
    {
        yield 'a new message' => ['sendMessage', ['conversationUid' => 1, 'content' => 'Weiter'], 'idle'];
        yield 'an edited message' => ['editMessage', ['conversationUid' => 1, 'messageIndex' => 0, 'content' => 'Anders'], 'idle'];
        yield 'an answer to a question' => ['submitInput', ['conversationUid' => 1, 'answer' => 'Ja', 'turnDigest' => 'digest'], 'awaiting_input'];
        yield 'an approval decision' => ['decideApproval', ['conversationUid' => 1, 'approve' => true, 'turnDigest' => 'digest'], 'awaiting_approval'];
        yield 'a resume' => ['resumeConversation', ['conversationUid' => 1], 'failed'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('continuations')]
    public function aGuidedProcessOnADeletedPageCannotBeContinued(string $action, array $body, string $status): void
    {
        $this->aboutPage(1, 10);
        $this->deletePage(10);
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrmcpagent_conversation')
            ->update('tx_nrmcpagent_conversation', ['status' => $status], ['uid' => 1]);

        $response = $this->get(ChatApiController::class)->{$action}($this->post($body));

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('has been deleted', json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)['error']);
        $row = $this->row(1);
        self::assertSame(['message_count' => 0, 'status' => $status], ['message_count' => (int) ($row['message_count'] ?? -1), 'status' => $row['status'] ?? '']);
    }
}
