<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\ValueObject\PendingWriteTarget;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Service\Agent\Inbox\PendingCallView;
use Netresearch\NrMcpAgent\Service\ApprovalCallPresenter;
use Netresearch\NrMcpAgent\Service\EditorActionLabelsInterface;
use Netresearch\NrMcpAgent\Service\StructuredPreview;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * What the proposal block of a process card receives (ADR-023): what the
 * write is about, from nr-llm's structured target, and its current and
 * proposed values only where nr-llm structures them — never parsed from the
 * preview lines.
 */
#[CoversClass(ApprovalCallPresenter::class)]
#[CoversClass(StructuredPreview::class)]
final class ApprovalProposalTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TCA']['pages'] = [
            'ctrl' => ['title' => 'LLL:core:pages.title'],
            'columns' => [
                'description' => ['label' => 'LLL:core:pages.description'],
                'title' => ['label' => 'LLL:core:pages.title_field'],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
        parent::tearDown();
    }

    private function german(): LanguageService
    {
        $language = $this->createMock(LanguageService::class);
        $language->method('sL')->willReturnCallback(static fn(string $reference): string => match ($reference) {
            'LLL:core:pages.title'       => 'Seite',
            'LLL:core:pages.description' => 'Beschreibung',
            default                      => '',
        });

        return $language;
    }

    private static function requireTarget(): void
    {
        if (!property_exists(PendingCallView::class, 'pendingTarget')) {
            self::markTestSkipped('Needs nr-llm 0.41 (PendingCallView::$pendingTarget, nr-llm PR 1024).');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PendingCallView $call): array
    {
        return (new ApprovalCallPresenter())->present([$call], $this->german(), null)[0];
    }

    #[Test]
    public function theAffectedRecordIsNrLlmsTargetWithTheSchemasLabels(): void
    {
        self::requireTarget();
        $call = new PendingCallView('update_page_metadata', '{}', true, ['Seite: „Home“'], pendingTarget: new PendingWriteTarget(new RecordReference('pages', 10002), ['title', 'description']));

        self::assertSame(
            ['table' => 'pages', 'uid' => 10002, 'fields' => ['description', 'title'], 'tableLabel' => 'Seite', 'fieldLabels' => ['Beschreibung', 'title']],
            $this->present($call)['affected'],
            'a field without a label in the reader\'s language keeps its name',
        );
    }

    #[Test]
    public function aWriteWithoutATargetAffectsNothingNamed(): void
    {
        $call = property_exists(PendingCallView::class, 'pendingTarget')
            ? new PendingCallView('create_page_draft', '{}', true, ['Neue Seite'], pendingTarget: null)
            : new PendingCallView('create_page_draft', '{}', true, ['Neue Seite']);

        self::assertNull($this->present($call)['affected']);
    }

    /** No nr-llm carries a structured preview yet: the card shows the lines. */
    #[Test]
    public function withoutAStructuredPreviewTheLinesStay(): void
    {
        $presented = $this->present(new PendingCallView('update_page_metadata', '{}', true, ['Seite: „Home“', 'Beschreibung: (leer) → „Neu“']));

        self::assertNull($presented['structured']);
        self::assertSame(['Seite: „Home“', 'Beschreibung: (leer) → „Neu“'], $presented['previewLines']);
    }

    #[Test]
    public function theStructuredEntriesKeepLabelValuesAndMeasure(): void
    {
        $entries = [
            ['field' => 'description', 'label' => 'Beschreibung', 'current' => null, 'proposed' => 'Neu', 'measure' => ['count' => 152, 'min' => 140, 'max' => 160]],
            ['field' => 'title', 'label' => 'Titel', 'current' => 'Home', 'proposed' => 'Start', 'measure' => null],
            ['field' => 'abstract', 'label' => '', 'current' => '', 'proposed' => 'Kurz', 'measure' => ['count' => 4, 'min' => null, 'max' => null]],
        ];

        self::assertSame([
            ['field' => 'description', 'label' => 'Beschreibung', 'current' => null, 'proposed' => 'Neu', 'measure' => ['count' => 152, 'min' => 140, 'max' => 160]],
            ['field' => 'title', 'label' => 'Titel', 'current' => 'Home', 'proposed' => 'Start', 'measure' => null],
            ['field' => 'abstract', 'label' => '', 'current' => '', 'proposed' => 'Kurz', 'measure' => ['count' => 4, 'min' => null, 'max' => null]],
        ], StructuredPreview::fromEntries($entries));
    }

    /**
     * Raw values pass through unchanged: the chat renders them as text, so a
     * script in a proposed value or in stored rich text is shown, not run.
     */
    #[Test]
    public function rawValuesPassThroughUnchanged(): void
    {
        $structured = StructuredPreview::fromEntries([
            ['field' => 'bodytext', 'label' => 'Text', 'current' => '<p>Alt</p>', 'proposed' => '<script>alert(1)</script>', 'measure' => null],
        ]);

        self::assertSame('<p>Alt</p>', $structured[0]['current'] ?? null);
        self::assertSame('<script>alert(1)</script>', $structured[0]['proposed'] ?? null);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unshapedEntries(): iterable
    {
        yield 'none' => [[]];
        yield 'not a list' => ['description: Neu'];
        yield 'an object, not nr-llm\'s array' => [[(object) ['field' => 'description', 'current' => '', 'proposed' => 'Neu']]];
        yield 'a proposed value that is no string' => [[['field' => 'description', 'current' => '', 'proposed' => 3]]];
        yield 'a current value that is no string' => [[['field' => 'description', 'current' => 3, 'proposed' => 'Neu']]];
        yield 'no field' => [[['current' => '', 'proposed' => 'Neu']]];
        yield 'an empty field' => [[['field' => '', 'current' => '', 'proposed' => 'Neu']]];
        yield 'one good, one not' => [[['field' => 'title', 'current' => 'a', 'proposed' => 'b'], ['field' => 'description']]];
    }

    /** One entry shaped otherwise: the card falls back to the lines, it does not guess. */
    #[Test]
    #[DataProvider('unshapedEntries')]
    public function anEntryShapedOtherwiseFallsBackToTheLines(mixed $entries): void
    {
        self::assertNull(StructuredPreview::fromEntries($entries));
    }

    /**
     * Against nr-llm's own view (PR 1036): the presenter passes on what
     * `structuredPreviewArray()` returns, labels and raw values included.
     */
    #[Test]
    public function thePresenterPassesOnNrLlmsStructuredPreview(): void
    {
        if (!method_exists(PendingCallView::class, 'structuredPreviewArray')) {
            self::markTestSkipped('Needs nr-llm with PendingCallView::structuredPreviewArray() (nr-llm PR 1036).');
        }

        $proposal = 'Netresearch\\NrLlm\\Domain\\ValueObject\\FieldProposal';
        $measure = 'Netresearch\\NrLlm\\Domain\\ValueObject\\FieldMeasure';
        $call = new PendingCallView('update_page_metadata', '{}', true, ['Seite: „Home“'], structuredPreview: [
            new $proposal('description', 'Beschreibung', null, '<script>alert(1)</script>', new $measure(25, 140, 160)),
        ]);

        self::assertSame(
            [['field' => 'description', 'label' => 'Beschreibung', 'current' => null, 'proposed' => '<script>alert(1)</script>', 'measure' => ['count' => 25, 'min' => 140, 'max' => 160]]],
            $this->present($call)['structured'],
        );
    }

    /** A failed preview has no structure to show, whatever nr-llm carries. */
    #[Test]
    public function aFailedPreviewShowsNoStructure(): void
    {
        if (!method_exists(PendingCallView::class, 'structuredPreviewArray')) {
            self::markTestSkipped('Needs nr-llm with PendingCallView::structuredPreviewArray() (nr-llm PR 1036).');
        }

        $proposal = 'Netresearch\\NrLlm\\Domain\\ValueObject\\FieldProposal';
        $call = new PendingCallView('update_page_metadata', '{}', true, ['Keine Vorschau'], previewFailed: true, structuredPreview: [
            new $proposal('description', 'Beschreibung', null, 'Neu'),
        ]);

        self::assertNull($this->present($call)['structured']);
    }

    /**
     * The status lines and the summary name what a proposal was about in the
     * reader's language: the fields' labels and the record, or, for a create,
     * the change's name.
     */
    #[Test]
    public function outcomesAreNamedByTheSchemasLabelsOrTheChangesName(): void
    {
        $labels = $this->createMock(EditorActionLabelsInterface::class);
        $labels->method('labelReferences')->willReturn(['create_page_draft' => 'LLL:create']);
        $language = $this->createMock(LanguageService::class);
        $language->method('sL')->willReturnCallback(static fn(string $reference): string => match ($reference) {
            'LLL:core:pages.title' => 'Seite',
            'LLL:core:pages.description' => 'Beschreibung',
            'LLL:create' => 'Seite anlegen',
            default => '',
        });

        $presented = (new ApprovalCallPresenter($labels))->presentOutcomes([
            ['outcome' => 'applied', 'after' => 2, 'tool' => 'update_page_metadata', 'table' => 'pages', 'uid' => 3, 'fields' => ['description']],
            ['outcome' => 'skipped', 'after' => 4, 'tool' => 'create_page_draft', 'table' => '', 'uid' => 0, 'fields' => []],
        ], $language);

        self::assertSame([
            ['outcome' => 'applied', 'after' => 2, 'subject' => 'Beschreibung', 'record' => 'Seite 3'],
            ['outcome' => 'skipped', 'after' => 4, 'subject' => 'Seite anlegen', 'record' => ''],
        ], $presented);
    }

    #[Test]
    public function aMeasureShapedOtherwiseIsLeftOut(): void
    {
        $structured = StructuredPreview::fromEntries([['field' => 'description', 'current' => '', 'proposed' => 'Neu', 'measure' => ['count' => '152', 'min' => 140, 'max' => 160]]]);

        self::assertNotNull($structured);
        self::assertSame('Neu', $structured[0]['proposed']);
        self::assertNull($structured[0]['measure']);
    }
}
