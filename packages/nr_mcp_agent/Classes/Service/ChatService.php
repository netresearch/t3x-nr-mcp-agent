<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Dto\ToolOptions;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Mcp\McpToolProvider;

final class ChatService
{
    private const MAX_TOOL_ITERATIONS = 20;
    private const MAX_LLM_RETRIES = 2;
    private const LLM_RETRY_DELAY_SECONDS = 3;

    public function __construct(
        private readonly LlmServiceManager $llmManager,
        private readonly ConversationRepository $repository,
        private readonly ExtensionConfiguration $config,
        private readonly McpToolProvider $mcpToolProvider,
    ) {}

    public function processConversation(Conversation $conversation): void
    {
        $taskUid = $this->config->getLlmTaskUid();
        if ($taskUid === 0) {
            $conversation->setStatus(ConversationStatus::Failed);
            $conversation->setErrorMessage('No nr-llm Task configured. Set llmTaskUid in Extension Configuration.');
            $this->persist($conversation);
            return;
        }

        try {
            $this->mcpToolProvider->connect();
            $tools = $this->mcpToolProvider->getToolDefinitions();
            $this->runAgentLoop($conversation, $taskUid, $tools);
        } catch (\Throwable $e) {
            $conversation->setStatus(ConversationStatus::Failed);
            $conversation->setErrorMessage($this->sanitizeErrorMessage($e->getMessage()));
            $this->persist($conversation);
        } finally {
            $this->mcpToolProvider->disconnect();
        }
    }

