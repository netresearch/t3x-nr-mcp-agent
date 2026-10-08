<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

/**
 * How the chat offers a question a run asks (ADR-018): reply buttons, a small
 * form, or a note that it cannot be answered here — and which submitted
 * values it accepts.
 *
 * The contract a tool's input schema is read against:
 *
 * - **Choice.** An object schema whose properties are one *choice property*
 *   — `enum` of scalars, or `oneOf`/`anyOf` of `{const, title}` branches for
 *   labelled options — and at most one *free-text property* (a string with no
 *   `enum`, `oneOf`, `anyOf` or `const`) that is not required. Each option
 *   becomes a button. When the choice property is not required either, text
 *   the user types is submitted as the free-text property. Exactly one of the
 *   two is ever submitted.
 * - **Form.** Any other object schema of scalar properties: one field per
 *   property, submitted together.
 * - **Unsupported.** Anything else — nested objects, arrays, no properties.
 *
 * The question shown above the buttons is the schema's `title`, else its
 * `description`, else the choice property's.
 *
 * nr-llm validates a submission by structure only (nr-llm ADR-105: no enum
 * check), so membership in the offered options is enforced here, before the
 * answer is recorded: without it a crafted request could put any text into a
 * field the user was only offered buttons for.
 */
final readonly class InputPauseForm
{
    public const KIND_CHOICE = 'choice';

    public const KIND_FORM = 'form';

    public const KIND_UNSUPPORTED = 'unsupported';

    private const SCALAR_TYPES = ['string', 'integer', 'number', 'boolean'];

    /**
     * @param self::KIND_*                                                                                                                   $kind
     * @param list<array{value: bool|float|int|string, label: string}>                                                                        $options
     * @param list<array{name: string, label: string, type: string, required: bool, options: list<array{value: bool|float|int|string, label: string}>, description: string}> $fields
     */
    private function __construct(
        public string $kind,
        public string $question,
        public string $choiceField = '',
        public array $options = [],
        public string $freeTextField = '',
        public array $fields = [],
    ) {}

    public static function unsupported(): self
    {
        return new self(self::KIND_UNSUPPORTED, '');
    }

    /**
     * @param array<string, mixed> $schema
     */
    public static function fromSchema(array $schema): self
    {
        $type = $schema['type'] ?? null;
        $properties = $schema['properties'] ?? null;
        if (($type !== null && $type !== 'object') || !is_array($properties) || $properties === []) {
            return self::unsupported();
        }

        $required = is_array($schema['required'] ?? null) ? array_values(array_filter($schema['required'], is_string(...))) : [];
        $question = self::text($schema['title'] ?? null) ?: self::text($schema['description'] ?? null);

        $choices = [];
        $freeTexts = [];
        $fields = [];
        foreach ($properties as $name => $property) {
            if (!is_string($name) || !is_array($property)) {
                return self::unsupported();
            }

            /** @var array<string, mixed> $property */
            $options = self::options($property);
            $declaresOptions = isset($property['enum']) || isset($property['oneOf']) || isset($property['anyOf']);
            if ($declaresOptions && $options === []) {
                // Options the chat cannot read are not a text field either:
                // whatever was typed would not be one of them.
                return self::unsupported();
            }

            $fieldType = $options !== [] ? 'select' : self::scalarType($property);
            if ($fieldType === '') {
                return self::unsupported();
            }

            if ($options !== []) {
                $choices[$name] = $property;
            } elseif ($fieldType === 'string' && !array_key_exists('const', $property)) {
                $freeTexts[] = $name;
            }

            $fields[] = [
                'name' => $name,
                'label' => self::text($property['title'] ?? null) ?: ucfirst(str_replace('_', ' ', $name)),
                'type' => $fieldType === 'string' ? 'text' : $fieldType,
                'required' => in_array($name, $required, true),
                'options' => $options,
                'description' => self::text($property['description'] ?? null),
            ];
        }

        $choiceField = array_key_first($choices);
        $isChoice = count($choices) === 1
            && is_string($choiceField)
            && (count($properties) === 1 || (count($properties) === 2 && count($freeTexts) === 1 && !in_array($freeTexts[0], $required, true)));

        if (!$isChoice) {
            return new self(self::KIND_FORM, $question, fields: $fields);
        }

        $choice = $choices[$choiceField];
        $question = $question ?: (self::text($choice['title'] ?? null) ?: self::text($choice['description'] ?? null));

        return new self(
            self::KIND_CHOICE,
            $question,
            choiceField: $choiceField,
            options: self::options($choice),
            // Free text is only an answer of its own when no button has to be
            // pressed: with the choice required, text alone would not validate.
            freeTextField: $freeTexts !== [] && !in_array($choiceField, $required, true) ? $freeTexts[0] : '',
        );
    }

    /**
     * What the client renders: the question, the buttons or the fields, and
     * whether typed text is an answer. Property names travel along because the
     * submission is keyed by them.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'question' => $this->question,
            'options' => $this->options,
            'freeText' => $this->freeTextField !== '',
            'fields' => $this->fields,
        ];
    }

    /**
     * The values to hand to the runtime for a submitted answer, and the line
     * the transcript shows for it; null when the answer is not one this form
     * offers.
     *
     * A choice is `{choice: <value>}` or `{freeText: "…"}`; a form is
     * `{fields: {<name>: <value>}}`.
     *
     * @param array<string, mixed>       $body
     * @param array{0: string, 1: string} $yesNo how the transcript line shows a ticked and an unticked checkbox
     *
     * @return array{data: array<string, mixed>, display: string}|null
     */
    public function submission(array $body, array $yesNo = ['yes', 'no']): ?array
    {
        return match ($this->kind) {
            self::KIND_CHOICE => $this->choiceSubmission($body),
            self::KIND_FORM => $this->formSubmission($body['fields'] ?? null, $yesNo),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{data: array<string, mixed>, display: string}|null
     */
    private function choiceSubmission(array $body): ?array
    {
        if (array_key_exists('choice', $body)) {
            foreach ($this->options as $option) {
                if ($option['value'] === $body['choice']) {
                    return ['data' => [$this->choiceField => $option['value']], 'display' => $option['label']];
                }
            }

            return null;
        }

        $text = is_string($body['freeText'] ?? null) ? trim($body['freeText']) : '';
        if ($this->freeTextField === '' || $text === '') {
            return null;
        }

        return ['data' => [$this->freeTextField => $text], 'display' => $text];
    }

    /**
     * @param array{0: string, 1: string} $yesNo
     *
     * @return array{data: array<string, mixed>, display: string}|null
     */
    private function formSubmission(mixed $values, array $yesNo): ?array
    {
        if (!is_array($values)) {
            return null;
        }

        $data = [];
        $lines = [];
        foreach ($this->fields as $field) {
            $raw = $values[$field['name']] ?? null;
            if ($raw === null || $raw === '') {
                if ($field['required']) {
                    return null;
                }

                continue;
            }

            $value = $this->coerce($field, $raw, $yesNo);
            if ($value === null) {
                return null;
            }

            $data[$field['name']] = $value['value'];
            $lines[] = $field['label'] . ': ' . $value['label'];
        }

        return $data === [] ? null : ['data' => $data, 'display' => implode("\n", $lines)];
    }

    /**
     * The submitted value as the schema's type, or null when it is not one.
     *
     * @param array{type: string, options: list<array{value: bool|float|int|string, label: string}>} $field
     * @param array{0: string, 1: string}                                                           $yesNo
     *
     * @return array{value: bool|float|int|string, label: string}|null
     */
    private function coerce(array $field, mixed $raw, array $yesNo): ?array
    {
        if ($field['type'] === 'select') {
            foreach ($field['options'] as $option) {
                if ($option['value'] === $raw) {
                    return $option;
                }
            }

            return null;
        }

        if ($field['type'] === 'integer') {
            $isInteger = is_int($raw) || (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1);

            return $isInteger ? ['value' => (int) $raw, 'label' => (string) (int) $raw] : null;
        }

        if ($field['type'] === 'number') {
            if (is_int($raw) || is_float($raw)) {
                return ['value' => $raw, 'label' => (string) $raw];
            }

            return is_string($raw) && is_numeric($raw) ? ['value' => (float) $raw, 'label' => $raw] : null;
        }

        if ($field['type'] === 'boolean') {
            return is_bool($raw) ? ['value' => $raw, 'label' => $raw ? $yesNo[0] : $yesNo[1]] : null;
        }

        return is_string($raw) && trim($raw) !== '' ? ['value' => trim($raw), 'label' => trim($raw)] : null;
    }

    /**
     * The labelled options of a property: its `enum`, or its `oneOf`/`anyOf`
     * of `const` branches; empty when it offers none.
     *
     * @param array<string, mixed> $property
     *
     * @return list<array{value: bool|float|int|string, label: string}>
     */
    private static function options(array $property): array
    {
        $enum = $property['enum'] ?? null;
        if (is_array($enum) && $enum !== []) {
            $options = [];
            foreach ($enum as $value) {
                if (!is_scalar($value)) {
                    return [];
                }

                $options[] = ['value' => $value, 'label' => (string) $value];
            }

            return $options;
        }

        $branches = $property['oneOf'] ?? $property['anyOf'] ?? null;
        if (!is_array($branches) || $branches === []) {
            return [];
        }

        $options = [];
        foreach ($branches as $branch) {
            $value = is_array($branch) ? ($branch['const'] ?? null) : null;
            if (!is_scalar($value) || !is_array($branch)) {
                return [];
            }

            $options[] = ['value' => $value, 'label' => self::text($branch['title'] ?? null) ?: (string) $value];
        }

        return $options;
    }

    /**
     * The property's scalar type, or '' for one the chat cannot offer.
     *
     * @param array<string, mixed> $property
     */
    private static function scalarType(array $property): string
    {
        $type = $property['type'] ?? 'string';
        if (is_array($type)) {
            $type = array_values(array_filter($type, static fn(mixed $t): bool => $t !== 'null'))[0] ?? '';
        }

        return is_string($type) && in_array($type, self::SCALAR_TYPES, true) ? $type : '';
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
