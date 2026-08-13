<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use LogicException;
use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Provider\Contract\DocumentCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\VisionCapableInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\Exception\ApproverNotPermittedException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAlreadyResumingException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingApprovalException;
use Netresearch\NrLlm\Service\Agent\Exception\StaleApprovalTurnException;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Exception\ChatException;
use Netresearch\NrMcpAgent\Exception\Exception as NrMcpAgentException;
use Netresearch\NrMcpAgent\Utility\ErrorMessageSanitizer;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Runs a backend chat turn through nr-llm's AgentRuntime.
 *
 * The whole tool loop is delegated to nr-llm: the AgentRuntime drives the
 * model over nr-llm's own ToolRegistry (its ~45 builtin backend tools) and
 * returns the settled result synchronously. This service only resolves the
 * LlmConfiguration the chat should use, builds the message transcript (with a
 * strong TYPO3-backend identity system prompt), and maps the run outcome back
 * onto the conversation. Tools are no longer sourced from MCP servers here.
 */
final class ChatService implements ChatApprovalInterface, ChatCapabilitiesInterface
{
    /**
     * Base identity and behaviour contract, prepended to every system prompt so
     * the assistant states who it is, is steered to use its tools instead of
     * asking the user to paste data, and never claims to be ChatGPT/OpenAI —
     * even when the configured Task/Configuration prompt is weak or empty.
     */
    private const IDENTITY_PROMPT = <<<'PROMPT'
        You are "TYPO3 Backend AI Chat by Netresearch", an AI assistant embedded directly in the TYPO3 backend of this website. You assist backend administrators and editors with managing, inspecting and troubleshooting this TYPO3 installation.

        You have direct access to tools that inspect and act on THIS TYPO3 system — for example reading the system log and last exceptions, checking system status, listing deprecations, reading or searching content records, and reading or searching the project source code. When a source-reading tool is available, the entire project source is readable, including installed packages under vendor/ (pass a vendor-relative path such as vendor/<vendor>/<package>/... to read a file, or scope a code search to a vendor package path); never claim that vendor/ or other project sources are outside your reach. Whenever a question can be answered by looking something up or by performing an action, USE the appropriate tool and work from its result. Do not ask the user to paste logs, copy data, or describe what they see when a tool can retrieve it for you.

        Never claim to be ChatGPT or GPT, and never claim to be made by OpenAI or any other vendor: you are the Netresearch TYPO3 Backend AI Chat. Always answer in the same language the user writes in.
        PROMPT;

    /** @var array{system_prompt: string, prompt_template: string}|null */
    private ?array $resolvedPrompts = null;

    public function __construct(
        private readonly ConversationRepository $repository,
        private readonly ExtensionConfiguration $config,
        private readonly AgentRuntimeInterface $agentRuntime,
        private readonly PendingApprovalReaderInterface $pendingApprovalReader,
        private readonly TaskRepository $taskRepository,
        private readonly ProviderAdapterRegistryInterface $adapterRegistry,
        private readonly ResourceFactory $resourceFactory,
        private readonly SiteFinder $siteFinder,
        private readonly DocumentExtractorRegistry $documentExtractorRegistry,
    ) {}

