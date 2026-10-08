<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Service\InputPause;
use Netresearch\NrMcpAgent\Service\InputPauseForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How a question's schema becomes reply buttons or a form, and which answers
 * the chat accepts for it (ADR-018).
 *
 * The acceptance half matters as much as the rendering half: nr-llm checks an
 * answer by structure only, so the option check here is the only thing that
 * keeps a crafted request from answering a button question with any text.
 */
#[CoversClass(InputPauseForm::class)]
#[CoversClass(InputPause::class)]
final class InputPauseFormTest extends TestCase
{
    /**
     * The shape the concept describes: one proposal, three fixed answers with
     * labels, and the user's own words as a fourth.
     *
     * @return array<string, mixed>
     */
    private static function proposalSchema(): array
    {
        return [
            'type' => 'object',
            'title' => 'Soll die neue Meta Description übernommen werden?',
            'properties' => [
                'decision' => [
                    'oneOf' => [
                        ['const' => 'accept', 'title' => 'Übernehmen'],
                        ['const' => 'variant', 'title' => 'Andere Variante'],
                        ['const' => 'skip', 'title' => 'Überspringen'],
                    ],
                ],
                'comment' => ['type' => 'string'],
            ],
        ];
    }

    #[Test]
    public function labelledOptionsBecomeButtonsInTheirOrder(): void
    {
        $form = InputPauseForm::fromSchema(self::proposalSchema());

        self::assertSame(InputPauseForm::KIND_CHOICE, $form->kind);
        self::assertSame(
            [
                ['value' => 'accept', 'label' => 'Übernehmen'],
                ['value' => 'variant', 'label' => 'Andere Variante'],
                ['value' => 'skip', 'label' => 'Überspringen'],
            ],
            $form->options,
        );
        self::assertSame('Soll die neue Meta Description übernommen werden?', $form->question);
        self::assertTrue($form->toArray()['freeText']);
    }

    #[Test]
    public function aButtonAnswersWithItsValueAndShowsItsLabel(): void
    {
        $submission = InputPauseForm::fromSchema(self::proposalSchema())->submission(['choice' => 'accept']);

        self::assertSame(['data' => ['decision' => 'accept'], 'display' => 'Übernehmen'], $submission);
    }

    /**
     * nr-llm's validator checks types, not membership. A value the user was
     * never offered must not get as far as the runtime.
     */
    #[Test]
    public function aValueThatWasNotOfferedIsRefused(): void
    {
        self::assertNull(InputPauseForm::fromSchema(self::proposalSchema())->submission(['choice' => 'delete everything']));
    }

    /** The same value with another JSON type is another value. */
    #[Test]
    public function aValueOfAnotherTypeIsRefused(): void
    {
        $form = InputPauseForm::fromSchema(['properties' => ['n' => ['enum' => [1, 2]]]]);

        self::assertNull($form->submission(['choice' => '1']));
        self::assertSame(['data' => ['n' => 1], 'display' => '1'], $form->submission(['choice' => 1]));
    }

    #[Test]
    public function typedTextGoesIntoTheFreeTextProperty(): void
    {
        $submission = InputPauseForm::fromSchema(self::proposalSchema())->submission(['freeText' => '  Kürzer, bitte.  ']);

        self::assertSame(['data' => ['comment' => 'Kürzer, bitte.'], 'display' => 'Kürzer, bitte.'], $submission);
    }

    #[Test]
    public function emptyFreeTextIsNoAnswer(): void
    {
        self::assertNull(InputPauseForm::fromSchema(self::proposalSchema())->submission(['freeText' => '   ']));
    }

    /**
     * With the choice required, text alone would not validate: the chat then
     * offers no free-text answer, and typed text stays an ordinary message.
     */
    #[Test]
    public function aRequiredChoiceOffersNoFreeTextAnswer(): void
    {
        $schema = self::proposalSchema();
        $schema['required'] = ['decision'];
        $form = InputPauseForm::fromSchema($schema);

        self::assertSame(InputPauseForm::KIND_CHOICE, $form->kind);
        self::assertFalse($form->toArray()['freeText']);
        self::assertNull($form->submission(['freeText' => 'Kürzer, bitte.']));
    }

    #[Test]
    public function aPlainEnumIsAChoiceLabelledWithItsValues(): void
    {
        $form = InputPauseForm::fromSchema([
            'type' => 'object',
            'properties' => ['language' => ['type' => 'string', 'enum' => ['Deutsch', 'English'], 'description' => 'Welche Sprache?']],
            'required' => ['language'],
        ]);

        self::assertSame(InputPauseForm::KIND_CHOICE, $form->kind);
        self::assertSame('Welche Sprache?', $form->question, 'without a schema title, the choice property says what is asked');
        self::assertSame([['value' => 'Deutsch', 'label' => 'Deutsch'], ['value' => 'English', 'label' => 'English']], $form->options);
    }

    /**
     * A required free-text property means text alone is not an answer either
     * way, so the schema is not a choice with an optional comment.
     */
    #[Test]
    public function aRequiredFreeTextPropertyMakesAForm(): void
    {
        $schema = self::proposalSchema();
        $schema['required'] = ['comment'];

        self::assertSame(InputPauseForm::KIND_FORM, InputPauseForm::fromSchema($schema)->kind);
    }

