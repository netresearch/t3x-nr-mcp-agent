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
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The limit of running conversations per user holds when two turns of the
 * same user are claimed at once (#220): the count and the claim are one step.
 */
final class ActiveConversationLimitTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    private ConversationRepository $repository;

    private bool $competitorClaimed = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/active_conversation_limit.csv');
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);

        $this->repository = $this->get(ConversationRepository::class);
    }

    /**
     * A second request of the same user claims conversation 12 after this
     * request counted the user's running conversations and before it claims
     * conversation 11: the window two simultaneous requests share.
     */
    #[Test]
    public function aMessageWhoseCountAnotherClaimOvertookIsRefused(): void
    {
        $response = $this->sendMessage($this->subject($this->racedRepository(), maxActive: 1), 11);

        self::assertTrue($this->competitorClaimed, 'the competing claim did not run');
        self::assertSame(429, $response->getStatusCode());
        self::assertSame(1, $this->repository->countActiveByBeUser(1));
        $refused = $this->repository->findByUid(11);
        self::assertNotNull($refused);
        self::assertSame(ConversationStatus::Idle, $refused->getStatus());
        self::assertSame(0, $refused->getMessageCount());
    }

    /** Below the limit, a claim that counts the competitor still goes through. */
    #[Test]
    public function aMessageBelowTheLimitGoesThroughBesideTheCompetitor(): void
    {
        $response = $this->sendMessage($this->subject($this->racedRepository(), maxActive: 2), 11);

        self::assertTrue($this->competitorClaimed, 'the competing claim did not run');
        self::assertSame(202, $response->getStatusCode());
        self::assertSame(2, $this->repository->countActiveByBeUser(1));
    }

    /** Retry of a failed turn starts a run, so the limit applies to it. */
    #[Test]
    public function aRetryOfAFailedTurnAtTheLimitIsRefused(): void
    {
        $this->setStatus(12, ConversationStatus::Processing);

        $response = $this->subject($this->repository, maxActive: 1)->resumeConversation($this->request(['conversationUid' => 13]));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame(ConversationStatus::Failed, $this->repository->findByUid(13)?->getStatus());
        self::assertSame(1, $this->repository->countActiveByBeUser(1));
    }

    /**
     * Retry of a turn that is still Processing (its worker never started)
     * starts no second run, so the limit does not apply to it: not even when
     * other conversations of the user already fill the limit.
     */
    #[Test]
    public function aRetryOfAStuckTurnIsNotRefusedByTheLimit(): void
    {
        $this->setStatus(11, ConversationStatus::Processing);
        $this->setStatus(12, ConversationStatus::Processing);

        $response = $this->subject($this->repository, maxActive: 1)->resumeConversation($this->request(['conversationUid' => 12]));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(2, $this->repository->countActiveByBeUser(1));
    }

    /**
     * The repository the controller uses, with a second request of the same
     * user that claims conversation 12 right after the controller has counted.
     */
    private function racedRepository(): CountThenCompeteConversationRepository
    {
        $competitor = function (): void {
            if ($this->competitorClaimed) {
                return;
            }

            $other = $this->repository->findByUid(12);
            self::assertNotNull($other);
            $other->setStatus(ConversationStatus::Processing);
            $this->competitorClaimed = $this->repository->updateIf($other, ConversationStatus::Idle);
        };

        return new CountThenCompeteConversationRepository($this->get(ConnectionPool::class), $competitor);
    }

    private function subject(ConversationRepository $repository, int $maxActive): ChatApiController
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getMaxMessageLength')->willReturn(10000);
        $config->method('getMaxActiveConversationsPerUser')->willReturn($maxActive);

        return new ChatApiController(
            $repository,
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
    }

    private function sendMessage(ChatApiController $subject, int $conversationUid): ResponseInterface
    {
        return $subject->sendMessage($this->request(['conversationUid' => $conversationUid, 'content' => 'Hello']));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body): ServerRequest
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode($body, JSON_THROW_ON_ERROR));
        $stream->rewind();

        return (new ServerRequest('/', 'POST'))->withBody($stream);
    }

    private function setStatus(int $uid, ConversationStatus $status): void
    {
        $conversation = $this->repository->findByUid($uid);
        self::assertNotNull($conversation);
        $conversation->setStatus($status);
        $this->repository->update($conversation);
        // Written earlier than the request under test, as a real row is: on
        // MySQL and MariaDB a conditional UPDATE that changes no value, the
        // tstamp included, reports no affected row.
        $this->getConnectionPool()->getConnectionForTable('tx_nrmcpagent_conversation')
            ->update('tx_nrmcpagent_conversation', ['tstamp' => 1710000000], ['uid' => $uid]);
    }
}
