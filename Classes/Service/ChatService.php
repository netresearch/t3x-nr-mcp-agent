<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use LogicException;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Provider\Contract\DocumentCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Contract\VisionCapableInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Domain\Repository\LlmTaskRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Mcp\McpToolProviderInterface;
use Netresearch\NrMcpAgent\Utility\ErrorMessageSanitizer;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

final class ChatService implements ChatCapabilitiesInterface
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
        private readonly LlmTaskRepository $llmTaskRepository,
        private readonly ProviderAdapterRegistry $adapterRegistry,
        private readonly ResourceFactory $resourceFactory,
        private readonly SiteFinder $siteFinder,
        private readonly DocumentExtractorRegistry $documentExtractorRegistry,
    ) {}

    /**
     * @return array{visionSupported: bool, maxFileSize: int, supportedFormats: list<string>}
     */
    public function getProviderCapabilities(): array
    {
        $extractionFormats = $this->documentExtractorRegistry->getAvailableMimeTypes();

        try {
            $provider = $this->resolveProvider();
            if ($provider instanceof VisionCapableInterface && $provider->supportsVision()) {
                $documentFormats = $provider instanceof DocumentCapableInterface && $provider->supportsDocuments()
                    ? $provider->getSupportedDocumentFormats()
                    : [];

                return [
                    'visionSupported' => true,
                    'maxFileSize' => $provider->getMaxImageSize(),
                    'supportedFormats' => array_values(array_unique(array_merge(
                        $provider->getSupportedImageFormats(),
                        $documentFormats,
                        $extractionFormats,
                    ))),
                ];
            }
        } catch (Throwable) {
            // Provider resolution failed — fall through to extraction-only response
        }

        return [
            'visionSupported' => false,
            'maxFileSize' => 0,
            'supportedFormats' => array_values($extractionFormats),
        ];
    }

    public function processConversation(Conversation $conversation): void
    {
        $this->resolvedPrompts = null;

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
        $this->resolvedPrompts = null;

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

            $tools = $mcpEnabled ? $this->mcpToolProvider->getToolDefinitions() : [];

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
        $messages = $this->buildLlmMessages($conversation->getDecodedMessages(), $provider);

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
            // buildLlmMessages expands fileUid refs to base64 for the LLM call only —
            // never persist the expanded result back to DB.
            $messages = $this->buildLlmMessages($conversation->getDecodedMessages(), $provider);

            $response = $this->callToolChatWithRetry($provider, $messages, $tools, $optionsArray);

            if ($response->hasToolCalls()) {
                /** @var array<mixed> $toolCalls */
                $toolCalls = $response->toolCalls ?? [];
                // Append assistant + tool messages to the stored (non-expanded) messages
                $storedMessages = $conversation->getDecodedMessages();
                $storedMessages[] = [
                    'role' => 'assistant',
                    'content' => $response->content,
                    'tool_calls' => $toolCalls,
                ];
                $conversation->setMessages($storedMessages);
                $this->persist($conversation);

                $conversation->setStatus(ConversationStatus::ToolLoop);
                $toolResults = $this->executeToolCalls($toolCalls);
                $storedMessages = $conversation->getDecodedMessages();
                foreach ($toolResults as $result) {
                    $storedMessages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $result['tool_call_id'],
                        'content' => $result['content'],
                    ];
                }
                $conversation->setMessages($storedMessages);
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
        $resolved = $this->llmTaskRepository->resolveModelByTaskUid($taskUid);

        $this->resolvedPrompts = [
            'system_prompt' => $resolved['systemPrompt'],
            'prompt_template' => $resolved['promptTemplate'],
        ];

        return $this->adapterRegistry->createAdapterFromModel($resolved['model']);
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

    /**
     * Converts stored messages (which may contain fileUid references) into
     * the multimodal content arrays expected by the LLM API.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    private function buildLlmMessages(array $messages, ProviderInterface $provider): array
    {
        $result = [];
        foreach ($messages as $msg) {
            if (!isset($msg['fileUid'])) {
                $result[] = $msg;
                continue;
            }

            try {
                $fileUid = is_int($msg['fileUid']) ? $msg['fileUid'] : (is_numeric($msg['fileUid']) ? (int) $msg['fileUid'] : 0);
                $file = $this->resourceFactory->getFileObject($fileUid);
                $localPath = $file->getForLocalProcessing(false);
                $base64 = base64_encode((string) file_get_contents($localPath));
                $mimeType = $file->getMimeType();

                $result[] = [
                    'role' => is_string($msg['role']) ? $msg['role'] : '',
                    'content' => [
                        ['type' => 'text', 'text' => is_string($msg['content'] ?? null) ? $msg['content'] : ''],
                        $this->buildFileContentBlock($mimeType, $base64, $provider),
                    ],
                ];
            } catch (Throwable) {
                $fileName = isset($msg['fileName']) && is_string($msg['fileName']) ? $msg['fileName'] : 'unknown';
                $content = is_string($msg['content'] ?? null) ? $msg['content'] : '';
                $result[] = [
                    'role' => is_string($msg['role']) ? $msg['role'] : '',
                    'content' => $content . "\n\n[Attached file '" . $fileName . "' is no longer available]",
                ];
            }
        }
        return $result;
    }

    /**
     * @return array<string, mixed>
     * @throws RuntimeException if the file type is not supported by the provider
     */
    private function buildFileContentBlock(string $mimeType, string $base64, ProviderInterface $provider): array
    {
        if (str_starts_with($mimeType, 'image/')) {
            return [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:' . $mimeType . ';base64,' . $base64],
            ];
        }
        if (!$provider instanceof DocumentCapableInterface || !$provider->supportsDocuments()) {
            throw new RuntimeException(
                'Provider "' . $provider->getIdentifier() . '" does not support document uploads (mime type: ' . $mimeType . ')',
                1742320000,
            );
        }
        return [
            'type' => 'document',
            'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $base64],
        ];
    }

    private function buildSystemPrompt(Conversation $conversation): string
    {
        $parts = [];

        // 1. Conversation-level custom prompt (highest priority)
        $custom = $conversation->getSystemPrompt();
        if ($custom !== '') {
            $parts[] = $custom;
        } else {
            // 2. Build from nr-llm Task prompt_template + Configuration system_prompt
            if ($this->resolvedPrompts === null) {
                throw new LogicException('resolveProvider() must be called before buildSystemPrompt()');
            }

            $configPrompt = $this->resolvedPrompts['system_prompt'];
            if ($configPrompt !== '') {
                $parts[] = $configPrompt;
            }

            $taskPrompt = $this->resolvedPrompts['prompt_template'];
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
        }

        // Always append site language context so the LLM knows which
        // sys_language_uid to use when creating or updating content.
        $languageContext = $this->buildSiteLanguagesContext();
        if ($languageContext !== '') {
            $parts[] = $languageContext;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Builds a concise site-language block for the system prompt.
     * Reads all TYPO3 site configurations and lists each language with its
     * sys_language_uid and ISO code so the LLM can pick the right language
     * record when creating or updating content.
     */
    private function buildSiteLanguagesContext(): string
    {
        try {
            $sites = $this->siteFinder->getAllSites();
        } catch (Throwable) {
            return '';
        }

        if ($sites === []) {
            return '';
        }

        /** @var array<int, array{uid: int, title: string, isoCode: string}> $languages */
        $languages = [];

        foreach ($sites as $site) {
            foreach ($site->getAllLanguages() as $language) {
                $uid = $language->getLanguageId();
                if (isset($languages[$uid])) {
                    continue;
                }

                $isoCode = '';
                try {
                    $locale = $language->getLocale();
                    $isoCode = method_exists($locale, 'getLanguageCode') ? strtolower($locale->getLanguageCode()) : '';
                } catch (Throwable) {
                }

                if ($isoCode === '') {
                    $hreflang = $language->getHreflang();
                    $isoCode = strtolower(explode('-', $hreflang)[0]);
                }

                $languages[$uid] = [
                    'uid' => $uid,
                    'title' => $language->getTitle(),
                    'isoCode' => $isoCode,
                ];
            }
        }

        if ($languages === []) {
            return '';
        }

        ksort($languages);

        $lines = [];
        foreach ($languages as $lang) {
            $suffix = $lang['uid'] === 0 ? ' (default)' : '';
            $lines[] = sprintf(
                '- %s: sys_language_uid=%d, ISO "%s"%s',
                $lang['title'],
                $lang['uid'],
                $lang['isoCode'],
                $suffix,
            );
        }

        return "Available site languages — always set sys_language_uid when creating or updating content:\n"
            . implode("\n", $lines);
    }

    private function persist(Conversation $conversation): void
    {
        $this->repository->update($conversation);
    }
}
