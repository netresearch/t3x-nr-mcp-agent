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
     * The TYPO3 workspace the editor worked in when they handed the latest
     * turn to the worker: 0 for live, a draft workspace's uid, -1 for a turn
     * handed over before it was kept. Written with the Processing status
     * that hands the turn over (ConversationRepository).
     */
    private int $workspace = -1;

    /**
     * What the agent did in the current turn, as a JSON list of step
     * summaries (NEXT-172). Written column by column while the turn runs
     * ({@see \Netresearch\NrMcpAgent\Service\RunActivityRecorder}) and
     * therefore deliberately not part of toRow(): a full-row write at the end
     * of the turn must not put back the list it started with.
     */
    private string $activity = '';

    private string $turnTiming = '';

    private string $liveText = '';

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

    /**
     * What each run segment of this conversation did (ADR-030), as JSON: the
     * tools that ran, the records written, the decision taken on a card and
     * how the run ended, with where in the transcript it happened. Later
     * turns read it, since a new run sees only the transcript's text.
     */
    private string $runRecord = '';

    /** Entries of the run record kept in the row; the notes read at most the newest 40. */
    private const MAX_RUN_RECORDS = 200;

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

    /** A title the extension gave and keeps deriving: a guided tour's "<skill> · <page>". */
    public const TITLE_TOUR = 'tour';

    /** A title the editor gave; it is never replaced (ADR-027). */
    public const TITLE_MANUAL = 'manual';

    /**
     * Where the title comes from (ADR-027): '' when the conversation does not
     * say — a first message, an image's "Alt-Text: <file>", or a title from
     * before the column existed —, TITLE_TOUR for the "<skill> · <page>" a
     * guided tour is stored with, TITLE_MANUAL once the editor renamed it.
     */
    private string $titleSource = '';

    /**
     * When the last message was written, as a Unix timestamp, kept by the
     * repository with every save of the messages; 0 for a row saved before
     * the column existed and not yet backfilled. Read-only here.
     */
    private int $lastMessageAt = 0;

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
        $conversation->turnTiming         = (string) self::val($row, 'turn_timing', '');
        $conversation->liveText           = (string) self::val($row, 'live_text', '');
        $conversation->guidedState        = (string) self::val($row, 'guided_state', '');
        $conversation->approvalCard       = (string) self::val($row, 'approval_card', '');
        $conversation->cardOutcomes       = (string) self::val($row, 'card_outcomes', '');
        $conversation->runRecord          = (string) self::val($row, 'run_record', '');
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
        $conversation->workspace          = (int) self::val($row, 'workspace', -1);
        $conversation->tstamp             = (int) self::val($row, 'tstamp', 0);
        $conversation->crdate             = (int) self::val($row, 'crdate', 0);
        $conversation->titleSource        = (string) self::val($row, 'title_source', '');
        $conversation->lastMessageAt      = (int) self::val($row, 'last_message_at', 0);

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
     * `title` and `title_source` are not part of it for the same reason: the
     * editor renames a conversation on their own, also while a turn runs, and
     * the worker's final save carries the title it loaded. The repository
     * writes them with a new row, and a title the extension gives only where
     * the editor has not renamed the conversation (ADR-027).
     *
     * @return array<string, int|string>
     */
    public function toRow(): array
    {
        return [
            'be_user'              => $this->beUser,
            'messages'             => $this->messages,
            'message_count'        => $this->messageCount,
            'status'               => $this->status,
            'current_request_id'   => $this->currentRequestId,
            'view_context'         => $this->viewContext,
            'guided_state'         => $this->guidedState,
            'approval_card'        => $this->approvalCard,
            'card_outcomes'        => $this->cardOutcomes,
            'run_record'           => $this->runRecord,
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
            'workspace'            => $this->workspace,
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

    public function getTitleSource(): string
    {
        return $this->titleSource;
    }

    /**
     * @param ''|self::TITLE_TOUR|self::TITLE_MANUAL $source
     */
    public function setTitleSource(string $source): void
    {
        $this->titleSource = $source;
    }

    public function isTitleManual(): bool
    {
        return $this->titleSource === self::TITLE_MANUAL;
    }

    public function getLastMessageAt(): int
    {
        return $this->lastMessageAt;
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

        // A turn sent from where no page is shown keeps the page the
        // conversation was last about, apart from the selected one (ADR-030):
        // it is no selection, and nothing that acts on the selected page reads
        // it, but the model is told which page the conversation was about.
        $last = 0;
        if ($pageId === 0) {
            $previous = $this->getViewContext()['pageId'];
            $last     = $previous > 0 ? $previous : $this->getLastPageId();
        }

        if ($last > 0) {
            $context['lastPageId'] = $last;
        }

        $this->viewContext = $pageId === 0 && $module === '' && $last === 0
            ? ''
            : json_encode($context, JSON_THROW_ON_ERROR);
    }

    /**
     * The page the conversation was last about while no page is selected
     * (ADR-030); 0 when a page is selected or the conversation never had one.
     */
    public function getLastPageId(): int
    {
        $decoded = $this->viewContext !== '' ? json_decode($this->viewContext, true) : null;
        $last    = is_array($decoded) ? ($decoded['lastPageId'] ?? null) : null;

        return is_int($last) && $last > 0 ? $last : 0;
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

    /**
     * The workspace the latest turn was handed over in: 0 for live, a draft
     * workspace's uid, null when it is not known — a turn handed over before
     * the workspace was kept.
     */
    public function getWorkspace(): ?int
    {
        return $this->workspace >= 0 ? $this->workspace : null;
    }

    public function setWorkspace(int $workspace): void
    {
        $this->workspace = $workspace >= 0 ? $workspace : -1;
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

    /** The text the model has written so far in the running turn (ADR-028). */
    public function getLiveText(): string
    {
        return $this->liveText;
    }

    /**
     * The stamps of the current turn's phases (TurnTimingRecorder).
     *
     * @return array<string, int>
     */
    public function getTurnTiming(): array
    {
        return self::decodeTurnTiming($this->turnTiming);
    }

    /**
     * @return array<string, int>
     */
    public static function decodeTurnTiming(string $json): array
    {
        $decoded = $json !== '' ? json_decode($json, true) : null;

        return is_array($decoded)
            ? array_filter($decoded, static fn (mixed $stamp, int|string $phase): bool => is_string($phase) && is_int($stamp), ARRAY_FILTER_USE_BOTH)
            : [];
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
     * Whether the editor's last decision settled the current point of a
     * guided process — the proposal was applied, kept or skipped — so that
     * the next proposal is about the next point (ADR-020, amendment of
     * 2026-10-31). Kept with the guided state and reset with it.
     */
    public function isGuidedPointSettled(): bool
    {
        $decoded = $this->guidedState !== '' ? json_decode($this->guidedState, true) : null;

        return is_array($decoded) && ($decoded['settled'] ?? false) === true;
    }

    /**
     * @param array{label: string, current: int, total: int, completed: bool}|null $progress
     * @param array{table: string, uid: int, field?: string}|null                  $highlight
     * @param bool                                                                 $pointSettled see isGuidedPointSettled(); only stored beside a progress
     */
    public function setGuidedState(?array $progress, ?array $highlight, bool $pointSettled = false): void
    {
        $state = ['progress' => $progress, 'highlight' => $highlight];
        if ($pointSettled && $progress !== null) {
            $state['settled'] = true;
        }

        $this->guidedState = $progress === null && $highlight === null
            ? ''
            : json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Note that the editor's decision settled the current point (see
     * isGuidedPointSettled()). Nothing to note without a running process.
     */
    public function markGuidedPointSettled(): void
    {
        $state = $this->getGuidedState();
        if ($state['progress'] === null || $state['progress']['completed']) {
            return;
        }

        $this->setGuidedState($state['progress'], $state['highlight'], true);
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
     * What a run segment did (ADR-030), placed where the transcript stands
     * now: before the answer the segment adds after it.
     *
     * @param array{outcome: string, decision?: string, tools: list<array{tool: string, calls: int, errors: int}>, writes: list<array{table: string, uid: int, complete: bool|null}>, waiting: list<string>} $entry
     */
    public function appendRunRecord(array $entry, ?int $after = null): void
    {
        $records = $this->getRunRecords();
        // `cards` is how many card outcomes there were: at the same position
        // the notes keep the order in which both were recorded.
        $records[] = ['after' => $after ?? $this->messageCount, 'cards' => count($this->getCardOutcomes())] + $entry;
        // Only the newest entries are ever read (RunRecordNotes); the column
        // is a text column.
        $records         = array_slice($records, -self::MAX_RUN_RECORDS);
        $this->runRecord = json_encode($records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Forget what is recorded from the transcript position on, when the
     * messages from there were cut away (an edited message, ADR-030): the
     * turns they described are gone, and their records would otherwise be
     * read as the record of the turns that take their place.
     */
    public function forgetRecordsFrom(int $position): void
    {
        $records         = array_values(array_filter($this->getRunRecords(), static fn (array $r): bool => $r['after'] < $position));
        $this->runRecord = $records === [] ? '' : json_encode($records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $outcomes           = array_values(array_filter($this->getCardOutcomes(), static fn (array $o): bool => $o['after'] < $position));
        $this->cardOutcomes = $outcomes === [] ? '' : json_encode($outcomes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<array{after: int, cards: int, outcome: string, decision?: string, tools: list<array{tool: string, calls: int, errors: int}>, writes: list<array{table: string, uid: int, complete: bool|null}>, waiting: list<string>}>
     */
    public function getRunRecords(): array
    {
        $decoded = $this->runRecord !== '' ? json_decode($this->runRecord, true) : null;
        if (!is_array($decoded)) {
            return [];
        }

        $records = [];
        foreach ($decoded as $entry) {
            if (is_array($entry) && is_int($entry['after'] ?? null) && is_string($entry['outcome'] ?? null)) {
                $records[] = $this->runRecordOf($entry, $entry['after'], $entry['outcome']);
            }
        }

        return $records;
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array{after: int, cards: int, outcome: string, decision?: string, tools: list<array{tool: string, calls: int, errors: int}>, writes: list<array{table: string, uid: int, complete: bool|null}>, waiting: list<string>}
     */
    private function runRecordOf(array $entry, int $after, string $outcome): array
    {
        $record = [
            'after'   => $after,
            'cards'   => is_int($entry['cards'] ?? null) ? $entry['cards'] : 0,
            'outcome' => $outcome,
            'tools'   => array_values(array_filter(array_map($this->toolOf(...), $this->listOf($entry['tools'] ?? null)))),
            'writes'  => array_values(array_filter(array_map($this->writeOf(...), $this->listOf($entry['writes'] ?? null)))),
            'waiting' => array_values(array_filter($this->listOf($entry['waiting'] ?? null), is_string(...))),
        ];
        if (is_string($entry['decision'] ?? null)) {
            $record['decision'] = $entry['decision'];
        }

        return $record;
    }

    /**
     * @return list<mixed>
     */
    private function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return array{tool: string, calls: int, errors: int}|null
     */
    private function toolOf(mixed $tool): ?array
    {
        if (!is_array($tool) || !is_string($tool['tool'] ?? null) || !is_int($tool['calls'] ?? null) || !is_int($tool['errors'] ?? null)) {
            return null;
        }

        return ['tool' => $tool['tool'], 'calls' => $tool['calls'], 'errors' => $tool['errors']];
    }

    /**
     * @return array{table: string, uid: int, complete: bool|null}|null
     */
    private function writeOf(mixed $write): ?array
    {
        if (!is_array($write) || !is_string($write['table'] ?? null) || !is_int($write['uid'] ?? null)) {
            return null;
        }

        $complete = $write['complete'] ?? null;

        return ['table' => $write['table'], 'uid' => $write['uid'], 'complete' => is_bool($complete) ? $complete : null];
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
