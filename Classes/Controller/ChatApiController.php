<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use finfo;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class ChatApiController
{
    public function __construct(
        private ConversationRepository $repository,
        private ChatProcessorInterface $processor,
        private ExtensionConfiguration $config,
        private ChatCapabilitiesInterface $chatService,
        private ResourceFactory $resourceFactory,
        private StorageRepository $storageRepository,
        private DocumentExtractorRegistry $documentExtractorRegistry,
    ) {}

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
        $mcpServerInstalled = $this->config->isMcpServerInstalled();
        if ($mcpEnabled && !$mcpServerInstalled) {
            $issues[] = 'MCP is enabled but hn/typo3-mcp-server is not installed. Install it via: composer require hn/typo3-mcp-server';
        } elseif (!$mcpEnabled && $mcpServerInstalled) {
            $issues[] = 'hn/typo3-mcp-server is installed but MCP is not enabled. Enable MCP in Extension Configuration to allow content actions.';
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
        $items = array_map(static fn(Conversation $c): array => [
            'uid' => $c->getUid(),
            'title' => $c->getTitle(),
            'status' => $c->getStatus()->value,
            'messageCount' => $c->getMessageCount(),
            'pinned' => $c->isPinned(),
            'resumable' => $c->isResumable(),
            'errorMessage' => $c->getErrorMessage(),
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
            if ($meta['message_count'] <= $afterIndex) {
                return new JsonResponse([
                    'status' => $meta['status'],
                    'messages' => [],
                    'totalCount' => $meta['message_count'],
                    'errorMessage' => $meta['error_message'],
                ]);
            }
        }

        $conversation = $this->findConversationOrFail($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $messages = $conversation->getDecodedMessages();
        $newMessages = array_slice($messages, $afterIndex);

        return new JsonResponse([
            'status' => $conversation->getStatus()->value,
            'messages' => $newMessages,
            'totalCount' => count($messages),
            'errorMessage' => $conversation->getErrorMessage(),
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
                    return new JsonResponse(['error' => 'File not found'], 404);
                }
                $fileName = $file->getName();
                $fileMimeType = $file->getMimeType();
            } catch (Exception) {
                return new JsonResponse(['error' => 'File not found'], 404);
            }
        }

        $currentStatus = $conversation->getStatus();
        if (in_array($currentStatus, [ConversationStatus::Processing, ConversationStatus::Locked, ConversationStatus::ToolLoop], true)
        ) {
            return new JsonResponse(['error' => 'Conversation is already processing'], 409);
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

        // Atomic CAS: write full row only if status still matches,
        // preventing race conditions with concurrent requests or worker dequeue.
        $claimed = $this->repository->updateIf($conversation, $currentStatus);
        if (!$claimed) {
            return new JsonResponse(['error' => 'Conversation is already processing'], 409);
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

        /** @var array<string, \Psr\Http\Message\UploadedFileInterface> $uploadedFiles */
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
            return new JsonResponse(['error' => 'File not found'], 404);
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

        // Atomic CAS: write full row only if status still matches.
        $claimed = $this->repository->updateIf($conversation, $currentStatus);
        if (!$claimed) {
            return new JsonResponse(['error' => 'Conversation is already processing'], 409);
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
