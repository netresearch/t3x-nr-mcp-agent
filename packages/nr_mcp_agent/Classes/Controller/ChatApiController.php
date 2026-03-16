<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Controller;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class ChatApiController
{
    public function __construct(
        private ConversationRepository $repository,
        private ChatProcessorInterface $processor,
        private ExtensionConfiguration $config,
    ) {}

    /**
     * GET /ai-chat/status – Check if AI chat is available for current user.
     */
    public function getStatus(ServerRequestInterface $request): ResponseInterface
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

        return new JsonResponse([
            'available' => $taskUid > 0,
            'mcpEnabled' => $mcpEnabled,
            'activeConversationCount' => $this->repository->countActiveByBeUser($this->getBeUserUid()),
            'issues' => $issues,
        ]);
    }

    /**
     * GET /ai-chat/conversations – List conversations for current user.
     */
    public function listConversations(ServerRequestInterface $request): ResponseInterface
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
    public function createConversation(ServerRequestInterface $request): ResponseInterface
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

        $currentStatus = $conversation->getStatus();
        if ($currentStatus === ConversationStatus::Processing
            || $currentStatus === ConversationStatus::Locked
            || $currentStatus === ConversationStatus::ToolLoop
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

        $conversation->appendMessage(MessageRole::User, $content);
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

    /**
     * @return array<string, string|int>
     */
    private function getBackendUser(): array
    {
        /** @var object{user: array<string, string|int>} $beUser */
        $beUser = $GLOBALS['BE_USER'];
        return $beUser->user;
    }
}
