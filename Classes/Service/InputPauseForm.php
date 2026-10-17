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
        $fields = self::fields($schema);
        if ($fields === null) {
            return self::unsupported();
        }

        $question = self::text($schema['title'] ?? null) ?: self::text($schema['description'] ?? null);
        $choices = array_values(array_filter($fields, static fn(array $f): bool => $f['type'] === 'select'));
        $freeTexts = array_values(array_filter($fields, static fn(array $f): bool => $f['freeText']));
        $isChoice = count($choices) === 1
            && (count($fields) === 1 || (count($fields) === 2 && count($freeTexts) === 1 && !$freeTexts[0]['required']));

        if (!$isChoice) {
            return new self(self::KIND_FORM, $question, fields: self::publicFields($fields));
        }

        $choice = $choices[0];

        return new self(
            self::KIND_CHOICE,
            $question ?: ($choice['label'] !== $choice['defaultLabel'] ? $choice['label'] : $choice['description']),
            choiceField: $choice['name'],
            options: $choice['options'],
            // Free text is only an answer of its own when no button has to be
            // pressed: with the choice required, text alone would not validate.
            freeTextField: $freeTexts !== [] && !$choice['required'] ? $freeTexts[0]['name'] : '',
        );
    }

    /**
     * One field per property, or null when a property is one the chat cannot
     * offer.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<array{name: string, label: string, defaultLabel: string, type: string, required: bool, options: list<array{value: bool|float|int|string, label: string}>, description: string, freeText: bool}>|null
     */
    private static function fields(array $schema): ?array
    {
        $type = $schema['type'] ?? null;
        $properties = $schema['properties'] ?? null;
        if (($type !== null && $type !== 'object') || !is_array($properties) || $properties === []) {
            return null;
        }

        $required = is_array($schema['required'] ?? null) ? array_values(array_filter($schema['required'], is_string(...))) : [];
        $fields = [];
        foreach ($properties as $name => $property) {
            $field = is_string($name) && is_array($property) ? self::field($name, $property, $required) : null;
            if ($field === null) {
                return null;
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * @param array<mixed>  $property
     * @param list<string>  $required
     *
     * @return array{name: string, label: string, defaultLabel: string, type: string, required: bool, options: list<array{value: bool|float|int|string, label: string}>, description: string, freeText: bool}|null
     */
    private static function field(string $name, array $property, array $required): ?array
    {
        $options = self::options($property);
        // Options the chat cannot read are not a text field either: whatever
        // was typed would not be one of them.
        $declaresOptions = isset($property['enum']) || isset($property['oneOf']) || isset($property['anyOf']);
        $scalarType = $options !== [] ? 'select' : self::scalarType($property);
        if ($scalarType === '' || ($declaresOptions && $options === [])) {
            return null;
        }

        $defaultLabel = ucfirst(str_replace('_', ' ', $name));

        return [
            'name' => $name,
            'label' => self::text($property['title'] ?? null) ?: $defaultLabel,
            'defaultLabel' => $defaultLabel,
            'type' => $scalarType === 'string' ? 'text' : $scalarType,
            'required' => in_array($name, $required, true),
            'options' => $options,
            'description' => self::text($property['description'] ?? null),
            'freeText' => $scalarType === 'string' && !array_key_exists('const', $property),
        ];
    }

    /**
     * The fields as the client receives them.
     *
     * @param list<array{name: string, label: string, defaultLabel: string, type: string, required: bool, options: list<array{value: bool|float|int|string, label: string}>, description: string, freeText: bool}> $fields
     *
     * @return list<array{name: string, label: string, type: string, required: bool, options: list<array{value: bool|float|int|string, label: string}>, description: string}>
     */
    private static function publicFields(array $fields): array
    {
        return array_map(static fn(array $f): array => [
            'name' => $f['name'],
            'label' => $f['label'],
            'type' => $f['type'],
            'required' => $f['required'],
            'options' => $f['options'],
            'description' => $f['description'],
        ], $fields);
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
            $option = $this->matchingOption($this->options, $body['choice']);

            return $option === null ? null : ['data' => [$this->choiceField => $option['value']], 'display' => $option['label']];
        }

        $text = is_string($body['freeText'] ?? null) ? trim($body['freeText']) : '';

        return $this->freeTextField === '' || $text === ''
            ? null
            : ['data' => [$this->freeTextField => $text], 'display' => $text];
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
        $valid = true;
        foreach ($this->fields as $field) {
            $raw = $values[$field['name']] ?? null;
            $empty = $raw === null || $raw === '';
            $value = $empty ? null : $this->coerce($field, $raw, $yesNo);
            // A field left empty is fine unless it is required; one filled
            // with a value that does not fit is never fine.
            $valid = $valid && ($value !== null || ($empty && !$field['required']));
            if ($value !== null) {
                $data[$field['name']] = $value['value'];
                $lines[] = $field['label'] . ': ' . $value['label'];
            }
        }

        return !$valid || $data === [] ? null : ['data' => $data, 'display' => implode("\n", $lines)];
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
        return match ($field['type']) {
            'select' => $this->matchingOption($field['options'], $raw),
            'integer' => $this->asInteger($raw),
            'number' => $this->asNumber($raw),
            'boolean' => is_bool($raw) ? ['value' => $raw, 'label' => $raw ? $yesNo[0] : $yesNo[1]] : null,
            default => is_string($raw) && trim($raw) !== '' ? ['value' => trim($raw), 'label' => trim($raw)] : null,
        };
    }

    /**
     * The option whose value is the submitted one, compared strictly: the same
     * value with another JSON type is another value.
     *
     * @param list<array{value: bool|float|int|string, label: string}> $options
     *
     * @return array{value: bool|float|int|string, label: string}|null
     */
    private function matchingOption(array $options, mixed $value): ?array
    {
        foreach ($options as $option) {
            if ($option['value'] === $value) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @return array{value: int, label: string}|null
     */
    private function asInteger(mixed $raw): ?array
    {
        $isInteger = is_int($raw) || (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1);

        return $isInteger ? ['value' => (int) $raw, 'label' => (string) (int) $raw] : null;
    }

    /**
     * @return array{value: float|int, label: string}|null
     */
    private function asNumber(mixed $raw): ?array
    {
        if (is_int($raw) || is_float($raw)) {
            return ['value' => $raw, 'label' => (string) $raw];
        }

        return is_string($raw) && is_numeric($raw) ? ['value' => (float) $raw, 'label' => $raw] : null;
    }

    /**
     * The labelled options of a property: its `enum`, or its `oneOf`/`anyOf`
     * of `const` branches; empty when it offers none or one that is not a
     * scalar.
     *
     * @param array<mixed> $property
     *
     * @return list<array{value: bool|float|int|string, label: string}>
     */
    private static function options(array $property): array
    {
        $enum = $property['enum'] ?? null;
        $branches = is_array($enum) && $enum !== []
            ? array_map(static fn(mixed $value): array => ['const' => $value], $enum)
            : ($property['oneOf'] ?? $property['anyOf'] ?? []);

        $options = [];
        foreach (is_array($branches) ? $branches : [] as $branch) {
            $value = is_array($branch) ? ($branch['const'] ?? null) : null;
            if (!is_scalar($value)) {
                return [];
            }

            $title = is_array($branch) ? self::text($branch['title'] ?? null) : '';
            $options[] = ['value' => $value, 'label' => $title !== '' ? $title : (string) $value];
        }

        return $options;
    }

    /**
     * The property's scalar type, or '' for one the chat cannot offer.
     *
     * @param array<mixed> $property
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
