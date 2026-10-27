<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Controller;

use Netresearch\NrMcpAgent\Controller\ChatApiController;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Service\HighlightTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * What the chip row above the chat input is told by the server (NEXT-221),
 * against a real database: the title of the page the page module shows, and
 * the name of the element a guided step is about. Both only for what the
 * reader may see. The fixture's editor (3) may show page 20 ("Über uns") and
 * its subpage 21 and read content elements, but may not show page 30 nor
 * page 31, a subpage of 20; user 4 may show page 20 but may not read content
 * elements.
 */
final class ContextChipsTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/guided_tools.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/context_chips.csv');
    }

    private function asUser(int $uid): BackendUserAuthentication
    {
        $user               = $this->setUpBackendUser($uid);
        $GLOBALS['BE_USER'] = $user;
        $GLOBALS['LANG']    = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);

        return $user;
    }

    private function conversation(int $pageUid): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(3);
        $conversation->setSkillIdentifier('seo-check', 7);
        $conversation->setViewContext($pageUid, 'web_layout');

        return $conversation;
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function json(ChatApiController $controller, string $action, array $query): array
    {
        $data = json_decode((string) $controller->{$action}((new ServerRequest())->withQueryParams($query))->getBody(), true);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    #[Test]
    public function thePageTitleIsNamedForAPageTheReaderMayShow(): void
    {
        $this->asUser(3);

        self::assertSame(['pageId' => 20, 'title' => 'Über uns'], $this->json($this->get(ChatApiController::class), 'getPageTitle', ['pageId' => '20']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pagesNotNamed(): iterable
    {
        yield 'a page the reader may not show' => ['30'];
        yield 'a subpage of a mount the reader may not show' => ['31'];
        yield 'a page that does not exist' => ['999'];
        yield 'no page' => ['0'];
        yield 'not a number' => ['20abc'];
    }

    #[Test]
    #[DataProvider('pagesNotNamed')]
    public function noTitleForAnyOtherPage(string $pageId): void
    {
        $this->asUser(3);

        $data = $this->json($this->get(ChatApiController::class), 'getPageTitle', ['pageId' => $pageId]);

        self::assertArrayHasKey('title', $data);
        self::assertNull($data['title']);
    }

    #[Test]
    public function theMessagesNameTheElementOfTheStep(): void
    {
        $this->asUser(3);

        $guided = $this->json($this->get(ChatApiController::class), 'getMessages', ['conversationUid' => '11'])['guided'] ?? null;

        self::assertSame(['progress' => null, 'highlight' => ['table' => 'tt_content', 'uid' => 100, 'title' => 'Wer wir sind']], $guided);
    }

    /** The stored highlight stays; only its name is withheld. */
    #[Test]
    public function anElementOffTheConversationsPageIsNotNamed(): void
    {
        $this->asUser(3);

        $guided = $this->json($this->get(ChatApiController::class), 'getMessages', ['conversationUid' => '12'])['guided'] ?? null;

        self::assertSame(['progress' => null, 'highlight' => ['table' => 'tt_content', 'uid' => 105, 'title' => '']], $guided);
    }

    #[Test]
    public function anElementWithoutAHeaderIsNamedByItsType(): void
    {
        $user = $this->asUser(3);

        self::assertSame('Regular Text Element', (new HighlightTitle())->of($this->conversation(20), ['table' => 'tt_content', 'uid' => 103], $user));
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function elementsNotNamed(): iterable
    {
        yield 'on another page than the conversation, one the reader may show' => [3, 20, 105];
        yield 'on a page the reader may not show' => [3, 30, 101];
        yield 'for a reader who may not read content elements' => [4, 20, 100];
        yield 'deleted' => [3, 20, 104];
        yield 'missing' => [3, 20, 999];
    }

    #[Test]
    #[DataProvider('elementsNotNamed')]
    public function anElementTheReaderMayNotSeeHereIsNotNamed(int $user, int $page, int $uid): void
    {
        self::assertSame('', (new HighlightTitle())->of($this->conversation($page), ['table' => 'tt_content', 'uid' => $uid], $this->asUser($user)));
    }

    #[Test]
    public function aHighlightOfAnotherTableIsNotNamedAsTheContentElementWithItsUid(): void
    {
        // Content element 100 exists on the conversation's page and is named
        // as tt_content (below); a page with the same uid must not borrow it.
        self::assertSame('', (new HighlightTitle())->of($this->conversation(20), ['table' => 'pages', 'uid' => 100], $this->asUser(3)));
    }

    #[Test]
    public function aReaderWhoMaySeeItGetsTheHeader(): void
    {
        self::assertSame('Wer wir sind', (new HighlightTitle())->of($this->conversation(20), ['table' => 'tt_content', 'uid' => 100], $this->asUser(3)));
        // The element refused above as off the conversation's page is named on its own page.
        self::assertSame('Unser Team', (new HighlightTitle())->of($this->conversation(21), ['table' => 'tt_content', 'uid' => 105], $this->asUser(3)));
    }

    /** The TCA label of `pages.description` in the reader's language, as the page properties form shows it. */
    private function descriptionLabel(): string
    {
        $label = $GLOBALS['TCA']['pages']['columns']['description']['label'] ?? null;
        self::assertIsString($label);
        $text = $GLOBALS['LANG']->sL($label);
        self::assertIsString($text);
        self::assertNotSame('', $text);
        self::assertStringNotContainsString(':', $text, 'a resolved label, not its key');

        return $text;
    }

    /** A page property (NEXT-221) is named by its label, never by its column. */
    #[Test]
    public function theMessagesNameThePagePropertyOfTheStep(): void
    {
        $this->asUser(3);

        $guided = $this->json($this->get(ChatApiController::class), 'getMessages', ['conversationUid' => '13'])['guided'] ?? null;

        self::assertSame(['progress' => null, 'highlight' => ['table' => 'pages', 'uid' => 20, 'field' => 'description', 'title' => $this->descriptionLabel()]], $guided);
    }

    /**
     * @return iterable<string, array{int, int, int, string}>
     */
    public static function pagePropertiesNotNamed(): iterable
    {
        yield 'of another page than the conversation' => [3, 20, 21, 'description'];
        yield 'of a page the reader may not show' => [3, 30, 30, 'description'];
        yield 'an excluded field the reader may not edit' => [4, 20, 20, 'description'];
        yield 'a column pages does not have' => [3, 20, 20, 'header'];
        yield 'no field' => [3, 20, 20, ''];
    }

    #[Test]
    #[DataProvider('pagePropertiesNotNamed')]
    public function aPagePropertyTheReaderMayNotSeeHereIsNotNamed(int $user, int $conversationPage, int $page, string $field): void
    {
        $reader = $this->asUser($user);

        self::assertSame('', (new HighlightTitle())->of($this->conversation($conversationPage), ['table' => 'pages', 'uid' => $page, 'field' => $field], $reader));
    }

    #[Test]
    public function aPagePropertyThatIsNotExcludedIsNamedForAnyReaderOfThePage(): void
    {
        $reader = $this->asUser(4);
        $label  = $GLOBALS['LANG']->sL($GLOBALS['TCA']['pages']['columns']['title']['label']);

        self::assertNotSame('', $label);
        self::assertSame($label, (new HighlightTitle())->of($this->conversation(20), ['table' => 'pages', 'uid' => 20, 'field' => 'title'], $reader));
    }

    /** The subpage the reader may show is named; 31 beside it is not (noTitleForAnyOtherPage). */
    #[Test]
    public function aSubpageTheReaderMayShowIsNamed(): void
    {
        $this->asUser(3);

        self::assertSame(['pageId' => 21, 'title' => 'Team'], $this->json($this->get(ChatApiController::class), 'getPageTitle', ['pageId' => '21']));
    }
}
