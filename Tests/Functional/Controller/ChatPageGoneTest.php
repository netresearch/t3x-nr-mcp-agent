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
use Netresearch\NrMcpAgent\Domain\Repository\PageChoiceRepository;
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Netresearch\NrMcpAgent\Service\SkillCatalogueInterface;
use Netresearch\NrMcpAgent\Service\SkillInvocationInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
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
     * The controller with a catalogue that knows `seo-optimieren` as a process
     * skill and with nr-llm able to start one, as ChatConversationStartTest
     * builds it; the page lookup and the approvals come from the container.
     */
    private function withProcessSkill(): ChatApiController
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getMaxMessageLength')->willReturn(10000);
        $config->method('getMaxActiveConversationsPerUser')->willReturn(3);

        $entry  = ['identifier' => 'seo-optimieren', 'name' => 'seo-optimieren', 'description' => '', 'uid' => 9422, 'process' => true];
        $skills = $this->createMock(SkillCatalogueInterface::class);
        $skills->method('isAvailable')->willReturn(true);
        $skills->method('requiresSecondApprover')->willReturn(false);
        $skills->method('catalogue')->willReturn([$entry]);
        $skills->method('find')->willReturnCallback(static fn (string $identifier): ?array => $identifier === 'seo-optimieren' ? $entry : null);

        return new ChatApiController(
            $this->get(ConversationRepository::class),
            $this->createMock(ChatProcessorInterface::class),
            $config,
            $this->createMock(ChatCapabilitiesInterface::class),
            $this->get(ChatApprovalInterface::class),
            $this->get(ResourceFactory::class),
            $this->get(StorageRepository::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            GeneralUtility::makeInstance(UriBuilder::class),
            skills: $skills,
            skillInvocation: $this->createMock(SkillInvocationInterface::class),
            pages: $this->get(PageChoiceRepository::class),
        );
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
     * A process picked in a chat whose stored page has been deleted must not
     * bind itself to that page: it would be read-only at once. The page is
     * dropped, and the chat asks for one.
     *
     * @return iterable<string, array{bool}>
     */
    public static function chatsWhosePageWasDeleted(): iterable
    {
        yield 'after a tour on the page was ended' => [true];
        yield 'an ordinary chat that was last on the page' => [false];
    }

    #[Test]
    #[DataProvider('chatsWhosePageWasDeleted')]
    public function aProcessPickedAfterItsPageWasDeletedAsksForAPage(bool $tourFirst): void
    {
        $this->aboutPage(1, 10, $tourFirst ? 'seo-optimieren' : '');
        $this->deletePage(10);
        $controller = $this->withProcessSkill();
        if ($tourFirst) {
            self::assertSame(200, $controller->endTour($this->post(['conversationUid' => 1]))->getStatusCode());
        }

        $picked = $controller->updateSkill($this->post(['conversationUid' => 1, 'skill' => 'seo-optimieren']));

        self::assertSame(200, $picked->getStatusCode(), (string) $picked->getBody());
        $viewContext = json_decode((string) ($this->get(ConnectionPool::class)->getConnectionForTable('tx_nrmcpagent_conversation')
            ->select(['view_context'], 'tx_nrmcpagent_conversation', ['uid' => 1])->fetchOne() ?: '{}'), true);
        self::assertSame(0, $viewContext['pageId'] ?? -1);
        // Only the page is dropped; the module the chat was used in stays.
        self::assertSame('web_layout', $viewContext['module'] ?? '');
        self::assertSame('seo-optimieren', $this->row(1)['skill_identifier'] ?? '');
        self::assertFalse($this->messages(1)['pageGone']);
        // Refused for being read-only would be 409 before the content is read.
        self::assertSame(400, $controller->sendMessage($this->post(['conversationUid' => 1, 'content' => '']))->getStatusCode());
        // The chat asks for a page.
        self::assertSame(['skill' => 'seo-optimieren', 'choosePage' => true], json_decode((string) $picked->getBody(), true, 512, JSON_THROW_ON_ERROR)['tourStart']);
    }

    /** A page that is still there stays the page a process picked in the chat works on. */
    #[Test]
    public function aProcessPickedInAChatOnALivePageKeepsThatPage(): void
    {
        $this->aboutPage(1, 10, skill: '');

        $picked = $this->withProcessSkill()->updateSkill($this->post(['conversationUid' => 1, 'skill' => 'seo-optimieren']));

        self::assertSame(200, $picked->getStatusCode(), (string) $picked->getBody());
        self::assertSame(['pageId' => 10, 'module' => 'web_layout'], json_decode($this->viewContextOf(1), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['skill' => 'seo-optimieren', 'choosePage' => false], json_decode((string) $picked->getBody(), true, 512, JSON_THROW_ON_ERROR)['tourStart']);
    }

    /**
     * A running tour whose page was deleted cannot have its skill switched:
     * that would leave its waiting run behind. Ending the tour withdraws it.
     */
    #[Test]
    public function aRunningTourOnADeletedPageCannotSwitchItsSkill(): void
    {
        $this->aboutPage(1, 10);
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrmcpagent_conversation')
            ->update('tx_nrmcpagent_conversation', ['status' => 'awaiting_approval', 'approval_run_uuid' => 'e2e00000-0000-4000-8000-0000000000fe'], ['uid' => 1]);
        $this->deletePage(10);

        $picked = $this->withProcessSkill()->updateSkill($this->post(['conversationUid' => 1, 'skill' => 'seo-optimieren']));

        self::assertSame(409, $picked->getStatusCode(), (string) $picked->getBody());
        self::assertSame(
            ['status' => 'awaiting_approval', 'skill_identifier' => 'seo-optimieren', 'approval_run_uuid' => 'e2e00000-0000-4000-8000-0000000000fe'],
            array_intersect_key($this->row(1), ['status' => 1, 'skill_identifier' => 1, 'approval_run_uuid' => 1]),
        );
        self::assertSame(10, json_decode($this->viewContextOf(1), true, 512, JSON_THROW_ON_ERROR)['pageId'] ?? 0);
    }

    private function viewContextOf(int $conversation): string
    {
        $value = $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrmcpagent_conversation')
            ->select(['view_context'], 'tx_nrmcpagent_conversation', ['uid' => $conversation])->fetchOne();

        return is_string($value) ? $value : '';
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
