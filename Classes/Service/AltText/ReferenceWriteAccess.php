<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

use Netresearch\NrMcpAgent\Domain\Repository\ImageReferenceRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Whether a backend user may change one column of one image reference, asked
 * the way nr-llm's `set_file_reference_alternative_text` asks it before its
 * card (nr-llm ADR-230), so that no card and no button is offered that the
 * DataHandler would then refuse (ADR-026):
 *
 * - the reference is a live, undeleted row in a `type => file` column of a
 *   table the TCA declares, and that table is not denylisted, `readOnly`, or
 *   `adminOnly` for a non-admin; its record is live and undeleted;
 * - "edit content" on the record's page (which, through calcPerms(), also
 *   asks the web mounts), and for a page's own file field
 *   "edit page" as well: the form edits it as a page property, while the
 *   DataHandler, writing the reference row alone, asks "edit content" (it
 *   asks "edit page" only with `pages` in the same datamap). A record on the
 *   root level is an admin's;
 * - no edit lock on the page (admins excepted), which the permission bits do
 *   not carry and the DataHandler refuses;
 * - the reference's language; the rights of the record and of every record
 *   that owns it through an inline field, up to the outermost one, as the
 *   list asks them (`tables_modify`, the language, `authMode` such as the
 *   content type, the edit lock): an image on a carousel item is refused
 *   when its content element is locked or of a type the user may not use;
 *   `tables_modify` for sys_file_reference;
 * - the field grants for the record's file field, for each owner's inline
 *   field and for the column written.
 *
 * The page is the outermost owner's.
 *
 * A reference or record that is not there and one the user may not edit get
 * the same refusal, so a refusal never confirms that a uid exists; the
 * others are named, and reach only a user who may edit on that page.
 */
class ReferenceWriteAccess
{
    public const NOT_PERMITTED = 'File reference or its record not found, or not permitted.';

    private const TABLE = 'sys_file_reference';

    /** As deep as the list follows owners (MissingAltTextScanner). */
    private const MAX_OWNER_DEPTH = 4;

    public function __construct(
        private readonly ImageReferenceRepository $rows,
        private readonly ImageFieldDiscovery $discovery,
        private readonly TableWriteEligibility $tables = new TableWriteEligibility(),
    ) {}

    /**
     * The reference and what it hangs on, once the user may write `$column`
     * there; else the refusal.
     */
    public function resolve(BackendUserAuthentication $user, int $referenceUid, string $column): WritableReference|string
    {
        if ($referenceUid < 1) {
            return self::NOT_PERMITTED;
        }

        $reference = $this->liveRow(self::TABLE, $referenceUid);
        if ($reference === null) {
            return self::NOT_PERMITTED;
        }

        $table = $this->string($reference['tablenames'] ?? null);
        $field = $this->string($reference['fieldname'] ?? null);
        if ($table === '' || !$this->isFileField($table, $field) || !$this->tables->mayWrite($table, $user)) {
            return self::NOT_PERMITTED;
        }

        $record = $this->liveRow($table, $this->int($reference['uid_foreign'] ?? 0));
        if ($record === null) {
            return self::NOT_PERMITTED;
        }

        $chain = $this->ownerChain($table, $record, $field);
        if ($chain === null) {
            return self::NOT_PERMITTED;
        }

        $top  = $chain[count($chain) - 1];
        $page = $this->editablePageOf($top['table'], $top['row'], $user);
        if (is_string($page)) {
            return $page;
        }

        $language = $this->int($reference['sys_language_uid'] ?? 0);
        if (!$user->checkLanguageAccess($language)) {
            return sprintf('Refused: file reference [%d] is in language %d, which the acting backend user may not edit. Nothing was written.', $referenceUid, $language);
        }

        if (!$user->isAdmin() && !$user->check('tables_modify', self::TABLE)) {
            return self::NOT_PERMITTED;
        }

        $refusal = $this->chainRefusal($chain, $column, $user);
        if ($refusal !== null) {
            return $refusal;
        }

        return new WritableReference(
            referenceUid: $referenceUid,
            reference: $reference,
            table: $table,
            field: $field,
            record: $record,
            page: $page,
            language: $language,
        );
    }

