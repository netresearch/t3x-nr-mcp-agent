<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Model;

use DateTimeImmutable;
use DateTimeInterface;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\DenyReason;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Enum\ProposalOutcome;

/**
 * Simple DTO/Value Object — no Extbase, no AbstractEntity.
 * Use Conversation::fromRow() to hydrate from a DB row.
 */
final class Conversation
{
    /** Prefix of a legacy transcript the upgrade wizard could not decode and left in place. */
    public const UNDECODABLE_MARKER = '!undecodable:';

    /** The recorded decision that is an answer to a question (ADR-018). */
    public const DECISION_INPUT = 'input';

    /** The notice on the user message that holds an answer to a question (ADR-018). */
    public const NOTICE_INPUT_ANSWER = 'inputAnswer';

    /**
     * The key that marks an assistant message the model wrote in the same
     * round as a tool call, before the tool ran.
     */
    public const KEY_BESIDE_TOOL_CALL = 'besideToolCall';

    private int $uid = 0;

    private int $beUser = 0;

    private string $title = '';

    private string $messages = '';

    private int $messageCount = 0;

    private string $status = 'idle';

    private string $currentRequestId = '';

    private string $systemPrompt = '';

    /**
     * Where the user was in the backend when they last sent a message: the
     * page selected in the page tree and the module open beside it, as JSON.
     *
     * Kept on the conversation rather than on the message: the turn runs in a
     * worker, so what the browser knew at send time has to travel with the
     * row, and the message array goes to the provider as it is stored — a key
     * added there would be sent along (NEXT-172).
     */
    private string $viewContext = '';

    /**
     * The skill the conversation was started with or switched to, by its
     * nr-llm identifier; empty for none (ADR-019). Passed to every run of the
     * conversation as the invoked skill. Written on its own column like the
     * instructions, so a turn that settles later cannot put an old one back.
     */
    private string $skillIdentifier = '';

    /** The skill's record uid, when the catalogue knew it (0 otherwise). */
    private int $skillUid = 0;

    /**
     * Whether the next turn starts the conversation's process skill anew
     * (nr-llm ADR-214 item 10): set when a process skill is picked in a
     * conversation that has already run, cleared by the turn that carried
     * the invocation. Written on its own column, like the skill, and never
     * by a full-row write.
     */
    private bool $skillStartPending = false;

    /**
     * What the agent did in the current turn, as a JSON list of step
     * summaries (NEXT-172). Written column by column while the turn runs
     * ({@see \Netresearch\NrMcpAgent\Service\RunActivityRecorder}) and
     * therefore deliberately not part of toRow(): a full-row write at the end
     * of the turn must not put back the list it started with.
     */
    private string $activity = '';

    /**
     * What the guided-state tools last reported for this conversation's runs
     * (ADR-020): the progress for the header and the element to highlight, as
     * JSON. Taken over from the run's state when a run returns.
     */
    private string $guidedState = '';

    /**
     * The process card a recorded decision answers (ADR-023): its tool and
     * the record and fields it names, as JSON, kept from the request until
     * the worker has recorded the outcome. Empty for any other card.
     */
    private string $approvalCard = '';

    /**
     * What became of each proposal of this conversation's guided process
     * (ADR-023), as JSON: the outcome, where in the transcript it happened,
     * and the record and fields the card named.
     */
    private string $cardOutcomes = '';

    private bool $archived = false;

    private bool $pinned = false;

    private string $errorMessage = '';

    /**
     * What KIND of failure the message describes, as a code the reader's
     * request turns into text: empty for an ordinary message.
     *
     * The message is stored as it happened; the code decides how it is shown
     * and to whom (ADR-017). A provider without an API key is technical detail
     * for an administrator and a plain "ask the administration" for an editor,
     * and only the request knows which of the two is reading.
     */
    private string $errorCode = '';

    /**
     * The run that is waiting for an approval, so the chat can link to it.
     *
     * Empty whenever nothing is pending. It is a separate column rather than
     * a part of the message because the message is prose and a link needs an
     * identifier the template can put into a URL.
     */
    private string $approvalRunUuid = '';

    /**
     * The decision a user made in the request, waiting for the worker to carry
     * it out: 'approve', 'deny', 'input' for an answer to a question
     * (ADR-018), or empty when nothing is pending.
     */
    private string $approvalDecision = '';