    /**
     * @return array{visionSupported: bool, maxFileSize: int, supportedFormats: list<string>}
     */
    public function getProviderCapabilities(): array
    {
        $extractionFormats = $this->documentExtractorRegistry->getAvailableExtensions();

        try {
            $configuration = $this->resolveConfiguration();
            $provider = $this->resolveProvider($configuration);
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
            'supportedFormats' => $extractionFormats,
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

        try {
            $configuration = $this->resolveConfiguration();
            $this->runAgentTurn($conversation, $configuration);
        } catch (Throwable $e) {
            $conversation->setStatus(ConversationStatus::Failed);
            $conversation->setErrorMessage(ErrorMessageSanitizer::sanitize($e->getMessage()));
            $this->persist($conversation);
        }
    }

    public function resumeConversation(Conversation $conversation): void
    {
        if (!$conversation->isResumable()) {
            return;
        }

        // The AgentRuntime drives the entire tool loop synchronously, so a turn
        // never leaves persisted pending tool calls to replay. Resuming a
        // Processing/ToolLoop/Failed conversation therefore simply re-runs the
        // turn over the existing transcript.
        $this->processConversation($conversation);
    }

    /**
     * Build the transcript and hand the whole turn to nr-llm's AgentRuntime,
     * which runs the tool loop over nr-llm's ToolRegistry and settles the result.
     */
    private function runAgentTurn(Conversation $conversation, LlmConfiguration $configuration): void
    {
        $conversation->setStatus(ConversationStatus::Processing);
        $this->repository->updateStatus($conversation->getUid(), ConversationStatus::Processing, $conversation->getBeUser());

        // The provider is resolved only to expand file attachments into the
        // multimodal wire shape (image/document capability); the chat itself
        // runs entirely inside the AgentRuntime against the configuration.
        $provider = $this->resolveProvider($configuration);

        $systemPrompt = $this->buildSystemPrompt($conversation);

        // buildLlmMessages expands fileUid refs to base64 for the LLM call only —
        // the expanded arrays are never persisted back to the conversation.
        $messages = [];
        if ($systemPrompt !== '') {
            $messages[] = ChatMessage::system($systemPrompt);
        }

        foreach ($this->buildLlmMessages($conversation->getDecodedMessages(), $provider) as $message) {
            $messages[] = $message;
        }

        // allowedToolNames is left at its null default: offer the whole
        // globally-enabled tool set (the runtime's own gate is authoritative).
        $result = $this->agentRuntime->run(new AgentRunRequest(
            configuration: $configuration,
            messages: $messages,
            actor: $this->resolveActor($conversation->getBeUser()),
        ));

        $this->applyResult($conversation, $result);
    }

    /**
     * Build the actor that drives the run from the live backend user the entry
     * commands (ChatWorkerCommand/ProcessChatCommand) already initialise via
     * {@see BackendUserInitializer::initialize()}, so the run authorises against
     * that user's admin flag and backend groups — exactly as the removed
     * `beUserUid` did in nr-llm 0.23. Never a service account: the run belongs to
     * a specific backend user, and a scopeless service account may do nothing.
     *
     * The conversation only stores the bare uid; the live `$GLOBALS['BE_USER']`
     * is the authoritative source for the admin flag and group ids. The uid-only
     * fallback keeps the class testable and never hard-crashes if the global is
     * absent (isolation / misuse) — ownership still holds through the uid.
     */
    /**
     * The pending tool call of a parked conversation, as the approvals inbox
     * would show it.
     *
     * Reuses nr-llm's own view factory rather than decoding the suspended state
     * here: the digest it puts on the view is the one ResumeCoordinator verifies
     * (ADR-132), and a second implementation of that would drift.
     *
     * Null when nothing is pending, when the run is gone, or when the actor may
     * not read it — status() answers per run, so an unreadable run and one that
     * does not exist are indistinguishable from here, which is the point.
     */
    public function pendingApproval(Conversation $conversation): ?WaitingRunView
    {
        $runUuid = $conversation->getApprovalRunUuid();
        if ($runUuid === '' || $conversation->getStatus() !== ConversationStatus::AwaitingApproval) {
            return null;
        }

        return $this->pendingApprovalReader->read(
            $this->resolveActor($conversation->getBeUser()),
            $runUuid,
        );
    }

    /**
     * Decide the pending tool call from the chat and carry the run to its end.
     *
     * approve() authorises per run through mayActOnRun() and drives the
     * continuation itself, returning the settled result — so the decision made
     * here goes through exactly the gate the AI Tasks module goes through, and
     * the answer lands in this conversation instead of being lost to a module
     * the chat cannot see.
     *
     * The digest travels with the decision and is verified against the freshly
     * claimed state inside the runtime (ADR-132). It is deliberately not checked
     * here: a check before the claim can pass on a turn a concurrent approval
     * has already replaced.
     */
    public function decideApproval(Conversation $conversation, bool $approve, string $turnDigest): void
    {
        $runUuid = $conversation->getApprovalRunUuid();
        if ($runUuid === '' || $conversation->getStatus() !== ConversationStatus::AwaitingApproval) {
            return;
        }

        // Claim the conversation for the duration of the inline continuation.
        // Without it a follow-up message can be accepted while approve() runs —
        // sendMessage does not treat AwaitingApproval as busy — and the full-row
        // write at the end would then erase that message together with the
        // claim it made. Processing is the busy state every other path uses.
        $conversation->setStatus(ConversationStatus::Processing);
        if (!$this->repository->updateIf($conversation, ConversationStatus::AwaitingApproval)) {
            // Someone else moved the conversation on between the controller's
            // read and here. Their state wins; do not decide on top of it.
            $conversation->setStatus(ConversationStatus::AwaitingApproval);
            $conversation->setApprovalRunUuid($runUuid);

            return;
        }

        try {
            $result = $this->agentRuntime->approve(
                $this->resolveActor($conversation->getBeUser()),
                $runUuid,
                new ApprovalDecision($approve, $conversation->getBeUser(), $turnDigest),
            );
        } catch (RunNotAwaitingApprovalException|RunAlreadyResumingException|StaleApprovalTurnException|ApproverNotPermittedException $e) {
            // These four RELEASE the run rather than consume it: it is still
            // pending and still decidable. Marking the conversation Failed would
            // be wrong twice — the card would vanish, and Failed is resumable,
            // so the UI would offer a Retry that starts a SECOND run over the
            // same transcript while the first is still waiting for its
            // approval. Put it back where it was and say what happened.
            $conversation->setStatus(ConversationStatus::AwaitingApproval);
            $conversation->setApprovalRunUuid($runUuid);
            $conversation->setErrorMessage(ErrorMessageSanitizer::sanitize($e->getMessage()));
            $this->persist($conversation);

            return;
        } catch (Throwable $e) {
            $conversation->setStatus(ConversationStatus::Failed);
            $conversation->setErrorMessage(ErrorMessageSanitizer::sanitize($e->getMessage()));
            $this->persist($conversation);

            return;
        }

        $this->applyResult($conversation, $result);
    }

    private function resolveActor(int $beUserUid): AiActorContext
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($beUserUid > 0 && $backendUser instanceof BackendUserAuthentication) {
            $liveUid = $backendUser->user['uid'] ?? null;
            if (is_numeric($liveUid) && (int) $liveUid === $beUserUid) {
                $groupIds = array_values(array_map(
                    static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0,
                    $backendUser->userGroupsUID,
                ));

                return AiActorContext::backendUser($beUserUid, $backendUser->isAdmin(), $groupIds);
            }
        }

        return AiActorContext::backendUser($beUserUid);
    }

