<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrMcpAgent\Service\AltText\ReferenceWriteAccess;
use Netresearch\NrMcpAgent\Tool\SetDecorativeImageTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The decorative mark as a tool (ADR-026), against the fixture of the list of
 * missing alt texts (missing_alt_texts.csv; users as in
 * MissingAltTextScannerTest, the editor's group also granted the mark). It
 * refuses what nr-llm's alt text writer refuses, so no card is offered that
 * the DataHandler would then refuse.
 */
final class SetDecorativeImageToolTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
        __DIR__ . '/../Fixtures/Extensions/alt_text_test',
    ];

    private const EDITOR = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/missing_alt_texts.csv');
    }

    private function contextAs(int $userUid): ToolExecutionContext
    {
        $user            = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);

        return ToolExecutionContext::fromBackendUser($user);
    }

    private function tool(): SetDecorativeImageTool
    {
        $tool = $this->get(ToolRegistry::class)->get(SetDecorativeImageTool::NAME);
        self::assertInstanceOf(SetDecorativeImageTool::class, $tool);

        return $tool;
    }

    private function mark(int $referenceUid): int
    {
        $value = $this->getConnectionPool()->getConnectionForTable('sys_file_reference')
            ->select(['tx_nrmcpagent_decorative'], 'sys_file_reference', ['uid' => $referenceUid])
            ->fetchOne();

        return is_numeric($value) ? (int) $value : -1;
    }

    /**
     * Registered with nr-llm, in the writers' group, and like every writer
     * disabled until an administrator enables it; a write, so nr-llm pauses
     * every call for an approval card.
     */
    #[Test]
    public function itIsADisabledWriterInTheEditingGroup(): void
    {
        $tool     = $this->tool();
        $decision = $this->get(ToolCallPolicyInterface::class)->decide(SetDecorativeImageTool::NAME, new LlmConfiguration(), $this->setUpBackendUser(self::EDITOR));

        self::assertSame('editing', $tool->getGroup());
        self::assertTrue($tool->getEffect()->isWrite());
        self::assertFalse($tool->requiresAdmin());
        self::assertSame([false, ToolDenialReason::TOOL_DISABLED], [$decision->allowed, $decision->reason]);
    }

    /** The editor marks an image of a content element, and takes the mark off again. */
    #[Test]
    public function theEditorSetsAndClearsTheMark(): void
    {
        $context = $this->contextAs(self::EDITOR);

        $set = $this->tool()->execute(['reference' => 1, 'decorative' => true], $context);
        self::assertFalse($set->isError, $set->content);
        self::assertSame(1, $this->mark(1));

        $cleared = $this->tool()->execute(['reference' => 1, 'decorative' => false], $context);
        self::assertFalse($cleared->isError, $cleared->content);
        self::assertSame(0, $this->mark(1));
    }

    /** A page's own image, a record's image and a carousel item's image are written alike. */
    #[Test]
    public function imagesOfPagesRecordsAndChildrenAreMarked(): void
    {
        $context = $this->contextAs(1);

        foreach ([25, 28, 27] as $referenceUid) {
            $result = $this->tool()->execute(['reference' => $referenceUid, 'decorative' => true], $context);
            self::assertFalse($result->isError, $referenceUid . ': ' . $result->content);
            self::assertSame(1, $this->mark($referenceUid));
        }
    }

    /**
     * Each refusal, with the editor otherwise allowed everything: the
     * reference, its message, and that nothing was written.
     *
     * @return iterable<string, array{int, int, string}>
     */
    public static function refusals(): iterable
    {
        // [user, reference, refusal]
        yield 'a reference that does not exist' => [self::EDITOR, 99999, ReferenceWriteAccess::NOT_PERMITTED];
        yield 'a page outside the web mounts' => [self::EDITOR, 16, ReferenceWriteAccess::NOT_PERMITTED];
        yield 'a page without "edit content"' => [self::EDITOR, 17, ReferenceWriteAccess::NOT_PERMITTED];
        yield 'a page property without "edit page"' => [self::EDITOR, 46, ReferenceWriteAccess::NOT_PERMITTED];
        yield 'a page property without "edit content"' => [self::EDITOR, 55, 'needs both "edit page" and "edit content" on page [36]'];
        yield 'a page locked for editing' => [self::EDITOR, 18, 'page [18] is locked for editing'];
        yield 'a content element locked for editing' => [self::EDITOR, 23, ReferenceWriteAccess::NOT_PERMITTED];
        yield 'a record locked for editing' => [self::EDITOR, 37, ReferenceWriteAccess::NOT_PERMITTED];
        yield 'a reference in a language the editor may not edit' => [self::EDITOR, 42, 'is in language 1'];
        yield 'a content element in a language the editor may not edit' => [self::EDITOR, 41, ReferenceWriteAccess::NOT_PERMITTED];
        yield 'a content element of a type the editor may not edit' => [7, 10, ReferenceWriteAccess::NOT_PERMITTED];
        yield 'a record table the editor may not modify' => [3, 28, ReferenceWriteAccess::NOT_PERMITTED];
        yield 'without modifying image references' => [6, 1, ReferenceWriteAccess::NOT_PERMITTED];
        yield 'without the grant for the mark' => [4, 1, 'sys_file_reference:tx_nrmcpagent_decorative'];
        yield 'without the grant for the image field' => [5, 28, 'tx_alttexttest_item:image'];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function aRightTheEditorLacksRefusesTheCall(int $userUid, int $referenceUid, string $refusal): void
    {
        $before = $this->mark($referenceUid);
        $result = $this->tool()->execute(['reference' => $referenceUid, 'decorative' => true], $this->contextAs($userUid));

        self::assertTrue($result->isError);
        self::assertStringContainsString($refusal, $result->content);
        self::assertSame($before, $this->mark($referenceUid));
        // The card says the same before anyone decides.
        self::assertStringContainsString($refusal, implode("\n", $this->tool()->previewCall(['reference' => $referenceUid, 'decorative' => true], $this->contextAs($userUid))));
    }

    /**
     * A carousel item's image is refused when its content element is locked
     * or of a type the editor may not use, as the list hides it then; the
     * editor may mark it otherwise.
     *
     * @return iterable<string, array{string, array<string, int|string>, array<string, int>, bool}>
     */
    public static function outerContentElements(): iterable
    {
        yield 'the content element as it is' => ['tt_content', ['editlock' => 0], ['uid' => 116], true];
        yield 'the content element locked for editing' => ['tt_content', ['editlock' => 1], ['uid' => 116], false];
        yield 'the content element of a type the editor may not use' => [
            'be_groups',
            ['explicit_allowdeny' => 'tt_content:CType:textpic,tt_content:CType:image,tt_content:CType:text,tt_content:CType:textmedia'],
            ['uid' => 1],
            false,
        ];
    }

    /**
     * @param array<string, int|string> $values
     * @param array<string, int>        $where
     */
    #[Test]
    #[DataProvider('outerContentElements')]
    public function theOuterContentElementDecidesForACarouselItemsImage(string $table, array $values, array $where, bool $allowed): void
    {
        $this->getConnectionPool()->getConnectionForTable($table)->update($table, $values, $where);

        $result = $this->tool()->execute(['reference' => 27, 'decorative' => true], $this->contextAs(self::EDITOR));

        self::assertSame(!$allowed, $result->isError, $result->content);
        self::assertSame($allowed ? 1 : 0, $this->mark(27));
    }

    /**
     * The write goes through nr-llm's ToolDataHandler (nr-llm ADR-206): a
     * hook of the installation that fails after the write no longer turns a
     * landed mark into a failed call, which the model would retry.
     */
    #[Test]
    public function aHookFailingAfterTheWriteDoesNotFailTheCall(): void
    {
        $hook = new class {
            public function processDatamap_afterAllOperations(): never
            {
                throw new RuntimeException('A hook of the installation failed after the write.', 1790000001);
            }
        };
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['nrMcpAgentTest'] = $hook::class;

        $result = $this->tool()->execute(['reference' => 1, 'decorative' => true], $this->contextAs(self::EDITOR));

        self::assertFalse($result->isError, $result->content);
        self::assertSame(1, $this->mark(1));
    }

    /** The tool writes the live workspace only, as nr-llm's writers do. */
    #[Test]
    public function aDraftWorkspaceIsRefused(): void
    {
        $context                                 = $this->contextAs(self::EDITOR);
        $context->actingBackendUser()->workspace = 1;

        $result = $this->tool()->execute(['reference' => 1, 'decorative' => true], $context);

        self::assertTrue($result->isError);
        self::assertStringContainsString('live workspace only', $result->content);
        self::assertSame(0, $this->mark(1));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedCalls(): iterable
    {
        yield 'no reference' => [['decorative' => true]];
        yield 'a reference of 0' => [['reference' => 0, 'decorative' => true]];
        yield 'a decorative flag that is no boolean' => [['reference' => 1, 'decorative' => 'yes']];
        yield 'an argument it does not take' => [['reference' => 1, 'decorative' => true, 'alternative' => '']];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('malformedCalls')]
    public function aMalformedCallWritesNothing(array $arguments): void
    {
        $result = $this->tool()->execute($arguments, $this->contextAs(self::EDITOR));

        self::assertTrue($result->isError);
        self::assertSame(0, $this->mark(1));
    }

    /**
     * The card names the image use; for an image that belongs to a section
     * or a record it warns that the image probably needs an alt text (case 2
     * of the specification). An image without a section gets no warning.
     *
     * @return iterable<string, array{int, list<string>}>
     */
    public static function cards(): iterable
    {
        $warning = 'Decorative images get no alt text. Screen readers then skip them. That only fits if the image adds nothing to the content. ';
        yield 'an image of a content element with a header' => [1, [
            'Mark the image as decorative',
            'Page Content: “Unser Team”',
            'On page: “Startseite”',
            'Field: Images',
            'File: “team.jpg”',
            $warning . 'This image belongs to the section “Unser Team” and therefore probably needs an alt text. Mark it as decorative anyway?',
        ]];
        yield 'a carousel item: the section is its content element' => [27, [
            'Mark the image as decorative',
            'Test carousel items: “Slide 1”',
            'On page: “Startseite”',
            'Field: Image',
            'File: “team.jpg”',
            $warning . 'This image belongs to the section “Karussell” and therefore probably needs an alt text. Mark it as decorative anyway?',
        ]];
        yield 'a record: named by its type and title' => [28, [
            'Mark the image as decorative',
            'Test articles: “Erster Artikel”',
            'On page: “Ordner Presse”',
            'Field: Images',
            'File: “team.jpg”',
            $warning . 'This image belongs to the Test articles “Erster Artikel” and therefore probably needs an alt text. Mark it as decorative anyway?',
        ]];
        yield 'a content element without a header: no warning' => [10, [
            'Mark the image as decorative',
            'Page Content: “[No title]”',
            'On page: “Startseite”',
            'Field: Images',
            'File: “team.jpg”',
        ]];
    }

    /**
     * @param list<string> $lines
     */
    #[Test]
    #[DataProvider('cards')]
    public function theCardNamesTheImageAndWarnsWhereItBelongsToContent(int $referenceUid, array $lines): void
    {
        self::assertSame($lines, $this->tool()->previewCall(['reference' => $referenceUid, 'decorative' => true], $this->contextAs(1)));
    }

    #[Test]
    public function takingTheMarkOffWarnsOfNothing(): void
    {
        $lines = $this->tool()->previewCall(['reference' => 1, 'decorative' => false], $this->contextAs(self::EDITOR));

        self::assertSame('Take the decorative mark off the image', $lines[0]);
        self::assertCount(5, $lines);
    }

    /** Whoever looks at the card sees it only for an image they may change themselves. */
    #[Test]
    public function theCardIsShownToAViewerWhoMayChangeTheImage(): void
    {
        $tool = $this->tool();

        self::assertTrue($tool->mayViewerReadPreview(['reference' => 1, 'decorative' => true], $this->setUpBackendUser(self::EDITOR)));
        self::assertFalse($tool->mayViewerReadPreview(['reference' => 1, 'decorative' => true], $this->setUpBackendUser(6)));
        self::assertFalse($tool->mayViewerReadPreview(['reference' => 'x'], $this->setUpBackendUser(1)));
    }
}
