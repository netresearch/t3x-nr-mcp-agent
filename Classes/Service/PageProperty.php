<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * A page property a guided step can be about (NEXT-221, ADR-020): a column
 * of `pages` in the TCA that the user may edit, and its label.
 *
 * The name is a column name, never a label: the label is read from the TCA
 * in the reader's language when the chat asks, so neither the model nor the
 * browser can put text into the chip.
 */
final class PageProperty
{
    private const TABLE = 'pages';

    /**
     * Whether the column exists in the TCA of `pages` and is not an excluded
     * field the user lacks: an excluded field needs an administrator or the
     * field in the user's `non_exclude_fields`, as in the page properties form.
     */
    public static function mayEdit(string $field, BackendUserAuthentication $user): bool
    {
        $column = self::column($field);
        if ($column === null) {
            return false;
        }

        $excluded = (bool) ($column['exclude'] ?? false);

        return !$excluded || $user->isAdmin() || $user->check('non_exclude_fields', self::TABLE . ':' . $field);
    }

    /** The column's label in the reader's language, '' where there is none. */
    public static function label(string $field, ?LanguageService $language): string
    {
        $label = self::column($field)['label'] ?? null;
        if (!is_string($label) || $label === '' || !$language instanceof LanguageService) {
            return '';
        }

        try {
            return trim($language->sL($label));
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function column(string $field): ?array
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $field) !== 1) {
            return null;
        }

        $tca     = $GLOBALS['TCA'] ?? null;
        $table   = is_array($tca) ? ($tca[self::TABLE] ?? null) : null;
        $columns = is_array($table) ? ($table['columns'] ?? null) : null;
        $column  = is_array($columns) ? ($columns[$field] ?? null) : null;

        return is_array($column) ? $column : null;
    }
}