    /**
     * The turn digest the card carried, kept with the decision so the worker
     * hands the runtime exactly what the reader saw (ADR-132).
     */
    private string $approvalTurnDigest = '';

    /**
     * The answer a user gave to a run that asked for input, as JSON, waiting
     * for the worker to hand it to the runtime (ADR-018). Recorded with the
     * decision 'input' and the digest of the question it answers, the way an
     * approval decision is recorded.
     */
    private string $pendingInput = '';

    /**
     * Why a denial was given on the card — 'variant', 'skip' or 'keep' — or empty
     * (ADR-018). Kept with the decision for the worker.
     */
    private string $approvalDenyReason = '';

    /**
     * The conversation's last run, whatever its outcome (nr-llm ADR-228): a
     * later turn names it as its predecessor, and nr-llm derives the guided
     * process from it. Unlike approvalRunUuid it survives every status.
     */
    private string $lastRunUuid = '';

    /**
     * The image use the conversation is about, when it was started with
     * "Optimieren" in the list of missing alt texts (ADR-026), as JSON:
     * `reference`, the sys_file_reference uid. Empty for any other
     * conversation. Only the uid is kept: whatever the chat says or sends
     * about the image is read again from the reference, with the user's
     * rights, when it is needed. Written with the row: the chat moves to
     * another image only while no turn runs, and the move is the claim of
     * the turn that proposes its text, so the turn reads the image its card
     * names.
     */
    private string $altTextHit = '';

    /** The privacy setting "Bildanalyse" the latest turn was dispatched with, as JSON (ImageAnalysisTurnSettings). */
    private string $imageAnalysis = '';

    private int $tstamp = 0;

    private int $crdate = 0;

    /**
     * Factory method: hydrate from a database row array.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $conversation                     = new self();
        $conversation->uid                = (int) self::val($row, 'uid', 0);
        $conversation->beUser             = (int) self::val($row, 'be_user', 0);
        $conversation->title              = (string) self::val($row, 'title', '');
        $conversation->messages           = (string) self::val($row, 'messages', '');
        $conversation->messageCount       = (int) self::val($row, 'message_count', 0);
        $conversation->status             = (string) self::val($row, 'status', 'idle');
        $conversation->currentRequestId   = (string) self::val($row, 'current_request_id', '');
        $conversation->systemPrompt       = (string) self::val($row, 'system_prompt', '');
        $conversation->viewContext        = (string) self::val($row, 'view_context', '');
        $conversation->skillIdentifier    = (string) self::val($row, 'skill_identifier', '');
        $conversation->skillUid           = (int) self::val($row, 'skill_uid', 0);
        $conversation->skillStartPending  = (bool) self::val($row, 'skill_start_pending', false);
        $conversation->activity           = (string) self::val($row, 'activity', '');
        $conversation->guidedState        = (string) self::val($row, 'guided_state', '');
        $conversation->approvalCard       = (string) self::val($row, 'approval_card', '');
        $conversation->cardOutcomes       = (string) self::val($row, 'card_outcomes', '');
        $conversation->archived           = (bool) self::val($row, 'archived', false);
        $conversation->pinned             = (bool) self::val($row, 'pinned', false);
        $conversation->errorMessage       = (string) self::val($row, 'error_message', '');
        $conversation->errorCode          = (string) self::val($row, 'error_code', '');
        $conversation->approvalRunUuid    = (string) self::val($row, 'approval_run_uuid', '');
        $conversation->approvalDecision   = (string) self::val($row, 'approval_decision', '');
        $conversation->approvalTurnDigest = (string) self::val($row, 'approval_turn_digest', '');
        $conversation->pendingInput       = (string) self::val($row, 'pending_input', '');
        $conversation->approvalDenyReason = (string) self::val($row, 'approval_deny_reason', '');
        $conversation->lastRunUuid        = (string) self::val($row, 'last_run_uuid', '');
        $conversation->altTextHit         = (string) self::val($row, 'alt_text_hit', '');
        $conversation->imageAnalysis      = (string) self::val($row, 'image_analysis', '');
        $conversation->tstamp             = (int) self::val($row, 'tstamp', 0);
        $conversation->crdate             = (int) self::val($row, 'crdate', 0);

        return $conversation;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function val(array $row, string $key, mixed $default): int|float|string|bool|null
    {
        $v = $row[$key] ?? $default;

        return is_scalar($v) ? $v : null;
    }

    /**
     * Serialize back to a DB-compatible array (for INSERT/UPDATE).
     *
     * `system_prompt` is not part of it: the user sets it on its own
     * (ConversationRepository::updateSystemPrompt()), and every full-row write
     * — the claim of a new turn, the worker's final save — carries the value it
     * loaded. Written from here, a save that races the user's edit would put
     * the old instructions back without anyone noticing (NEXT-172).
     *
     * @return array<string, int|string>
     */
    public function toRow(): array
    {
        return [
            'be_user'              => $this->beUser,
            'title'                => $this->title,
            'messages'             => $this->messages,
            'message_count'        => $this->messageCount,
            'status'               => $this->status,
            'current_request_id'   => $this->currentRequestId,
            'view_context'         => $this->viewContext,
            'guided_state'         => $this->guidedState,
            'approval_card'        => $this->approvalCard,
            'card_outcomes'        => $this->cardOutcomes,
            'archived'             => (int) $this->archived,
            'pinned'               => (int) $this->pinned,
            'error_message'        => $this->errorMessage,
            'error_code'           => $this->errorCode,
            'approval_run_uuid'    => $this->approvalRunUuid,
            'approval_decision'    => $this->approvalDecision,
            'approval_turn_digest' => $this->approvalTurnDigest,
            'last_run_uuid'        => $this->lastRunUuid,
            'pending_input'        => $this->pendingInput,
            'approval_deny_reason' => $this->approvalDenyReason,
            'alt_text_hit'         => $this->altTextHit,
            'image_analysis'       => $this->imageAnalysis,
        ];
    }

