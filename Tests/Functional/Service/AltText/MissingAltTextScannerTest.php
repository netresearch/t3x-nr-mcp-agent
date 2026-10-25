<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Service\AltText;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Repository\MissingAltTextRepository;
use Netresearch\NrMcpAgent\Enum\HiddenReason;
use Netresearch\NrMcpAgent\Service\AltText\ImageFieldDiscovery;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltText;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextScan;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The "missing alt texts" check (ADR-026) against one fixture
 * (missing_alt_texts.csv): each rule with a reference it lets through and one
 * it drops, the permission filter as non-admin editors, the record types,
 * the hidden reasons, the order and the counts.
 *
 * The fixture extension alt_text_test adds a record table with two image
 * fields and content elements inside its records ("Test articles", like
 * news), and an inline child of content elements ("Test carousel items").
 *
 * Users: 1 admin; 2 an editor with every right the check asks for, mounted
 * on "Wurzel" (1) and allowed the default language only; 3–6 the same editor
 * missing one right each (the article tables, the reference's alternative
 * text, the article image field, sys_file_reference); 7 an editor allowed
 * one content element type only.
 */
final class MissingAltTextScannerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
        __DIR__ . '/../../Fixtures/Extensions/alt_text_test',
    ];

    /** What the editor (user 2) may fix, in list order. */
    private const EDITOR_ORDER = [13, 10, 31, 35, 45, 1, 2, 4, 7, 32, 11, 22, 27, 14, 48, 15, 20, 21, 25, 47, 51, 52, 28, 40, 30, 29];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/missing_alt_texts.csv');
    }

    private function scanAs(int $userUid): MissingAltTextScan
    {
        $user            = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);

        return $this->scanner()->scanForCurrentUser();
    }

    /**
     * A container of content elements, as an extension declares it: an
     * inline field of tt_content on tt_content (gridelements, for example),
     * here content element 118 holding element 101, and 118 locked.
     */
    private function containContentElement101InLocked118(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['tx_alttexttest_children_grid'] = [
            'label'  => 'Children',
            'config' => ['type' => 'inline', 'foreign_table' => 'tt_content', 'foreign_field' => 'tx_alttexttest_container'],
        ];
        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $connection->update('tt_content', ['tx_alttexttest_container' => 118], ['uid' => 101]);
        $connection->update('tt_content', ['editlock' => 1], ['uid' => 118]);
    }

    /**
     * The list asks what the writer asks: a content element inside a locked
     * container of the same table keeps its image in the list.
     */
    #[Test]
    public function aLockedContainerOfTheSameTableKeepsTheImageListed(): void
    {
        $this->containContentElement101InLocked118();

        self::assertContains(10, $this->referenceUids($this->scanAs(2)));
    }

    /** Built here so each test reads the extension configuration as it sets it. */
    private function scanner(): MissingAltTextScanner
    {
        return new MissingAltTextScanner(
            new ImageFieldDiscovery(new ExtensionConfiguration()),
            new MissingAltTextRepository($this->get(ConnectionPool::class)),
            $this->get(Context::class),
        );
    }

    /**
     * @return list<int>
     */
    private function referenceUids(MissingAltTextScan $scan): array
    {
        return array_map(static fn (MissingAltText $hit): int => $hit->referenceUid, $scan->hits);
    }

    private function hit(MissingAltTextScan $scan, int $referenceUid): MissingAltText
    {
        foreach ($scan->hits as $hit) {
            if ($hit->referenceUid === $referenceUid) {
                return $hit;
            }
        }

        self::fail('Reference ' . $referenceUid . ' is not among the hits');
    }

    /**
     * Each rule: the reference it lets through, and the one it drops. Read as
     * admin, so no permission is in the way.
     *
     * @return iterable<string, array{int, bool}>
     */
    public static function rules(): iterable
    {
        yield 'no alt text at all is open' => [1, true];
        yield 'an alt text on the reference is done' => [3, false];
        yield 'an explicitly empty alt text is open' => [2, true];
        yield 'an alt text of blanks is open' => [4, true];
        yield 'a file that is no image is not checked' => [5, false];
        yield 'an alt text inherited from the file list is done' => [6, false];
        yield 'an empty alt text overriding the file list is open' => [7, true];
        yield 'a decorative image is done' => [8, false];
        yield 'a deleted reference is not checked' => [9, false];
        yield 'a deleted content element is not checked' => [12, false];
        yield 'a content element on a deleted page is not checked' => [24, false];
        yield 'a deleted record is not checked' => [38, false];
        yield 'the file list text in the reference language counts' => [33, false];
        yield 'an empty file list translation leaves it open' => [34, true];
        yield 'an empty default file list text leaves it open' => [35, true];
        yield 'the default file list text counts without a translation' => [36, false];
        yield 'a default-excluded field is not checked' => [26, false];
        yield 'a page property is checked' => [25, true];
        yield 'a record field is checked' => [28, true];
        yield 'a content element child is checked' => [27, true];
        yield 'a field the record type shows is checked' => [32, true];
        yield 'a field the record type does not show is not checked' => [43, false];
        yield 'a draft of the file list text does not decide' => [44, false];
        yield 'a draft of the file list translation does not decide' => [53, false];
        yield 'an empty file list text with an empty draft is open' => [45, true];
    }

    #[Test]
    #[DataProvider('rules')]
    public function aRuleDecidesWhetherTheReferenceIsOpen(int $referenceUid, bool $open): void
    {
        $uids = $this->referenceUids($this->scanAs(1));

        if ($open) {
            self::assertContains($referenceUid, $uids);
        } else {
            self::assertNotContains($referenceUid, $uids);
        }
    }

    #[Test]
    public function anAdminSeesEveryOpenHit(): void
    {
        $uids = $this->referenceUids($this->scanAs(1));
        sort($uids);

        self::assertSame([1, 2, 4, 7, 10, 11, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 25, 27, 28, 29, 30, 31, 32, 34, 35, 37, 39, 40, 41, 42, 45, 46, 47, 48, 49, 50, 51, 52, 54, 55], $uids);
    }

    /**
     * A question about one image reads that reference only, and the same
     * filter decides: an open hit of the editor, one the editor may not fix
     * (page outside the web mounts), one with an alt text.
     *
     * @return iterable<string, array{int, list<int>}>
     */
    public static function singleReferences(): iterable
    {
        yield 'an open hit of the editor' => [1, [1]];
        yield 'an image the editor may not fix' => [16, []];
        yield 'an image with an alt text' => [3, []];
    }

    /**
     * @param list<int> $expected
     */
    #[Test]
    #[DataProvider('singleReferences')]
    public function oneReferenceIsScannedAlone(int $referenceUid, array $expected): void
    {
        $user            = $this->setUpBackendUser(2);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);

        self::assertSame($expected, $this->referenceUids($this->scanner()->scan($user, $referenceUid)));
    }

    /** The editor's list in list order: page tree, content order, then the records by folder and title. */
    #[Test]
    public function theEditorsHitsAreInListOrder(): void
    {
        self::assertSame(self::EDITOR_ORDER, $this->referenceUids($this->scanAs(2)));
    }

    /**
     * Each permission as a non-admin editor: the hit the editor may fix, and
     * the one a missing permission drops.
     *
     * @return iterable<string, array{int, int, int}>
     */
    public static function permissions(): iterable
    {
        // [user, kept reference, dropped reference]
        yield 'a page outside the web mounts' => [2, 10, 16];
        yield 'a page without "edit content"' => [2, 10, 17];
        yield 'a page property on a page with "edit content" but not "edit page"' => [2, 47, 46];
        // The tool that writes the text asks both: the DataHandler, writing
        // the reference alone, asks "edit content" on its page.
        yield 'a page property on a page with "edit page" but not "edit content"' => [2, 25, 55];
        yield 'a record in a folder without "edit content"' => [2, 28, 49];
        yield 'a page locked for editing' => [2, 10, 18];
        yield 'a content element locked for editing' => [2, 10, 23];
        yield 'a record locked for editing' => [2, 28, 37];
        yield 'a content element in a language the editor may not edit' => [2, 10, 19];
        // The two below differ from it in one language each: the record's
        // and the reference's are checked on their own.
        yield 'a reference on a content element in a language the editor may not edit' => [2, 10, 41];
        yield 'a reference in a language the editor may not edit' => [2, 10, 42];
        yield 'a page translation in a language the editor may not edit' => [2, 25, 39];
        yield 'a record table the editor may not modify' => [3, 10, 28];
        yield 'a content element inside such a record' => [3, 10, 30];
        yield 'a child table the editor may not modify' => [3, 10, 27];
        yield 'an image field that is an excluded field and not granted' => [5, 40, 28];
        // The content type is a value the editor may be allowed or not
        // (authMode, explicit_allowdeny), which the alt text writer asks of
        // the element; user 7 may edit "Text & Images" elements only.
        yield 'a content element of a type the editor may not edit' => [7, 1, 10];
    }

    #[Test]
    #[DataProvider('permissions')]
    public function aPermissionTheEditorLacksDropsTheHit(int $userUid, int $kept, int $dropped): void
    {
        $uids = $this->referenceUids($this->scanAs($userUid));

        self::assertContains($kept, $uids);
        self::assertNotContains($dropped, $uids);
    }

    #[Test]
    public function withoutTheRightOnTheAlternativeTextNothingIsListed(): void
    {
        self::assertSame(0, $this->scanAs(4)->count());
        self::assertGreaterThan(0, $this->scanAs(2)->count());
    }

    #[Test]
    public function withoutModifyingImageReferencesNothingIsListed(): void
    {
        self::assertSame(0, $this->scanAs(6)->count());
    }

    /** Record types come from the TCA at runtime; images on pages, content elements and their children are "pages". */
    #[Test]
    public function aHitIsFiledUnderPagesOrUnderItsRecordType(): void
    {
        $scan = $this->scanAs(2);

        self::assertSame(MissingAltTextScan::PAGES, $this->hit($scan, 1)->type);
        self::assertSame(MissingAltTextScan::PAGES, $this->hit($scan, 25)->type);
        self::assertSame(MissingAltTextScan::PAGES, $this->hit($scan, 27)->type);
        self::assertSame('tx_alttexttest_item', $this->hit($scan, 28)->type);
        self::assertSame('tx_alttexttest_item', $this->hit($scan, 30)->type);
        self::assertSame(['pages' => 22, 'tx_alttexttest_item' => 4], $scan->countsByType());
    }

    #[Test]
    public function aHitIsGroupedUnderItsPageOrItsRecord(): void
    {
        $scan = $this->scanAs(2);

        $child = $this->hit($scan, 27);
        self::assertSame([10, 'Startseite', 116, 'carousel', 'Karussell', 'tx_alttexttest_child', 1], [$child->groupUid, $child->groupTitle, $child->elementUid, $child->elementType, $child->elementHeader, $child->table, $child->recordUid]);

        $inRecord = $this->hit($scan, 30);
        self::assertSame([1, 'Erster Artikel', 19, 'Ordner Presse', 'tt_content', 117], [$inRecord->groupUid, $inRecord->groupTitle, $inRecord->pageUid, $inRecord->pageTitle, $inRecord->table, $inRecord->recordUid]);

        $pageProperty = $this->hit($scan, 25);
        self::assertSame([22, 0, 'media', 'team.jpg'], [$pageProperty->groupUid, $pageProperty->elementUid, $pageProperty->field, $pageProperty->fileName]);
    }

    /** A translated page's own image is listed under the page in the default language. */
    #[Test]
    public function aPageTranslationsImageIsGroupedUnderTheDefaultPage(): void
    {
        $hit = $this->hit($this->scanAs(1), 39);

        self::assertSame([22, 1, 'pages', 23], [$hit->groupUid, $hit->languageUid, $hit->table, $hit->recordUid]);
    }

    /**
     * @return iterable<string, array{int, HiddenReason|null, int}>
     */
    public static function hiddenReasons(): iterable
    {
        yield 'visible' => [1, null, 0];
        yield 'hidden content element' => [11, HiddenReason::Element, 0];
        yield 'hidden page' => [14, HiddenReason::Page, 0];
        yield 'page under a hidden page that extends to subpages' => [15, HiddenReason::ParentPage, 0];
        yield 'page under a hidden page that does not extend to subpages' => [48, null, 0];
        yield 'page under an expired page that extends to subpages' => [51, HiddenReason::Expired, 946684800];
        yield 'page under a scheduled page that extends to subpages' => [52, HiddenReason::Scheduled, 4102444800];
        yield 'page with a future start time' => [20, HiddenReason::Scheduled, 4102444800];
        yield 'page past its end time' => [21, HiddenReason::Expired, 946684800];
        yield 'content element with a future start time' => [22, HiddenReason::Scheduled, 4102444800];
        yield 'hidden record' => [29, HiddenReason::Record, 0];
        yield 'hidden reference' => [31, HiddenReason::Image, 0];
    }

    /** Hidden and timed content is listed, with the reason it is not visible now. */
    #[Test]
    #[DataProvider('hiddenReasons')]
    public function aHitCarriesWhyItIsNotVisible(int $referenceUid, ?HiddenReason $reason, int $date): void
    {
        $hit = $this->hit($this->scanAs(2), $referenceUid);

        self::assertSame($reason, $hit->hiddenReason);
        self::assertSame($date, $hit->hiddenDate);
    }

    /**
     * Two ancestors that pass their visibility on: the one highest up names
     * the reason (an expired area above a hidden one). Pages the editor may
     * only view, so read as admin.
     */
    #[Test]
    public function theAncestorHighestUpNamesTheReason(): void
    {
        $hit = $this->hit($this->scanAs(1), 54);

        self::assertSame([HiddenReason::Expired, 946684800], [$hit->hiddenReason, $hit->hiddenDate]);
    }

    /** Content in a language counts its page translation: hidden there, the image is not shown. */
    #[Test]
    public function aHiddenPageTranslationHidesItsContent(): void
    {
        $hit = $this->hit($this->scanAs(1), 50);

        self::assertSame(HiddenReason::Page, $hit->hiddenReason);
    }

    #[Test]
    public function theCountsAgreeWithTheHits(): void
    {
        $scan = $this->scanAs(2);

        self::assertSame(count(self::EDITOR_ORDER), $scan->count());
        // Pages 10, 11, 12, 14, 20, 21, 22, 24, 25, 30 and 32; articles 1 and 2.
        self::assertSame(11, $scan->pageCount());
        self::assertSame(2, $scan->recordCount());
    }

    #[Test]
    public function withoutABackendUserTheScanIsEmpty(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertSame(0, $this->scanner()->scanForCurrentUser()->count());
    }

    /** A table and a field excluded in the extension configuration are not checked. */
    #[Test]
    public function configuredExclusionsAreNotChecked(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_mcp_agent']['altTextExcludedTables'] = 'tx_alttexttest_item';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_mcp_agent']['altTextExcludedFields'] = 'tt_content.assets';

        $uids = $this->referenceUids($this->scanAs(1));

        self::assertNotContains(28, $uids);
        // A content element inside an excluded record goes with it.
        self::assertNotContains(30, $uids);
        self::assertNotContains(32, $uids);
        // The configured list replaces the default one.
        self::assertContains(1, $uids);
    }
}
