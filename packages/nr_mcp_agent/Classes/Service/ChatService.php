<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Dto\ToolOptions;
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

        $messages = $conversation->getDecodedMessages();
        $lastMessage = end($messages);

        if ($lastMessage && $lastMessage['role'] === 'assistant' && !empty($lastMessage['tool_calls'])) {
            $toolResults = $this->executeToolCalls($lastMessage['tool_calls']);
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

        $this->processConversation($conversation);
    }

    private function runAgentLoop(
        Conversation $conversation,
        int $taskUid,
        array $tools,
    ): void {
        $conversation->setStatus(ConversationStatus::Processing);
        $this->persist($conversation);

        for ($i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++) {
            $messages = $conversation->getDecodedMessages();

            $response = $this->callLlmWithRetry(
                $messages, $tools, $taskUid, $conversation
            );

            if ($response->hasToolCalls()) {
                $messages = $conversation->getDecodedMessages();
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $response->getContent(),
                    'tool_calls' => $response->toolCalls,
                ];
                $conversation->setMessages($messages);
                $this->persist($conversation);

                $conversation->setStatus(ConversationStatus::ToolLoop);
                $toolResults = $this->executeToolCalls($response->toolCalls);
                foreach ($toolResults as $result) {
                    $messages = $conversation->getDecodedMessages();
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $result['tool_call_id'],
                        'content' => $result['content'],
                    ];
                    $conversation->setMessages($messages);
                }
                $this->persist($conversation);
                continue;
            }

            $conversation->appendMessage('assistant', $response->getContent());
            $conversation->setStatus(ConversationStatus::Idle);
            $this->persist($conversation);
            return;
        }

        $conversation->setStatus(ConversationStatus::Failed);
        $conversation->setErrorMessage('Max tool iterations reached');
        $this->persist($conversation);
    }

    private function callLlmWithRetry(
        array $messages,
        array $tools,
        int $taskUid,
        Conversation $conversation,
    ): mixed {
        $lastException = null;
        for ($attempt = 0; $attempt <= self::MAX_LLM_RETRIES; $attempt++) {
            try {
                return $this->llmManager->chatWithTools(
                    $messages,
                    $tools,
                    ToolOptions::auto(),
                    taskUid: $taskUid,
                    systemPrompt: $this->buildSystemPrompt($conversation),
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
        throw $lastException;
    }

    private function executeToolCalls(array $toolCalls): array
    {
        $results = [];
        foreach ($toolCalls as $call) {
            $functionName = $call['function']['name'] ?? $call['name'] ?? '';
            $arguments = $call['function']['arguments'] ?? $call['input'] ?? [];
            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true) ?? [];
            }
            $result = $this->mcpToolProvider->executeTool($functionName, $arguments);
            $results[] = [
                'tool_call_id' => $call['id'],
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

        $lang = $GLOBALS['BE_USER']->uc['lang'] ?? 'default';

        return match ($lang) {
            'de' => 'Du bist ein TYPO3-Assistent. Du hilfst beim Verwalten von Inhalten über die verfügbaren Tools. Antworte auf Deutsch.',
            default => 'You are a TYPO3 assistant. You help manage content using the available tools. Respond in English.',
        };
    }

    private function sanitizeErrorMessage(string $message): string
    {
        $message = preg_replace('/(?:Bearer |sk-|key-)[a-zA-Z0-9\-_]+/', '[REDACTED]', $message);
        $message = preg_replace('#https?://[^\s]+#', '[URL]', $message);
        return mb_substr($message, 0, 500);
    }

    private function persist(Conversation $conversation): void
    {
        $this->repository->update($conversation);
    }
}
