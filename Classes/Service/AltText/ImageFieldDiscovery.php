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
     * an intermediate table names no single owner and is not followed.
     *
     * @return list<InlineOwner>
     */
    public function ownersOf(string $table): array
    {
        $owners = [];
        foreach ($this->tca() as $ownerTable => $config) {
            foreach ($this->columns($config) as $field => $column) {
                $columnConfig = is_array($column['config'] ?? null) ? $column['config'] : [];
                if (($columnConfig['type'] ?? null) !== 'inline' || ($columnConfig['foreign_table'] ?? null) !== $table) {
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
