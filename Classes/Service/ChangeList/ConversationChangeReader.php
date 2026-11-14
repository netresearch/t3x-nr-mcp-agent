<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\ChangeList;

use Netresearch\NrLlm\Domain\Enum\ChangeCoverage;
use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ObservedChange;
use Netresearch\NrLlm\Domain\ValueObject\ObservedChangeSet;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;

/**
 * Reads what the runs of a conversation changed from their persisted events
 * (ADR-034), through the one door nr-llm offers: `events()`, for a run the
 * actor may read (`AGENT_READ`, as the chat reads a pending run).
 *
 * Per run, the `tool_changes` events are the list (nr-llm ADR-292). A run
 * with none — recorded before nr-llm wrote them, or one that wrote nothing —
 * falls back to its `tool_write` events: the record each tool named, with no
 * action, no workspace and no dependent record, marked legacy. A run that
 * has `tool_changes` events is never also read through `tool_write`: both
 * name the tool's target, and the second reading would double it.
 *
 * Whether a call failed after it wrote is read from the `tool` event of the
 * same tool that precedes its `tool_changes` event, and from the
 * `hookFailedAfterWrite` of its `tool_write` event, which a tool that reports
 * no error can still set. Whether the tool wrote all it planned
 * (`writeCompleteness`) is read from the same `tool_write` event and put on the
 * call's write target. That event is the one between the call's `tool` event
 * and its `tool_changes` event (nr-llm RunTrace::recordToolResult); it adds
 * these facts to the observed record and no row of its own.
 */
final readonly class ConversationChangeReader
{
    public function __construct(
        private AgentRuntimeInterface $runtime,
        private AgentRunRepositoryInterface $runs,
    ) {}

    /**
     * @param list<string>                                              $runUuids     from the conversation's run records, oldest first
     * @param list<array{table: string, uid: int, complete: bool|null}> $legacyWrites the writes of run records that carry no run uuid
     */
    public function read(AiActorContext $actor, array $runUuids, array $legacyWrites = []): ChangeCollection
    {
        /** @var array<string, RecordChange> $byRecord */
        $byRecord    = [];
        $notCaptured = false;
        $omitted     = 0;
        $legacy      = $legacyWrites !== [];
        $read        = 0;
        $unreadable  = 0;

        foreach ($legacyWrites as $write) {
            $this->merge($byRecord, new RecordChange(
                $write['table'],
                $write['uid'],
                isWriteTarget: true,
                legacy: true,
                complete: $write['complete'],
            ));
        }

        foreach ($runUuids as $uuid) {
            $run = $this->runs->findByUuid($uuid);
            if (!$run instanceof AgentRun || !$actor->mayActOnRun($run, ServiceAccountScope::AGENT_READ)) {
                ++$unreadable;

                continue;
            }

            ++$read;
            $observed = [];
            $writes   = [];
            $failed   = [];
            $pending  = [];
            foreach ($this->runtime->events($actor, $uuid) as $event) {
                $tool = is_string($event->payload['toolName'] ?? null) ? $event->payload['toolName'] : '';
                if ($event->kind === RunStep::KIND_TOOL) {
                    $failed[$tool] = ($event->payload['toolIsError'] ?? false) === true;
                    unset($pending[$tool]);
                } elseif ($event->kind === RunStep::KIND_WRITE) {
                    $writes[]       = $event->payload;
                    $pending[$tool] = $event->payload;
                } elseif ($event->kind === RunStep::KIND_CHANGES) {
                    $set = ObservedChangeSet::tryFromEventPayload($event->payload);
                    if ($set instanceof ObservedChangeSet) {
                        $observed[] = [$set, $failed[$tool] ?? false, $pending[$tool] ?? null];
                        unset($pending[$tool]);
                    }
                }
            }

            if ($observed === []) {
                $legacy = $legacy || $writes !== [];
                foreach ($writes as $payload) {
                    $change = $this->legacyChange($payload);
                    if ($change instanceof RecordChange) {
                        $this->merge($byRecord, $change);
                    }
                }

                continue;
            }

            foreach ($observed as [$set, $callFailed, $write]) {
                $notCaptured = $notCaptured || $set->coverage === ChangeCoverage::NOT_CAPTURED;
                $omitted += $set->omitted;
                foreach ($set->changes as $change) {
                    $this->merge($byRecord, $this->changeOf($change, $callFailed, $write));
                }
            }
        }

        return new ChangeCollection(array_values($byRecord), $notCaptured, $omitted, $legacy, $read, $unreadable);
    }

    /**
     * @param array<string, RecordChange> $byRecord
     */
    private function merge(array &$byRecord, RecordChange $change): void
    {
        $key            = $change->key();
        $byRecord[$key] = isset($byRecord[$key]) ? $byRecord[$key]->seenAgain($change) : $change;
    }

    /**
     * @param array<mixed>|null $write the `tool_write` payload of the same call
     */
    private function changeOf(ObservedChange $change, bool $callFailed, ?array $write): RecordChange
    {
        [$hookFailed, $complete] = $write === null ? [false, null] : $this->writeFacts($write);
        $isTarget                = $write !== null && (
            $change->isWriteTarget
            || ($change->record->table === ($write['writeTargetTable'] ?? null) && $change->record->uid === ($write['writeTargetUid'] ?? null))
        );

        return new RecordChange(
            $change->record->table,
            $change->record->uid,
            $change->action,
            $change->workspace,
            $change->liveUid,
            $change->language,
            $change->isWriteTarget,
            $change->hidden,
            $change->restrictions,
            // A hook that failed after the write puts the call's records in doubt, as an error does.
            $callFailed || $hookFailed,
            complete: $isTarget ? $complete : null,
        );
    }

    /**
     * What a `tool_write` event states about its write: whether a hook failed
     * after it, and whether the tool wrote all it planned (null: not stated).
     * The two are independent: nr-llm reports the hook failure beside the
     * completeness and does not change it (ToolResult).
     *
     * @param array<mixed> $payload
     *
     * @return array{bool, ?bool}
     */
    private function writeFacts(array $payload): array
    {
        $hookFailed   = ($payload['hookFailedAfterWrite'] ?? false) === true;
        $completeness = is_string($payload['writeCompleteness'] ?? null) ? WriteCompleteness::tryFrom($payload['writeCompleteness']) : null;
        $complete     = match ($completeness) {
            WriteCompleteness::COMPLETE => true,
            WriteCompleteness::PARTIAL  => false,
            default                     => null,
        };

        return [$hookFailed, $complete];
    }

    /**
     * @param array<mixed> $payload a `tool_write` event's payload
     */
    private function legacyChange(array $payload): ?RecordChange
    {
        $table = $payload['writeTargetTable'] ?? null;
        $uid   = $payload['writeTargetUid'] ?? null;
        if (!is_string($table) || $table === '' || !is_int($uid) || $uid <= 0) {
            return null;
        }

        [$hookFailed, $complete] = $this->writeFacts($payload);

        return new RecordChange(
            $table,
            $uid,
            isWriteTarget: true,
            failedAfterWrite: $hookFailed,
            legacy: true,
            complete: $complete,
        );
    }
}
