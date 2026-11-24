<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Service;

use Netresearch\NrLlm\Domain\Enum\ChangeAction;
use Netresearch\NrMcpAgent\Service\ChangeList\ChangeCollection;
use Netresearch\NrMcpAgent\Service\ChangeList\ChangePresenter;
use Netresearch\NrMcpAgent\Service\ChangeList\RecordChange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The change list as one viewer may see it (ADR-034), with TYPO3's real page
 * permissions, mounts, table access and workspaces: nr-llm's list names table
 * and uid of records the viewer may not be allowed to read, so a record the
 * viewer may not read is withheld whole and only counted.
 */
#[CoversClass(ChangePresenter::class)]
final class ChangeListPresenterTest extends FunctionalTestCase
{
    private const ADMIN = 1;

    private const EDITOR = 11;

    /** Mounts 1:/marketing/ only, with read permission on files and folders. */
    private const MARKETING = 12;

    protected array $coreExtensionsToLoad = ['filelist', 'workspaces'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/change_view.csv');
    }

    /**
     * @param list<RecordChange> $changes
     *
     * @return array<string, mixed>
     */
    private function present(int $user, array $changes, ?ChangeCollection $collection = null, bool $backendRequest = true): array
    {
        $viewer             = $this->setUpBackendUser($user);
        $GLOBALS['BE_USER'] = $viewer;
        // Core hands a viewer's file mounts and permissions to a storage only in a backend request.
        $GLOBALS['TYPO3_REQUEST'] = $backendRequest
            ? (new ServerRequest('https://typo3-testing.local/typo3/'))->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            : null;
        self::assertInstanceOf(BackendUserAuthentication::class, $viewer);

        // As in a backend request: the labels and the record titles read $GLOBALS['LANG'].
        $language        = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($viewer);
        $GLOBALS['LANG'] = $language;

        return $this->get(ChangePresenter::class)->present($collection ?? new ChangeCollection($changes, runs: 1), $viewer, $language);
    }

    /**
     * @param array<string, mixed> $presented
     *
     * @return list<string>
     */
    private function keys(array $presented): array
    {
        return array_map(static fn (array $item): string => $item['table'] . ':' . $item['uid'], $presented['changes']);
    }

    private function change(string $table, int $uid, ChangeAction $action = ChangeAction::UPDATED, int $workspace = 0, bool $target = true): RecordChange
    {
        return new RecordChange($table, $uid, $action, $workspace, isWriteTarget: $target);
    }

    /** The case the whole class exists for: a record the editor may not read is not in the answer, in no field. */
    #[Test]
    public function anEditorSeesOnlyTheRecordsTheyMayRead(): void
    {
        $presented = $this->present(self::EDITOR, [
            $this->change('pages', 10),
            $this->change('tt_content', 1),
            // On a page outside the editor's mount and permissions.
            $this->change('tt_content', 2),
            $this->change('pages', 20),
            // Inside the mount, but the page denies the editor "show".
            $this->change('tt_content', 3),
            $this->change('pages', 11),
            // A table the editor has no select access to.
            $this->change('be_users', 1),
            // A row that does not exist.
            $this->change('tt_content', 999),
        ]);

        self::assertSame(['pages:10', 'tt_content:1'], $this->keys($presented));
        self::assertSame(6, $presented['withheld']);
        $json = json_encode($presented, JSON_THROW_ON_ERROR);
        foreach (['Geheim', 'gesperrte', 'be_users', '"uid":20', '"uid":2,', '"uid":999'] as $secret) {
            self::assertStringNotContainsString($secret, $json, $secret);
        }
    }

