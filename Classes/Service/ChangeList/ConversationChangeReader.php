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
 * same tool that precedes its `tool_changes` event.
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
            foreach ($this->runtime->events($actor, $uuid) as $event) {
                $tool = is_string($event->payload['toolName'] ?? null) ? $event->payload['toolName'] : '';
                if ($event->kind === RunStep::KIND_TOOL) {
                    $failed[$tool] = ($event->payload['toolIsError'] ?? false) === true;
                } elseif ($event->kind === RunStep::KIND_WRITE) {
                    $writes[] = $event->payload;
                } elseif ($event->kind === RunStep::KIND_CHANGES) {
                    $set = ObservedChangeSet::tryFromEventPayload($event->payload);
                    if ($set instanceof ObservedChangeSet) {
                        $observed[] = [$set, $failed[$tool] ?? false];
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

            foreach ($observed as [$set, $callFailed]) {
                $notCaptured = $notCaptured || $set->coverage === ChangeCoverage::NOT_CAPTURED;
                $omitted += $set->omitted;
                foreach ($set->changes as $change) {
                    $this->merge($byRecord, $this->changeOf($change, $callFailed));
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

    private function changeOf(ObservedChange $change, bool $callFailed): RecordChange
    {
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
            $callFailed,
        );
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

        $hookFailed   = ($payload['hookFailedAfterWrite'] ?? false) === true;
        $completeness = is_string($payload['writeCompleteness'] ?? null) ? WriteCompleteness::tryFrom($payload['writeCompleteness']) : null;
        $complete     = match ($completeness) {
            WriteCompleteness::COMPLETE => true,
            WriteCompleteness::PARTIAL  => false,
            default                     => null,
        };

        return new RecordChange(
            $table,
            $uid,
            isWriteTarget: true,
            failedAfterWrite: $hookFailed,
            legacy: true,
            complete: $hookFailed ? null : $complete,
        );
    }
}
