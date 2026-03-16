<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Mcp\McpToolProviderInterface;
use Netresearch\NrMcpAgent\Utility\ErrorMessageSanitizer;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper;

final class ChatService
{
    private const MAX_TOOL_ITERATIONS = 20;
    private const MAX_LLM_RETRIES = 2;
    private const LLM_RETRY_DELAY_SECONDS = 3;

    /** @var array{system_prompt: string, prompt_template: string}|null */
    private ?array $resolvedPrompts = null;

    public function __construct(
        private readonly ConversationRepository $repository,
        private readonly ExtensionConfiguration $config,
        private readonly McpToolProviderInterface $mcpToolProvider,
        private readonly ConnectionPool $connectionPool,
        private readonly ProviderAdapterRegistry $adapterRegistry,
        private readonly DataMapper $dataMapper,
    ) {}

    public function processConversation(Conversation $conversation): void
    {
        if ($this->config->getLlmTaskUid() === 0) {
            $conversation->setStatus(ConversationStatus::Failed);
            $conversation->setErrorMessage('No nr-llm Task configured. Set llmTaskUid in Extension Configuration.');
            $this->persist($conversation);
            return;
        }

        $mcpEnabled = $this->config->isMcpEnabled() && $this->config->isMcpServerInstalled();

        try {
            if ($mcpEnabled) {
                $this->mcpToolProvider->connect();
                $tools = $this->mcpToolProvider->getToolDefinitions();
            } else {
                $tools = [];
            }

            if ($tools !== []) {
                $this->runAgentLoop($conversation, $tools);
            } else {
                $this->runSimpleChat($conversation);
            }
        } catch (Throwable $e) {
            $conversation->setStatus(ConversationStatus::Failed);
            $conversation->setErrorMessage(ErrorMessageSanitizer::sanitize($e->getMessage()));
            $this->persist($conversation);
        } finally {
            if ($mcpEnabled) {
                $this->mcpToolProvider->disconnect();
            }
        }
    }

