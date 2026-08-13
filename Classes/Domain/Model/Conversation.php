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
    private int $uid = 0;

    private int $beUser = 0;

    private string $title = '';

    private string $messages = '';

    private int $messageCount = 0;

    private string $status = 'idle';

    private string $currentRequestId = '';

    private string $systemPrompt = '';

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
            'system_prompt' => $this->systemPrompt,
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
        if ($this->messages === '') {
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
