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
        'Ort: unter „Product page“',
        'Titel: „test“',
        'Technische Details: Seite 157, Tabelle pages',
    ];

    #[Test]
    public function aDeclaredActionIsNamedInTheReadersLanguage(): void
    {
        $call = new PendingCallView('create_page_draft', '{"parent":157}', true, self::PREVIEW);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], $this->german());

        self::assertSame('Seite als Entwurf anlegen', $presented[0]['actionLabel']);
        self::assertSame('create_page_draft', $presented[0]['name']);
        self::assertSame('{"parent":157}', $presented[0]['argumentsJson']);
        self::assertTrue($presented[0]['toolStillRegistered']);
        self::assertFalse($presented[0]['previewFailed']);
        self::assertFalse($presented[0]['previewStale']);
    }

    #[Test]
    public function aToolWithoutADeclarationGetsAnEmptyLabel(): void
    {
        $call = new PendingCallView('delete_record', '{}', true, ['Seite: „Alt“']);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], $this->german());

        self::assertSame('', $presented[0]['actionLabel']);
    }

    #[Test]
    public function withoutALabelSourceOrALanguageNothingIsNamed(): void
    {
        $call = new PendingCallView('create_page_draft', '{}', true, self::PREVIEW);

        self::assertSame('', (new ApprovalCallPresenter())->present([$call], $this->german())[0]['actionLabel']);
        self::assertSame('', (new ApprovalCallPresenter($this->labels()))->present([$call], null)[0]['actionLabel']);
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
        $lines = ['Ort: unter „Product page“', 'Sichtbarkeit: zunächst verborgen, erste Unterseite'];
        $call  = new PendingCallView('create_page_draft', '{}', true, $lines);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], $this->german());

        self::assertSame($lines, $presented[0]['previewLines']);
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

    /** A failed or withheld preview carries its reason in the lines; they stay whole. */
    #[Test]
    public function aFailedPreviewKeepsAllItsLines(): void
    {
        $call = new PendingCallView('create_page_draft', '{}', true, self::PREVIEW, previewFailed: true);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], $this->german());

        self::assertSame(self::PREVIEW, $presented[0]['previewLines']);
        self::assertSame('', $presented[0]['technicalDetails']);
        self::assertTrue($presented[0]['previewFailed']);
    }

    /** Without the reader's language the label is unknown, so nothing is guessed. */
    #[Test]
    public function withoutALanguageNoLineIsMoved(): void
    {
        $call = new PendingCallView('create_page_draft', '{}', true, self::PREVIEW);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], null);

        self::assertSame(self::PREVIEW, $presented[0]['previewLines']);
    }

    /** A label line with nothing after its prefix carries no identifiers to show. */
    #[Test]
    public function aTechnicalLineWithoutIdentifiersStays(): void
    {
        $lines = ['Titel: „test“', 'Technische Details: '];
        $call  = new PendingCallView('create_page_draft', '{}', true, $lines);

        $presented = (new ApprovalCallPresenter($this->labels()))->present([$call], $this->german());

        self::assertSame($lines, $presented[0]['previewLines']);
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
        $matching = new PendingCallView('a', '{}', true, ['Titel', '(Technisch: Seite 1)']);
        $open     = new PendingCallView('b', '{}', true, ['Titel', '(Technisch: Seite 1']);

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
        $call = new PendingCallView('a', '{}', true, ['Titel', 'Seite 1']);

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
