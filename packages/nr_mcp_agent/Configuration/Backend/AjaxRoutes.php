<?php

declare(strict_types=1);

use Netresearch\NrMcpAgent\Controller\ChatApiController;

return [
    'ai_chat_status' => [
        'path' => '/ai-chat/status',
        'target' => ChatApiController::class . '::getStatus',
    ],
    'ai_chat_conversations' => [
        'path' => '/ai-chat/conversations',
        'target' => ChatApiController::class . '::listConversations',
    ],
    'ai_chat_conversation_create' => [
        'path' => '/ai-chat/conversations/create',
        'target' => ChatApiController::class . '::createConversation',
    ],
    'ai_chat_conversation_messages' => [
        'path' => '/ai-chat/conversations/messages',
        'target' => ChatApiController::class . '::getMessages',
    ],
    'ai_chat_conversation_send' => [
        'path' => '/ai-chat/conversations/send',
        'target' => ChatApiController::class . '::sendMessage',
    ],
    'ai_chat_conversation_resume' => [
        'path' => '/ai-chat/conversations/resume',
        'target' => ChatApiController::class . '::resumeConversation',
    ],
    'ai_chat_conversation_archive' => [
        'path' => '/ai-chat/conversations/archive',
        'target' => ChatApiController::class . '::archiveConversation',
    ],
    'ai_chat_conversation_pin' => [
        'path' => '/ai-chat/conversations/pin',
        'target' => ChatApiController::class . '::togglePin',
    ],
];
