<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Tool;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrMcpAgent\Service\AltText\ReferenceWriteAccess;
use Netresearch\NrMcpAgent\Tool\SetDecorativeImageTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The decorative mark in a draft workspace (ADR-032): the run that recorded
 * the editor's workspace stores a version of the reference there, live stays
 * as it is; a run that did not record it, another workspace's draft and a
 * table that is not versioned or is edited live are refused. Against the list's fixture
 * (missing_alt_texts.csv) with a real workspace the editor is a member of.
 */
final class SetDecorativeImageToolWorkspaceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist', 'workspaces'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
        __DIR__ . '/../Fixtures/Extensions/alt_text_test',
    ];

    private const EDITOR = 2;

    private const DRAFT = 1;

    private const OTHER_DRAFT = 2;

    /** "Unser Team" on the start page, the editor's to change. */
    private const REFERENCE = 1;

    private const COLUMN = 'tx_nrmcpagent_decorative';

    private const WORKSPACE_LINE = 'Workspace: “Entwurf” — saved there as a draft, live stays unchanged until the workspace is published';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/missing_alt_texts.csv');
        $workspaces = $this->getConnectionPool()->getConnectionForTable('sys_workspace');
        $workspaces->insert('sys_workspace', ['uid' => self::DRAFT, 'pid' => 0, 'title' => 'Entwurf', 'members' => 'be_users_2']);
        $workspaces->insert('sys_workspace', ['uid' => self::OTHER_DRAFT, 'pid' => 0, 'title' => 'Andere', 'members' => 'be_users_2']);
    }

    /** The editor, set up in the draft workspace — asserted, so a test never passes in live by accident. */
    private function editorIn(int $workspace): BackendUserAuthentication
    {
        $user = $this->setUpBackendUser(self::EDITOR);
        self::assertTrue($user->setTemporaryWorkspace($workspace));
        self::assertSame($workspace, $user->workspace);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);

        return $user;
    }

    /** A run that recorded the workspace the editor works in (nr-llm ADR-260). */
    private function pinnedRun(BackendUserAuthentication $user): ToolExecutionContext
    {
        $context = ToolExecutionContext::fromBackendUser($user);
        self::assertSame($user->workspace, $context->actor->workspaceId);

        return $context;
    }

    /** A run that does not say which workspace it was started in. */
    private function unpinnedRun(BackendUserAuthentication $user): ToolExecutionContext
    {
        $context = ToolExecutionContext::forBackendUser(AiActorContext::backendUser(self::EDITOR), $user);
        self::assertNull($context->actor->workspaceId);

        return $context;
    }

    private function tool(): SetDecorativeImageTool
    {
        $tool = $this->get(ToolRegistry::class)->get(SetDecorativeImageTool::NAME);
        self::assertInstanceOf(SetDecorativeImageTool::class, $tool);

        return $tool;
    }

    /**
     * Every row of the reference: the live one and its versions.
     *
     * @return list<array{uid: int, wsid: int, oid: int, state: int, mark: int}>
     */
    private function rowsOf(int $referenceUid): array
    {
        $qb = $this->getConnectionPool()->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', 't3ver_wsid', 't3ver_oid', 't3ver_state', self::COLUMN)
            ->from('sys_file_reference')
            ->where($qb->expr()->or(
                $qb->expr()->eq('uid', $referenceUid),
                $qb->expr()->eq('t3ver_oid', $referenceUid),
            ))
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'uid'   => (int) $row['uid'],
            'wsid'  => (int) $row['t3ver_wsid'],
            'oid'   => (int) $row['t3ver_oid'],
            'state' => (int) $row['t3ver_state'],
            'mark'  => (int) $row[self::COLUMN],
        ], $rows);
    }

    /**
     * @param array<string, int|string> $values
     */
    private function insertReference(array $values): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'pid'         => 10,
            'uid_local'   => 1,
            'uid_foreign' => 100,
            'tablenames'  => 'tt_content',
            'fieldname'   => 'image',
            ...$values,
        ]);
    }

    /** The run that recorded the draft workspace marks a version there; the live row keeps no mark. */
    #[Test]
    public function aRunInTheDraftWorkspaceMarksAVersionAndLeavesLiveAlone(): void
    {
        $user = $this->editorIn(self::DRAFT);

        $result = $this->tool()->execute(['reference' => self::REFERENCE, 'decorative' => true], $this->pinnedRun($user));

        self::assertFalse($result->isError, $result->content);
        self::assertStringEndsWith('Stored as a draft in the workspace "Entwurf"; live stays unchanged until that workspace is published.', $result->content);
        $rows = $this->rowsOf(self::REFERENCE);
        self::assertCount(2, $rows, (string) json_encode($rows));
        self::assertSame(['uid' => self::REFERENCE, 'wsid' => 0, 'oid' => 0, 'state' => 0, 'mark' => 0], $rows[0], 'the live row changed');
        self::assertSame(['wsid' => self::DRAFT, 'oid' => self::REFERENCE, 'state' => 0, 'mark' => 1], array_diff_key($rows[1], ['uid' => true]));
    }

    /** Taking the mark off in the workspace works on the version; live, still unmarked, stays so. */
    #[Test]
    public function theMarkIsTakenOffTheVersionAgain(): void
    {
        $user    = $this->editorIn(self::DRAFT);
        $context = $this->pinnedRun($user);
        self::assertFalse($this->tool()->execute(['reference' => self::REFERENCE, 'decorative' => true], $context)->isError);

        $result = $this->tool()->execute(['reference' => self::REFERENCE, 'decorative' => false], $context);

        self::assertFalse($result->isError, $result->content);
        $rows = $this->rowsOf(self::REFERENCE);
        self::assertCount(2, $rows);
        self::assertSame([0, 0], [$rows[0]['mark'], $rows[1]['mark']]);
        self::assertSame(self::DRAFT, $rows[1]['wsid']);
    }

    /**
     * A run that did not record the workspace would write wherever the
     * user's record points when it resumes: refused on the card and in the
     * write, nothing written.
     */
    #[Test]
    public function aRunThatDidNotRecordTheWorkspaceIsRefused(): void
    {
        $user    = $this->editorIn(self::DRAFT);
        $context = $this->unpinnedRun($user);

        $card   = $this->tool()->previewCall(['reference' => self::REFERENCE, 'decorative' => true], $context);
        $result = $this->tool()->execute(['reference' => self::REFERENCE, 'decorative' => true], $context);

        self::assertCount(1, $card);
        self::assertStringContainsString('does not record which workspace it was started in', $card[0]);
        self::assertTrue($result->isError);
        self::assertSame($card[0], $result->content);
        self::assertSame([['uid' => self::REFERENCE, 'wsid' => 0, 'oid' => 0, 'state' => 0, 'mark' => 0]], $this->rowsOf(self::REFERENCE));
    }

    /** A user who may work in no workspace (core sets a negative one) writes nothing. */
    #[Test]
    public function aUserWhoMayWorkInNoWorkspaceWritesNothing(): void
    {
        $user            = $this->editorIn(self::DRAFT);
        $context         = $this->pinnedRun($user);
        $user->workspace = -99;

        $result = $this->tool()->execute(['reference' => self::REFERENCE, 'decorative' => true], $context);

        self::assertTrue($result->isError);
        self::assertStringContainsString('does not record which workspace it was started in', $result->content);
        self::assertSame([['uid' => self::REFERENCE, 'wsid' => 0, 'oid' => 0, 'state' => 0, 'mark' => 0]], $this->rowsOf(self::REFERENCE));
    }

    /** A run recorded in another workspace than the one the user is set up in is refused too. */
    #[Test]
    public function aRunRecordedInAnotherWorkspaceIsRefused(): void
    {
        $user    = $this->editorIn(self::DRAFT);
        $context = ToolExecutionContext::forBackendUser(AiActorContext::backendUser(self::EDITOR, false, [1], [], self::OTHER_DRAFT), $user);

        $result = $this->tool()->execute(['reference' => self::REFERENCE, 'decorative' => true], $context);

        self::assertTrue($result->isError);
        self::assertSame([['uid' => self::REFERENCE, 'wsid' => 0, 'oid' => 0, 'state' => 0, 'mark' => 0]], $this->rowsOf(self::REFERENCE));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function tablesCoreKeepsNoDraftOf(): iterable
    {
        yield 'versioning taken off' => ['versioningWS', false];
        yield 'always edited live' => ['versioningWS_alwaysAllowLiveEdit', true];
    }

    /**
     * Where an installation takes the versioning off or lets the table be
     * edited live in a workspace, the write could go live past the workspace
     * (TYPO3 13.4 edits the live row for either), so it is refused, and live
     * keeps no mark.
     */
    #[Test]
    #[DataProvider('tablesCoreKeepsNoDraftOf')]
    public function aTableCoreKeepsNoDraftOfIsRefused(string $flag, bool $value): void
    {
        $GLOBALS['TCA']['sys_file_reference']['ctrl'][$flag] = $value;
        $user                                                = $this->editorIn(self::DRAFT);
        $context                                             = $this->pinnedRun($user);

        $card   = $this->tool()->previewCall(['reference' => self::REFERENCE, 'decorative' => true], $context);
        $result = $this->tool()->execute(['reference' => self::REFERENCE, 'decorative' => true], $context);

        self::assertTrue($result->isError);
        self::assertStringContainsString('turns versioning off or sets versioningWS_alwaysAllowLiveEdit', $result->content);
        self::assertSame([$result->content], $card);
        self::assertSame([['uid' => self::REFERENCE, 'wsid' => 0, 'oid' => 0, 'state' => 0, 'mark' => 0]], $this->rowsOf(self::REFERENCE));
    }

    /** A reference new in the workspace is that workspace's own row: marked in place, no second row. */
    #[Test]
    public function aReferenceNewInTheWorkspaceIsMarkedInPlace(): void
    {
        $this->insertReference(['uid' => 900, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 0, 't3ver_state' => 1]);
        $user = $this->editorIn(self::DRAFT);

        $result = $this->tool()->execute(['reference' => 900, 'decorative' => true], $this->pinnedRun($user));

        self::assertFalse($result->isError, $result->content);
        self::assertSame([['uid' => 900, 'wsid' => self::DRAFT, 'oid' => 0, 'state' => 1, 'mark' => 1]], $this->rowsOf(900));
    }

    /**
     * Another workspace's draft is neither read nor written: its version of
     * the reference stays as it is, a reference new there is not there, and
     * a version row named by its own uid is not there either.
     */
    #[Test]
    public function anotherWorkspacesDraftIsNeitherReadNorWritten(): void
    {
        $this->insertReference(['uid' => 901, 't3ver_wsid' => self::OTHER_DRAFT, 't3ver_oid' => self::REFERENCE, 't3ver_state' => 0]);
        $this->insertReference(['uid' => 902, 't3ver_wsid' => self::OTHER_DRAFT, 't3ver_oid' => 0, 't3ver_state' => 1]);
        $user    = $this->editorIn(self::DRAFT);
        $context = $this->pinnedRun($user);

        foreach ([901, 902] as $uid) {
            $result = $this->tool()->execute(['reference' => $uid, 'decorative' => true], $context);
            self::assertSame([true, ReferenceWriteAccess::NOT_PERMITTED], [$result->isError, $result->content], (string) $uid);
            self::assertSame([ReferenceWriteAccess::NOT_PERMITTED], $this->tool()->previewCall(['reference' => $uid, 'decorative' => true], $context));
        }

        $marked = $this->tool()->execute(['reference' => self::REFERENCE, 'decorative' => true], $context);
        self::assertFalse($marked->isError, $marked->content);

        $rows = $this->rowsOf(self::REFERENCE);
        self::assertCount(3, $rows, (string) json_encode($rows));
        self::assertSame(0, $rows[0]['mark'], 'live');
        self::assertSame(['uid' => 901, 'wsid' => self::OTHER_DRAFT, 'oid' => self::REFERENCE, 'state' => 0, 'mark' => 0], $rows[1], 'the other workspace');
        self::assertSame([self::DRAFT, 1], [$rows[2]['wsid'], $rows[2]['mark']]);
        self::assertSame(0, $this->rowsOf(902)[0]['mark']);
    }

    /** A reference deleted in the workspace is not there for it; live keeps it unmarked. */
    #[Test]
    public function aReferenceDeletedInTheWorkspaceIsNotThere(): void
    {
        $this->insertReference(['uid' => 904, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => self::REFERENCE, 't3ver_state' => 2]);
        $user = $this->editorIn(self::DRAFT);

        $result = $this->tool()->execute(['reference' => self::REFERENCE, 'decorative' => true], $this->pinnedRun($user));

        self::assertSame([true, ReferenceWriteAccess::NOT_PERMITTED], [$result->isError, $result->content]);
        self::assertSame([0, 0], array_column($this->rowsOf(self::REFERENCE), 'mark'));
    }

    /** A version row of the editor's own workspace, named by its own uid, is reached through its live uid only. */
    #[Test]
    public function aVersionRowNamedByItsOwnUidIsNotThere(): void
    {
        $this->insertReference(['uid' => 903, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => self::REFERENCE, 't3ver_state' => 0]);
        $user = $this->editorIn(self::DRAFT);

        $result = $this->tool()->execute(['reference' => 903, 'decorative' => true], $this->pinnedRun($user));

        self::assertSame([true, ReferenceWriteAccess::NOT_PERMITTED], [$result->isError, $result->content]);
        self::assertSame(0, $this->rowsOf(903)[0]['mark']);
    }

    /**
     * The card names the workspace the run writes into, and the record as
     * that workspace shows it.
     */
    #[Test]
    public function theCardNamesTheWorkspaceAndShowsTheRecordAsItIsThere(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid'        => 500, 'pid' => 10, 'CType' => 'textpic', 'header' => 'Unser Team im Entwurf', 'colPos' => 0, 'sorting' => 256,
            't3ver_wsid' => self::DRAFT, 't3ver_oid' => 100, 't3ver_state' => 0,
        ]);
        $user = $this->editorIn(self::DRAFT);

        $lines = $this->tool()->previewCall(['reference' => self::REFERENCE, 'decorative' => false], $this->pinnedRun($user));

        self::assertSame([
            'Take the decorative mark off the image',
            'Page Content: “Unser Team im Entwurf”',
            'On page: “Startseite”',
            'Field: Images',
            'File: “team.jpg”',
            self::WORKSPACE_LINE,
        ], $lines);
    }

    /**
     * The workspace line is nr-llm's own (approvalPreview.workspace.target
     * with its quotation marks), so it reads exactly as on nr-llm's alt text
     * card: in German too, where the quotation marks are the language's.
     */
    #[Test]
    public function theWorkspaceLineIsNrLlmsOwnInTheEditorsLanguage(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_users')->update('be_users', ['lang' => 'de'], ['uid' => self::EDITOR]);
        $user = $this->editorIn(self::DRAFT);

        $lines = $this->tool()->previewCall(['reference' => self::REFERENCE, 'decorative' => false], $this->pinnedRun($user));

        self::assertContains('Arbeitsumgebung: „Entwurf“ – dort als Entwurf gespeichert, live bleibt unverändert, bis die Arbeitsumgebung veröffentlicht wird', $lines);
    }
}
