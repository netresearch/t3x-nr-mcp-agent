<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Model;

use DateTimeImmutable;
use DateTimeInterface;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;

/**
 * Simple DTO/Value Object — no Extbase, no AbstractEntity.
 * Use Conversation::fromRow() to hydrate from a DB row.
 */
final class Conversation
{
    /** Prefix of a legacy transcript the upgrade wizard could not decode and left in place. */
    public const UNDECODABLE_MARKER = '!undecodable:';

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
     * What the agent did in the current turn, as a JSON list of step
     * summaries (NEXT-172). Written column by column while the turn runs
     * ({@see \Netresearch\NrMcpAgent\Service\RunActivityRecorder}) and
     * therefore deliberately not part of toRow(): a full-row write at the end
     * of the turn must not put back the list it started with.
     */
    private string $activity = '';

    private bool $archived = false;

    private bool $pinned = false;

    private string $errorMessage = '';

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
     * it out: 'approve', 'deny', or empty when nothing is pending.
     */
    private string $approvalDecision = '';

    /**
     * The turn digest the card carried, kept with the decision so the worker
     * hands the runtime exactly what the reader saw (ADR-132).
     */
    private string $approvalTurnDigest = '';

    private int $tstamp = 0;

    /** @phpstan-ignore-next-line property.onlyWritten */
    private int $crdate = 0;

    /**
     * Factory method: hydrate from a database row array.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $conversation = new self();
        $conversation->uid = (int) self::val($row, 'uid', 0);
        $conversation->beUser = (int) self::val($row, 'be_user', 0);
        $conversation->title = (string) self::val($row, 'title', '');
        $conversation->messages = (string) self::val($row, 'messages', '');
        $conversation->messageCount = (int) self::val($row, 'message_count', 0);
        $conversation->status = (string) self::val($row, 'status', 'idle');
        $conversation->currentRequestId = (string) self::val($row, 'current_request_id', '');
        $conversation->systemPrompt = (string) self::val($row, 'system_prompt', '');
        $conversation->viewContext = (string) self::val($row, 'view_context', '');
        $conversation->activity = (string) self::val($row, 'activity', '');
        $conversation->archived = (bool) self::val($row, 'archived', false);
        $conversation->pinned = (bool) self::val($row, 'pinned', false);
        $conversation->errorMessage = (string) self::val($row, 'error_message', '');
        $conversation->approvalRunUuid = (string) self::val($row, 'approval_run_uuid', '');
        $conversation->approvalDecision = (string) self::val($row, 'approval_decision', '');
        $conversation->approvalTurnDigest = (string) self::val($row, 'approval_turn_digest', '');
        $conversation->tstamp = (int) self::val($row, 'tstamp', 0);
        $conversation->crdate = (int) self::val($row, 'crdate', 0);
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
            'be_user' => $this->beUser,
            'title' => $this->title,
            'messages' => $this->messages,
            'message_count' => $this->messageCount,
            'status' => $this->status,
            'current_request_id' => $this->currentRequestId,
            'view_context' => $this->viewContext,
            'archived' => (int) $this->archived,
            'pinned' => (int) $this->pinned,
            'error_message' => $this->errorMessage,
            'approval_run_uuid' => $this->approvalRunUuid,
            'approval_decision' => $this->approvalDecision,
            'approval_turn_digest' => $this->approvalTurnDigest,
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
        $this->messages = json_encode($messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->messageCount = count($messages);
    }

    /**
     * @param string|array<mixed> $content
     */
    public function appendMessage(MessageRole $role, string|array $content): void
    {
        $messages = $this->getDecodedMessages();
        $messages[] = ['role' => $role->value, 'content' => $content, 'createdAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)];
        $this->setMessages($messages); // setMessages already updates messageCount

        if ($this->title === '' && $role === MessageRole::User && is_string($content)) {
            $this->setTitle($content);
        }
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
            $this->approvalRunUuid = '';
            $this->approvalDecision = '';
            $this->approvalTurnDigest = '';
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
     * @return array{pageId: int, module: string}
     */
    public function getViewContext(): array
    {
        $decoded = $this->viewContext !== '' ? json_decode($this->viewContext, true) : null;
        $pageId = is_array($decoded) && is_int($decoded['pageId'] ?? null) ? $decoded['pageId'] : 0;
        $module = is_array($decoded) && is_string($decoded['module'] ?? null) ? $decoded['module'] : '';

        return ['pageId' => max(0, $pageId), 'module' => $module];
    }

    /**
     * Only a positive page id and a module identifier made of the characters
     * TYPO3 module identifiers use are kept; anything else is stored as absent.
     * Whether the user may see the page or the module is decided when the turn
     * runs, not here.
     */
    public function setViewContext(int $pageId, string $module): void
    {
        $pageId = max(0, $pageId);
        if (preg_match('/^\w{1,100}$/', $module) !== 1) {
            $module = '';
        }

        $this->viewContext = $pageId === 0 && $module === ''
            ? ''
            : json_encode(['pageId' => $pageId, 'module' => $module], JSON_THROW_ON_ERROR);
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

    public function getApprovalRunUuid(): string
    {
        return $this->approvalRunUuid;
    }

    public function setApprovalRunUuid(string $runUuid): void
    {
        $this->approvalRunUuid = $runUuid;
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
    public function recordApprovalDecision(bool $approve, string $turnDigest): void
    {
        $this->approvalDecision = $approve ? 'approve' : 'deny';
        $this->approvalTurnDigest = $turnDigest;
    }

    public function clearApprovalDecision(): void
    {
        $this->approvalDecision = '';
        $this->approvalTurnDigest = '';
    }

    /** Whether a decision is recorded and still waiting to be carried out. */
    public function hasPendingApprovalDecision(): bool
    {
        return $this->approvalDecision !== '' && $this->approvalRunUuid !== '';
    }

    public function setErrorMessage(string $message): void
    {
        $this->errorMessage = $message;
    }

    public function getTstamp(): int
    {
        return $this->tstamp;
    }

    public function hasPendingToolCalls(): bool
    {
        $messages = $this->getDecodedMessages();
        $lastMessage = end($messages);
        return is_array($lastMessage)
            && ($lastMessage['role'] ?? '') === 'assistant'
            && !empty($lastMessage['tool_calls']);
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
