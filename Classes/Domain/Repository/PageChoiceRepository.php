<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Repository;

use Generator;
use InvalidArgumentException;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Pages the current backend user may work on with the assistant: the ones a
 * dashboard widget offers to improve, and the checks for a page named
 * elsewhere (an open improvement point).
 *
 * A page is offered for improvement only when the user may edit its content
 * in the default language, the version the widgets start the chat on:
 * - it lies in one of their web mounts and grants them "show page"
 *   (BackendUtility::readPageAccess(), as in core's "latest changed pages"
 *   widget);
 * - it grants them "edit content" (Permission::CONTENT_EDIT);
 * - it is not locked for editing (`editlock`, which binds everyone but
 *   admins, as core's recordEditAccessInternals() treats it);
 * - the chat would accept the start: the user may edit the language and the
 *   page's site has it (mayStartIn(), the same rule as the chat's start).
 * The permission clause in the query only keeps the list from filling up with
 * pages these checks would drop.
 *
 * The mount is checked per page, after the query, so candidates are read in
 * batches until the list is full: where a group grants permissions widely and
 * the mount is what narrows the view, the newest pages may all lie elsewhere.
 * MAX_CANDIDATES bounds the work on a large site; past it the list may come
 * out shorter than asked.
 *
 * Offering a page is not permission to change it: whatever the chat does on
 * the page is checked again when it does it.
 */
