<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Controller;

use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Controller\ChatApiController;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Netresearch\NrMcpAgent\Service\SkillCatalogueInterface;
use Netresearch\NrMcpAgent\Service\SkillInvocation;
use Netresearch\NrMcpAgent\Service\SkillInvocationInterface;
use Netresearch\NrMcpAgent\Service\TourContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * What the poll tells the chat about starting a guided process anew
 * (ADR-023): nothing unless nr-llm can start a run with an invocation, then
 * the skill and whether the process still needs a page; and where "Fertig"
 * leads once the process is finished.
 */
#[CoversClass(ChatApiController::class)]
final class ChatApiControllerTourStartTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed>|null $catalogueEntry what the catalogue knows of the skill
     *
     * @return array<string, mixed>
     */
    private function messagesOf(Conversation $conversation, bool $invocation, ?array $catalogueEntry = ['uid' => 4, 'name' => 'SEO', 'process' => true], ?UriBuilder $uriBuilder = null): array
    {
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $skills = $this->createMock(SkillCatalogueInterface::class);
        $skills->method('find')->willReturn($catalogueEntry);
        $tour = $this->createMock(TourContext::class);
        $tour->method('of')->willReturn(null);

        $subject = new ChatApiController(
            $repository,
            $this->createMock(ChatProcessorInterface::class),
            $config,
            $this->createMock(ChatCapabilitiesInterface::class),
            $this->createMock(ChatApprovalInterface::class),
            $this->createMock(ResourceFactory::class),
            $this->createMock(StorageRepository::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $uriBuilder ?? $this->createMock(UriBuilder::class),
            skills: $skills,
            tourContext: $tour,
            skillInvocation: $invocation ? new class implements SkillInvocationInterface {
                public function withInvocation(AgentRunRequest $request, SkillInvocation $invocation): ?AgentRunRequest
                {
                    return $request;
                }
            } : null,
        );

        $user = $this->createStub(BackendUserAuthentication::class);
        $user->user = ['uid' => 2, 'usergroup' => '', 'admin' => 0];
        $GLOBALS['BE_USER'] = $user;
        $GLOBALS['LANG'] = $this->createStub(LanguageService::class);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['conversationUid' => '1']);

        $data = json_decode((string) $subject->getMessages($request)->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }

    private static function conversation(int $pageUid, string $skill = '3:seo/page-tour'): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(2);
        $conversation->setSkillIdentifier($skill, 4);
        if ($pageUid > 0) {
            $conversation->setViewContext($pageUid, 'web_layout', 0);
        }

        return $conversation;
    }

    #[Test]
    public function aProcessWithoutAPageAsksForOne(): void
    {
        self::assertSame(['skill' => '3:seo/page-tour', 'choosePage' => true], $this->messagesOf(self::conversation(0), true)['tourStart'] ?? 'missing');
    }

    #[Test]
    public function aProcessOnAPageAsksForNone(): void
    {
        self::assertSame(['skill' => '3:seo/page-tour', 'choosePage' => false], $this->messagesOf(self::conversation(20), true)['tourStart'] ?? 'missing');
    }

    /** A skill the catalogue does not know as a process or not counts as one, as elsewhere. */
    #[Test]
    public function aSkillTheCatalogueDoesNotMarkCountsAsAProcess(): void
    {
        self::assertSame(['skill' => '3:seo/page-tour', 'choosePage' => true], $this->messagesOf(self::conversation(0), true, ['uid' => 4, 'name' => 'SEO', 'process' => null])['tourStart'] ?? 'missing');
    }

    /**
     * @return iterable<string, array{Conversation, bool, array<string, mixed>|null}>
     */
    public static function noNewStart(): iterable
    {
        yield 'nr-llm cannot start a run with an invocation' => [self::conversation(0), false, ['uid' => 4, 'name' => 'SEO', 'process' => true]];
        yield 'a skill that is no process' => [self::conversation(0), true, ['uid' => 4, 'name' => 'SEO', 'process' => false]];
        yield 'no skill' => [self::conversation(0, ''), true, ['uid' => 4, 'name' => 'SEO', 'process' => true]];
    }

    /**
     * @param array<string, mixed>|null $entry
     */
    #[Test]
    #[DataProvider('noNewStart')]
    public function otherwiseNothingIsOffered(Conversation $conversation, bool $invocation, ?array $entry): void
    {
        $data = $this->messagesOf($conversation, $invocation, $entry);

        self::assertArrayHasKey('tourStart', $data);
        self::assertNull($data['tourStart']);
    }

    #[Test]
    public function aFinishedProcessLeadsToTheDashboard(): void
    {
        $conversation = self::conversation(20);
        $conversation->setGuidedState(['label' => 'SEO', 'current' => 5, 'total' => 5, 'completed' => true], null);
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->with('dashboard')->willReturn(new Uri('/typo3/module/dashboard'));

        self::assertSame('/typo3/module/dashboard', $this->messagesOf($conversation, false, uriBuilder: $uriBuilder)['dashboardUrl'] ?? 'missing');
    }

    #[Test]
    public function aRunningProcessHasNoWayOut(): void
    {
        $conversation = self::conversation(20);
        $conversation->setGuidedState(['label' => 'SEO', 'current' => 2, 'total' => 5, 'completed' => false], null);
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->expects(self::never())->method('buildUriFromRoute');

        self::assertSame('', $this->messagesOf($conversation, false, uriBuilder: $uriBuilder)['dashboardUrl'] ?? 'missing');
    }

    #[Test]
    public function withoutTheDashboardThereIsNoLink(): void
    {
        $conversation = self::conversation(20);
        $conversation->setGuidedState(['label' => 'SEO', 'current' => 5, 'total' => 5, 'completed' => true], null);
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willThrowException(new RouteNotFoundException('dashboard'));

        self::assertSame('', $this->messagesOf($conversation, false, uriBuilder: $uriBuilder)['dashboardUrl'] ?? 'missing');
    }
}
