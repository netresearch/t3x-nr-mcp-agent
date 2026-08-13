<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use finfo;
use Netresearch\NrLlm\Controller\Backend\AgentRunController;
use Netresearch\NrLlm\Service\Agent\Inbox\PendingCallView;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class ChatApiController
{
    private const ERROR_FILE_NOT_FOUND = 'File not found';

    private const ERROR_CONVERSATION_PROCESSING = 'Conversation is already processing';

    public function __construct(
        private ConversationRepository $repository,
        private ChatProcessorInterface $processor,
        private ExtensionConfiguration $config,
        private ChatCapabilitiesInterface $chatService,
        private ChatApprovalInterface $chatApproval,
        private ResourceFactory $resourceFactory,
        private StorageRepository $storageRepository,
        private DocumentExtractorRegistry $documentExtractorRegistry,
        private UriBuilder $uriBuilder,
    ) {}

    /**
     * Link to the run that is waiting for an approval.
     *
     * The approvals inbox lists every run the user may act on, so pointing at
     * the module alone still leaves them searching. The read-only run detail
     * takes the uuid directly.
     *
     * The detail arrived in nr-llm 0.29 (ADR-153) and this extension supports
     * 0.28 as well, so the action has to be checked rather than assumed. It
     * cannot be caught either: a backend module route resolves whether or not
     * the action behind it is registered, so the URI would build and the
     * failure would appear only on click, as an exception page. That is the
     * impression this whole notice exists to remove. On 0.28 the notice simply
     * carries no link, which is what it did before the link existed.
     *
     * Returns an empty string when there is nothing pending, and when the
     * route is unknown: a chat that throws because a link cannot be built is
     * worse than a chat without the link.
     */
    private function buildApprovalUrl(string $runUuid): string
    {
        // PHPStan sees whichever nr-llm composer resolved and calls the check
        // constant. composer.json permits 0.28 and 0.29, and the method exists
        // in only one of them, so the condition is undecidable at analysis time
        // and load-bearing at runtime — ApprovalLinkTargetTest asserts both
        // answers against the installed version.
        // @phpstan-ignore function.alreadyNarrowedType
        if ($runUuid === '' || !method_exists(AgentRunController::class, 'showAction')) {
            return '';
        }

        try {
            return (string) $this->uriBuilder->buildUriFromRoute('nrllm_aitasks', [
                'controller' => 'Backend\\AgentRun',
                'action' => 'show',
                'runUuid' => $runUuid,
            ]);
        } catch (RouteNotFoundException) {
            return '';
        }
    }

    /**
     * GET /ai-chat/status – Check if AI chat is available for current user.
     */
    public function getStatus(): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $taskUid = $this->config->getLlmTaskUid();
        $mcpEnabled = $this->config->isMcpEnabled();
        $issues = [];
        if ($taskUid === 0) {
            $issues[] = 'No nr-llm Task configured. An admin must create an nr-llm Task record and set its UID in Extension Configuration.';
        }

        if ($this->config->hasLegacyMcpFields()) {
            $issues[] = 'Legacy MCP fields (mcpServerCommand/mcpServerArgs) are still set in Extension Configuration. These fields are no longer used. MCP servers are now configured in the List module on PID 0.';
        }

        $capabilities = $this->chatService->getProviderCapabilities();
        return new JsonResponse([
            'available' => $taskUid > 0,
            'mcpEnabled' => $mcpEnabled,
            'activeConversationCount' => $this->repository->countActiveByBeUser($this->getBeUserUid()),
            'issues' => $issues,
            ...$capabilities,
        ]);
    }

    /**
     * GET /ai-chat/conversations – List conversations for current user.
     */
    public function listConversations(): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $conversations = $this->repository->findByBeUser($this->getBeUserUid());
        $items = array_map(fn(Conversation $c): array => [
            'uid' => $c->getUid(),
            'title' => $c->getTitle(),
            'status' => $c->getStatus()->value,
            'messageCount' => $c->getMessageCount(),
            'pinned' => $c->isPinned(),
            'resumable' => $c->isResumable(),
            'errorMessage' => $c->getErrorMessage(),
            'approvalUrl' => $this->buildApprovalUrl($c->getApprovalRunUuid()),
            'tstamp' => $c->getTstamp(),
        ], $conversations);
        return new JsonResponse(['conversations' => $items]);
    }

    /**
     * POST /ai-chat/conversations/create – Create new conversation.
     */
    public function createConversation(): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $conversation = new Conversation();
        $conversation->setBeUser($this->getBeUserUid());

        $uid = $this->repository->add($conversation);
        return new JsonResponse([
            'uid' => $uid,
        ], 201);
    }

    /**
     * GET /ai-chat/conversations/messages?conversationUid={uid}&after={index}
     */
    public function getMessages(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        /** @var array<string, string> $queryParams */
        $queryParams = $request->getQueryParams();
        $uid = (int) ($queryParams['conversationUid'] ?? 0);
        $afterIndex = (int) ($queryParams['after'] ?? 0);

        // Fast path for polling: check metadata first without loading messages blob
        if ($afterIndex > 0) {
            $meta = $this->repository->findPollStatus($uid, $this->getBeUserUid());
            if ($meta === null) {
                return new JsonResponse(['error' => 'Conversation not found'], 404);
            }

            // The stuck case writes no message, so the fast path is exactly where
            // a conversation that needs repairing lives. Falling through loads
            // the row so reconcile() can look at it; the condition is narrow
            // enough that an ordinary poll never pays for it.
            $mayNeedRepair = $meta['status'] === ConversationStatus::Processing->value
                && $meta['approval_run_uuid'] !== ''
                && $meta['tstamp'] > 0;

            // A run that pauses for approval writes no message either, so the
            // poll that first sees the pause takes this path too — and the fast
            // response has no pendingApproval, which the client reads as "no
            // card". The user would be left with the notice and the deep link
            // until they reload. Polling has already stopped by then
            // (awaiting_approval is not a processing status), so nothing repairs
            // it. Fall through once and answer with the card.
            $isAwaitingApproval = $meta['status'] === ConversationStatus::AwaitingApproval->value;

            if ($meta['message_count'] <= $afterIndex && !$mayNeedRepair && !$isAwaitingApproval) {
                return new JsonResponse([
                    'status' => $meta['status'],
                    'messages' => [],
                    'totalCount' => $meta['message_count'],
                    'errorMessage' => $meta['error_message'],
                    'approvalUrl' => $this->buildApprovalUrl($meta['approval_run_uuid']),
                ]);
            }
        }

        $conversation = $this->findConversationOrFail($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        // A claimed conversation whose worker never took the decision would spin
        // forever; the run itself says whether that happened.
        $this->chatApproval->reconcile($conversation);

        $messages = $conversation->getDecodedMessages();
        $newMessages = array_slice($messages, $afterIndex);

        return new JsonResponse([
            'status' => $conversation->getStatus()->value,
            'messages' => $newMessages,
            'totalCount' => count($messages),
            'errorMessage' => $conversation->getErrorMessage(),
            'approvalUrl' => $this->buildApprovalUrl($conversation->getApprovalRunUuid()),
            'pendingApproval' => $this->buildPendingApproval($conversation),
        ]);
    }

    /**
     * POST /ai-chat/conversations/send
     */
    public function sendMessage(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $body = $this->parseBody($request);
        $conversation = $this->findConversationOrFail($request, $body);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $content = trim((string) ($body['content'] ?? ''));

        if ($content === '') {
            return new JsonResponse(['error' => 'Empty message'], 400);
        }

        $maxLength = $this->config->getMaxMessageLength();
        if ($maxLength > 0 && mb_strlen($content) > $maxLength) {
            return new JsonResponse(['error' => sprintf('Message too long (max %d characters)', $maxLength)], 400);
        }

        $fileUid = isset($body['fileUid']) ? (int) $body['fileUid'] : null;
        $fileName = null;
        $fileMimeType = null;

        if ($fileUid !== null) {
            $existingFileCount = $this->countFilesInConversation($conversation);
            if ($existingFileCount >= 5) {
                return new JsonResponse(['error' => 'Maximum 5 files per conversation reached'], 400);
            }

            try {
                $file = $this->resourceFactory->getFileObject($fileUid);
                if (!$file->checkActionPermission('read')) {
                    return new JsonResponse(['error' => self::ERROR_FILE_NOT_FOUND], 404);
                }

                $fileName = $file->getName();
                $fileMimeType = $file->getMimeType();
            } catch (Exception) {
                return new JsonResponse(['error' => self::ERROR_FILE_NOT_FOUND], 404);
            }
        }

        $currentStatus = $conversation->getStatus();
        if (in_array($currentStatus, [ConversationStatus::Processing, ConversationStatus::Locked, ConversationStatus::ToolLoop], true)
        ) {
            return new JsonResponse(['error' => self::ERROR_CONVERSATION_PROCESSING], 409);
        }

        $maxActive = $this->config->getMaxActiveConversationsPerUser();
        if ($maxActive > 0) {
            $activeCount = $this->repository->countActiveByBeUser($this->getBeUserUid());
            if ($activeCount >= $maxActive) {
                return new JsonResponse(['error' => sprintf('Too many active conversations (max %d)', $maxActive)], 429);
            }
        }

        if ($fileUid !== null) {
            $messages = $conversation->getDecodedMessages();
            $messages[] = [
                'role' => MessageRole::User->value,
                'content' => $content,
                'fileUid' => $fileUid,
                'fileName' => $fileName,
                'fileMimeType' => $fileMimeType,
                'createdAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            ];
            $conversation->setMessages($messages);
            if ($conversation->getTitle() === '') {
                $conversation->setTitle($content);
            }
        } else {
            $conversation->appendMessage(MessageRole::User, $content);
        }

        $conversation->setStatus(ConversationStatus::Processing);
        $conversation->setErrorMessage('');
        // A new turn abandons a pending approval: the reference would otherwise
        // survive into Processing, where the approval link still reads it and
        // reconcile() would hand the card back in the middle of the new turn.
        $conversation->setApprovalRunUuid('');
        $conversation->clearApprovalDecision();

        // Atomic CAS: write full row only if status still matches,
        // preventing race conditions with concurrent requests or worker dequeue.
        $claimed = $this->repository->updateIf($conversation, $currentStatus);
        if (!$claimed) {
            return new JsonResponse(['error' => self::ERROR_CONVERSATION_PROCESSING], 409);
        }

        $this->processor->dispatch($conversation->getUid());

        return new JsonResponse(['status' => 'processing'], 202);
    }

    /**
     * POST /ai-chat/file-upload – Upload a file to FAL for use as chat attachment.
     */
    public function fileUpload(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        /** @var array<string, UploadedFileInterface> $uploadedFiles */
        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return new JsonResponse(['error' => 'No file uploaded'], 400);
        }

        $capabilities = $this->chatService->getProviderCapabilities();
        // $capabilities['supportedFormats'] contains file extensions (e.g. 'png', 'jpg') because
        // the frontend uses them for the <input accept> filter.  finfo returns MIME types, so we
        // map extensions to MIME types before comparing.
        $extensionMimeMap = [
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'pdf'  => 'application/pdf',
        ];
        $providerMimeTypes = array_values(array_filter(array_map(
            static fn(string $ext): ?string => $extensionMimeMap[$ext] ?? null,
            $capabilities['supportedFormats'],
        )));
        $allowedMimeTypes = array_values(array_unique(array_merge(
            $providerMimeTypes,
            $this->documentExtractorRegistry->getAvailableMimeTypes(),
        )));

        $maxSize = 20 * 1024 * 1024; // 20 MB
        if ($file->getSize() > $maxSize) {
            return new JsonResponse(['error' => 'File too large (max 20 MB)'], 400);
        }

        // Validate MIME type server-side via finfo — client-supplied Content-Type is untrusted
        $uri = $file->getStream()->getMetadata('uri');
        $tempPath = is_string($uri) ? $uri : '';
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($tempPath);
        if (!is_string($detectedMime) || !in_array($detectedMime, $allowedMimeTypes, true)) {
            return new JsonResponse(['error' => 'File type not supported'], 422);
        }

        // For extraction-backed formats, run lightweight validation at upload time
        if ($this->documentExtractorRegistry->canExtract($detectedMime)) {
            try {
                $this->documentExtractorRegistry->validate($tempPath, $detectedMime);
            } catch (RuntimeException $e) {
                return new JsonResponse(['error' => 'File could not be processed: ' . $e->getMessage()], 422);
            }
        }

        $storage = $this->storageRepository->getDefaultStorage();
        if ($storage === null) {
            return new JsonResponse(['error' => 'No default storage configured'], 500);
        }

        $beUserUid = $this->getBeUserUid();
        $targetFolder = $this->getOrCreateUploadFolder($storage, $beUserUid);

        $clientFilename = $file->getClientFilename() ?? 'upload';
        $falFile = $storage->addFile(
            $tempPath,
            $targetFolder,
            $clientFilename,
        );

        return new JsonResponse([
            'fileUid' => $falFile->getUid(),
            'name' => $falFile->getName(),
            'mimeType' => $falFile->getMimeType(),
            'size' => $falFile->getSize(),
        ]);
    }

    /**
     * GET /ai-chat/file-info?fileUid={uid} – Resolve FAL file metadata by UID.
     */
    public function fileInfo(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        /** @var array<string, string> $params */
        $params = $request->getQueryParams();
        $rawUid = $params['fileUid'] ?? '';

        if ($rawUid === '' || !ctype_digit((string) $rawUid) || (int) $rawUid <= 0) {
            return new JsonResponse(['error' => 'Invalid fileUid'], 400);
        }

        try {
            $file = $this->resourceFactory->getFileObject((int) $rawUid);
        } catch (Exception) {
            return new JsonResponse(['error' => self::ERROR_FILE_NOT_FOUND], 404);
        }

        if (!$file->checkActionPermission('read')) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        if (!in_array($file->getExtension(), $this->documentExtractorRegistry->getAvailableExtensions(), true)) {
            return new JsonResponse(['error' => 'Unsupported file type'], 422);
        }

        return new JsonResponse([
            'fileUid'  => $file->getUid(),
            'name'     => $file->getName(),
            'mimeType' => $file->getMimeType(),
            'size'     => $file->getSize(),
        ]);
    }

    /**
     * Whether this user may decide an approval at all.
     *
     * The same module the AI Tasks inbox lives in — `nrllm_aitasks` is
     * `access: user`, so it can be withheld from a group. Without this check the
     * chat would be a second, unscoped way to release the write fence: a group
     * given the chat but not the module could decide runs it previously could
     * only start. The write itself stays inside what the approver may do either
     * way (nr-llm re-evaluates the tool policy against them), but who may
     * release the fence is not something this extension gets to widen on its own.
     */
    private function mayDecideApprovals(): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }

        return $backendUser->isAdmin() || (bool) $backendUser->check('modules', 'nrllm_aitasks');
    }

    /**
     * The pending approval as the chat renders it: what the call would do, its
     * arguments, and the digest the decision has to carry back.
     *
     * Null whenever there is nothing to decide. `unreadableReason` is passed
     * through rather than swallowed — a run whose suspended state cannot be read
     * must say so instead of showing an empty card that looks decidable.
     *
     * @return array<string, mixed>|null
     */
    private function buildPendingApproval(Conversation $conversation): ?array
    {
        if (!$this->mayDecideApprovals()) {
            // No card for someone who cannot decide: buttons they may not press
            // are worse than the prose alone.
            return null;
        }

        $view = $this->chatApproval->pendingApproval($conversation);
        if ($view === null) {
            return null;
        }

        // A run waiting for INPUT reaches this while the conversation row still
        // says AwaitingApproval, and its view carries no calls — which would
        // render as two enabled buttons over an empty card. The input pause
        // belongs to the module. An unreadable run does pass, so the card can
        // say why there is nothing to decide instead of silently vanishing.
        if ($view->mode === WaitingRunView::MODE_INPUT) {
            return null;
        }

        return [
            'runUuid'          => $view->runUuid,
            'turnDigest'       => $view->turnDigest ?? '',
            'configLabel'      => $view->configLabel,
            'unreadableReason' => $view->unreadableReason,
            'calls'            => array_map(
                static fn(PendingCallView $call): array => [
                    'name'                => $call->name,
                    'toolStillRegistered' => $call->toolStillRegistered,
                    'previewLines'        => $call->previewLines,
                    'previewFailed'       => $call->previewFailed,
                    'argumentsJson'       => $call->argumentsJson,
                ],
                $view->pendingCalls,
            ),
        ];
    }

    /**
     * POST /ai-chat/conversations/approve
     *
     * Records the decision and hands it to the worker, like every other path
     * here. approve() drives the whole continuation — up to MAX_ITERATIONS
     * provider round-trips — which a gateway timeout would kill with the write
     * already done and nothing written back.
     *
     * The objection to doing it this way is that the click no longer gets its
     * answer in the response. It gets it from the poll instead, which is where
     * every other outcome in this chat already arrives; and the case the
     * objection actually points at — a worker that never starts — is what
     * reconcile() answers, using the run's own status rather than a timer.
     */
    public function decideApproval(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $conversation = $this->findConversationOrFail($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        if (!$this->mayDecideApprovals()) {
            return new JsonResponse(['error' => 'Not allowed to decide approvals'], 403);
        }

        if ($conversation->getStatus() !== ConversationStatus::AwaitingApproval) {
            return new JsonResponse(['error' => 'Conversation is not waiting for an approval'], 409);
        }

        $body = $this->parseBody($request);
        $approve = (bool) ($body['approve'] ?? false);
        $digest = $body['turnDigest'] ?? '';
        $turnDigest = is_string($digest) ? $digest : '';

        if (!$this->chatApproval->recordDecision($conversation, $approve, $turnDigest)) {
            return new JsonResponse(['error' => self::ERROR_CONVERSATION_PROCESSING], 409);
        }

        $this->processor->dispatch($conversation->getUid());

        // 202, like every other path that hands work to the worker. The decision
        // is recorded, not yet carried out; the chat's poll reports the outcome,
        // and reconciles the conversation if the worker never takes it.
        return new JsonResponse(['status' => $conversation->getStatus()->value], 202);
    }

    /**
     * POST /ai-chat/conversations/resume
     */
    public function resumeConversation(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $conversation = $this->findConversationOrFail($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        if (!$conversation->isResumable()) {
            return new JsonResponse(['error' => 'Conversation is not resumable'], 400);
        }

        $currentStatus = $conversation->getStatus();

        $conversation->setStatus(ConversationStatus::Processing);
        $conversation->setErrorMessage('');
        // Retry re-runs the turn; it does not carry out a decision recorded
        // earlier. Without this the worker would find one and execute the write
        // from a click labelled "Retry".
        $conversation->setApprovalRunUuid('');
        $conversation->clearApprovalDecision();

        // Atomic CAS: write full row only if status still matches.
        $claimed = $this->repository->updateIf($conversation, $currentStatus);
        if (!$claimed) {
            return new JsonResponse(['error' => self::ERROR_CONVERSATION_PROCESSING], 409);
        }

        $this->processor->dispatch($conversation->getUid());

        return new JsonResponse(['status' => 'processing'], 202);
    }

    /**
     * POST /ai-chat/conversations/archive
     */
    public function archiveConversation(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $conversation = $this->findConversationOrFail($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $this->repository->updateArchived($conversation->getUid(), true, $this->getBeUserUid());

        return new JsonResponse(['status' => 'archived']);
    }

    /**
     * POST /ai-chat/conversations/pin
     */
    public function togglePin(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $conversation = $this->findConversationOrFail($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $newPinned = !$conversation->isPinned();
        $this->repository->updatePinned($conversation->getUid(), $newPinned, $this->getBeUserUid());

        return new JsonResponse(['pinned' => $newPinned]);
    }

    /**
     * POST /ai-chat/conversations/rename
     */
    public function renameConversation(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        // Parse body once — PSR-7 streams are one-shot; passing $body to
        // findConversationOrFail avoids reading the stream a second time.
        $body = $this->parseBody($request);
        $conversation = $this->findConversationOrFail($request, $body);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            return new JsonResponse(['error' => 'Title must not be empty'], 400);
        }

        $this->repository->updateTitle($conversation->getUid(), $title, $this->getBeUserUid());

        return new JsonResponse(['title' => $title]);
    }

    /**
     * @param array<string, string|int>|null $parsedBody
     */
    private function findConversationOrFail(ServerRequestInterface $request, ?array $parsedBody = null): Conversation|ResponseInterface
    {
        $body = $parsedBody ?? $this->parseBody($request);
        /** @var array<string, string> $queryParams */
        $queryParams = $request->getQueryParams();
        $uid = (int) ($queryParams['conversationUid'] ?? $body['conversationUid'] ?? 0);

        $conversation = $this->repository->findOneByUidAndBeUser($uid, $this->getBeUserUid());

        if ($conversation === null) {
            return new JsonResponse(['error' => 'Conversation not found'], 404);
        }

        return $conversation;
    }

    private function checkAccess(): ?ResponseInterface
    {
        $allowedGroups = $this->config->getAllowedGroupIds();
        if ($allowedGroups === []) {
            return null;
        }

        $beUser = $this->getBackendUser();

        if (((int) ($beUser['admin'] ?? 0)) === 1) {
            return null;
        }

        $userGroups = GeneralUtility::intExplode(
            ',',
            (string) ($beUser['usergroup'] ?? ''),
            true,
        );

        if (array_intersect($allowedGroups, $userGroups) !== []) {
            return null;
        }

        return new JsonResponse(['error' => 'Access denied'], 403);
    }

    /**
     * @return array<string, string|int>
     */
    private function parseBody(ServerRequestInterface $request): array
    {
        /** @var array<string, string|int> $body */
        $body = json_decode((string) $request->getBody(), true) ?? [];
        return $body;
    }

    private function getBeUserUid(): int
    {
        return (int) ($this->getBackendUser()['uid'] ?? 0);
    }

    private function countFilesInConversation(Conversation $conversation): int
    {
        $messages = $conversation->getDecodedMessages();
        return count(array_filter($messages, static fn(array $msg): bool => isset($msg['fileUid'])));
    }

    private function getOrCreateUploadFolder(ResourceStorage $storage, int $beUserUid): Folder
    {
        $basePath = 'ai-chat/' . $beUserUid;
        if (!$storage->hasFolder($basePath)) {
            return $storage->createFolder($basePath);
        }

        return $storage->getFolder($basePath);
    }

    /**
     * @return array<string, string|int>
     */
    private function getBackendUser(): array
    {
        // BE_USER is always set for authenticated backend requests; no DI alternative exists.
        /** @var object{user: array<string, string|int>} $beUser */
        $beUser = $GLOBALS['BE_USER'];
        return $beUser->user;
    }
}
