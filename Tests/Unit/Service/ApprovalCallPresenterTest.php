<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Service\Agent\Inbox\PendingCallView;
use Netresearch\NrMcpAgent\Service\ApprovalCallPresenter;
use Netresearch\NrMcpAgent\Service\EditorActionLabelsInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * What the approval card receives per pending call: the action's human name
 * and nr-llm's technical preview line moved out of the lines an editor reads.
 */
#[CoversClass(ApprovalCallPresenter::class)]
final class ApprovalCallPresenterTest extends TestCase
{
    private const LABEL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.create_page_draft.label';

    /**
     * A German reader: the editor action label and nr-llm's technical-details
     * label resolve, everything else does not (sL() answers '').
     */
    private function german(): LanguageService
    {
        $language = $this->createMock(LanguageService::class);
        $language->method('sL')->willReturnCallback(static fn(string $reference): string => match ($reference) {
            self::LABEL                                  => ' Seite als Entwurf anlegen ',
            ApprovalCallPresenter::TECHNICAL_DETAILS_LABEL => 'Technische Details: %s',
            default                                      => '',
        });

        return $language;
    }

    private function labels(): EditorActionLabelsInterface
    {
        $labels = $this->createMock(EditorActionLabelsInterface::class);
        $labels->method('labelReferences')->willReturn(['create_page_draft' => self::LABEL]);

        return $labels;
    }

    private const PREVIEW = [
        'Neue Seite als Entwurf anlegen',
        'Ort: unter „Product page“',
        'Titel: „test“',
        'Technische Details: Seite 157, Tabelle pages',
    ];