    /**
     * Why the user may not write `$column` through this chain, or null: a
     * table that may not be written, a record the user may not edit, or a
     * field without its grant.
     *
     * @param non-empty-list<array{table: string, row: array<string, mixed>, via: string}> $chain
     */
    private function chainRefusal(array $chain, string $column, BackendUserAuthentication $user): ?string
    {
        foreach ($chain as $link) {
            if (!$this->tables->mayWrite($link['table'], $user) || !$this->mayEditRecord($link['table'], $link['row'], $user)) {
                return self::NOT_PERMITTED;
            }
        }

        $fields   = array_map(static fn (array $link): array => [$link['table'], $link['via']], $chain);
        $fields[] = [self::TABLE, $column];

        $ungranted = [];
        foreach ($fields as [$table, $field]) {
            if (!$this->mayEditField($user, $table, $field)) {
                $ungranted[] = $table . ':' . $field;
            }
        }

        return $ungranted === [] ? null : sprintf('Refused: the acting backend user holds no field-level ("exclude field") grant for %s. Nothing was written.', implode(', ', $ungranted));
    }

    /**
     * The record and the records that own it through an inline field, from
     * the record outward, each with the field the next one down hangs on.
     * Null when an owner is gone or the chain is deeper than the list follows.
     *
     * @param array<string, mixed> $record
     *
     * @return non-empty-list<array{table: string, row: array<string, mixed>, via: string}>|null
     */
    private function ownerChain(string $table, array $record, string $field): ?array
    {
        $chain = [['table' => $table, 'row' => $record, 'via' => $field]];
        for ($depth = 0; $depth <= self::MAX_OWNER_DEPTH; ++$depth) {
            $owner = $this->ownerOf($table, $record);
            if ($owner === null) {
                return $chain;
            }

            [$table, $uid, $field] = $owner;
            $row                   = $this->liveRow($table, $uid);
            if ($row === null) {
                return null;
            }

            $record  = $row;
            $chain[] = ['table' => $table, 'row' => $record, 'via' => $field];
        }

        return null;
    }

    /**
     * The owner of a row through an inline field, as [table, uid, field].
     *
     * @param array<string, mixed> $row
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    private function ownerOf(string $table, array $row): ?array
    {
        foreach ($this->discovery->ownersOf($table) as $owner) {
            $uid = $this->int($row[$owner->foreignField] ?? 0);
            if ($uid <= 0 || ($owner->foreignTableField !== '' && $this->string($row[$owner->foreignTableField] ?? null) !== $owner->table)) {
                continue;
            }

            return [$owner->table, $uid, $owner->field];
        }

        return null;
    }

    /**
     * The page the record is edited on, once the user may edit there; empty
     * for a record on the root level, which only an admin edits. Else the
     * refusal.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>|string
     */
    private function editablePageOf(string $table, array $record, BackendUserAuthentication $user): array|string
    {
        if ($table !== 'pages' && $this->int($record['pid'] ?? 0) === 0) {
            return $user->isAdmin() ? [] : self::NOT_PERMITTED;
        }

        $page = $table === 'pages' ? $this->editablePageItself($record, $user) : $this->pageWithContentRight($record, $user);
        if (!is_array($page)) {
            return $page;
        }

        $editlock = $this->ctrlString('pages', 'editlock');
        if (!$user->isAdmin() && $editlock !== '' && (bool) ($page[$editlock] ?? false)) {
            return sprintf('Refused: page [%d] is locked for editing (edit lock). Nothing was written.', $this->int($page['uid'] ?? 0));
        }

        return $page;
    }

