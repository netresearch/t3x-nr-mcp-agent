<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Controller;

use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
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
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A question a run asks, through the real nr-llm run store and the real
 * conversation table (ADR-018).
 *
 * The unit tests stub nr-llm. This one seeds an actual run suspended
 * WAITING_FOR_INPUT, so what is proven is that nr-llm's own view factory and
 * digest reach the reply buttons, and that the answer lands in the
 * conversation row — the new column included — for the worker to pick up.
 */
final class ChatInputRoundTripTest extends FunctionalTestCase
{
    private const RUN = '6f1c4a52-9b2e-4c7d-8e1f-0a3b5c7d9e21';

    private const ACCEPT = 'Meta Description';

    /** @var array<string, mixed> */
    private const SCHEMA = [
        'type' => 'object',
        'title' => 'Mit welchem Punkt soll ich beginnen?',
        'properties' => [
            'start' => ['oneOf' => [
                ['const' => 'meta', 'title' => self::ACCEPT],
                ['const' => 'headings', 'title' => 'Überschriften'],
                ['const' => 'images', 'title' => 'Alternativtexte'],
            ]],
            'comment' => ['type' => 'string'],
        ],
    ];

    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    private ConversationRepository $repository;

    private ChatApiController $subject;

    private SuspendedRunState $state;

    private int $conversationUid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);

        $this->repository = $this->get(ConversationRepository::class);

        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getMaxMessageLength')->willReturn(10000);

        $this->subject = new ChatApiController(
            $this->repository,
            $this->createMock(ChatProcessorInterface::class),
            $config,
            $this->createMock(ChatCapabilitiesInterface::class),
            $this->get(ChatApprovalInterface::class),
            $this->get(ResourceFactory::class),
            $this->get(StorageRepository::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            GeneralUtility::makeInstance(UriBuilder::class),
        );

        $this->state = new SuspendedRunState(
            messages: [['role' => 'user', 'content' => 'Prüfe die Seite']],
            pendingCalls: [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'ask_user', 'arguments' => []]]],
            iterations: 1,
            promptTokens: 10,
            completionTokens: 5,
            inputToolName: 'ask_user',
            inputSchema: self::SCHEMA,
        );

        $runs = $this->get(AgentRunRepositoryInterface::class);
        $runUid = $runs->startRun(self::RUN, 1, 'demo', 1);
        self::assertTrue($runs->suspendRunForInput($runUid, json_encode($this->state->toArray(), JSON_THROW_ON_ERROR)));

        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Prüfe die Seite');
        $conversation->appendMessage(MessageRole::Assistant, self::SCHEMA['title']);
        $conversation->setStatus(ConversationStatus::AwaitingInput);
        $conversation->setApprovalRunUuid(self::RUN);
        $this->conversationUid = $this->repository->add($conversation);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): ServerRequest
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode(['conversationUid' => $this->conversationUid, ...$body], JSON_THROW_ON_ERROR));
        $stream->rewind();

        return (new ServerRequest('/', 'POST'))->withBody($stream);
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingInput(): array
    {
        $response = $this->subject->getMessages((new ServerRequest())->withQueryParams(['conversationUid' => $this->conversationUid]));
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['pendingInput'] ?? null, 'the poll must carry the question');

        return $body['pendingInput'];
    }

    #[Test]
    public function thePollCarriesTheQuestionWithNrLlmsOwnDigest(): void
    {
        $pending = $this->pendingInput();

        self::assertSame((new PendingTurnDigest())->forInputState($this->state), $pending['turnDigest']);
        self::assertSame('choice', $pending['kind']);
        self::assertSame([self::ACCEPT, 'Überschriften', 'Alternativtexte'], array_column($pending['options'], 'label'));
        self::assertTrue($pending['freeText']);
    }

    #[Test]
    public function anAnswerIsRecordedOnTheRowForTheWorker(): void
    {
        $digest = $this->pendingInput()['turnDigest'];

        $response = $this->subject->submitInput($this->post(['turnDigest' => $digest, 'choice' => 'meta']));

        self::assertSame(202, $response->getStatusCode());
        $stored = $this->repository->findByUid($this->conversationUid);
        self::assertNotNull($stored);
        self::assertSame(ConversationStatus::Processing, $stored->getStatus());
        self::assertTrue($stored->hasPendingInputSubmission());
        self::assertSame(['start' => 'meta'], $stored->getPendingInputData());
        self::assertSame($digest, $stored->getApprovalTurnDigest());
        $messages = $stored->getDecodedMessages();
        self::assertSame(['user', self::ACCEPT], [$messages[2]['role'] ?? null, $messages[2]['content'] ?? null]);
    }

    #[Test]
    public function anAnswerThatWasNotOfferedLeavesTheRowAlone(): void
    {
        $digest = $this->pendingInput()['turnDigest'];

        $response = $this->subject->submitInput($this->post(['turnDigest' => $digest, 'choice' => 'publish everything']));

        self::assertSame(400, $response->getStatusCode());
        $stored = $this->repository->findByUid($this->conversationUid);
        self::assertNotNull($stored);
        self::assertSame(ConversationStatus::AwaitingInput, $stored->getStatus());
        self::assertFalse($stored->hasPendingInputSubmission());
        self::assertSame(2, $stored->getMessageCount());
    }
}