    /**
     * Map the settled AgentRuntime result onto the conversation: the final
     * assistant answer on completion, a Failed status with a sanitized reason
     * for any other outcome.
     */
    private function applyResult(Conversation $conversation, AgentRunResult $result): void
    {
        if ($result->outcome === AgentRunOutcome::COMPLETED && $result->loopResult !== null) {
            $conversation->appendMessage(MessageRole::Assistant, $result->loopResult->finalContent);
            $conversation->setStatus(ConversationStatus::Idle);
            $this->persist($conversation);
            return;
        }

        // A pending approval is not a failure. The run did exactly what it is
        // supposed to do: it stopped before a write and is waiting for a human.
        // Reporting it as Failed made the safeguard look like a crash.
        if ($result->outcome === AgentRunOutcome::AWAITING_APPROVAL) {
            $conversation->setStatus(ConversationStatus::AwaitingApproval);
            $conversation->setErrorMessage($this->describeAwaitingApproval($result));
            // Kept separately from the message so the chat can build a link to
            // this run instead of asking the user to find it in a list.
            $conversation->setApprovalRunUuid($result->runUuid);
            $this->persist($conversation);
            return;
        }

        $conversation->setStatus(ConversationStatus::Failed);
        $conversation->setErrorMessage($this->describeFailure($result));
        $this->persist($conversation);
    }

    /**
     * Say what is pending and where it is granted.
     *
     * The run uuid is included because the approvals inbox lists runs and the
     * user has to find the right one; it is empty when the run could not be
     * persisted (the persister is fail-soft), and then it is simply left out
     * rather than shown as an empty reference.
     */
    private function describeAwaitingApproval(AgentRunResult $result): string
    {
        $message = 'This step writes data, so it is waiting for your approval.'
            . ' Grant it under Web > AI Tasks > Approvals, then the run continues on its own.';

        return $result->runUuid !== ''
            ? $message . ' Run: ' . $result->runUuid
            : $message;
    }

    /**
     * Turn a failed run outcome into a human-readable message. A default arm is
     * mandatory: AgentRunOutcome gains cases in nr-llm minor releases.
     *
     * AWAITING_APPROVAL is handled before this is reached — see applyResult().
     */
    private function describeFailure(AgentRunResult $result): string
    {
        if ($result->error !== null) {
            return ErrorMessageSanitizer::sanitize($result->error->getMessage());
        }

        // Only reference AgentRunOutcome cases guaranteed by the minimum
        // supported nr-llm (^0.23.1); newer cases fall through to the default.
        return match ($result->outcome) {
            AgentRunOutcome::GUARDRAIL_BLOCKED,
            AgentRunOutcome::GUARDRAIL_APPROVAL_REQUIRED => 'The request was blocked by a safety guardrail.',
            default => sprintf('The assistant run did not complete (%s).', $result->outcome->value),
        };
    }

