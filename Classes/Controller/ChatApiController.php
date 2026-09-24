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
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationErrorCode;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Utility\ContinueIntent;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientUserPermissionsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class ChatApiController
{
    private const ERROR_FILE_NOT_FOUND = 'File not found';

    private const LANGUAGE_FILE = 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf';

    /** The length the conversation model keeps; longer input is refused rather than cut. */
    private const MAX_SYSTEM_PROMPT_LENGTH = 10000;

    public function __construct(
        private ConversationRepository $repository,
        private ChatProcessorInterface $processor,
        private ExtensionConfiguration $config,
        private ChatCapabilitiesInterface $chatService,
        private ChatApprovalInterface $chatApproval,
        private ResourceFactory $resourceFactory,
        private StorageRepository $storageRepository,
        private DocumentExtractorRegistry $documentExtractorRegistry,
        private UploadMimeTypeMap $uploadMimeTypeMap,
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
        $issues = [];
        if ($taskUid === 0) {
            $issues[] = 'No nr-llm Task configured. An admin must create an nr-llm Task record and set its UID in Extension Configuration.';
        }


        $capabilities = $this->chatService->getProviderCapabilities();
        return new JsonResponse([
            'available' => $taskUid > 0,
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
            ...$this->presentError($c->getErrorMessage(), $c->getErrorCode()),
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
                return new JsonResponse(['error' => $this->translate('error.conversationNotFound')], 404);
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
                    ...$this->presentError($meta['error_message'], $meta['error_code']),
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
            ...$this->presentError($conversation->getErrorMessage(), $conversation->getErrorCode()),
            'approvalUrl' => $this->buildApprovalUrl($conversation->getApprovalRunUuid()),
            'pendingApproval' => $this->buildPendingApproval($conversation),
            'systemPrompt' => $conversation->getSystemPrompt(),
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
        // "weiter" while a run waits for an approval is not a new request. Left
        // to the ordinary path below it abandoned the approval and started a
        // second run over the same transcript — which drafted the page again
        // (ADR-017, NEXT-167). It is never read as an approval either. A run
        // awaiting approval is not busy, so this comes before refuseNewTurn().
        if ($currentStatus === ConversationStatus::AwaitingApproval && $fileUid === null && ContinueIntent::matches($content)) {
            $continued = $this->continuePendingRun($conversation, $content);
            if ($continued !== null) {
                return $continued;
            }
        }

        $refusal = $this->refuseNewTurn($currentStatus);
        if ($refusal !== null) {
            return $refusal;
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

        return $this->queueTurn($conversation, $currentStatus, $body);
    }

    /**
     * POST /ai-chat/conversations/edit – Replace one of the user's own
     * messages and run the conversation again from there.
     *
     * Everything after the edited message is dropped: the answers to the old
     * wording are answers to a question that is no longer in the transcript.
     * An attachment of the edited message stays attached — the edit changes
     * the text, not what was handed over with it.
     *
     * The same guards and the same claim as sendMessage(): an edit is a new
     * turn over a shorter transcript.
     */
    public function editMessage(ServerRequestInterface $request): ResponseInterface
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

        $rawIndex = $body['index'] ?? null;
        $index = is_int($rawIndex) ? $rawIndex : -1;
        $messages = $conversation->getDecodedMessages();
        $original = $messages[$index] ?? null;
        if ($original === null
            || ($original['role'] ?? '') !== MessageRole::User->value
            || !is_string($original['content'] ?? null)
        ) {
            return new JsonResponse(['error' => $this->translate('error.notEditable')], 400);
        }

        // The index is a position in the transcript the client last loaded.
        // If the transcript has changed since — another tab edited or sent —
        // the same index may point at another message, and the edit would
        // replace the wrong one. The client sends what it saw; a mismatch is a
        // conflict to reload, not an edit to guess at.
        $expectedContent = $body['expectedContent'] ?? null;
        $expectedCount = $body['messageCount'] ?? null;
        if ($expectedContent !== $original['content'] || $expectedCount !== count($messages)) {
            return new JsonResponse(['error' => $this->translate('error.editStale')], 409);
        }

        $currentStatus = $conversation->getStatus();
        $refusal = $this->refuseNewTurn($currentStatus);
        if ($refusal !== null) {
            return $refusal;
        }

        // The title was taken from the first message when it was sent. Editing
        // that message changes what the conversation is about, so an automatic
        // title follows; a title the user renamed stays.
        if ($index === 0 && $conversation->getTitle() === mb_substr($original['content'], 0, 255)) {
            $conversation->setTitle($content);
        }

        $original['content'] = $content;
        $original['createdAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $conversation->setMessages([...array_slice($messages, 0, $index), $original]);

        return $this->queueTurn($conversation, $currentStatus, $body);
    }

    /**
     * POST /ai-chat/conversations/system-prompt – Set the conversation's own
     * instructions; an empty value removes them.
     *
     * Written as a column of its own, which no full-row write touches, so a
     * turn that settles later cannot put the old value back. Refused while a
     * turn is running all the same: that turn already runs with the old
     * instructions, and saying "saved" would suggest otherwise.
     */
    public function updateSystemPrompt(ServerRequestInterface $request): ResponseInterface
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

        $prompt = trim((string) ($body['systemPrompt'] ?? ''));
        if (mb_strlen($prompt) > self::MAX_SYSTEM_PROMPT_LENGTH) {
            return new JsonResponse(['error' => sprintf('Instructions too long (max %d characters)', self::MAX_SYSTEM_PROMPT_LENGTH)], 400);
        }

        if ($this->isBusy($conversation->getStatus())) {
            return new JsonResponse(['error' => $this->translate('error.conversationProcessing')], 409);
        }

        $this->repository->updateSystemPrompt($conversation->getUid(), $prompt, $this->getBeUserUid());

        return new JsonResponse(['systemPrompt' => $prompt]);
    }

    private function isBusy(ConversationStatus $status): bool
    {
        return in_array($status, [ConversationStatus::Processing, ConversationStatus::Locked, ConversationStatus::ToolLoop], true);
    }

    /**
     * Why a new turn may not start now, or null when it may: the conversation
     * is already working, or the user has too many that are.
     */
    private function refuseNewTurn(ConversationStatus $currentStatus): ?ResponseInterface
    {
        if ($this->isBusy($currentStatus)) {
            return new JsonResponse(['error' => $this->translate('error.conversationProcessing')], 409);
        }

        $maxActive = $this->config->getMaxActiveConversationsPerUser();
        if ($maxActive > 0) {
            $activeCount = $this->repository->countActiveByBeUser($this->getBeUserUid());
            if ($activeCount >= $maxActive) {
                return new JsonResponse(['error' => sprintf('Too many active conversations (max %d)', $maxActive)], 429);
            }
        }

        return null;
    }

    /**
     * Claim the conversation for a new turn and hand it to the worker.
     *
     * @param array<string, mixed> $body the request body, for the view context
     */
    private function queueTurn(Conversation $conversation, ConversationStatus $currentStatus, array $body): ResponseInterface
    {
        // Where the user is in the backend, for this turn (NEXT-172). Only
        // shape is checked here; the worker decides what the user may see.
        $context = $body['context'] ?? null;
        if (is_array($context)) {
            $pageId = $context['pageId'] ?? 0;
            $module = $context['module'] ?? '';
            $conversation->setViewContext(is_int($pageId) ? $pageId : 0, is_string($module) ? $module : '');
        } else {
            $conversation->setViewContext(0, '');
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
            return new JsonResponse(['error' => $this->translate('error.conversationProcessing')], 409);
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
        // map extensions to MIME types before comparing — through the same UploadMimeTypeMap
        // getProviderCapabilities() filters with, so the picker and this check cannot disagree.
        $allowedMimeTypes = array_values(array_unique(array_merge(
            $this->uploadMimeTypeMap->toMimeTypes($capabilities['supportedFormats']),
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

        try {
            $targetFolder = $this->getOrCreateUploadFolder($storage, $beUserUid);
            $falFile = $this->storeAttachment($storage, $targetFolder, $tempPath, $file->getClientFilename() ?? 'upload');
        } catch (InsufficientFolderAccessPermissionsException|InsufficientFolderWritePermissionsException|InsufficientUserPermissionsException) {
            // The user's file mounts and permissions decide this, and core makes
            // the decision inside addFile()/createFolder(). Answer it as the
            // refusal it is instead of letting it surface as a 500.
            return new JsonResponse(['error' => 'Not allowed to store files in the chat attachment folder'], 403);
        }

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
     * Answer a "go on" message on a conversation that waits for an approval,
     * or null to let it through as an ordinary new turn (ADR-017).
     *
     * Still waiting: nothing is written and no run starts; the reader is told
     * that the decision is taken on the card. The message is not treated as
     * the decision: an approval is a decision on the preview the card shows,
     * and "habe alles freigegeben" is a belief about one — on the demo it was
     * written about runs decided in another module (conversations 79, 80).
     *
     * Decided elsewhere and finished: the message and a note are appended —
     * the note names the records the run wrote, so the next turn knows the
     * page exists instead of drafting it again — and the conversation is idle.
     * Decided elsewhere and still running: the same answer as any busy row.
     * Anything the run cannot tell (gone, unreadable): the ordinary path.
     */
    private function continuePendingRun(Conversation $conversation, string $content): ?ResponseInterface
    {
        $pending = $this->chatApproval->inspectPendingRun($conversation);

        if ($pending['state'] === ChatApprovalInterface::PENDING_RUN_WAITING) {
            // Only a reader who may decide gets the card (buildPendingApproval),
            // so only they are sent to it.
            return new JsonResponse([
                'error' => $this->translate($this->mayDecideApprovals() ? 'error.approvalStillPending' : 'error.approvalStillPendingElsewhere'),
            ], 409);
        }

        if ($pending['state'] === ChatApprovalInterface::PENDING_RUN_BUSY) {
            return new JsonResponse(['error' => $this->translate('error.conversationProcessing')], 409);
        }

        if ($pending['state'] !== ChatApprovalInterface::PENDING_RUN_SETTLED) {
            return null;
        }

        // Stored as a notice code plus a language-neutral line, for the reason
        // error_code exists (ADR-017): the reader gets a label in their own
        // language, rendered from the code and the records; the model reads the
        // line, which names the records so the next turn knows they exist.
        $conversation->appendMessage(MessageRole::User, $content);
        $conversation->appendMessage(
            MessageRole::Assistant,
            sprintf(
                '[The pending step was decided outside this chat and its run has finished. Records it wrote: %s.]',
                $pending['writes'] === [] ? 'none' : implode(', ', $pending['writes']),
            ),
            ChatService::NOTICE_RUN_FINISHED_OUTSIDE,
            $pending['writes'],
        );
        $conversation->setStatus(ConversationStatus::Idle);
        $conversation->setErrorMessage('');
        $conversation->setApprovalRunUuid('');
        $conversation->clearApprovalDecision();

        if (!$this->repository->updateIf($conversation, ConversationStatus::AwaitingApproval)) {
            return new JsonResponse(['error' => $this->translate('error.conversationProcessing')], 409);
        }

        return new JsonResponse(['status' => ConversationStatus::Idle->value], 200);
    }

    /**
     * The stored failure as this reader sees it (ADR-017).
     *
     * A failure with a code is phrased per reader: an administrator gets the
     * technical message and a link to where it is fixed, everyone else a plain
     * sentence that says who can fix it. "API key identifier is required for
     * provider OpenAI" helps the one and tells the other nothing (NEXT-167,
     * demo conversation 49).
     *
     * @return array{errorMessage: string, errorLink: string, errorLinkLabel: string}
     */
    private function presentError(string $message, string $code): array
    {
        $plain = ['errorMessage' => $message, 'errorLink' => '', 'errorLinkLabel' => ''];

        $kind = ConversationErrorCode::tryFrom($code);
        if ($kind === null || $message === '') {
            return $plain;
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || !$backendUser->isAdmin()) {
            return [
                'errorMessage' => $this->translate(match ($kind) {
                    ConversationErrorCode::ProviderNotConfigured => 'error.providerNotConfigured',
                    ConversationErrorCode::ChatNotConfigured => 'error.chatNotConfigured',
                }),
                'errorLink' => '',
                'errorLinkLabel' => '',
            ];
        }

        [$route, $label] = match ($kind) {
            ConversationErrorCode::ProviderNotConfigured => ['nrllm_providers', 'error.openProviders'],
            ConversationErrorCode::ChatNotConfigured => ['nrllm_tasks', 'error.openTasks'],
        };

        try {
            $link = (string) $this->uriBuilder->buildUriFromRoute($route);
        } catch (RouteNotFoundException) {
            return $plain;
        }

        return ['errorMessage' => $message, 'errorLink' => $link, 'errorLinkLabel' => $this->translate($label)];
    }

    /**
     * A label from the chat's language file, for the refusals the chat shows
     * verbatim in its status notice (NEXT-159): every refusal decideApproval()
     * and resumeConversation() can return, the shared checkAccess() and
     * findConversationOrFail() included. The decide refusals render under the
     * pending label while the card is still on screen — chat-core.js keeps the
     * status at awaiting_approval when decideApproval() fails — and the resume
     * refusals behind the error prefix, because Retry is offered in the error
     * branch only. The processing 409 is one unit on every endpoint that
     * claims the row, so sendMessage() shares it.
     *
     * $GLOBALS['LANG'] is the LanguageService BackendUserAuthenticator creates
     * from the user's preferences for every backend request, AJAX routes
     * included — the same source nr-llm's ModuleChromeTrait reads its labels
     * from. Without one, outside a backend request, the key is returned: sL()
     * answers an empty string for a key it cannot resolve, and an empty error
     * explains nothing.
     */
    private function translate(string $key): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            return $key;
        }

        $label = $languageService->sL(self::LANGUAGE_FILE . ':' . $key);

        return $label !== '' ? $label : $key;
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
                    // nr-llm refuses an approved write whose record changed after
                    // the preview and hands the run back with this flag. Without
                    // it the card returns looking exactly as it did before the
                    // click, and only the approvals module said why.
                    'previewStale'        => $call->previewStale,
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
            return new JsonResponse(['error' => $this->translate('error.approvalNotAllowed')], 403);
        }

        if ($conversation->getStatus() !== ConversationStatus::AwaitingApproval) {
            return new JsonResponse(['error' => $this->translate('error.notAwaitingApproval')], 409);
        }

        $body = $this->parseBody($request);
        $approve = (bool) ($body['approve'] ?? false);
        $digest = $body['turnDigest'] ?? '';
        $turnDigest = is_string($digest) ? $digest : '';

        if (!$this->chatApproval->recordDecision($conversation, $approve, $turnDigest)) {
            return new JsonResponse(['error' => $this->translate('error.conversationProcessing')], 409);
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
            return new JsonResponse(['error' => $this->translate('error.notResumable')], 400);
        }

        // A recorded decision is work in flight, not a stuck turn. Processing is
        // resumable, and the worker holds the decision from the moment it is
        // recorded until the continuation settles — so a Retry arriving in that
        // window would clear the decision and start a second turn over the same
        // transcript while the first is still carrying out the approved write.
        // That is the double-creation NEXT-156 describes; refuse it rather than
        // race it. The escape hatch stays open: a decision no worker ever picked
        // up is handed back as the card by reconcile(), which clears it.
        if ($conversation->hasPendingApprovalDecision()) {
            return new JsonResponse(['error' => $this->translate('error.decisionInFlight')], 409);
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
            return new JsonResponse(['error' => $this->translate('error.conversationProcessing')], 409);
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
            return new JsonResponse(['error' => $this->translate('error.conversationNotFound')], 404);
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

        return new JsonResponse(['error' => $this->translate('error.accessDenied')], 403);
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
        $basePath = $this->config->getAttachmentFolder() . '/' . $beUserUid;
        if (!$storage->hasFolder($basePath)) {
            return $storage->createFolder($basePath);
        }

        return $storage->getFolder($basePath);
    }

    /**
     * Put the upload in the attachment folder as a managed file, without ever
     * replacing one that is already there.
     *
     * `addFile()` is called with `RENAME` explicitly rather than by default:
     * this is the one place in the extension that writes into someone's
     * `fileadmin`, and "the default happens to be the safe one" is not a thing a
     * reader of this method should have to go and check. A name that is taken
     * therefore yields `image_01.png`; nothing is overwritten and nothing is
     * deleted (NEXT-157).
     *
     * Renaming alone would litter the folder, though: attaching the same picture
     * to three conversations is an ordinary thing to do, and it would leave
     * `image.png`, `image_01.png` and `image_02.png`, three `sys_file` rows and
     * three sets of metadata to maintain for one picture. So when the name is
     * taken by a file with the same content, that file is returned as it stands.
     * The comparison is `sha1`, which is what FAL itself indexes files by; it is
     * deliberately scoped to the same name in the same folder rather than a
     * storage-wide content search, because the answer has to be a file this user
     * put here, and because a hash query over `sys_file` is not free.
     */
    private function storeAttachment(
        ResourceStorage $storage,
        Folder $targetFolder,
        string $tempPath,
        string $clientFilename,
    ): File {
        $targetName = $storage->sanitizeFileName($clientFilename, $targetFolder);

        if ($storage->hasFileInFolder($targetName, $targetFolder)) {
            $existing = $storage->getFileInFolder($targetName, $targetFolder);
            // A ProcessedFile cannot occur in an attachment folder, but the
            // signature allows one and a processed file is not a file anybody
            // may reference — so only a real one short-circuits.
            if ($existing instanceof File && $existing->getSha1() === sha1_file($tempPath)) {
                return $existing;
            }
        }

        return $storage->addFile($tempPath, $targetFolder, $targetName, DuplicationBehavior::RENAME);
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
