<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;

/**
 * Which fields the "missing alt texts" check reads, found in the TCA at
 * runtime (ADR-026): every column of type `file` in every table, so a newly
 * installed extension's image fields are checked without configuration.
 * TYPO3 13.4 migrates the older inline FAL fields to type `file` when it
 * loads the TCA, so they are found the same way.
 *
 * Left out are the tables and `table.field` entries the extension
 * configuration excludes, and sys_file_reference and sys_file_metadata
 * themselves.
 *
 * It also answers which tables own another table's records through an inline
 * field: an image on a carousel item belongs to the item's content element,
 * one on a content element inside a news record to that record.
 */
class ImageFieldDiscovery
{
    /** Tables that hold file metadata or references, never an image use of their own. */
    private const NEVER = ['sys_file_reference', 'sys_file_metadata', 'sys_file'];

    public function __construct(
        private readonly ExtensionConfiguration $configuration,
    ) {}

    /**
     * @return list<ImageField> ordered by table and field
     */
    public function fields(): array
    {
        $excludedTables = $this->configuration->getAltTextExcludedTables();
        $excludedFields = $this->configuration->getAltTextExcludedFields();
        $fields         = [];
        foreach ($this->tca() as $table => $config) {
            if (in_array($table, self::NEVER, true) || in_array($table, $excludedTables, true)) {
                continue;
            }

            foreach ($this->columns($config) as $field => $column) {
                $type = is_array($column['config'] ?? null) ? ($column['config']['type'] ?? null) : null;
                if ($type === 'file' && !in_array($table . '.' . $field, $excludedFields, true)) {
                    $fields[] = new ImageField($table, $field);
                }
            }
        }

        usort($fields, static fn (ImageField $a, ImageField $b): int => [$a->table, $a->field] <=> [$b->table, $b->field]);

        return $fields;
    }

    /**
     * Whether the extension configuration leaves the table out: its own image
     * fields, and images in records it owns (a content element inside it).
     */
    public function isExcludedTable(string $table): bool
    {
        return in_array($table, $this->configuration->getAltTextExcludedTables(), true);
    }

    /**
     * The image fields grouped by table, for the reference query.
     *
     * @return array<string, list<string>>
     */
    public function fieldsByTable(): array
    {
        $byTable = [];
        foreach ($this->fields() as $field) {
            $byTable[$field->table][] = $field->field;
        }

        return $byTable;
    }

    /**
     * The tables that own records of `$table` through an inline field that
     * names the owner in the child (`foreign_field`). An inline relation over
     * an intermediate table names no single owner and is not followed, and
     * neither is one from a table to itself: that is a container of records
     * of its own kind (a grid element holding content elements), whose
     * children are edited on their own, and the DataHandler asks only the
     * child's rights.
     *
     * @return list<InlineOwner>
     */
    public function ownersOf(string $table): array
    {
        $owners = [];
        foreach ($this->tca() as $ownerTable => $config) {
            foreach ($this->columns($config) as $field => $column) {
                $columnConfig = is_array($column['config'] ?? null) ? $column['config'] : [];
                if (($columnConfig['type'] ?? null) !== 'inline' || ($columnConfig['foreign_table'] ?? null) !== $table || $ownerTable === $table) {
                    continue;
                }

                $foreignField = $columnConfig['foreign_field'] ?? null;
                if (!is_string($foreignField) || $foreignField === '' || isset($columnConfig['MM'])) {
                    continue;
                }

                $foreignTableField = $columnConfig['foreign_table_field'] ?? '';
                $owners[]          = new InlineOwner($ownerTable, $field, $foreignField, is_string($foreignTableField) ? $foreignTableField : '');
            }
        }

        return $owners;
    }

    /**
     * Whether a record of the given type shows the field in its form: listed
     * in the type's `showitem`, directly or in one of its palettes, and not
     * turned into another kind of field by the type's `columnsOverrides`. A
     * reference left over in a field its record's type does not show (an
     * image on a "Text" content element whose type was "Text & Images") is in
     * no form and in no output, and not checked. A table without types shows
     * every field.
     */
    public function isShown(string $table, string $type, string $field): bool
    {
        $config = $this->tca()[$table] ?? [];
        $types  = is_array($config['types'] ?? null) ? $config['types'] : [];
        if ($types === []) {
            return true;
        }

        // A type the TCA does not define falls back to "0", then "1", as the
        // form does; a table without a type field has only one of them.
        $typeConfig = $types[$type] ?? $types['0'] ?? $types['1'] ?? null;
        $typeConfig = is_array($typeConfig) ? $typeConfig : [];

        $overrides = is_array($typeConfig['columnsOverrides'] ?? null) ? $typeConfig['columnsOverrides'] : [];
        $override  = is_array($overrides[$field] ?? null) ? $this->fieldType($overrides[$field]) : null;
        $baseType  = $this->fieldType($this->columns($config)[$field] ?? []);
        if ($override !== null && $override !== $baseType) {
            return false;
        }

        $palettes = is_array($config['palettes'] ?? null) ? $config['palettes'] : [];
        foreach ($this->items($typeConfig['showitem'] ?? null) as $item) {
            if ($item[0] === $field) {
                return true;
            }

            if ($item[0] === '--palette--' && is_array($palettes[$item[2] ?? ''] ?? null)) {
                foreach ($this->items($palettes[$item[2] ?? '']['showitem'] ?? null) as $paletteItem) {
                    if ($paletteItem[0] === $field) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $column
     */
    private function fieldType(array $column): ?string
    {
        $type = is_array($column['config'] ?? null) ? ($column['config']['type'] ?? null) : null;

        return is_string($type) ? $type : null;
    }

    /**
     * The items of a `showitem` list, each split at `;` into field, label and
     * palette name.
     *
     * @return list<list<string>>
     */
    private function items(mixed $showitem): array
    {
        if (!is_string($showitem)) {
            return [];
        }

        $items = [];
        foreach (explode(',', $showitem) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $items[] = array_map(trim(...), explode(';', $item));
            }
        }

        return $items;
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function tca(): array
    {
        $tca    = $GLOBALS['TCA'] ?? null;
        $tables = [];
        if (!is_array($tca)) {
            return [];
        }

        foreach ($tca as $table => $config) {
            if (is_string($table) && is_array($config)) {
                $tables[$table] = $config;
            }
        }

        return $tables;
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<string, array<mixed>>
     */
    private function columns(array $config): array
    {
        $columns = [];
        foreach (is_array($config['columns'] ?? null) ? $config['columns'] : [] as $field => $column) {
            if (is_string($field) && is_array($column)) {
                $columns[$field] = $column;
            }
        }

        return $columns;
    }
}
