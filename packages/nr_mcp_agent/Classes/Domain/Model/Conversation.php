<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Model;

use Netresearch\NrMcpAgent\Enum\ConversationStatus;

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
    private int $tstamp = 0;
    private int $crdate = 0;

    /**
     * Factory method: hydrate from a database row array.
     */
    public static function fromRow(array $row): self
    {
        $conversation = new self();
        $conversation->uid = (int)($row['uid'] ?? 0);
        $conversation->beUser = (int)($row['be_user'] ?? 0);
        $conversation->title = (string)($row['title'] ?? '');
        $conversation->messages = (string)($row['messages'] ?? '');
        $conversation->messageCount = (int)($row['message_count'] ?? 0);
        $conversation->status = (string)($row['status'] ?? 'idle');
        $conversation->currentRequestId = (string)($row['current_request_id'] ?? '');
        $conversation->systemPrompt = (string)($row['system_prompt'] ?? '');
        $conversation->archived = (bool)($row['archived'] ?? false);
        $conversation->pinned = (bool)($row['pinned'] ?? false);
        $conversation->errorMessage = (string)($row['error_message'] ?? '');
        $conversation->tstamp = (int)($row['tstamp'] ?? 0);
        $conversation->crdate = (int)($row['crdate'] ?? 0);
        return $conversation;
    }

    /**
     * Serialize back to a DB-compatible array (for INSERT/UPDATE).
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
            'archived' => (int)$this->archived,
            'pinned' => (int)$this->pinned,
            'error_message' => $this->errorMessage,
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

    public function getDecodedMessages(): array
    {
        if ($this->messages === '') {
            return [];
        }
        return json_decode($this->messages, true, 512, JSON_THROW_ON_ERROR);
    }

    public function setMessages(array $messages): void
    {
        $this->messages = json_encode($messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->messageCount = count($messages);
    }

    public function appendMessage(string $role, string|array $content): void
    {
        $messages = $this->getDecodedMessages();
        $messages[] = ['role' => $role, 'content' => $content];
        $this->setMessages($messages);
        $this->messageCount = count($messages);

        if ($this->title === '' && $role === 'user' && is_string($content)) {
            $this->setTitle($content);
        }
    }

    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    public function getStatus(): ConversationStatus
    {
        return ConversationStatus::from($this->status);
    }

    public function setStatus(ConversationStatus $status): void
    {
        $this->status = $status->value;
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
        $this->systemPrompt = $prompt;
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

    public function setErrorMessage(string $message): void
    {
        $this->errorMessage = $message;
    }

    public function getTstamp(): int
    {
        return $this->tstamp;
    }

    public function isResumable(): bool
    {
        return in_array(
            $this->getStatus(),
            [ConversationStatus::Processing, ConversationStatus::ToolLoop, ConversationStatus::Failed],
            true
        );
    }
}
