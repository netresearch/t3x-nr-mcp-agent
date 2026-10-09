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
use Netresearch\NrMcpAgent\Enum\ProposalOutcome;
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Netresearch\NrMcpAgent\Service\ProcessPinReleaseInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use stdClass;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * The × of a guided process (ADR-023): ends the tour, withdraws a waiting
 * proposal through the guarded cancel, and keeps what was applied.
 */
final class ChatApiControllerEndTourTest extends TestCase
{
    private ConversationRepository&MockObject $repository;
    private ChatProcessorInterface&MockObject $processor;
    private ChatApprovalInterface&MockObject $chatApproval;
    private ProcessPinReleaseInterface&MockObject $pinRelease;

    /** @var list<array{ConversationStatus, ConversationStatus, string, string}> expected status, written status, skill, run */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(ConversationRepository::class);
        $this->repository->method('updateIf')->willReturnCallback(function (Conversation $c, ConversationStatus $expected): bool {
            $this->writes[] = [$expected, $c->getStatus(), $c->getSkillIdentifier(), $c->getApprovalRunUuid()];

            return true;
        });
        $this->processor = $this->createMock(ChatProcessorInterface::class);
        $this->chatApproval = $this->createMock(ChatApprovalInterface::class);
        $this->pinRelease = $this->createMock(ProcessPinReleaseInterface::class);

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->user = ['uid' => 1, 'usergroup' => '1'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{ConversationStatus}>
     */
    public static function waitingStatuses(): iterable
    {
        yield 'waiting for approval' => [ConversationStatus::AwaitingApproval];
        yield 'waiting for an answer' => [ConversationStatus::AwaitingInput];
    }

    #[Test]
    #[DataProvider('waitingStatuses')]
    public function endingATourWithdrawsTheWaitingRunAndClearsTheSkill(ConversationStatus $status): void
    {
        $conversation = $this->tourConversation($status);
        $this->chatApproval->expects(self::once())->method('releasePendingRun')
            ->with(self::callback(static fn(Conversation $before): bool => $before->getApprovalRunUuid() === 'run-1'
                && $before->getSkillIdentifier() === '3:seo/check'))
            ->willReturn(true);
        $this->pinRelease->expects(self::once())->method('release')
            ->with(self::callback(static fn(Conversation $before): bool => $before->getSkillIdentifier() === '3:seo/check'));
        $this->processor->expects(self::never())->method('dispatch');

        $response = $this->endTour($conversation);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'idle', 'skill' => null], json_decode((string) $response->getBody(), true));
        self::assertSame([[$status, ConversationStatus::Idle, '', '']], $this->writes);
        self::assertSame(0, $conversation->getSkillUid());
        self::assertSame(['progress' => null, 'highlight' => null], $conversation->getGuidedState());
        self::assertSame('', $conversation->getApprovalDecision());
        self::assertNull($conversation->getApprovalCard());
    }

    /**
     * What the tour applied stays: the outcomes the chat shows are not reset,
     * and nothing is written beside the conversation row.
     */
    #[Test]
    public function endingATourKeepsTheAppliedOutcomes(): void
    {
        $conversation = $this->tourConversation(ConversationStatus::AwaitingApproval);
        $card = ['tool' => 'content_update', 'table' => 'tt_content', 'uid' => 7, 'fields' => ['header']];
        $conversation->appendCardOutcome(ProposalOutcome::Applied, $card);
        $this->chatApproval->method('releasePendingRun')->willReturn(true);
        $this->chatApproval->expects(self::never())->method('recordDecision');
        $this->repository->expects(self::never())->method('update');

        self::assertSame(200, $this->endTour($conversation)->getStatusCode());

        self::assertSame([['outcome' => 'applied', 'after' => $conversation->getMessageCount()] + $card], $conversation->getCardOutcomes());
    }

    #[Test]
    public function anIdleTourEndsWithoutACancel(): void
    {
        $conversation = $this->tourConversation(ConversationStatus::Idle);
        $conversation->setApprovalRunUuid('');
        $this->chatApproval->expects(self::never())->method('releasePendingRun');
        $this->pinRelease->expects(self::once())->method('release');

        self::assertSame(200, $this->endTour($conversation)->getStatusCode());
        self::assertSame([[ConversationStatus::Idle, ConversationStatus::Idle, '', '']], $this->writes);
    }

    /**
     * @return iterable<string, array{ConversationStatus}>
     */
    public static function busyStatuses(): iterable
    {
        yield 'processing' => [ConversationStatus::Processing];
        yield 'locked' => [ConversationStatus::Locked];
        yield 'tool loop' => [ConversationStatus::ToolLoop];
    }

    #[Test]
    #[DataProvider('busyStatuses')]
    public function aBusyTourCannotBeEnded(ConversationStatus $status): void
    {
        $conversation = $this->tourConversation($status);
        $this->chatApproval->expects(self::never())->method('releasePendingRun');
        $this->pinRelease->expects(self::never())->method('release');

        self::assertSame(409, $this->endTour($conversation)->getStatusCode());
        self::assertSame([], $this->writes);
        self::assertSame('3:seo/check', $conversation->getSkillIdentifier());
    }

    /**
     * The guarded cancel lost: the run is being carried on, or a decision won
     * the race. The row goes back to the tour as it was, and the pin stays.
     */
    #[Test]
    #[DataProvider('waitingStatuses')]
    public function aLostCancelPutsTheTourBack(ConversationStatus $status): void
    {
        $conversation = $this->tourConversation($status);
        $this->chatApproval->method('releasePendingRun')->willReturn(false);
        $this->pinRelease->expects(self::never())->method('release');

        self::assertSame(409, $this->endTour($conversation)->getStatusCode());
        self::assertSame([
            [$status, ConversationStatus::Idle, '', ''],
            [ConversationStatus::Idle, $status, '3:seo/check', 'run-1'],
        ], $this->writes);
    }

    #[Test]
    public function aRowChangedMeanwhileIsNotTouched(): void
    {
        $conversation = $this->tourConversation(ConversationStatus::AwaitingApproval);
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $repository->expects(self::once())->method('updateIf')->willReturn(false);
        $this->chatApproval->expects(self::never())->method('releasePendingRun');
        $this->pinRelease->expects(self::never())->method('release');

        self::assertSame(409, $this->controller($repository)->endTour($this->request())->getStatusCode());
    }

    #[Test]
    public function aConversationWithoutATourIsLeftAlone(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid('run-1');
        $this->chatApproval->expects(self::never())->method('releasePendingRun');
        $this->pinRelease->expects(self::never())->method('release');

        $response = $this->endTour($conversation);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->writes);
        self::assertSame('run-1', $conversation->getApprovalRunUuid());
    }

    /**
     * nr-llm has no call to release the pin of an aborted process yet; until
     * then nothing implements the seam and the tour still ends.
     */
    #[Test]
    public function withoutAPinReleaseTheTourStillEnds(): void
    {
        $conversation = $this->tourConversation(ConversationStatus::AwaitingInput);
        $this->chatApproval->method('releasePendingRun')->willReturn(true);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $subject = $this->controller($this->repository, withPinRelease: false);

        self::assertSame(200, $subject->endTour($this->request())->getStatusCode());
        self::assertSame('', $conversation->getSkillIdentifier());
    }

    private function tourConversation(ConversationStatus $status): Conversation
    {
        $conversation = new Conversation();
        $conversation->setSkillIdentifier('3:seo/check', 12);
        $conversation->setGuidedState(['label' => 'Meta-Beschreibung', 'current' => 2, 'total' => 5, 'completed' => false], ['table' => 'tt_content', 'uid' => 7]);
        $conversation->setStatus($status);
        $conversation->setApprovalRunUuid('run-1');
        $conversation->recordApprovalDecision(true, 'digest');
        $conversation->setApprovalCard(['tool' => 'content_update', 'table' => 'tt_content', 'uid' => 7, 'fields' => ['header']]);

        return $conversation;
    }

    private function endTour(Conversation $conversation): \Psr\Http\Message\ResponseInterface
    {
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        return $this->controller($this->repository)->endTour($this->request());
    }

    private function controller(ConversationRepository $repository, bool $withPinRelease = true): ChatApiController
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);

        return new ChatApiController(
            $repository,
            $this->processor,
            $config,
            $this->createMock(ChatCapabilitiesInterface::class),
            $this->chatApproval,
            $this->createMock(ResourceFactory::class),
            $this->createMock(StorageRepository::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->createMock(UriBuilder::class),
            pinRelease: $withPinRelease ? $this->pinRelease : null,
        );
    }

    private function request(): ServerRequestInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('{"conversationUid": 1}');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getBody')->willReturn($stream);
        $request->method('getQueryParams')->willReturn([]);

        return $request;
    }
}