    /** Without a usable preview the declared label, in the reader's language, names the change. */
    #[Test]
    public function aDeclaredActionNamesACallWhosePreviewFailed(): void
    {
        $call = new PendingCallView('create_page_draft', '{"parent":157}', true, ['Die Vorschau ist fehlgeschlagen.'], previewFailed: true);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], $this->german());

        self::assertSame('Seite als Entwurf anlegen', $presented[0]['actionLabel']);
        self::assertSame('create_page_draft', $presented[0]['name']);
        self::assertSame('{"parent":157}', $presented[0]['argumentsJson']);
        self::assertTrue($presented[0]['toolStillRegistered']);
        self::assertTrue($presented[0]['previewFailed']);
        self::assertFalse($presented[0]['previewStale']);
        self::assertFalse($presented[0]['actionLabelFromPreview']);
    }

    #[Test]
    public function aDeclaredActionNamesACallWithoutPreview(): void
    {
        $call = new PendingCallView('create_page_draft', '{}', true);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], $this->german());

        self::assertSame('Seite als Entwurf anlegen', $presented[0]['actionLabel']);
        self::assertSame([], $presented[0]['previewLines']);
    }

    /**
     * nr-llm declares no editor action for delete_record, but its preview
     * opens with the change's name (rule 16). That line names the card and
     * the button, and is not repeated below.
     */
    #[Test]
    public function aToolWithoutADeclarationIsNamedByItsPreviewsFirstLine(): void
    {
        $call = new PendingCallView('delete_record', '{}', true, ['Seite löschen', 'Seite: „Alt“', 'Technische Details: Seite 3']);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], $this->german());

        self::assertSame('Seite löschen', $presented[0]['actionLabel']);
        self::assertTrue($presented[0]['actionLabelFromPreview']);
        self::assertSame(['Seite: „Alt“'], $presented[0]['previewLines']);
        self::assertSame('Seite 3', $presented[0]['technicalDetails']);
    }

    /**
     * The preview's first line is per call and more specific than the tool's
     * label, so it wins even where a label is declared; showing both would
     * repeat the heading.
     */
    #[Test]
    public function thePreviewsFirstLineWinsOverADeclaredLabel(): void
    {
        $call = new PendingCallView('create_page_draft', '{}', true, self::PREVIEW);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], $this->german());

        self::assertSame('Neue Seite als Entwurf anlegen', $presented[0]['actionLabel']);
        self::assertTrue($presented[0]['actionLabelFromPreview']);
        self::assertSame(['Ort: unter „Product page“', 'Titel: „test“'], $presented[0]['previewLines']);
    }

    /** A preview made of the change's name alone still names the card. */
    #[Test]
    public function aPreviewOfOnlyTheChangesNameBecomesTheHeading(): void
    {
        $call = new PendingCallView('delete_record', '{}', true, ['Seite löschen']);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], $this->german());

        self::assertSame('Seite löschen', $presented[0]['actionLabel']);
        self::assertSame([], $presented[0]['previewLines']);
    }

    /** Two lines, the name and the identifiers: both leave the body. */
    #[Test]
    public function aNameAndATechnicalLineLeaveNothingInTheBody(): void
    {
        $call = new PendingCallView('delete_record', '{}', true, ['Seite löschen', 'Technische Details: Seite 3']);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], $this->german());

        self::assertSame('Seite löschen', $presented[0]['actionLabel']);
        self::assertSame([], $presented[0]['previewLines']);
        self::assertSame('Seite 3', $presented[0]['technicalDetails']);
    }

    /** Without a label source or a language the declaration cannot be read; the preview names the change. */
    #[Test]
    public function withoutALabelSourceOrALanguageThePreviewNamesTheChange(): void
    {
        $call = new PendingCallView('create_page_draft', '{}', true, self::PREVIEW);

        self::assertSame('Neue Seite als Entwurf anlegen', (new ApprovalCallPresenter())->present([$call], $this->german())[0]['actionLabel']);
        self::assertSame('Neue Seite als Entwurf anlegen', (new ApprovalCallPresenter($this->labels()))->present([$call], null)[0]['actionLabel']);
    }

    #[Test]
    public function theTechnicalLastLineMovesOutOfThePreviewLines(): void
    {
        $call = new PendingCallView('create_page_draft', '{}', true, self::PREVIEW, previewStale: true);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], $this->german());

        self::assertSame(['Ort: unter „Product page“', 'Titel: „test“'], $presented[0]['previewLines']);
        self::assertSame('Seite 157, Tabelle pages', $presented[0]['technicalDetails']);
        self::assertTrue($presented[0]['previewStale']);
    }

    /**
     * nr-llm before 0.39 and tools outside nr-llm end on an ordinary line.
     * Taking the last line by position would hide part of what is decided.
     */
    #[Test]
    public function anOrdinaryLastLineStaysWhereItIs(): void
    {
        // Longer than the label's prefix, so a test that only looked at the
        // end of the line would take it for the technical one.
        $lines = ['Neue Seite als Entwurf anlegen', 'Ort: unter „Product page“', 'Sichtbarkeit: zunächst verborgen, erste Unterseite'];
        $call  = new PendingCallView('create_page_draft', '{}', true, $lines);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], $this->german());

        self::assertSame(array_slice($lines, 1), $presented[0]['previewLines']);
        self::assertSame('', $presented[0]['technicalDetails']);
    }

    /** The label is matched as the last line only; an earlier match is content. */
    #[Test]
    public function aTechnicalLookingLineBeforeTheEndIsNotMoved(): void
    {
        $lines = ['Technische Details: Seite 157', 'Titel: „test“'];
        $call  = new PendingCallView('create_page_draft', '{}', true, $lines);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], $this->german());

        self::assertSame($lines, $presented[0]['previewLines']);
    }

    /** Moving the only line would leave a card that shows nothing of what is decided. */
    #[Test]
    public function aPreviewOfOnlyTheTechnicalLineKeepsIt(): void
    {
        $lines = ['Technische Details: Seite 157, Tabelle pages'];
        $call  = new PendingCallView('create_page_draft', '{}', true, $lines);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], $this->german());

        self::assertSame($lines, $presented[0]['previewLines']);
        self::assertSame('', $presented[0]['technicalDetails']);
    }

    /** The technical line never becomes the heading, even where nothing else names the change. */
    #[Test]
    public function aLoneTechnicalLineIsNotTakenAsTheHeading(): void
    {
        $lines = ['Technische Details: Seite 157'];
        $call  = new PendingCallView('delete_record', '{}', true, $lines);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], $this->german());

        self::assertSame('', $presented[0]['actionLabel']);
        self::assertFalse($presented[0]['actionLabelFromPreview']);
        self::assertSame($lines, $presented[0]['previewLines']);
    }

    /** A failed or withheld preview carries its reason in the lines; they stay whole. */
    #[Test]
    public function aFailedPreviewKeepsAllItsLines(): void
    {
        $call = new PendingCallView('delete_record', '{}', true, self::PREVIEW, previewFailed: true);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], $this->german());

        self::assertSame(self::PREVIEW, $presented[0]['previewLines']);
        self::assertSame('', $presented[0]['technicalDetails']);
        self::assertTrue($presented[0]['previewFailed']);
        // Its first line is a reason, not the change's name.
        self::assertFalse($presented[0]['actionLabelFromPreview']);
        self::assertSame('', $presented[0]['actionLabel']);
    }

    /** Without the reader's language the label is unknown, so nothing is guessed. */
    #[Test]
    public function withoutALanguageNoLineIsMoved(): void
    {
        $call = new PendingCallView('create_page_draft', '{}', true, self::PREVIEW);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], null);

        // The first line names the change; the technical last line stays.
        self::assertSame(array_slice(self::PREVIEW, 1), $presented[0]['previewLines']);
        self::assertSame('', $presented[0]['technicalDetails']);
    }

    /** A label line with nothing after its prefix carries no identifiers to show. */
    #[Test]
    public function aTechnicalLineWithoutIdentifiersStays(): void
    {
        $lines = ['Neue Seite als Entwurf anlegen', 'Titel: „test“', 'Technische Details: '];
        $call  = new PendingCallView('create_page_draft', '{}', true, $lines);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], $this->german());

        self::assertSame(array_slice($lines, 1), $presented[0]['previewLines']);
        self::assertSame('', $presented[0]['technicalDetails']);
    }

    /** A label text around the placeholder on both sides is matched on both sides. */
    #[Test]
    public function aLabelWithTextAfterThePlaceholderIsMatchedOnBothEnds(): void
    {
        $language = $this->createMock(LanguageService::class);
        $language->method('sL')->willReturnCallback(static fn(string $reference): string => match ($reference) {
            ApprovalCallPresenter::TECHNICAL_DETAILS_LABEL => '(Technisch: %s)',
            default                                      => '',
        });
        $matching = new PendingCallView('a', '{}', true, ['Ändern', 'Titel', '(Technisch: Seite 1)']);
        $open     = new PendingCallView('b', '{}', true, ['Ändern', 'Titel', '(Technisch: Seite 1']);

        $presented = (new ApprovalCallPresenter())->present([$matching, $open], $language);

        self::assertSame(['Titel'], $presented[0]['previewLines']);
        self::assertSame('Seite 1', $presented[0]['technicalDetails']);
        self::assertSame(['Titel', '(Technisch: Seite 1'], $presented[1]['previewLines']);
    }

    /** A label that is nothing but its placeholder would match every line; it matches none. */
    #[Test]
    public function aLabelWithoutTextAroundThePlaceholderMovesNothing(): void
    {
        $language = $this->createMock(LanguageService::class);
        $language->method('sL')->willReturn('%s');
        $call = new PendingCallView('a', '{}', true, ['Ändern', 'Titel', 'Seite 1']);

        $presented = (new ApprovalCallPresenter())->present([$call], $language);

        self::assertSame(['Titel', 'Seite 1'], $presented[0]['previewLines']);
        self::assertSame('', $presented[0]['technicalDetails']);
    }

    #[Test]
    public function noPreviewStaysNoPreview(): void
    {
        $call = new PendingCallView('remote_tool', '{}', false);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], $this->german());

        self::assertSame([], $presented[0]['previewLines']);
        self::assertSame('', $presented[0]['technicalDetails']);
        self::assertFalse($presented[0]['toolStillRegistered']);
    }
}