    public function resumeConversation(Conversation $conversation): void
    {
        if (!$conversation->isResumable()) {
            return;
        }

        if ($this->config->getLlmTaskUid() === 0) {
            $conversation->setStatus(ConversationStatus::Failed);
            $conversation->setErrorMessage('No nr-llm Task configured. Set llmTaskUid in Extension Configuration.');
            $this->persist($conversation);
            return;
        }

        $mcpEnabled = $this->config->isMcpEnabled() && $this->config->isMcpServerInstalled();

        try {
            if ($mcpEnabled) {
                $this->mcpToolProvider->connect();
            }

            if ($mcpEnabled && $conversation->hasPendingToolCalls()) {
                $messages = $conversation->getDecodedMessages();
                /** @var array{tool_calls: array<mixed>} $lastMessage */
                $lastMessage = end($messages);
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

            if ($mcpEnabled) {
                $tools = $this->mcpToolProvider->getToolDefinitions();
            } else {
                $tools = [];
            }

            if ($tools !== []) {
                $this->runAgentLoop($conversation, $tools);
            } else {
                $this->runSimpleChat($conversation);
            }
        } catch (Throwable $e) {
            $conversation->setStatus(ConversationStatus::Failed);
            $conversation->setErrorMessage(ErrorMessageSanitizer::sanitize($e->getMessage()));
            $this->persist($conversation);
        } finally {
            if ($mcpEnabled) {
                $this->mcpToolProvider->disconnect();
            }
        }
    }

    /**
     * Simple chat without tools — fast path for when MCP is disabled.
     */
    private function runSimpleChat(Conversation $conversation): void
    {
        $conversation->setStatus(ConversationStatus::Processing);
        $this->repository->updateStatus($conversation->getUid(), ConversationStatus::Processing, $conversation->getBeUser());

        $provider = $this->resolveProvider();
        $systemPrompt = $this->buildSystemPrompt($conversation);
        $messages = $conversation->getDecodedMessages();

        if ($systemPrompt !== '') {
            array_unshift($messages, ['role' => 'system', 'content' => $systemPrompt]);
        }

        $response = $this->callChatWithRetry($provider, $messages);

        $conversation->appendMessage(MessageRole::Assistant, $response->content);
        $conversation->setStatus(ConversationStatus::Idle);
        $this->persist($conversation);
    }

    /**
     * Agent loop with MCP tools — used when tools are available.
     *
     * @param list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}> $tools
     */
    private function runAgentLoop(
        Conversation $conversation,
        array $tools,
    ): void {
        $conversation->setStatus(ConversationStatus::Processing);
        $this->repository->updateStatus($conversation->getUid(), ConversationStatus::Processing, $conversation->getBeUser());

        $provider = $this->resolveProvider();
        if (!$provider instanceof ToolCapableInterface) {
            throw new RuntimeException(sprintf(
                'Provider "%s" does not support tool calling',
                $provider->getIdentifier(),
            ));
        }

        $systemPrompt = $this->buildSystemPrompt($conversation);
        $optionsArray = array_filter([
            'system_prompt' => $systemPrompt,
            'tool_choice' => 'auto',
        ]);

        for ($i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++) {
            $messages = $conversation->getDecodedMessages();

            $response = $this->callToolChatWithRetry($provider, $messages, $tools, $optionsArray);

            if ($response->hasToolCalls()) {
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

            $conversation->appendMessage(MessageRole::Assistant, $response->content);
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
     */
    private function callChatWithRetry(
        ProviderInterface $provider,
        array $messages,
    ): CompletionResponse {
        $lastException = null;
        for ($attempt = 0; $attempt <= self::MAX_LLM_RETRIES; $attempt++) {
            try {
                return $provider->chatCompletion($messages, []);
            } catch (Throwable $e) {
                $lastException = $e;
                if (!$this->isTransientError($e) || $attempt >= self::MAX_LLM_RETRIES) {
                    throw $e;
                }
                sleep(self::LLM_RETRY_DELAY_SECONDS * ($attempt + 1));
            }
        }
        throw $lastException;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}> $tools
     * @param array<string, mixed> $options
     */
    private function callToolChatWithRetry(
        ToolCapableInterface $provider,
        array $messages,
        array $tools,
        array $options,
    ): CompletionResponse {
        $lastException = null;
        for ($attempt = 0; $attempt <= self::MAX_LLM_RETRIES; $attempt++) {
            try {
                return $provider->chatCompletionWithTools($messages, $tools, $options);
            } catch (Throwable $e) {
                $lastException = $e;
                if (!$this->isTransientError($e) || $attempt >= self::MAX_LLM_RETRIES) {
                    throw $e;
                }
                sleep(self::LLM_RETRY_DELAY_SECONDS * ($attempt + 1));
            }
        }
        throw $lastException;
    }

    private function isTransientError(Throwable $e): bool
    {
        return str_contains($e->getMessage(), '429')
            || str_contains($e->getMessage(), '503')
            || str_contains($e->getMessage(), 'rate')
            || str_contains($e->getMessage(), 'overloaded');
    }

    /**
     * Resolve a fully configured provider adapter from the task chain.
     *
     * Follows: Task → Configuration → Model → Provider (DB entities),
     * then uses ProviderAdapterRegistry to create a configured adapter instance.
     */
    private function resolveProvider(): ProviderInterface
    {
        $taskUid = $this->config->getLlmTaskUid();

        $qb = $this->connectionPool->getQueryBuilderForTable('tx_nrllm_model');
        $row = $qb
            ->select('m.*', 'c.system_prompt AS _config_system_prompt', 't.prompt_template AS _task_prompt_template')
            ->from('tx_nrllm_task', 't')
            ->join('t', 'tx_nrllm_configuration', 'c', $qb->expr()->eq('c.uid', $qb->quoteIdentifier('t.configuration_uid')))
            ->join('c', 'tx_nrllm_model', 'm', $qb->expr()->eq('m.uid', $qb->quoteIdentifier('c.model_uid')))
            ->where($qb->expr()->eq('t.uid', $qb->createNamedParameter($taskUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            throw new RuntimeException(sprintf('Could not resolve LLM model for task uid %d', $taskUid));
        }

        // Extract prompts before passing row to DataMapper (which only expects model columns)
        $this->resolvedPrompts = [
            'system_prompt' => is_string($row['_config_system_prompt'] ?? null) ? $row['_config_system_prompt'] : '',
            'prompt_template' => is_string($row['_task_prompt_template'] ?? null) ? $row['_task_prompt_template'] : '',
        ];
        unset($row['_config_system_prompt'], $row['_task_prompt_template']);

        /** @var list<LlmModel> $models */
        $models = $this->dataMapper->map(LlmModel::class, [$row]);
        $model = $models[0] ?? null;

        if ($model === null) {
            throw new RuntimeException(sprintf('Could not map LLM model for task uid %d', $taskUid));
        }

        return $this->adapterRegistry->createAdapterFromModel($model);
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
        // 1. Conversation-level custom prompt (highest priority)
        $custom = $conversation->getSystemPrompt();
        if ($custom !== '') {
            return $custom;
        }

        // 2. Build from nr-llm Task prompt_template + Configuration system_prompt
        $parts = [];

        $configPrompt = $this->resolvedPrompts['system_prompt'] ?? '';
        if ($configPrompt !== '') {
            $parts[] = $configPrompt;
        }

        $taskPrompt = $this->resolvedPrompts['prompt_template'] ?? '';
        if ($taskPrompt !== '') {
            $parts[] = $taskPrompt;
        }

        // 3. Fallback: locale-based default if nothing configured
        if ($parts === []) {
            $beUser = $GLOBALS['BE_USER'] ?? null;
            /** @var array<string, mixed> $uc */
            $uc = is_object($beUser) && isset($beUser->uc) && is_array($beUser->uc) ? $beUser->uc : [];
            $langRaw = $uc['lang'] ?? 'default';
            $lang = is_string($langRaw) ? $langRaw : 'default';

            $parts[] = match ($lang) {
                'de' => 'Du bist ein TYPO3-Assistent. Du hilfst beim Verwalten von Inhalten über die verfügbaren Tools. Antworte auf Deutsch.',
                default => 'You are a TYPO3 assistant. You help manage content using the available tools. Respond in English.',
            };
        }

        return implode("\n\n", $parts);
    }

    private function persist(Conversation $conversation): void
    {
        $this->repository->update($conversation);
    }
}