readonly class PageChoiceRepository
{
    /** Links, shortcuts, spacers, folders and the recycler hold nothing a tour could improve. */
    private const EXCLUDED_DOKTYPES = [
        PageRepository::DOKTYPE_LINK,
        PageRepository::DOKTYPE_SHORTCUT,
        PageRepository::DOKTYPE_SPACER,
        PageRepository::DOKTYPE_SYSFOLDER,
        255, // recycler, a constant TYPO3 14 no longer has
    ];

    /** Candidate rows read per query. */
    private const BATCH_SIZE = 100;

    /** Candidate rows read at most for one list. */
    private const MAX_CANDIDATES = 2000;

    /** A page title shorter than this reads as too short for search results (decision on the SEO tour). */
    public const MIN_TITLE_LENGTH = 30;

    /** SEO defects findWithSeoDefects() reports, in this order. */
    public const DEFECT_MISSING_DESCRIPTION = 'missingDescription';

    public const DEFECT_SHORT_TITLE = 'shortTitle';

    public const DEFECT_MISSING_SOCIAL_IMAGE = 'missingSocialImage';

    public function __construct(
        private ConnectionPool $connectionPool,
        private SiteFinder $siteFinder,
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    /**
     * The most recently changed pages the user may improve, newest first.
     *
     * @return list<array{uid: int, title: string}>
     */
    public function findRecentlyChanged(int $limit): array
    {
        $user = $this->backendUser();
        if ($user === null || $limit < 1) {
            return [];
        }

        $pages = [];
        foreach ($this->candidateRows($user, ['uid']) as $row) {
            $page = $this->findEditable($this->uidOf($row));
            if ($page !== null) {
                $pages[] = $page;
                if (count($pages) === $limit) {
                    break;
                }
            }
        }

        return $pages;
    }

    /**
     * The most recently changed pages the user may improve that miss what the
     * SEO tour fixes, newest first, each with its defects:
     * - no meta description (`description`);
     * - a title shorter than MIN_TITLE_LENGTH characters — the SEO title
     *   (`seo_title`) where EXT:seo provides one and it is set, else the page
     *   title, as that is what search results show;
     * - no social-media image (`og_image`), checked only where EXT:seo
     *   provides the field.
     * Default-language pages only, read from the database as stored: no
     * model, no rendering.
     *
     * @return list<array{uid: int, title: string, defects: non-empty-list<string>}>
     */
    public function findWithSeoDefects(int $limit): array
    {
        $user = $this->backendUser();
        if ($user === null || $limit < 1) {
            return [];
        }

        $columns = ['uid', 'title', 'description'];
        $seoTitle = $this->hasPageField('seo_title');
        $socialImage = $this->hasPageField('og_image');
        if ($seoTitle) {
            $columns[] = 'seo_title';
        }

        if ($socialImage) {
            $columns[] = 'og_image';
        }

        $pages = [];
        foreach ($this->candidateRows($user, $columns) as $row) {
            $defects = $this->seoDefects($row, $seoTitle, $socialImage);
            $page = $defects === [] ? null : $this->findEditable($this->uidOf($row));
            if ($page !== null) {
                $pages[] = [...$page, 'defects' => $defects];
                if (count($pages) === $limit) {
                    break;
                }
            }
        }

        return $pages;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function seoDefects(array $row, bool $seoTitle, bool $socialImage): array
    {
        $defects = [];
        if (trim($this->stringOf($row['description'] ?? null)) === '') {
            $defects[] = self::DEFECT_MISSING_DESCRIPTION;
        }

        $title = $seoTitle ? trim($this->stringOf($row['seo_title'] ?? null)) : '';
        if ($title === '') {
            $title = trim($this->stringOf($row['title'] ?? null));
        }

        if (mb_strlen($title) < self::MIN_TITLE_LENGTH) {
            $defects[] = self::DEFECT_SHORT_TITLE;
        }

        if ($socialImage && (!is_numeric($row['og_image'] ?? null) || (int) $row['og_image'] <= 0)) {
            $defects[] = self::DEFECT_MISSING_SOCIAL_IMAGE;
        }

        return $defects;
    }

    /** Whether `pages` has the field; EXT:seo adds `seo_title` and `og_image`. */
    private function hasPageField(string $field): bool
    {
        return $this->tcaSchemaFactory->has('pages') && $this->tcaSchemaFactory->get('pages')->hasField($field);
    }

    private function stringOf(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function uidOf(array $row): int
    {
        return is_numeric($row['uid'] ?? null) ? (int) $row['uid'] : 0;
    }

    /**
     * Candidate rows in batches, newest change first, up to MAX_CANDIDATES.
     *
     * @param list<string> $columns
     * @return Generator<int, array<string, mixed>>
     */
    private function candidateRows(BackendUserAuthentication $user, array $columns): Generator
    {
        for ($offset = 0; $offset < self::MAX_CANDIDATES; $offset += self::BATCH_SIZE) {
            $rows = $this->candidateBatch($user, $columns, $offset);
            yield from $rows;

            if (count($rows) < self::BATCH_SIZE) {
                return;
            }
        }
    }

    /**
     * One batch of default-language pages that may hold content and grant the
     * user "edit content", newest change first.
     *
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    private function candidateBatch(BackendUserAuthentication $user, array $columns, int $offset): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($user->workspace));

        $qb->select(...$columns)
            ->from('pages')
            ->where(
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->notIn('doktype', $qb->createNamedParameter(self::EXCLUDED_DOKTYPES, Connection::PARAM_INT_ARRAY)),
            )
            ->orderBy('tstamp', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults(self::BATCH_SIZE);

        $permsClause = QueryHelper::stripLogicalOperatorPrefix($user->getPagePermsClause(Permission::CONTENT_EDIT));
        if ($permsClause !== '') {
            $qb->andWhere($permsClause);
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * The page if the user may see it in the page tree, else null.
     *
     * @return array{uid: int, title: string}|null
     */
    public function findAccessible(int $uid): ?array
    {
        $row = $this->readableRow($uid);

        return $row === null ? null : $this->choice($uid, $row);
    }

    /**
     * The page if the user may improve it — edit its content and start the
     * chat on it in the language (default: the default language) — else null.
     *
     * @return array{uid: int, title: string}|null
     */
    public function findEditable(int $uid, int $languageUid = 0): ?array
    {
        $user = $this->backendUser();
        $row = $this->readableRow($uid);
        if ($user === null || $row === null || !$user->doesUserHaveAccess($row, Permission::CONTENT_EDIT)) {
            return null;
        }

        if (!$user->isAdmin() && (bool) ($row['editlock'] ?? false)) {
            return null;
        }

        return $this->mayStartIn($uid, $languageUid) ? $this->choice($uid, $row) : null;
    }

    /**
     * Whether the chat would start a conversation about the page in this
     * language: the user may edit the language and the page's site has it. A
     * page outside every site has its default language and no other.
     */
    public function mayStartIn(int $pageUid, int $languageUid): bool
    {
        $user = $this->backendUser();
        if ($user === null || $languageUid < 0 || !$user->checkLanguageAccess($languageUid)) {
            return false;
        }

        try {
            $site = $this->siteFinder->getSiteByPageId($pageUid);
        } catch (SiteNotFoundException) {
            return $languageUid === 0;
        }

        try {
            $site->getLanguageById($languageUid);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /**
     * The page row if it lies in the user's web mounts and grants them "show
     * page", else null. Typed as core's readPageAccess() returns it.
     *
     * @return array<mixed>|null
     */
    private function readableRow(int $uid): ?array
    {
        $user = $this->backendUser();
        if ($user === null || $uid < 1) {
            return null;
        }

        $row = BackendUtility::readPageAccess($uid, $user->getPagePermsClause(Permission::PAGE_SHOW));

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<mixed> $row
     * @return array{uid: int, title: string}
     */
    private function choice(int $uid, array $row): array
    {
        $title = $row['title'] ?? '';

        return ['uid' => $uid, 'title' => is_string($title) ? $title : ''];
    }

    private function backendUser(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $user : null;
    }
}
