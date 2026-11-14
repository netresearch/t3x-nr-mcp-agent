<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\ChangeList;

use Netresearch\NrLlm\Domain\Enum\ChangeAction;

/**
 * One record a run changed, as nr-llm observed it (ADR-292) or, for a run
 * from before that record existed, as its `tool_write` step named it (ADR-034).
 *
 * Facts only, no title and no permission: those are added per viewer
 * (ChangePresenter). Every flag is a separate fact: a record that is not
 * hidden can still be a draft, and nothing here states that it is public.
 *
 * @phpstan-type Restrictions list<'starttime'|'endtime'|'fe_group'>
 */
final readonly class RecordChange
{
    /**
     * @param list<string> $restrictions
     * @param ?bool        $complete     the write target only: whether the tool said it wrote what it planned (its `tool_write` step); null when not stated
     */
    public function __construct(
        public string $table,
        public int $uid,
        public ?ChangeAction $action = null,
        public int $workspace = 0,
        public ?int $liveUid = null,
        public ?int $language = null,
        public bool $isWriteTarget = false,
        public ?bool $hidden = null,
        public array $restrictions = [],
        public bool $failedAfterWrite = false,
        public bool $legacy = false,
        public ?bool $complete = null,
    ) {}

    public function key(): string
    {
        return $this->table . ':' . $this->uid;
    }

    /**
     * The same record seen again, by a later call of the run or conversation:
     * the later observation states the record's state now, a record created
     * earlier stays created, and having been the tool's target once, and having been in doubt after a failed
     * call, is kept.
     */
    public function seenAgain(self $later): self
    {
        $keepsCreated = $this->action === ChangeAction::CREATED
            && in_array($later->action, [ChangeAction::UPDATED, ChangeAction::MOVED], true);

        return new self(
            $later->table,
            $later->uid,
            $keepsCreated ? $this->action : ($later->action ?? $this->action),
            $later->workspace,
            $later->liveUid ?? $this->liveUid,
            $later->language ?? $this->language,
            $this->isWriteTarget || $later->isWriteTarget,
            $later->hidden ?? $this->hidden,
            $later->restrictions,
            $this->failedAfterWrite || $later->failedAfterWrite,
            $this->legacy && $later->legacy,
            $later->complete ?? $this->complete,
        );
    }
}