    /** A page a run deleted is still a page the editor may read: the delete is what the list reports. */
    #[Test]
    public function aDeletedPageOnAReadablePageIsListedForTheEditorWhoMayReadIt(): void
    {
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 12, 'pid' => 10, 'title' => 'Gelöschte Unterseite', 'doktype' => 1, 'sorting' => 10, 'perms_userid' => 1, 'perms_user' => 31, 'perms_groupid' => 5, 'perms_group' => 1, 'perms_everybody' => 0, 'deleted' => 1]);
        // The same shape outside the mount and the permissions stays withheld.
        $pages->insert('pages', ['uid' => 21, 'pid' => 20, 'title' => 'Geheime gelöschte Seite', 'doktype' => 1, 'sorting' => 10, 'perms_userid' => 1, 'perms_user' => 31, 'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => 0, 'deleted' => 1]);

        $presented = $this->present(self::EDITOR, [$this->change('pages', 12, ChangeAction::DELETED), $this->change('pages', 21, ChangeAction::DELETED)]);

        self::assertSame(['pages:12'], $this->keys($presented));
        self::assertSame(1, $presented['withheld']);
        self::assertStringNotContainsString('Geheime gelöschte', json_encode($presented, JSON_THROW_ON_ERROR));
    }

    /** A file record lies on the root level, but the file belongs to a storage: no file mount, no name and uid. */
    #[Test]
    public function aFileRecordIsListedOnlyForAViewerWithTheFileMount(): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_file')->insert('sys_file', ['uid' => 7, 'pid' => 0, 'storage' => 1, 'identifier' => '/geheim.pdf', 'name' => 'geheim.pdf']);

        $editor = $this->present(self::EDITOR, [$this->change('sys_file', 7)]);
        self::assertSame([], $this->keys($editor));
        self::assertSame(1, $editor['withheld']);
        self::assertStringNotContainsString('geheim', json_encode($editor, JSON_THROW_ON_ERROR));

        self::assertSame(['sys_file:7'], $this->keys($this->present(self::ADMIN, [$this->change('sys_file', 7)])));
    }

    /**
     * A mount of 1:/marketing/ is not a mount of the whole storage: a file in
     * 1:/intern/ is withheld whole, and so is the metadata row of that file,
     * while the same two records of a file inside the mount are listed.
     */
    #[Test]
    public function aFileOutsideTheViewersFileMountIsWithheldInsideTheSameStorage(): void
    {
        $this->importFiles();

        $presented = $this->present(self::MARKETING, [
            $this->change('sys_file', 8),
            $this->change('sys_file', 9),
            $this->change('sys_file_metadata', 1),
            $this->change('sys_file_metadata', 2),
        ]);

        self::assertSame(['sys_file:8', 'sys_file_metadata:1'], $this->keys($presented));
        self::assertSame(2, $presented['withheld']);
        $json = json_encode($presented, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('intern-geheim', $json);
        self::assertStringNotContainsString('"uid":9', $json);
        self::assertStringNotContainsString('"uid":2,', $json);
        self::assertStringContainsString('marketing-offen', $json);

        // The administrator has no mount to cross.
        self::assertSame(['sys_file:9', 'sys_file_metadata:2'], $this->keys($this->present(self::ADMIN, [$this->change('sys_file', 9), $this->change('sys_file_metadata', 2)])));
    }

    /**
     * Without a backend request core loads no mounts and no permissions into a
     * storage, and then allows every action on it. That is no verdict, so the
     * file is withheld, also from a viewer who has the mount.
     */
    #[Test]
    public function aFileIsWithheldWhenCoreDidNotLoadTheViewersPermissionsIntoItsStorage(): void
    {
        $this->importFiles();

        $presented = $this->present(self::MARKETING, [$this->change('sys_file', 8)], backendRequest: false);

        self::assertSame([], $this->keys($presented));
        self::assertSame(1, $presented['withheld']);
    }

    /** A metadata row whose file is gone cannot be tied to a mount, so it is withheld. */
    #[Test]
    public function aMetadataRowWithoutItsFileIsWithheld(): void
    {
        $this->importFiles();
        $this->getConnectionPool()->getConnectionForTable('sys_file_metadata')->insert('sys_file_metadata', ['uid' => 3, 'pid' => 0, 'file' => 404, 'title' => 'verwaist']);

        $presented = $this->present(self::MARKETING, [$this->change('sys_file_metadata', 3)]);

        self::assertSame([], $this->keys($presented));
        self::assertSame(1, $presented['withheld']);
    }

    private function importFiles(): void
    {
        foreach (['marketing', 'intern'] as $folder) {
            GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/' . $folder);
        }

        $files = $this->getConnectionPool()->getConnectionForTable('sys_file');
        $files->insert('sys_file', ['uid' => 8, 'pid' => 0, 'storage' => 1, 'identifier' => '/marketing/offen.pdf', 'name' => 'marketing-offen.pdf']);
        $files->insert('sys_file', ['uid' => 9, 'pid' => 0, 'storage' => 1, 'identifier' => '/intern/geheim.pdf', 'name' => 'intern-geheim.pdf']);

        $metadata = $this->getConnectionPool()->getConnectionForTable('sys_file_metadata');
        $metadata->insert('sys_file_metadata', ['uid' => 1, 'pid' => 0, 'file' => 8, 'title' => 'marketing-offen']);
        $metadata->insert('sys_file_metadata', ['uid' => 2, 'pid' => 0, 'file' => 9, 'title' => 'intern-geheim']);
    }

    #[Test]
    public function anAdministratorSeesEverythingThatExists(): void
    {
        $presented = $this->present(self::ADMIN, [
            $this->change('pages', 20),
            $this->change('tt_content', 2),
            $this->change('tt_content', 999),
            $this->change('not_a_table', 1),
        ]);

        self::assertSame(['pages:20', 'tt_content:2'], $this->keys($presented));
        self::assertSame(2, $presented['withheld']);
        self::assertSame('Geheime Seite', $presented['changes'][0]['title']);
        self::assertSame('Page', $presented['changes'][0]['tableLabel']);
    }

    /** What the server does not know stays unknown: no flag is filled in for the viewer. */
    #[Test]
    public function theStoredFactsPassThroughAsSeparateFields(): void
    {
        $change = new RecordChange('tt_content', 1, ChangeAction::CREATED, 0, language: 1, isWriteTarget: false, hidden: false, restrictions: ['starttime', 'fe_group']);

        $item = $this->present(self::ADMIN, [$change])['changes'][0];

        self::assertSame('created', $item['action']);
        self::assertFalse($item['draft']);
        self::assertFalse($item['hidden']);
        self::assertSame(['starttime', 'fe_group'], $item['restrictions']);
        self::assertFalse($item['writeTarget']);
        self::assertSame(1, $item['language']['id']);
        self::assertNotSame('', $item['language']['label']);

        // The write facts of an observed entry reach the view as they are.
        $partial = $this->present(self::ADMIN, [new RecordChange('tt_content', 1, ChangeAction::CREATED, isWriteTarget: true, failedAfterWrite: true, complete: false)])['changes'][0];
        self::assertFalse($partial['complete']);
        self::assertTrue($partial['failedAfterWrite']);
        self::assertFalse($partial['legacy']);

        $unknown = $this->present(self::ADMIN, [new RecordChange('tt_content', 1)])['changes'][0];
        self::assertNull($unknown['hidden']);
        self::assertNull($unknown['language']);
        self::assertSame('', $unknown['action']);
    }

    #[Test]
    public function theCoverageNotesOfTheCollectionAreCarriedOnUnchanged(): void
    {
        $presented = $this->present(self::ADMIN, [], new ChangeCollection([], notCaptured: true, omitted: 3, legacy: true, runs: 2, unreadable: 1));

        self::assertTrue($presented['notCaptured']);
        self::assertSame(3, $presented['omitted']);
        self::assertTrue($presented['legacy']);
        self::assertSame(2, $presented['runs']);
        self::assertSame(1, $presented['unreadableRuns']);
    }

    #[Test]
    public function entriesBeyondTheLimitAreCountedAsOmitted(): void
    {
        $changes = [];
        for ($i = 0; $i < ChangePresenter::LIMIT + 3; ++$i) {
            $changes[] = new RecordChange('pages', 20, ChangeAction::UPDATED);
        }

        $presented = $this->present(self::ADMIN, $changes);

        self::assertCount(ChangePresenter::LIMIT, $presented['changes']);
        self::assertSame(3, $presented['omitted']);
    }

    /** The title of a workspace the viewer may not use is withheld; its id is not. */
    #[Test]
    public function aWorkspaceTitleIsWithheldFromAViewerWhoMayNotUseIt(): void
    {
        $member    = $this->present(self::EDITOR, [$this->change('tt_content', 1, workspace: 1)])['changes'][0];
        $nonMember = $this->present(self::EDITOR, [$this->change('tt_content', 1, workspace: 2)])['changes'][0];

        self::assertSame(['id' => 1, 'title' => 'Entwurf'], $member['workspace']);
        self::assertTrue($member['draft']);
        self::assertSame(['id' => 2, 'title' => ''], $nonMember['workspace']);
        self::assertTrue($nonMember['draft']);
    }

    /** A version row in a workspace the viewer may not use is a record the viewer may not read. */
    #[Test]
    public function aVersionRowInAForeignWorkspaceIsWithheld(): void
    {
        $presented = $this->present(self::EDITOR, [
            $this->change('tt_content', 4, workspace: 1),
            $this->change('tt_content', 5, workspace: 2),
        ]);

        self::assertSame(['tt_content:4'], $this->keys($presented));
        self::assertSame(1, $presented['withheld']);
    }

    #[Test]
    public function linksAreOfferedOnlyWhereTheViewerMayUseThem(): void
    {
        // The editor may modify pages but not tt_content.
        $page    = $this->present(self::EDITOR, [$this->change('pages', 10)])['changes'][0];
        $content = $this->present(self::EDITOR, [$this->change('tt_content', 1)])['changes'][0];

        self::assertStringContainsString('record', $page['editUrl']);
        self::assertSame('', $content['editUrl']);
        self::assertStringContainsString('history', $content['historyUrl']);
        // The workspace module shows the workspace the viewer works in, and only a draft has one.
        self::assertSame('', $page['workspaceUrl']);
    }

    private function writeSite(): void
    {
        // The fixture's editors may not work live; here they may, so both workspaces can be compared.
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update('be_groups', ['workspace_perms' => 1], ['uid' => 5]);

        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 10,
            'base'       => 'https://example.com/',
            'languages'  => [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/', 'enabled' => true],
                ['languageId' => 1, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/en/', 'enabled' => true],
            ],
        ]);
    }

    private function preview(int $user, string $table, int $uid, int $workspace = 0, ?int $viewerWorkspace = null): string
    {
        $this->present($user, []);
        $viewer = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $viewer);
        $viewer->setWorkspace($viewerWorkspace ?? $workspace);
        self::assertSame($viewerWorkspace ?? $workspace, $viewer->workspace, 'the viewer works in the workspace the case names');

        // As the backend's authentication middleware does: core's preview reads this aspect.
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect($viewer->workspace));

        return $this->get(ChangePresenter::class)->previewUrl($table, $uid, $workspace, $viewer);
    }

    /** A page, and an element on it, are shown where they are: the element by its anchor. */
    #[Test]
    public function aReadablePageAndElementLinkToThePageThatShowsThem(): void
    {
        $this->writeSite();

        self::assertSame('https://example.com/', $this->preview(self::EDITOR, 'pages', 10));
        self::assertSame('https://example.com/#c1', $this->preview(self::EDITOR, 'tt_content', 1));
        // The change list carries the same link.
        self::assertSame('https://example.com/#c1', $this->present(self::EDITOR, [$this->change('tt_content', 1)])['changes'][0]['previewUrl']);
    }

    /** A hidden element is not on the page; a hidden page is previewable for its editors. */
    #[Test]
    public function aHiddenElementHasNoPreviewAndAHiddenPageHasOne(): void
    {
        $this->writeSite();
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update('tt_content', ['hidden' => 1], ['uid' => 1]);
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['hidden' => 1], ['uid' => 10]);

        self::assertSame('', $this->preview(self::EDITOR, 'tt_content', 1));
        self::assertSame('https://example.com/', $this->preview(self::EDITOR, 'pages', 10));
    }

    /** The preview permission is the list's: what the viewer may not read is not linked. */
    #[Test]
    public function aRecordTheViewerMayNotReadIsNotLinked(): void
    {
        $this->writeSite();

        foreach ([['pages', 11], ['pages', 20], ['tt_content', 2], ['tt_content', 3], ['tt_content', 999], ['be_users', 1]] as [$table, $uid]) {
            self::assertSame('', $this->preview(self::EDITOR, $table, $uid), $table . ':' . $uid);
        }

        self::assertNotSame('', $this->preview(self::ADMIN, 'pages', 11));
    }

    /** The core previews the workspace the viewer works in, so only that one is linked. */
    #[Test]
    public function aRecordOfAnotherWorkspaceIsNotLinked(): void
    {
        $this->writeSite();

        self::assertSame('', $this->preview(self::EDITOR, 'tt_content', 1, workspace: 1, viewerWorkspace: 0));
        self::assertSame('', $this->preview(self::EDITOR, 'tt_content', 1, workspace: 0, viewerWorkspace: 1));

        // In the workspace the core hands out the workspace's own preview.
        $url = $this->preview(self::EDITOR, 'tt_content', 1, workspace: 1);
        self::assertStringContainsString('workspace', $url);
        // The version row and its live record are one record on the page.
        self::assertSame($this->withoutToken($url), $this->withoutToken($this->preview(self::EDITOR, 'tt_content', 4, workspace: 1)));
    }

    /** What the version says counts: hidden or removed in the draft, shown live. */
    #[Test]
    public function theVersionDecidesWhetherThePageShowsTheElement(): void
    {
        $this->writeSite();
        $content = $this->getConnectionPool()->getConnectionForTable('tt_content');

        $content->update('tt_content', ['hidden' => 1], ['uid' => 4]);
        self::assertSame('', $this->preview(self::EDITOR, 'tt_content', 1, workspace: 1));
        self::assertNotSame('', $this->preview(self::EDITOR, 'tt_content', 1));

        $content->update('tt_content', ['hidden' => 0, 't3ver_state' => 2], ['uid' => 4]);
        self::assertSame('', $this->preview(self::EDITOR, 'tt_content', 1, workspace: 1));
    }

    /** A translation is shown in its language; a translated page through its original. */
    #[Test]
    public function aTranslationLinksToItsLanguage(): void
    {
        $this->writeSite();
        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', ['uid' => 12, 'pid' => 0, 'title' => 'Visible page', 'doktype' => 1, 'sys_language_uid' => 1, 'l10n_parent' => 10, 'slug' => '/']);

        self::assertStringStartsWith('https://example.com/en/', $this->preview(self::ADMIN, 'pages', 12));
    }

    /** Without a site, or on a page type with no frontend page, there is nothing to open. */
    #[Test]
    public function aPageWithoutAFrontendPageIsNotLinked(): void
    {
        $this->writeSite();
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['doktype' => 254], ['uid' => 10]);

        self::assertSame('', $this->preview(self::EDITOR, 'pages', 10));
        self::assertSame('', $this->preview(self::EDITOR, 'tt_content', 1));
        // The workspace's preview does not ask for the page type itself.
        self::assertSame('', $this->preview(self::EDITOR, 'pages', 10, workspace: 1));
    }

    private function withoutToken(string $url): string
    {
        return (string) preg_replace('/([?&])token=[^&#]*/', '$1', $url);
    }
}
