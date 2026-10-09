<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Controller;

use Netresearch\NrLlm\Service\Agent\Inbox\PendingCallView;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
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
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Who may decide an approval in the chat (ADR-021), with TYPO3's real group
 * permissions: an editor whose group holds "Approve own changes in the chat"
 * decides in their own conversation; an editor without it does not; and a
 * configuration that requires a second approver leaves only the denial.
 *
 * The run itself is stubbed — what nr-llm does with the decision is nr-llm's
 * test suite's concern. What is real here is `check('custom_options', …)`.
 */
final class ChatApprovalPermissionTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    private ChatApprovalInterface&MockObject $approval;

    private bool $fourEyes = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups_approve_own.csv');
        $this->approval = $this->createMock(ChatApprovalInterface::class);
        $this->approval->method('requiresSecondApprover')->willReturnCallback(fn(): bool => $this->fourEyes);
    }

    private function subject(): ChatApiController
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);

        return new ChatApiController(
            $this->get(ConversationRepository::class),
            $this->createMock(ChatProcessorInterface::class),
            $config,
            $this->createMock(ChatCapabilitiesInterface::class),
            $this->approval,
            $this->get(ResourceFactory::class),
            $this->get(StorageRepository::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            GeneralUtility::makeInstance(UriBuilder::class),
        );
    }

    private function waiting(int $beUser, bool $previewed = true): int
    {
        $conversation = new Conversation();
        $conversation->setBeUser($beUser);
        $conversation->appendMessage(MessageRole::User, 'Setze die Meta Description');
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid('run-uuid-1');
        $this->approval->method('pendingApproval')->willReturn(new WaitingRunView(
            runUuid: 'run-uuid-1',
            mode: WaitingRunView::MODE_APPROVAL,
            createdAt: 1710000000,
            configLabel: 'Demo',
            turnDigest: 'digest-1',
            pendingCalls: [new PendingCallView(
                'update_page_metadata',
                '{"uid":10}',
                true,
                $previewed ? ['Seite: „Home“', 'Meta Description: (leer) → „Neu“'] : ['Keine Berechtigung für diese Seite'],
                !$previewed,
            )],
        ));

        return $this->get(ConversationRepository::class)->add($conversation);
    }

    /**
     * @return array<string, mixed>
     */
    private function messages(int $uid): array
    {
        $body = json_decode((string) $this->subject()->getMessages((new ServerRequest())->withQueryParams(['conversationUid' => $uid]))->getBody(), true);
        self::assertIsArray($body);

        return $body;
    }

    private function decide(int $uid, bool $approve): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode(['conversationUid' => $uid, 'approve' => $approve, 'turnDigest' => 'digest-1'], JSON_THROW_ON_ERROR));
        $stream->rewind();

        return $this->subject()->decideApproval((new ServerRequest('/', 'POST'))->withBody($stream));
    }

    #[Test]
    public function anEditorWithThePermissionDecidesInTheirOwnConversation(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(3);
        $uid = $this->waiting(3);
        $this->approval->expects(self::once())->method('recordDecision')->with(self::anything(), true, 'digest-1')->willReturn(true);

        $body = $this->messages($uid);
        self::assertTrue($body['mayDecideApproval']);
        self::assertIsArray($body['pendingApproval']);
        self::assertSame('', $body['pendingApproval']['approveBlocked']);

        self::assertSame(202, $this->decide($uid, true)->getStatusCode());
    }

    #[Test]
    public function anEditorWithoutThePermissionGetsNoCardAndIsRefused(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(4);
        $uid = $this->waiting(4);
        $this->approval->expects(self::never())->method('recordDecision');

        $body = $this->messages($uid);
        self::assertFalse($body['mayDecideApproval']);
        self::assertNull($body['pendingApproval']);

        self::assertSame(403, $this->decide($uid, true)->getStatusCode());
        self::assertSame(403, $this->decide($uid, false)->getStatusCode());
    }

    /**
     * A failed preview is the stand-in for missing record permissions: the
     * tool computed it with this user's rights. Approving is refused, on the
     * card and again on the server; denying stays possible.
     */
    #[Test]
    public function aChangeWithoutAPreviewCanOnlyBeDeniedByThePermission(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(3);
        $uid = $this->waiting(3, previewed: false);
        $this->approval->expects(self::once())->method('recordDecision')->with(self::anything(), false)->willReturn(true);

        $body = $this->messages($uid);
        self::assertTrue($body['mayDecideApproval']);
        self::assertSame('preview', $body['pendingApproval']['approveBlocked']);

        self::assertSame(403, $this->decide($uid, true)->getStatusCode());
        self::assertSame(202, $this->decide($uid, false)->getStatusCode());
    }

    /**
     * The module rule is unchanged: an administrator decides any approval,
     * with or without a preview.
     */
    #[Test]
    public function anAdministratorStillDecidesWithoutAPreview(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $uid = $this->waiting(1, previewed: false);
        $this->approval->method('recordDecision')->willReturn(true);

        self::assertSame('', $this->messages($uid)['pendingApproval']['approveBlocked']);
        self::assertSame(202, $this->decide($uid, true)->getStatusCode());
    }

    /**
     * Four-eyes (nr-llm ADR-172): in the chat the reader started the run, so
     * nobody approves it here — not the editor, not an administrator. The
     * denial stays, as nr-llm allows it.
     */
    #[Test]
    public function fourEyesLeavesOnlyTheDenialForEveryone(): void
    {
        $this->fourEyes = true;
        foreach ([3, 1] as $user) {
            $GLOBALS['BE_USER'] = $this->setUpBackendUser($user);
            $uid = $this->waiting($user);

            self::assertSame('secondApprover', $this->messages($uid)['pendingApproval']['approveBlocked']);
            self::assertSame(409, $this->decide($uid, true)->getStatusCode());
        }

        $this->approval->method('recordDecision')->willReturn(true);
        self::assertSame(202, $this->decide($uid, false)->getStatusCode());
    }

    /** The permission covers the holder's own conversations, never another user's. */
    #[Test]
    public function thePermissionDoesNotReachAnotherUsersConversation(): void
    {
        $uid = $this->waiting(4);
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(3);

        self::assertSame(404, $this->decide($uid, true)->getStatusCode());
    }
}
