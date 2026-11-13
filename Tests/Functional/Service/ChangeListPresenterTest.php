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
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
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
    private function present(int $user, array $changes, ?ChangeCollection $collection = null): array
    {
        $viewer             = $this->setUpBackendUser($user);
        $GLOBALS['BE_USER'] = $viewer;
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
}
