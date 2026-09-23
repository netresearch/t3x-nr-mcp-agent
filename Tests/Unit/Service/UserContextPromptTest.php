<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Localization\Locales;

/**
 * The user's part of the system prompt: answer language and backend module
 * (NEXT-172). The page part needs the database and is covered by the
 * functional UserContextPromptTest.
 */
final class UserContextPromptTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    private function actingAs(int $uid, string $lang): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->user = ['uid' => $uid, 'lang' => $lang];
        $GLOBALS['BE_USER'] = $user;
    }

    private function conversationOf(int $beUser, string $module = ''): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser($beUser);
        $conversation->setViewContext(0, $module);

        return $conversation;
    }

    private function subject(?ModuleProvider $moduleProvider = null): UserContextPrompt
    {
        $languageService = $this->createStub(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn(string $key): string => $key === 'LLL:EXT:backend/module.xlf:layout' ? 'Seite' : '',
        );
        $factory = $this->createStub(LanguageServiceFactory::class);
        $factory->method('createFromUserPreferences')->willReturn($languageService);

        return new UserContextPrompt(new Locales(), $moduleProvider ?? $this->createStub(ModuleProvider::class), $factory);
    }

    #[Test]
    public function theAnswerLanguageIsTheBackendLanguage(): void
    {
        $this->actingAs(1, 'de');

        $prompt = $this->subject()->build($this->conversationOf(1));

        self::assertStringContainsString('backend is set to German (de)', $prompt);
        self::assertStringContainsString('Write your replies in German', $prompt);
        self::assertStringContainsString('explicitly asks for another language', $prompt);
    }

    #[Test]
    public function anUnsetLanguageIsEnglish(): void
    {
        $this->actingAs(1, '');

        self::assertStringContainsString('(en)', $this->subject()->build($this->conversationOf(1)));
    }

    #[Test]
    public function nothingIsSaidOnBehalfOfAnotherUser(): void
    {
        $this->actingAs(2, 'de');

        self::assertSame('', $this->subject()->build($this->conversationOf(1)));
    }

    #[Test]
    public function nothingIsSaidWithoutABackendUser(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertSame('', $this->subject()->build($this->conversationOf(1)));
    }

    #[Test]
    public function anAccessibleModuleIsNamedInTheUsersLanguage(): void
    {
        $this->actingAs(1, 'de');
        $module = $this->createStub(ModuleInterface::class);
        $module->method('getTitle')->willReturn('LLL:EXT:backend/module.xlf:layout');
        $provider = $this->createMock(ModuleProvider::class);
        $provider->expects(self::once())->method('getModule')
            ->with('web_layout', self::isInstanceOf(BackendUserAuthentication::class))
            ->willReturn($module);

        $prompt = $this->subject($provider)->build($this->conversationOf(1, 'web_layout'));

        self::assertStringContainsString('- Open backend module: "Seite" (identifier web_layout)', $prompt);
        self::assertStringNotContainsString('Selected page', $prompt);
    }

    #[Test]
    public function aModuleTheUserMayNotOpenIsLeftOut(): void
    {
        $this->actingAs(1, 'de');
        $provider = $this->createStub(ModuleProvider::class);
        $provider->method('getModule')->willReturn(null);

        $prompt = $this->subject($provider)->build($this->conversationOf(1, 'tools_toolsmaintenance'));

        self::assertStringNotContainsString('tools_toolsmaintenance', $prompt);
        self::assertStringNotContainsString('Where the user is', $prompt);
    }
}