    public function resumeConversation(Conversation $conversation): void
    {
        if (!$conversation->isResumable()) {
            return;
        }

        $taskUid = $this->config->getLlmTaskUid();
        if ($taskUid === 0) {
            $conversation->setStatus(ConversationStatus::Failed);
            $conversation->setErrorMessage('No nr-llm Task configured. Set llmTaskUid in Extension Configuration.');
            $this->persist($conversation);
            return;
        }

        try {
            $this->mcpToolProvider->connect();

            // If last message is assistant with pending tool_calls, execute them first
            $messages = $conversation->getDecodedMessages();
            $lastMessage = end($messages);

            if ($lastMessage && $lastMessage['role'] === 'assistant' && !empty($lastMessage['tool_calls'])) {
                /** @var array<mixed> $pendingToolCalls */
                $pendingToolCalls = is_array($lastMessage['tool_calls']) ? $lastMessage['tool_calls'] : [];
                $toolResults = $this->executeToolCalls($pendingToolCalls);
                $messages = $conversation->getDecodedMessages();
                foreach ($toolResults as $result) {
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $result['tool_call_id'],
                        'content' => $result['content'],
                    ];
                }
                $conversation->setMessages($messages);
                $this->persist($conversation);
            }

            $tools = $this->mcpToolProvider->getToolDefinitions();
            $this->runAgentLoop($conversation, $taskUid, $tools);
        } catch (\Throwable $e) {
            $conversation->setStatus(ConversationStatus::Failed);
            $conversation->setErrorMessage($this->sanitizeErrorMessage($e->getMessage()));
            $this->persist($conversation);
        } finally {
            $this->mcpToolProvider->disconnect();
        }
    }

    /**
     * @param list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}> $tools
     */
    private function runAgentLoop(
        Conversation $conversation,
        int $taskUid,
        array $tools,
    ): void {
        $conversation->setStatus(ConversationStatus::Processing);
        $this->repository->updateStatus($conversation->getUid(), ConversationStatus::Processing);

        for ($i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++) {
            $messages = $conversation->getDecodedMessages();

            $response = $this->callLlmWithRetry(
                $messages, $tools, $taskUid, $conversation
            );

            if ($response->hasToolCalls()) {
                // Reuse $messages from above — no second decode needed
                /** @var array<mixed> $toolCalls */
                $toolCalls = $response->toolCalls ?? [];
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $response->content,
                    'tool_calls' => $toolCalls,
                ];
                $conversation->setMessages($messages);
                $this->persist($conversation);

                $conversation->setStatus(ConversationStatus::ToolLoop);
                $toolResults = $this->executeToolCalls($toolCalls);
                foreach ($toolResults as $result) {
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $result['tool_call_id'],
                        'content' => $result['content'],
                    ];
                }
                $conversation->setMessages($messages);
                $this->persist($conversation);
                continue;
            }

            $conversation->appendMessage('assistant', $response->content);
            $conversation->setStatus(ConversationStatus::Idle);
            $this->persist($conversation);
            return;
        }

        $conversation->setStatus(ConversationStatus::Failed);
        $conversation->setErrorMessage('Max tool iterations reached');
        $this->persist($conversation);
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}> $tools
     */
    private function callLlmWithRetry(
        array $messages,
        array $tools,
        int $taskUid,
        Conversation $conversation,
    ): CompletionResponse {
        $lastException = null;
        for ($attempt = 0; $attempt <= self::MAX_LLM_RETRIES; $attempt++) {
            try {
                return $this->llmManager->chatWithTools(
                    $messages, // @phpstan-ignore argument.type (nr-llm API will be updated)
                    $tools,
                    ToolOptions::auto(), // @phpstan-ignore class.notFound, argument.type (nr-llm API will be updated)
                    taskUid: $taskUid, // @phpstan-ignore argument.unknown (nr-llm API will be updated)
                    systemPrompt: $this->buildSystemPrompt($conversation), // @phpstan-ignore argument.unknown (nr-llm API will be updated)
                );
            } catch (\Throwable $e) {
                $lastException = $e;
                $isTransient = str_contains($e->getMessage(), '429')
                    || str_contains($e->getMessage(), '503')
                    || str_contains($e->getMessage(), 'rate')
                    || str_contains($e->getMessage(), 'overloaded');
                if (!$isTransient || $attempt >= self::MAX_LLM_RETRIES) {
                    throw $e;
                }
                sleep(self::LLM_RETRY_DELAY_SECONDS * ($attempt + 1));
            }
        }
        // $lastException is always set by the loop before this point
        throw $lastException;
    }

    /**
     * @param array<mixed> $toolCalls
     * @return list<array{tool_call_id: mixed, content: string}>
     */
    private function executeToolCalls(array $toolCalls): array
    {
        $results = [];
        foreach ($toolCalls as $call) {
            if (!is_array($call)) {
                continue;
            }
            /** @var array<string, mixed> $callData */
            $callData = $call;
            /** @var array<string, mixed> $function */
            $function = is_array($callData['function'] ?? null) ? $callData['function'] : [];
            $nameRaw = $function['name'] ?? $callData['name'] ?? '';
            $functionName = is_string($nameRaw) ? $nameRaw : '';
            $arguments = $function['arguments'] ?? $callData['input'] ?? [];
            if (is_string($arguments)) {
                /** @var array<string, mixed> $arguments */
                $arguments = json_decode($arguments, true) ?? [];
            }
            if (!is_array($arguments)) {
                $arguments = [];
            }
            /** @var array<string, mixed> $arguments */
            $result = $this->mcpToolProvider->executeTool($functionName, $arguments);
            $results[] = [
                'tool_call_id' => $callData['id'] ?? null,
                'content' => $result,
            ];
        }
        return $results;
    }

    private function buildSystemPrompt(Conversation $conversation): string
    {
        $custom = $conversation->getSystemPrompt();
        if ($custom !== '') {
            return $custom;
        }

        /** @var mixed $beUser */
        $beUser = $GLOBALS['BE_USER'] ?? null;
        /** @var array<string, mixed> $uc */
        $uc = is_object($beUser) && isset($beUser->uc) && is_array($beUser->uc) ? $beUser->uc : [];
        $langRaw = $uc['lang'] ?? 'default';
        $lang = is_string($langRaw) ? $langRaw : 'default';

        return match ($lang) {
            'de' => 'Du bist ein TYPO3-Assistent. Du hilfst beim Verwalten von Inhalten über die verfügbaren Tools. Antworte auf Deutsch.',
            default => 'You are a TYPO3 assistant. You help manage content using the available tools. Respond in English.',
        };
    }

    private function sanitizeErrorMessage(string $message): string
    {
        $message = preg_replace('/(?:Bearer |sk-|key-)[a-zA-Z0-9\-_]+/', '[REDACTED]', $message) ?? $message;
        $message = preg_replace('#https?://[^\s]+#', '[URL]', $message) ?? $message;
        return mb_substr($message, 0, 500);
    }

    private function persist(Conversation $conversation): void
    {
        $this->repository->update($conversation);
    }
}
