<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\ConversationList;

use JsonSerializable;

/**
 * One conversation as a list shows it (ConversationListPresenter, ADR-027).
 * The chat endpoint sends it as JSON, the dashboard widget hands it to its
 * template.
 */
final readonly class ConversationListItem implements JsonSerializable
{
    /**
     * @param string                                                                               $title        the stored title
     * @param string                                                                               $displayTitle what a list shows
     * @param string                                                                               $fullTitle    the tooltip: the stored title unshortened, or the derived one
     * @param 'tour'|'altText'|'own'|'empty'                                                       $titleKind    where the shown title comes from
     * @param list<string>                                                                         $context      what the title does not name, translated
     * @param string                                                                               $contextLabel the context and the point of a tour, as one line
     * @param array{uid: int, languageUid: int}|null                                               $page         the page the conversation is about, when the user may see it
     * @param array{current: int, total: int, label: string}|null                                  $progress     the point a guided tour is at
     * @param string                                                                               $createdAt    ISO 8601, '' when unknown
     * @param array{kind: string, text: string, label: string, absolute: string, datetime: string} $lastActivity when the last message was written
     * @param 'neutral'|'info'|'warning'|'danger'                                                  $statusTone   the colour the status is shown in
     * @param string                                                                               $titleSuffix  what tells two alike titles apart, the end of $displayTitle; '' when nothing has to
     */
    public function __construct(
        public int $uid,
        public string $title,
        public string $displayTitle,
        public string $fullTitle,
        public string $titleKind,
        public array $context,
        public string $contextLabel,
        public ?array $page,
        public bool $pageGone,
        public ?string $skillTitle,
        public bool $guided,
        public ?array $progress,
        public string $createdAt,
        public int $lastActivityTimestamp,
        public array $lastActivity,
        public string $status,
        public string $statusTone,
        public string $statusLabel,
        public int $messageCount,
        public bool $pinned,
        public string $titleSuffix = '',
    ) {}

    /**
     * The same item with the start time after its title, as the presenter
     * tells two alike apart. The suffix is kept apart as well, so a list that
     * shortens the title shortens the part before it (ADR-027).
     */
    public function withTitleSuffix(string $suffix): self
    {
        return new self(
            uid: $this->uid,
            title: $this->title,
            displayTitle: $this->displayTitle . ConversationTitle::SEPARATOR . $suffix,
            fullTitle: $this->fullTitle,
            titleKind: $this->titleKind,
            context: $this->context,
            contextLabel: $this->contextLabel,
            page: $this->page,
            pageGone: $this->pageGone,
            skillTitle: $this->skillTitle,
            guided: $this->guided,
            progress: $this->progress,
            createdAt: $this->createdAt,
            lastActivityTimestamp: $this->lastActivityTimestamp,
            lastActivity: $this->lastActivity,
            status: $this->status,
            statusTone: $this->statusTone,
            statusLabel: $this->statusLabel,
            messageCount: $this->messageCount,
            pinned: $this->pinned,
            titleSuffix: $suffix,
        );
    }

    /** The shown title without the suffix that tells it apart; the whole title when there is none. */
    public function getTitleBase(): string
    {
        return $this->titleSuffix === ''
            ? $this->displayTitle
            : substr($this->displayTitle, 0, -strlen(ConversationTitle::SEPARATOR . $this->titleSuffix));
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'uid'          => $this->uid,
            'title'        => $this->title,
            'displayTitle' => $this->displayTitle,
            'titleSuffix'  => $this->titleSuffix,
            'fullTitle'    => $this->fullTitle,
            'titleKind'    => $this->titleKind,
            'contextLabel' => $this->contextLabel,
            'pageUid'      => $this->page['uid'] ?? 0,
            'pageGone'     => $this->pageGone,
            'skillTitle'   => $this->skillTitle,
            'guided'       => $this->guided,
            'progress'     => $this->progress,
            'createdAt'    => $this->createdAt,
            // The client words the time ("Heute, 10:42") in the reader's
            // time zone and anew on every render (conversation-list.js).
            'lastActivityAt' => $this->lastActivity['datetime'],
            'status'         => $this->status,
            'statusTone'     => $this->statusTone,
            'statusLabel'    => $this->statusLabel,
            'messageCount'   => $this->messageCount,
            'pinned'         => $this->pinned,
        ];
    }
}