    /**
     * Resolve the LlmConfiguration the chat should use from the configured
     * nr-llm Task (llmTaskUid → Task → Configuration) and cache the Task and
     * Configuration prompts for {@see buildSystemPrompt()}.
     *
     * @throws NrMcpAgentException when the Task or its Configuration is missing
     */
    private function resolveConfiguration(): LlmConfiguration
    {
        $taskUid = $this->config->getLlmTaskUid();
        $task = $this->taskRepository->findByUid($taskUid);
        if ($task === null) {
            throw new NrMcpAgentException(sprintf('nr-llm Task with uid %d not found', $taskUid));
        }

        $configuration = $task->getConfiguration();
        if ($configuration === null) {
            throw new NrMcpAgentException(sprintf('nr-llm Task with uid %d has no LLM configuration assigned', $taskUid));
        }

        $this->resolvedPrompts = [
            'system_prompt' => $configuration->getSystemPrompt(),
            'prompt_template' => $task->getPromptTemplate(),
        ];

        return $configuration;
    }

    /**
     * Create a configured provider adapter for the configuration's fixed model.
     * Used only for multimodal file expansion and capability reporting — the
     * chat turn itself runs through the AgentRuntime.
     *
     * @throws NrMcpAgentException when the configuration has no fixed model
     */
    private function resolveProvider(LlmConfiguration $configuration): ProviderInterface
    {
        $model = $configuration->getLlmModel();
        if ($model === null) {
            throw new NrMcpAgentException('The nr-llm configuration has no fixed model to resolve a provider adapter from.');
        }

        return $this->adapterRegistry->createAdapterFromModel($model);
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
                if (is_int($msg['fileUid'])) {
                    $fileUid = $msg['fileUid'];
                } else {
                    $fileUid = is_numeric($msg['fileUid']) ? (int) $msg['fileUid'] : 0;
                }

                $file = $this->resourceFactory->getFileObject($fileUid);
                $localPath = $file->getForLocalProcessing(false);
                $base64 = base64_encode((string) file_get_contents($localPath));
                $mimeType = $file->getMimeType();

                $result[] = [
                    'role' => is_string($msg['role']) ? $msg['role'] : '',
                    'content' => [
                        ['type' => 'text', 'text' => is_string($msg['content'] ?? null) ? $msg['content'] : ''],
                        $this->buildFileContentBlock($mimeType, $base64, $localPath, $provider),
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
     * @throws ChatException if the file type is not supported by the provider or any extractor
     */
    private function buildFileContentBlock(
        string $mimeType,
        string $base64,
        string $localPath,
        ProviderInterface $provider,
    ): array {
        if (str_starts_with($mimeType, 'image/')) {
            return [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:' . $mimeType . ';base64,' . $base64],
            ];
        }

        if ($provider instanceof DocumentCapableInterface && $provider->supportsDocuments()) {
            return [
                'type' => 'document',
                'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $base64],
            ];
        }

        if ($this->documentExtractorRegistry->canExtract($mimeType)) {
            $text = $this->documentExtractorRegistry->extract($localPath, $mimeType);
            return [
                'type' => 'text',
                'text' => '[Extracted from ' . basename($localPath) . ']' . "\n"
                    . ($text !== '' ? $text : '[File contained no extractable text]'),
            ];
        }

        throw new ChatException(
            'Provider "' . $provider->getIdentifier() . '" does not support document uploads (mime type: ' . $mimeType . ')',
            1742320000,
        );
    }

    private function buildSystemPrompt(Conversation $conversation): string
    {
        // The identity/behaviour contract is always the first layer so the
        // assistant's identity and tool-seeking behaviour hold regardless of
        // how the Task/Configuration prompt is (mis)configured.
        $parts = [self::IDENTITY_PROMPT];

        // 1. Conversation-level custom prompt (highest-priority task instructions)
        $custom = $conversation->getSystemPrompt();
        if ($custom !== '') {
            $parts[] = $custom;
        } else {
            // 2. Task Configuration system_prompt + Task prompt_template
            if ($this->resolvedPrompts === null) {
                throw new LogicException('resolveConfiguration() must be called before buildSystemPrompt()');
            }

            $configPrompt = $this->resolvedPrompts['system_prompt'];
            if ($configPrompt !== '') {
                $parts[] = $configPrompt;
            }

            $taskPrompt = $this->resolvedPrompts['prompt_template'];
            if ($taskPrompt !== '') {
                $parts[] = $taskPrompt;
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
                    // Locale resolution failed — fall back to hreflang below
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
