<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Domain\Repository;

use Netresearch\NrMcpAgent\Domain\Repository\PageChoiceRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The pages the assistant widgets offer: those the user may improve — edit
 * their content — newest change first.
 *
 * The editor belongs to one backend group ("Redaktion"): core's calcPerms()
 * grants a user without any group no page permission at all.
 * The editor's only web mount is "Bereich A" (20). "Fremder Bereich" (30)
 * grants them every permission but lies outside the mount; "Gesperrt" (22)
 * lies inside it but grants them nothing; "Nur ansehen" (28) grants them
 * "show page" only; "Gesperrt fuer Bearbeitung" (29) grants them everything
 * but is locked for editing (`editlock`). None of the pages belongs to a
 * site, so only the default language exists for them.
 */
final class PageChoiceRepositoryTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/dashboard_page_choice.csv');
    }

    private function repository(): PageChoiceRepository
    {
        return new PageChoiceRepository($this->get(ConnectionPool::class), $this->get(SiteFinder::class), $this->get(TcaSchemaFactory::class));
    }

    /**
     * @param list<array{uid: int, title: string}> $pages
     * @return list<int>
     */
    private static function uids(array $pages): array
    {
        return array_column($pages, 'uid');
    }

    #[Test]
    public function anEditorIsOfferedOnlyPagesInTheirMountThatTheyMayEdit(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(3);

        $uids = self::uids($this->repository()->findRecentlyChanged(10));

        // Newest first; hidden pages are pages an editor works on, too.
        self::assertSame([21, 26, 27, 20], $uids);
    }

    /** Decision 5, the granting direction: a page whose content the editor may edit is offered. */
    #[Test]
    public function aPageTheEditorMayEditIsOffered(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(3);

        self::assertContains(21, self::uids($this->repository()->findRecentlyChanged(10)));
        self::assertSame(['uid' => 21, 'title' => 'Inhalt A'], $this->repository()->findEditable(21));
    }

    /** Decision 5, the refusing direction: "show page" alone is not enough. */
    #[Test]
    public function aPageTheEditorMayOnlyViewIsNotOffered(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(3);

        self::assertNotContains(28, self::uids($this->repository()->findRecentlyChanged(10)));
        self::assertNull($this->repository()->findEditable(28));
        // The editor may still see it: a recommendation about it stays readable.
        self::assertSame(['uid' => 28, 'title' => 'Nur ansehen'], $this->repository()->findAccessible(28));
    }

    /** A page locked for editing is read-only for everyone but admins. */
    #[Test]
    public function aPageLockedForEditingIsNotOfferedToAnEditorButToAnAdmin(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(3);
        self::assertNotContains(29, self::uids($this->repository()->findRecentlyChanged(10)));
        self::assertNull($this->repository()->findEditable(29));

        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        self::assertSame(['uid' => 29, 'title' => 'Gesperrt fuer Bearbeitung'], $this->repository()->findEditable(29));
    }

    /**
     * The chat starts on a page only in a language the page's site has; a
     * page outside every site has the default language only.
     */
    #[Test]
    public function theChatStartsOnAPageOutsideASiteInTheDefaultLanguageOnly(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(3);

        self::assertTrue($this->repository()->mayStartIn(21, 0));
        self::assertFalse($this->repository()->mayStartIn(21, 1));
        self::assertFalse($this->repository()->mayStartIn(21, -1));
    }

    /**
     * Decision 4: an editor whose groups allow only another language may not
     * start the chat in the default language, so no page is offered to them.
     */
    #[Test]
    public function anEditorWithoutTheDefaultLanguageIsOfferedNoPage(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(4);

        self::assertFalse($this->repository()->mayStartIn(21, 0));
        self::assertNull($this->repository()->findEditable(21));
        self::assertSame([], $this->repository()->findRecentlyChanged(10));
    }

    /**
     * Decision 4, on a page that belongs to a site: the chat starts in the
     * languages the site has, and in no other.
     */
    #[Test]
    public function theChatStartsOnAPageInASiteInTheSitesLanguagesOnly(): void
    {
        $directory = $this->instancePath . '/typo3conf/sites/assistant';
        mkdir($directory, 0o777, true);
        file_put_contents($directory . '/config.yaml', implode("\n", [
            'rootPageId: 20',
            "base: 'https://example.com/'",
            'languages:',
            '  - languageId: 0',
            "    title: English",
            "    locale: en_US.UTF-8",
            "    base: '/'",
            '  - languageId: 1',
            "    title: Deutsch",
            "    locale: de_DE.UTF-8",
            "    base: '/de/'",
            '',
        ]));
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(3);

        self::assertTrue($this->repository()->mayStartIn(21, 0));
        self::assertTrue($this->repository()->mayStartIn(21, 1));
        self::assertFalse($this->repository()->mayStartIn(21, 2));
    }

    /**
     * The permission clause keeps view-only pages from using up the candidate
     * window: an editor whose mount holds thousands of newer pages they may
     * only view is still offered the pages they may edit.
     */
    #[Test]
    public function manyNewerViewOnlyPagesDoNotHideThePagesTheEditorMayEdit(): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        $rows = [];
        for ($uid = 3000; $uid < 5100; $uid++) {
            $rows[] = [$uid, 20, 'Nur ansehen ' . $uid, 1, 3, 1, 100000 + $uid];
        }
        $connection->bulkInsert('pages', $rows, ['uid', 'pid', 'title', 'doktype', 'perms_userid', 'perms_user', 'tstamp']);
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(3);

        self::assertSame([21, 26, 27, 20], self::uids($this->repository()->findRecentlyChanged(15)));
    }

    #[Test]
    public function aPageOutsideTheMountIsNotOfferedThoughItsPermissionsAllowIt(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(3);

        self::assertNotContains(30, self::uids($this->repository()->findRecentlyChanged(10)));
        self::assertNull($this->repository()->findAccessible(30));
    }

    #[Test]
    public function aPageInsideTheMountIsNotOfferedWhenItsPermissionsDenyIt(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(3);

        self::assertNotContains(22, self::uids($this->repository()->findRecentlyChanged(10)));
        self::assertNull($this->repository()->findAccessible(22));
    }

    #[Test]
    public function foldersDeletedPagesAndTranslationsAreNotOffered(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);

        self::assertSame([29, 28, 30, 22, 21, 26, 27, 20], self::uids($this->repository()->findRecentlyChanged(10)));
    }

    #[Test]
    public function theListStopsAtTheLimit(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);

        self::assertSame([29, 28], self::uids($this->repository()->findRecentlyChanged(2)));
    }

    /**
     * On a real site the group often grants "show" widely and the mount is
     * what narrows an editor's view: many newer pages outside it must not
     * crowd out the pages they can work on.
     */
    #[Test]
    public function manyNewerPagesOutsideTheMountDoNotHideThePagesInIt(): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        for ($uid = 1000; $uid < 1120; $uid++) {
            $connection->insert('pages', [
                'uid' => $uid, 'pid' => 30, 'title' => 'Fremd ' . $uid, 'doktype' => 1,
                'perms_userid' => 3, 'perms_user' => 31, 'tstamp' => 10000 + $uid,
            ]);
        }
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(3);

        self::assertSame([21, 26, 27, 20], self::uids($this->repository()->findRecentlyChanged(15)));
    }

    #[Test]
    public function anAccessiblePageIsReturnedWithItsTitle(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(3);

        self::assertSame(['uid' => 21, 'title' => 'Inhalt A'], $this->repository()->findAccessible(21));
    }

    #[Test]
    public function withoutABackendUserNothingIsOffered(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertSame([], $this->repository()->findRecentlyChanged(10));
        self::assertNull($this->repository()->findAccessible(21));
        self::assertNull($this->repository()->findEditable(21));
        self::assertFalse($this->repository()->mayStartIn(21, 0));
    }
}