    /**
     * A page whose own file field holds the image: the form edits it as a
     * page property ("edit page"), the DataHandler writes the reference with
     * "edit content".
     *
     * @param array<string, mixed> $page
     *
     * @return array<string, mixed>|string
     */
    private function editablePageItself(array $page, BackendUserAuthentication $user): array|string
    {
        if (!$user->doesUserHaveAccess($page, Permission::PAGE_EDIT)) {
            return self::NOT_PERMITTED;
        }

        return $user->doesUserHaveAccess($page, Permission::CONTENT_EDIT) ? $page : sprintf(
            'Refused: an image on a page\'s own file field needs both "edit page" and "edit content" on page [%d], '
            . 'and the acting backend user lacks "edit content" there. Nothing was written.',
            $this->int($page['uid'] ?? 0),
        );
    }

    /**
     * The page a record lies on, once the user may edit content there.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>|string
     */
    private function pageWithContentRight(array $record, BackendUserAuthentication $user): array|string
    {
        $page = $this->liveRow('pages', $this->int($record['pid'] ?? 0));

        return $page !== null && $user->doesUserHaveAccess($page, Permission::CONTENT_EDIT) ? $page : self::NOT_PERMITTED;
    }

    /**
     * The record-level rights the DataHandler asks of the record: its table,
     * its language, the values of its `authMode` fields and its edit lock.
     *
     * @param array<string, mixed> $record
     */
    private function mayEditRecord(string $table, array $record, BackendUserAuthentication $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (!$user->check('tables_modify', $table)) {
            return false;
        }

        $languageField = $this->ctrlString($table, 'languageField');
        if ($languageField !== '' && !$user->checkLanguageAccess($this->int($record[$languageField] ?? 0))) {
            return false;
        }

        foreach ($this->columns($table) as $name => $definition) {
            $config = is_array($definition) ? ($definition['config'] ?? null) : null;
            if (!is_array($config) || ($config['type'] ?? null) !== 'select' || !(bool) ($config['authMode'] ?? false)) {
                continue;
            }

            if (array_key_exists($name, $record) && !$user->checkAuthMode($table, $name, $this->string($record[$name]))) {
                return false;
            }
        }

        $editlock = $this->ctrlString($table, 'editlock');

        return $editlock === '' || !(bool) ($record[$editlock] ?? false);
    }

    /** Whether the column is no exclude field, or the user holds its grant. */
    private function mayEditField(BackendUserAuthentication $user, string $table, string $column): bool
    {
        $definition = $this->columns($table)[$column] ?? null;

        return $user->isAdmin()
            || !is_array($definition)
            || !(bool) ($definition['exclude'] ?? false)
            || $user->check('non_exclude_fields', $table . ':' . $column);
    }

    private function isFileField(string $table, string $field): bool
    {
        $definition = $this->columns($table)[$field] ?? null;
        $config     = is_array($definition) ? ($definition['config'] ?? null) : null;

        return is_array($config) && ($config['type'] ?? null) === 'file';
    }

    /**
     * A live, undeleted row: a workspace version is another workspace's draft
     * and counts as not there.
     *
     * @return array<string, mixed>|null
     */
    private function liveRow(string $table, int $uid): ?array
    {
        if ($this->ctrl($table) === []) {
            return null;
        }

        return $this->rows->findLive($table, $uid, (bool) ($this->ctrl($table)['versioningWS'] ?? false));
    }

    /**
     * @return array<mixed>
     */
    private function ctrl(string $table): array
    {
        $tca    = $GLOBALS['TCA'] ?? null;
        $config = is_array($tca) ? ($tca[$table] ?? null) : null;

        return is_array($config) && is_array($config['ctrl'] ?? null) ? $config['ctrl'] : [];
    }

    private function ctrlString(string $table, string $key): string
    {
        $value = $this->ctrl($table)[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<mixed>
     */
    private function columns(string $table): array
    {
        $tca    = $GLOBALS['TCA'] ?? null;
        $config = is_array($tca) ? ($tca[$table] ?? null) : null;

        return is_array($config) && is_array($config['columns'] ?? null) ? $config['columns'] : [];
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The record's title as the backend shows it, "[No title]" when it has none.
     *
     * @param array<string, mixed> $record
     */
    public static function titleOf(string $table, array $record): string
    {
        $title = trim(BackendUtility::getRecordTitle($table, $record));

        return $title !== '' ? $title : BackendUtility::getNoRecordTitle();
    }
}
