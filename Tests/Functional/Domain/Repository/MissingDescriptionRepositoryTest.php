<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Domain\Repository;

use Netresearch\NrMcpAgent\Domain\Repository\MissingDescriptionRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The count of page versions without a meta description
 * (missing_descriptions.csv), per user, language and workspace.
 *
 * Site "main" on page 1 has German (0) and English (1); page 13 is a French
 * (2) translation the site does not have, page 15 lies outside every site.
 * As the admin sees it live, these lack a description: 1 (empty), 3 (only
 * spaces), 7 (the editor may only view it), 8 (locked for editing), 10 and
 * 11 (English translations of 1 and of 2, which has one), 15, 17 (NULL),
 * 25.
 * Left out: 2 (has one), 4 (hidden), 5 (folder), 6 (link), 9 (deleted),
 * 12 (translation of the hidden 4), 13 (language the site lacks), 14
 * (hidden translation), 16 (spacer), 18 (backend user section), 19 (mount
 * point). Rows 20 to 24 and 26 are versions of workspace 1.
 */
final class MissingDescriptionRepositoryTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist', 'workspaces'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/missing_descriptions.csv');
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base'       => 'https://example.com/',
            'languages'  => [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/', 'flag' => 'de'],
                ['languageId' => 1, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/en/', 'flag' => 'us'],
            ],
        ]);
    }

    /**
     * @return array{count: int, first: array{pageUid: int, languageUid: int, title: string}|null}|null
     */
    private function summary(int $user, int $workspace = 0): ?array
    {
        $backendUser = $this->setUpBackendUser($user);
        if ($workspace !== 0) {
            $backendUser->setWorkspace($workspace);
            self::assertSame($workspace, $backendUser->workspace);
        }

        return (new MissingDescriptionRepository($this->get(ConnectionPool::class), $this->get(SiteFinder::class)))->summary();
    }

    #[Test]
    public function theAdminCountsEveryPageVersionWithoutADescription(): void
    {
        self::assertSame(['count' => 9, 'translations' => 2, 'first' => ['pageUid' => 17, 'languageUid' => 0, 'title' => 'Neu']], $this->summary(1));
    }

    /** Pages outside the mount, view-only and locked pages are not the editor's to fix. */
    #[Test]
    public function anEditorCountsOnlyThePagesTheyMayEdit(): void
    {
        self::assertSame(['count' => 6, 'translations' => 2, 'first' => ['pageUid' => 17, 'languageUid' => 0, 'title' => 'Neu']], $this->summary(2));
    }

    /** An editor of English only counts the English versions, and starts on the newest of them. */
    #[Test]
    public function anEditorCountsOnlyTheLanguagesTheyMayEdit(): void
    {
        self::assertSame(['count' => 2, 'translations' => 2, 'first' => ['pageUid' => 2, 'languageUid' => 1, 'title' => 'With description (EN)']], $this->summary(4));
    }

    /**
     * An editor of the default language only counts no translation and is
     * not offered one: the English versions 10 and 11 are left out, though
     * they lack a description.
     */
    #[Test]
    public function anEditorWithoutALanguageNeitherCountsNorIsOfferedIt(): void
    {
        self::assertSame(['count' => 4, 'translations' => 0, 'first' => ['pageUid' => 17, 'languageUid' => 0, 'title' => 'Neu']], $this->summary(6));
    }

    /**
     * Page 28 grants everybody "edit page", but lies under page 27, which the
     * editor may not show (missing_descriptions_unreadable.csv). The page tree
     * hides it and DataHandler refuses to save it, so it is neither counted
     * nor offered, though it is the newest page without a description. The
     * admin counts it and starts on it.
     */
    #[Test]
    public function aPageUnderOneTheEditorMayNotShowIsNotTheirs(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/missing_descriptions_unreadable.csv');

        self::assertSame(['count' => 6, 'translations' => 2, 'first' => ['pageUid' => 17, 'languageUid' => 0, 'title' => 'Neu']], $this->summary(2));
        self::assertSame(['count' => 10, 'translations' => 2, 'first' => ['pageUid' => 28, 'languageUid' => 0, 'title' => 'Unter Intern']], $this->summary(1));
    }

    /** Without the field or the table there is nothing the user could write: nothing is counted. */
    #[Test]
    public function aUserWhoMayNotWriteTheDescriptionGetsNoCount(): void
    {
        self::assertNull($this->summary(3));
        self::assertNull($this->summary(5));
    }

    /**
     * In the workspace its versions decide: 1 and 3 got a description there,
     * 2 lost its one, 17 is deleted there, 22 is new there, and 25 became a
     * folder there. The tour starts on 2: its workspace version is the latest
     * change, though the live page is older than 22.
     */
    #[Test]
    public function inAWorkspaceItsVersionsDecide(): void
    {
        self::assertSame(['count' => 7, 'translations' => 2, 'first' => ['pageUid' => 2, 'languageUid' => 0, 'title' => 'Mit Beschreibung']], $this->summary(1, 1));
    }

    /** A user without access to any workspace (workspace -99) may write nothing. */
    #[Test]
    public function aUserWithoutAWorkspaceGetsNoCount(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', ['workspace_perms' => 0], ['uid' => 1]);
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', ['workspace_perms' => 0], ['uid' => 2]);

        self::assertNull($this->summary(2));
    }

    #[Test]
    public function withoutABackendUserThereIsNothingToCount(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertNull((new MissingDescriptionRepository($this->get(ConnectionPool::class), $this->get(SiteFinder::class)))->summary());
    }
}
