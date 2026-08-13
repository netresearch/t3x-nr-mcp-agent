<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Controller;

use Netresearch\NrLlm\Controller\Backend\AgentRunController;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Controller\ChatApiController;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;
use stdClass;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * The approval link points at nr-llm's read-only agent run detail, which
 * arrived in 0.29 (ADR-153). This extension also supports 0.28, where the
 * action does not exist.
 *
 * That difference cannot be caught: a backend module route resolves whether or
 * not the action behind it is registered, so the URI builds either way and the
 * failure appears only when someone clicks — as an exception page, which is
 * the impression the notice exists to remove. So the controller checks, and
 * this asserts the check answers for the nr-llm that is actually installed
 * rather than for the one the constraint permits.
 */
#[CoversNothing]
final class ApprovalLinkTargetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->user = ['uid' => 1, 'usergroup' => '1,2'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    /**
     * One assertion for both worlds: with the detail present the API hands out
     * the link, without it the notice carries none. A test that only knew one
     * of the two would go green on whichever nr-llm the runner resolved.
     */
    #[Test]
    public function theLinkIsOfferedExactlyWhenNrLlmHasTheRunDetail(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid('run-uuid-1234');
        $conversation->setErrorMessage('This step writes data.');

        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('findPollStatus')->willReturn([
            'status' => 'awaiting_approval',
            'message_count' => 5,
            'error_message' => 'This step writes data.',
            'approval_run_uuid' => 'run-uuid-1234',
            'tstamp' => 1710000000,
        ]);
        // A parked conversation no longer answers from the poll fast path — it
        // falls through so the approval card can be built, and the link comes
        // from the full response.
        $repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturn('/typo3/module/web/nrllm-aitasks?runUuid=run-uuid-1234');

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);

        $subject = new ChatApiController(
            $repository,
            $this->createMock(ChatProcessorInterface::class),
            $config,
            $this->createMock(ChatCapabilitiesInterface::class),
            $this->createMock(ChatApprovalInterface::class),
            $this->createMock(ResourceFactory::class),
            $this->createMock(StorageRepository::class),
            new DocumentExtractorRegistry([]),
            $uriBuilder,
        );

        $request = (new ServerRequest('/', 'GET'))
            ->withQueryParams(['conversationUid' => '1', 'after' => '5']);
        $data = json_decode((string) $subject->getMessages($request)->getBody(), true);

        self::assertIsArray($data);
        self::assertArrayHasKey('approvalUrl', $data);

        if (method_exists(AgentRunController::class, 'showAction')) {
            self::assertSame(
                '/typo3/module/web/nrllm-aitasks?runUuid=run-uuid-1234',
                $data['approvalUrl'],
                'nr-llm has the run detail, so the pending approval must be reachable from the chat.',
            );

            $parameters = array_map(
                static fn(ReflectionParameter $p): string => $p->getName(),
                (new ReflectionMethod(AgentRunController::class, 'showAction'))->getParameters(),
            );
            self::assertContains('runUuid', $parameters, 'showAction cannot name a run any more.');

            return;
        }

        self::assertSame(
            '',
            $data['approvalUrl'],
            'nr-llm predates the run detail, so a link would resolve to an action that does not '
            . 'exist and answer with an exception page.',
        );
    }
}
