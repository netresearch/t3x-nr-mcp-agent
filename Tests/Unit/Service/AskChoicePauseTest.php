<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Service\InputPauseForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A choice of a guided process (ADR-023, item 9) through nr-llm's choice
 * builtin `ask_choice` (nr-llm PR 1029): the run waits for input on a schema
 * the tool builds per call, one required string property `answer` whose
 * `enum` holds the call's options and whose `title` is the question. The
 * chat answers it with its reply buttons (ADR-018); nothing is specific to
 * the tool.
 *
 * The cases on the schema run on every nr-llm; the ones against the tool
 * itself skip where the installed nr-llm does not have it.
 */
#[CoversClass(InputPauseForm::class)]
final class AskChoicePauseTest extends TestCase
{
    private const TOOL = 'Netresearch\\NrLlm\\Service\\Tool\\Builtin\\AskChoiceTool';

    private const INPUT_SCHEMA = 'Netresearch\\NrLlm\\Service\\Tool\\InputSchema';

    private const QUESTION = 'Für die Seite gibt es kein Bild. Wie geht es weiter?';

    private const OPTIONS = ['Bild hochladen', 'Vorhandenes Bild wählen', 'Ohne Bild weiter'];

    /**
     * The schema `ask_choice` suspends the run on, as nr-llm PR 1029 builds
     * it ({@see self::theToolBuildsExactlyThisSchema()} pins it).
     *
     * @param list<string> $options
     *
     * @return array<string, mixed>
     */
    public static function schema(string $question = self::QUESTION, array $options = self::OPTIONS): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'answer' => [
                    'type'  => 'string',
                    'title' => $question,
                    'enum'  => $options,
                ],
            ],
            'required' => ['answer'],
        ];
    }

    private static function requireTool(): object
    {
        if (!class_exists(self::TOOL)) {
            self::markTestSkipped('Needs nr-llm with ' . self::TOOL . ' (nr-llm PR 1029).');
        }

        $tool = new (self::TOOL)();
        self::assertIsObject($tool);

        return $tool;
    }

    #[Test]
    public function theToolBuildsExactlyThisSchema(): void
    {
        $tool = self::requireTool();

        self::assertSame(self::schema(), call_user_func([$tool, 'inputSchemaFor'], ['question' => self::QUESTION, 'options' => self::OPTIONS]));
    }

    #[Test]
    public function itIsAChoiceWithTheOptionsAsButtons(): void
    {
        $form = InputPauseForm::fromSchema(self::schema())->toArray();

        self::assertSame('choice', $form['kind']);
        self::assertSame(self::QUESTION, $form['question']);
        self::assertSame(
            array_map(static fn(string $o): array => ['value' => $o, 'label' => $o], self::OPTIONS),
            $form['options'],
        );
        self::assertFalse($form['freeText'], 'the answer is required: typed text is a message of its own');
    }

    /** The skip option is one of the call's options like any other; its wording is the skill's. */
    #[Test]
    public function aButtonSendsItsOptionAsTheAnswer(): void
    {
        $submission = InputPauseForm::fromSchema(self::schema())->submission(['choice' => 'Ohne Bild weiter']);

        self::assertSame(['data' => ['answer' => 'Ohne Bild weiter'], 'display' => 'Ohne Bild weiter'], $submission);
    }

    #[Test]
    public function anythingButAnOptionIsRefused(): void
    {
        $form = InputPauseForm::fromSchema(self::schema());

        self::assertNull($form->submission(['choice' => 'Ohne Bild']));
        self::assertNull($form->submission(['choice' => 'ohne bild weiter']));
        self::assertNull($form->submission(['freeText' => 'Ohne Bild weiter']));
        self::assertNull($form->submission([]));
    }

    /** Markup in an option travels as text; the chat renders it as text only. */
    #[Test]
    public function markupInAnOptionStaysText(): void
    {
        $options = ['<img src=x onerror=alert(1)>', 'Fertig'];
        $form = InputPauseForm::fromSchema(self::schema('Los?', $options));

        self::assertSame('<img src=x onerror=alert(1)>', $form->toArray()['options'][0]['label']);
        self::assertSame(['answer' => '<img src=x onerror=alert(1)>'], $form->submission(['choice' => '<img src=x onerror=alert(1)>'])['data'] ?? null);
    }

    /** The answer the chat sends is one nr-llm's own check accepts (nr-llm PR 1029). */
    #[Test]
    public function theAnswerPassesNrLlmsEnumCheck(): void
    {
        self::requireTool();
        $schema = self::schema();
        $data = InputPauseForm::fromSchema($schema)->submission(['choice' => 'Vorhandenes Bild wählen'])['data'] ?? [];

        self::assertTrue(call_user_func([self::INPUT_SCHEMA, 'enumsHold'], $data, $schema));
        self::assertFalse(call_user_func([self::INPUT_SCHEMA, 'enumsHold'], ['answer' => 'Bild'], $schema), 'control: nr-llm refuses what is no option');
    }

    /** "Los geht's" / "Fertig" after the overview: two plain options. */
    #[Test]
    public function aPlainChoiceOfTwoIsTwoButtons(): void
    {
        $form = InputPauseForm::fromSchema(self::schema('Mit dem ersten Punkt beginnen?', ["Los geht's", 'Fertig']))->toArray();

        self::assertSame(["Los geht's", 'Fertig'], array_column($form['options'], 'label'));
    }
}