    #[Test]
    public function twoChoicePropertiesMakeAForm(): void
    {
        $form = InputPauseForm::fromSchema(['properties' => [
            'a' => ['enum' => ['x', 'y']],
            'b' => ['enum' => ['z']],
        ]]);

        self::assertSame(InputPauseForm::KIND_FORM, $form->kind);
        self::assertSame(['select', 'select'], array_column($form->fields, 'type'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unsupportedSchemas(): iterable
    {
        yield 'no properties' => [['type' => 'object', 'properties' => []]];
        yield 'not an object' => [['type' => 'string']];
        yield 'a nested object' => [['properties' => ['address' => ['type' => 'object']]]];
        yield 'an array' => [['properties' => ['tags' => ['type' => 'array']]]];
        yield 'an option that is not scalar' => [['properties' => ['a' => ['enum' => [['x' => 1]]]]]];
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[Test]
    #[DataProvider('unsupportedSchemas')]
    public function aSchemaTheChatCannotOfferIsUnsupported(array $schema): void
    {
        $form = InputPauseForm::fromSchema($schema);

        self::assertSame(InputPauseForm::KIND_UNSUPPORTED, $form->kind);
        self::assertNull($form->submission(['choice' => 'x', 'freeText' => 'x', 'fields' => ['x' => 'x']]));
    }

    /**
     * An unreadable pause has no digest to answer with, whatever the schema.
     */
    #[Test]
    public function anUnreadablePauseOffersNothing(): void
    {
        self::assertSame(InputPauseForm::KIND_UNSUPPORTED, (new InputPause('run', '', self::proposalSchema(), 'state-unreadable'))->form()->kind);
        self::assertSame(InputPauseForm::KIND_UNSUPPORTED, (new InputPause('run', '', self::proposalSchema()))->form()->kind);
        self::assertSame(InputPauseForm::KIND_CHOICE, (new InputPause('run', 'digest', self::proposalSchema()))->form()->kind);
    }

    /**
     * @return array<string, mixed>
     */
    private static function formSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'title' => 'Seitentitel'],
                'count' => ['type' => 'integer'],
                'ratio' => ['type' => 'number'],
                'hidden' => ['type' => 'boolean', 'title' => 'Verborgen'],
                'layout' => ['enum' => ['wide', 'narrow']],
                'site_note' => ['type' => ['string', 'null']],
            ],
            'required' => ['title'],
        ];
    }

    #[Test]
    public function aFormHasOneFieldPerProperty(): void
    {
        $form = InputPauseForm::fromSchema(self::formSchema());

        self::assertSame(InputPauseForm::KIND_FORM, $form->kind);
        self::assertSame(['title', 'count', 'ratio', 'hidden', 'layout', 'site_note'], array_column($form->fields, 'name'));
        self::assertSame(['text', 'integer', 'number', 'boolean', 'select', 'text'], array_column($form->fields, 'type'));
        self::assertSame(['Seitentitel', 'Count', 'Ratio', 'Verborgen', 'Layout', 'Site note'], array_column($form->fields, 'label'));
        self::assertSame([true, false, false, false, false, false], array_column($form->fields, 'required'));
    }

    #[Test]
    public function aFormAnswerIsCoercedToTheSchemasTypes(): void
    {
        $submission = InputPauseForm::fromSchema(self::formSchema())->submission(
            ['fields' => ['title' => ' Über uns ', 'count' => '3', 'ratio' => '0.5', 'hidden' => false, 'layout' => 'wide', 'unknown' => 'dropped']],
            ['Ja', 'Nein'],
        );

        self::assertNotNull($submission);
        self::assertSame(['title' => 'Über uns', 'count' => 3, 'ratio' => 0.5, 'hidden' => false, 'layout' => 'wide'], $submission['data']);
        self::assertSame("Seitentitel: Über uns\nCount: 3\nRatio: 0.5\nVerborgen: Nein\nLayout: wide", $submission['display']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function refusedFormAnswers(): iterable
    {
        yield 'a required field missing' => [['count' => '3']];
        yield 'a required field empty' => [['title' => '']];
        yield 'text for an integer' => [['title' => 'x', 'count' => 'three']];
        yield 'a fraction for an integer' => [['title' => 'x', 'count' => '1.5']];
        yield 'text for a number' => [['title' => 'x', 'ratio' => 'half']];
        yield 'a string for a checkbox' => [['title' => 'x', 'hidden' => 'yes']];
        yield 'a value that was not offered' => [['title' => 'x', 'layout' => 'full']];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[Test]
    #[DataProvider('refusedFormAnswers')]
    public function aFormAnswerThatDoesNotFitIsRefused(array $fields): void
    {
        self::assertNull(InputPauseForm::fromSchema(self::formSchema())->submission(['fields' => $fields]));
    }

    #[Test]
    public function aFormAnswerWithoutFieldsIsRefused(): void
    {
        self::assertNull(InputPauseForm::fromSchema(self::formSchema())->submission(['fields' => 'title=x']));
        self::assertNull(InputPauseForm::fromSchema(['properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']]])->submission(['fields' => []]));
    }
}
