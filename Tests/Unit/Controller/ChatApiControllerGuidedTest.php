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
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * The poll hands the browser the conversation's guided state (ADR-020): the
 * progress for the header and the element for the page module.
 */
#[CoversClass(ChatApiController::class)]
final class ChatApiControllerGuidedTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function messagesOf(Conversation $conversation): array
    {
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('findOneByUidAndBeUser')->willReturn($conversation);
        $config = $this->createMock(ExtensionConfiguration::class);
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
            new UploadMimeTypeMap(),
            $this->createMock(UriBuilder::class),
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

    #[Test]
    public function thePollCarriesTheGuidedState(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(2);
        $conversation->setGuidedState(['label' => 'Über uns · Deutsch', 'current' => 2, 'total' => 5, 'completed' => false], ['table' => 'tt_content', 'uid' => 100]);

        self::assertSame(
            ['progress' => ['label' => 'Über uns · Deutsch', 'current' => 2, 'total' => 5, 'completed' => false], 'highlight' => ['table' => 'tt_content', 'uid' => 100]],
            $this->messagesOf($conversation)['guided'] ?? null,
        );
    }

    #[Test]
    public function withoutAGuidedProcessThereIsNothingToShow(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(2);

        self::assertSame(['progress' => null, 'highlight' => null], $this->messagesOf($conversation)['guided'] ?? 'missing');
    }
}