    public function getUid(): int
    {
        return $this->uid;
    }

    public function getBeUser(): int
    {
        return $this->beUser;
    }

    public function setBeUser(int $beUser): void
    {
        $this->beUser = $beUser;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = mb_substr($title, 0, 255);
    }

    public function getMessages(): string
    {
        return $this->messages;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDecodedMessages(): array
    {
        // A legacy transcript the upgrade wizard could not decode is kept
        // behind this marker (ADR-016); the conversation opens empty instead
        // of failing on every request.
        if ($this->messages === '' || str_starts_with($this->messages, self::UNDECODABLE_MARKER)) {
            return [];
        }

        /** @var list<array<string, mixed>> $decoded */
        $decoded = json_decode($this->messages, true, 512, JSON_THROW_ON_ERROR);

        // Normalize tool_calls: OpenAI requires arguments as JSON string, not object.
        // json_decode turns the stored string into an array — re-encode it.
        foreach ($decoded as &$msg) {
            if (!isset($msg['tool_calls'])) {
                continue;
            }

            if (!is_array($msg['tool_calls'])) {
                continue;
            }

            foreach ($msg['tool_calls'] as &$call) {
                if (!is_array($call)) {
                    continue;
                }

                if (!is_array($call['function'] ?? null)) {
                    continue;
                }

                if (!is_array($call['function']['arguments'] ?? null)) {
                    continue;
                }

                $call['function']['arguments'] = json_encode($call['function']['arguments'], JSON_THROW_ON_ERROR);
            }

            unset($call);
        }

        unset($msg);

        return $decoded;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function setMessages(array $messages): void
    {
        $this->messages     = json_encode($messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->messageCount = count($messages);
    }

    /**
     * @param string|array<mixed>  $content
     * @param string               $notice     a code the chat renders as a label in the reader's language
     *                                         (ADR-017); nr-llm's message factory ignores the key, so it
     *                                         does not reach the model
     * @param list<string>         $noticeArgs values the label is filled with, e.g. record references
     * @param array<string, mixed> $data       what the chat renders beside the label (the image card of a
     *                                         conversation about an alt text, ADR-026), under `data`; never
     *                                         sent to the model as it is
     */
    public function appendMessage(MessageRole $role, string|array $content, string $notice = '', array $noticeArgs = [], array $data = []): void
    {
        $messages = $this->getDecodedMessages();
        $message  = ['role' => $role->value, 'content' => $content, 'createdAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)];
        if ($notice !== '') {
            $message['notice'] = $notice;
            if ($noticeArgs !== []) {
                $message['noticeArgs'] = $noticeArgs;
            }
        }

        if ($data !== []) {
            $message['data'] = $data;
        }

        $messages[] = $message;
        $this->setMessages($messages); // setMessages already updates messageCount

        if ($this->title === '' && $role === MessageRole::User && is_string($content)) {
            $this->setTitle($content);
        }
    }

    /**
     * Put text the model wrote beside a tool call into the transcript as an
     * assistant message of its own, marked as such.
     *
     * The reader sees it where the model said it, before the answer, card or
     * question that follows. The mark lets the transcript the model reads on
     * later turns join it with the assistant message after it, so that one
     * assistant turn stays one message on the wire.
     */
    public function appendTextBesideToolCall(string $text): void
    {
        $messages   = $this->getDecodedMessages();
        $messages[] = [
            'role'                     => MessageRole::Assistant->value,
            'content'                  => $text,
            'createdAt'                => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            self::KEY_BESIDE_TOOL_CALL => true,
        ];
        $this->setMessages($messages);
    }

    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    public function getStatus(): ConversationStatus
    {
        return ConversationStatus::tryFrom($this->status) ?? ConversationStatus::Idle;
    }

    public function setStatus(ConversationStatus $status): void
    {
        $this->status = $status->value;

        // The approval fields survive the busy states and are cleared once the
        // conversation settles. Clearing them here rather than at each caller:
        // the paths that settle a conversation are spread across the service
        // and both commands, and one of them would have been forgotten.
        //
        // Processing deliberately keeps them: a decision recorded in the
        // request is carried by the row until the worker executes it, and the
        // run uuid is what lets a stuck conversation be reconciled against its
        // run afterwards. Only the card's own render gate reads the waiting
        // status, so a reference surviving into Processing shows nothing.
        if ($status === ConversationStatus::Idle || $status === ConversationStatus::Failed) {
            $this->approvalRunUuid    = '';
            $this->approvalDecision   = '';
            $this->approvalTurnDigest = '';
            $this->pendingInput       = '';
            $this->approvalDenyReason = '';
        }
    }

    public function getCurrentRequestId(): string
    {
        return $this->currentRequestId;
    }

    public function setCurrentRequestId(string $id): void
    {
        $this->currentRequestId = $id;
    }

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function setSystemPrompt(string $prompt): void
    {
        $this->systemPrompt = mb_substr($prompt, 0, 10000);
    }

    /**
     * @return array{pageId: int, module: string, languageId: int}
     */
    public function getViewContext(): array
    {
        $decoded    = $this->viewContext !== '' ? json_decode($this->viewContext, true) : null;
        $pageId     = is_array($decoded) && is_int($decoded['pageId'] ?? null) ? $decoded['pageId'] : 0;
        $module     = is_array($decoded) && is_string($decoded['module'] ?? null) ? $decoded['module'] : '';
        $languageId = is_array($decoded) && is_int($decoded['languageId'] ?? null) ? $decoded['languageId'] : -1;

        return ['pageId' => max(0, $pageId), 'module' => $module, 'languageId' => max(-1, $languageId)];
    }

    /**
     * Only a positive page id and a module identifier made of the characters
     * TYPO3 module identifiers use are kept; anything else is stored as absent.
     * Whether the user may see the page or the module is decided when the turn
     * runs, not here.
     */
    public function setViewContext(int $pageId, string $module, int $languageId = -1): void
    {
        $pageId = max(0, $pageId);
        if (preg_match('/^\w{1,100}$/', $module) !== 1) {
            $module = '';
        }

        // The page's language (sys_language_uid), -1 when unknown. Only kept
        // with a page: a language without a page says nothing.
        $context = ['pageId' => $pageId, 'module' => $module];
        if ($pageId > 0 && $languageId >= 0) {
            $context['languageId'] = $languageId;
        }

        $this->viewContext = $pageId === 0 && $module === ''
            ? ''
            : json_encode($context, JSON_THROW_ON_ERROR);
    }

    public function getSkillIdentifier(): string
    {
        return $this->skillIdentifier;
    }

    /**
     * Only an identifier made of the characters nr-llm skill identifiers use
     * is kept; anything else is stored as none. Whether the skill exists is
     * decided by the caller and, at run time, by nr-llm.
     */
    public function setSkillIdentifier(string $identifier, int $uid = 0): void
    {
        $this->skillIdentifier = self::isSkillIdentifier($identifier) ? $identifier : '';
        $this->skillUid        = $this->skillIdentifier !== '' ? max(0, $uid) : 0;
    }

    /**
     * The image use the conversation is about (ADR-026); `reference` is 0
     * for a conversation about none. `position` is where the image stood in
     * the list when the chat took it up, so the image after it can be found
     * once it is fixed.
     *
     * @return array{reference: int, position: array{type: string, label: string, key: list<int|string>}|null}
     */
    public function getAltTextHit(): array
    {
        $decoded   = $this->altTextHit !== '' ? json_decode($this->altTextHit, true) : null;
        $reference = is_array($decoded) ? ($decoded['reference'] ?? null) : null;
        $position  = is_array($decoded) ? ($decoded['position'] ?? null) : null;
        $key       = is_array($position) && is_array($position['key'] ?? null) ? $position['key'] : null;

        return [
            'reference' => is_int($reference) && $reference > 0 ? $reference : 0,
            'position'  => is_array($position) && is_string($position['type'] ?? null) && is_string($position['label'] ?? null) && $key !== null
                ? ['type' => $position['type'], 'label' => $position['label'], 'key' => array_values(array_filter($key, static fn (mixed $part): bool => is_int($part) || is_string($part)))]
                : null,
        ];
    }

    public function getAltTextHitJson(): string
    {
        return $this->altTextHit;
    }

    /**
     * @param array{type: string, label: string, key: list<int|string>}|null $position
     */
    public function setAltTextHit(int $referenceUid, ?array $position = null): void
    {
        $this->altTextHit = $referenceUid > 0
            ? json_encode(array_filter(['reference' => $referenceUid, 'position' => $position], static fn (mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR)
            : '';
    }

    /** The privacy setting the latest turn was dispatched with; empty for one dispatched before it was kept. */
    public function getImageAnalysisSnapshot(): string
    {
        return $this->imageAnalysis;
    }

    public function setImageAnalysisSnapshot(string $snapshot): void
    {
        $this->imageAnalysis = $snapshot;
    }

    public function getSkillUid(): int
    {
        return $this->skillUid;
    }

    public function isSkillStartPending(): bool
    {
        return $this->skillStartPending;
    }

    public function setSkillStartPending(bool $pending): void
    {
        $this->skillStartPending = $pending;
    }

    public static function isSkillIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,99}$/', $identifier) === 1;
    }

    /**
     * @return list<array<string, bool|int|string>>
     */
    public function getActivity(): array
    {
        return self::decodeActivity($this->activity);
    }

    public function getActivityJson(): string
    {
        return $this->activity;
    }

    /**
     * @param list<array<string, bool|int|string>> $entries
     */
    public function setActivity(array $entries): void
    {
        $this->activity = $entries === [] ? '' : json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Decode a stored activity list, dropping anything that is not a list of
     * flat entries. Shared with the poll path, which reads the column without
     * hydrating a conversation.
     *
     * @return list<array<string, bool|int|string>>
     */
    public static function decodeActivity(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $entries = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $clean = [];
            foreach ($entry as $key => $value) {
                if (is_string($key) && (is_bool($value) || is_int($value) || is_string($value))) {
                    $clean[$key] = $value;
                }
            }

            $entries[] = $clean;
        }

        return $entries;
    }

    /**
     * The highlight is a content element `{table: 'tt_content', uid}` or a
     * property of the conversation's page `{table: 'pages', uid, field}`
     * (NEXT-221); any other shape reads as none.
     *
     * @return array{progress: array{label: string, current: int, total: int, completed: bool}|null, highlight: array{table: string, uid: int, field?: string}|null}
     */
    public function getGuidedState(): array
    {
        $decoded   = $this->guidedState !== '' ? json_decode($this->guidedState, true) : null;
        $progress  = is_array($decoded) ? ($decoded['progress'] ?? null) : null;
        $highlight = is_array($decoded) ? ($decoded['highlight'] ?? null) : null;

        return [
            'progress' => is_array($progress) && is_string($progress['label'] ?? null) && is_int($progress['current'] ?? null) && is_int($progress['total'] ?? null)
                ? ['label' => $progress['label'], 'current' => $progress['current'], 'total' => $progress['total'], 'completed' => ($progress['completed'] ?? false) === true]
                : null,
            'highlight' => is_array($highlight) ? $this->guidedHighlight($highlight) : null,
        ];
    }

    /**
     * @param array<array-key, mixed> $highlight
     *
     * @return array{table: string, uid: int, field?: string}|null
     */
    private function guidedHighlight(array $highlight): ?array
    {
        $table = $highlight['table'] ?? null;
        $uid   = $highlight['uid'] ?? null;
        if (!is_int($uid)) {
            return null;
        }

        if ($table === 'tt_content') {
            return ['table' => 'tt_content', 'uid' => $uid];
        }

        $field = $highlight['field'] ?? null;
        if ($table === 'pages' && $uid > 0 && is_string($field) && $field !== '') {
            return ['table' => 'pages', 'uid' => $uid, 'field' => $field];
        }

        return null;
    }

    /**
     * @param array{label: string, current: int, total: int, completed: bool}|null $progress
     * @param array{table: string, uid: int, field?: string}|null                  $highlight
     */
    public function setGuidedState(?array $progress, ?array $highlight): void
    {
        $this->guidedState = $progress === null && $highlight === null
            ? ''
            : json_encode(['progress' => $progress, 'highlight' => $highlight], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function setArchived(bool $archived): void
    {
        $this->archived = $archived;
    }

    public function isPinned(): bool
    {
        return $this->pinned;
    }

    public function setPinned(bool $pinned): void
    {
        $this->pinned = $pinned;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getApprovalRunUuid(): string
    {
        return $this->approvalRunUuid;
    }

    public function setApprovalRunUuid(string $runUuid): void
    {
        $this->approvalRunUuid = $runUuid;
    }

    public function getLastRunUuid(): string
    {
        return $this->lastRunUuid;
    }

    public function setLastRunUuid(string $runUuid): void
    {
        $this->lastRunUuid = $runUuid;
    }

    public function getApprovalDecision(): string
    {
        return $this->approvalDecision;
    }

    public function getApprovalTurnDigest(): string
    {
        return $this->approvalTurnDigest;
    }

    /**
     * Record what the user decided, for the worker to carry out.
     *
     * Both values travel together: a digest without a decision means nothing,
     * and a decision without the digest the card carried would be verified
     * against whatever the turn looks like by then.
     */
    public function recordApprovalDecision(bool $approve, string $turnDigest, ?DenyReason $reason = null): void
    {
        $this->approvalDenyReason = $approve || !$reason instanceof DenyReason ? '' : $reason->value;
        $this->approvalDecision   = $approve ? 'approve' : 'deny';
        $this->approvalTurnDigest = $turnDigest;
    }

    public function clearApprovalDecision(): void
    {
        $this->approvalDecision   = '';
        $this->approvalTurnDigest = '';
        $this->pendingInput       = '';
        $this->approvalDenyReason = '';
        $this->approvalCard       = '';
    }

    /**
     * @param array{tool: string, table: string, uid: int, fields: list<string>}|null $card
     */
    public function setApprovalCard(?array $card): void
    {
        $this->approvalCard = $card === null ? '' : json_encode($card, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{tool: string, table: string, uid: int, fields: list<string>}|null
     */
    public function getApprovalCard(): ?array
    {
        $decoded = $this->approvalCard !== '' ? json_decode($this->approvalCard, true) : null;

        return is_array($decoded) ? $this->cardOf($decoded) : null;
    }

    /**
     * @param array{tool: string, table: string, uid: int, fields: list<string>} $card
     */
    public function appendCardOutcome(ProposalOutcome $outcome, array $card): void
    {
        $outcomes           = $this->getCardOutcomes();
        $outcomes[]         = ['outcome' => $outcome->value, 'after' => $this->messageCount] + $card;
        $this->cardOutcomes = json_encode($outcomes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<array{outcome: string, after: int, tool: string, table: string, uid: int, fields: list<string>}>
     */
    public function getCardOutcomes(): array
    {
        $decoded = $this->cardOutcomes !== '' ? json_decode($this->cardOutcomes, true) : null;
        if (!is_array($decoded)) {
            return [];
        }

        $outcomes = [];
        foreach ($decoded as $entry) {
            $card    = is_array($entry) ? $this->cardOf($entry) : null;
            $outcome = is_array($entry) && is_string($entry['outcome'] ?? null) ? ProposalOutcome::tryFrom($entry['outcome']) : null;
            $after   = is_array($entry) ? ($entry['after'] ?? null) : null;
            if ($card !== null && $outcome instanceof ProposalOutcome && is_int($after)) {
                $outcomes[] = ['outcome' => $outcome->value, 'after' => $after] + $card;
            }
        }

        return $outcomes;
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array{tool: string, table: string, uid: int, fields: list<string>}|null
     */
    private function cardOf(array $entry): ?array
    {
        $fields = $entry['fields'] ?? null;
        if (!is_string($entry['tool'] ?? null) || !is_string($entry['table'] ?? null) || !is_int($entry['uid'] ?? null) || !is_array($fields)) {
            return null;
        }

        return [
            'tool'   => $entry['tool'],
            'table'  => $entry['table'],
            'uid'    => $entry['uid'],
            'fields' => array_values(array_filter($fields, is_string(...))),
        ];
    }

    public function getApprovalDenyReason(): ?DenyReason
    {
        return DenyReason::tryFrom($this->approvalDenyReason);
    }

    /**
     * Record the answer to a run that asked for input, for the worker to hand
     * to the runtime (ADR-018).
     *
     * Stored as a decision of its own kind, so everything that guards a
     * recorded decision — the Retry refusal, the worker's routing, the reconcile
     * of a worker that never came — guards the answer too. The digest is the
     * one the question was shown with: nr-llm refuses an answer to a question
     * that has changed since (nr-llm ADR-150).
     *
     * @param array<string, mixed> $data the values, keyed by the schema's property names
     */
    public function recordInputSubmission(array $data, string $turnDigest): void
    {
        $this->approvalDecision   = self::DECISION_INPUT;
        $this->approvalTurnDigest = $turnDigest;
        $this->pendingInput       = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Put the user's answer to a question into the transcript, marked so that
     * it can be taken out again if nr-llm hands the question back unanswered.
     *
     * The answer is shown at once, as a sent message is; it is also what the
     * model reads on later turns, where the question's run is not in the
     * transcript. The notice key is ignored by nr-llm's message factory, so it
     * does not reach the model.
     */
    public function appendInputAnswer(string $display): void
    {
        $this->appendMessage(MessageRole::User, $display, self::NOTICE_INPUT_ANSWER);
    }

    /**
     * Take the last answer back out when the question it answered is open
     * again: an answer next to the same question, still waiting, would read
     * as if it had been given.
     */
    public function dropInputAnswer(): void
    {
        $messages = $this->getDecodedMessages();
        $last     = end($messages);
        if (is_array($last) && ($last['notice'] ?? null) === self::NOTICE_INPUT_ANSWER) {
            array_pop($messages);
            $this->setMessages($messages);
        }
    }

    /**
     * Clear the recorded decision when the run hands it back, and take out
     * the transcript line this decision added: an answer to a question, or
     * the label of a denial with a reason. A plain approval or cancel adds
     * none, and the last line may then be an answer an earlier question
     * already delivered to the run, which must stay.
     */
    public function withdrawDecision(): void
    {
        $addedLine = $this->approvalDecision === self::DECISION_INPUT || $this->getApprovalDenyReason() instanceof DenyReason;
        $this->clearApprovalDecision();
        if ($addedLine) {
            $this->dropInputAnswer();
        }
    }

    /** Whether the recorded decision is an answer to a question rather than an approval. */
    public function hasPendingInputSubmission(): bool
    {
        return $this->approvalDecision === self::DECISION_INPUT && $this->hasPendingApprovalDecision();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPendingInputData(): array
    {
        $decoded = $this->pendingInput !== '' ? json_decode($this->pendingInput, true) : null;
        if (!is_array($decoded)) {
            return [];
        }

        $data = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /** Whether a decision is recorded and still waiting to be carried out. */
    public function hasPendingApprovalDecision(): bool
    {
        return $this->approvalDecision !== '' && $this->approvalRunUuid !== '';
    }

    /**
     * Set the failure text and its kind together, so a message written without
     * a code can never inherit the code of the one it replaces.
     */
    public function setErrorMessage(string $message, string $code = ''): void
    {
        $this->errorMessage = $message;
        $this->errorCode    = $code;
    }

    public function getTstamp(): int
    {
        return $this->tstamp;
    }

    /** When the conversation was created, as a Unix timestamp; 0 before it is stored. */
    public function getCrdate(): int
    {
        return $this->crdate;
    }

    public function hasPendingToolCalls(): bool
    {
        $messages    = $this->getDecodedMessages();
        $lastMessage = end($messages);

        return is_array($lastMessage)
            && ($lastMessage['role'] ?? '') === 'assistant'
            && is_array($lastMessage['tool_calls'] ?? null)
            && $lastMessage['tool_calls'] !== [];
    }

    public function isResumable(): bool
    {
        return in_array(
            $this->getStatus(),
            [ConversationStatus::Processing, ConversationStatus::ToolLoop, ConversationStatus::Failed],
            true,
        );
    }
}
