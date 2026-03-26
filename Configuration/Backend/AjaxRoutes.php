<?php

declare(strict_types=1);

use Netresearch\NrMcpAgent\Controller\ChatApiController;

return [
    'ai_chat_status' => [
        'path' => '/ai-chat/status',
        'target' => ChatApiController::class . '::getStatus',
        'methods' => ['GET'],
    ],
    'ai_chat_conversations' => [
        'path' => '/ai-chat/conversations',
        'target' => ChatApiController::class . '::listConversations',
        'methods' => ['GET'],
    ],
    'ai_chat_conversation_create' => [
        'path' => '/ai-chat/conversations/create',
        'target' => ChatApiController::class . '::createConversation',
        'methods' => ['POST'],
    ],
    'ai_chat_conversation_messages' => [
        'path' => '/ai-chat/conversations/messages',
        'target' => ChatApiController::class . '::getMessages',
        'methods' => ['GET'],
    ],
    'ai_chat_conversation_send' => [
        'path' => '/ai-chat/conversations/send',
        'target' => ChatApiController::class . '::sendMessage',
        'methods' => ['POST'],
    ],
    'ai_chat_conversation_resume' => [
        'path' => '/ai-chat/conversations/resume',
        'target' => ChatApiController::class . '::resumeConversation',
        'methods' => ['POST'],
    ],
    'ai_chat_conversation_archive' => [
        'path' => '/ai-chat/conversations/archive',
        'target' => ChatApiController::class . '::archiveConversation',
        'methods' => ['POST'],
    ],
    'ai_chat_conversation_pin' => [
        'path' => '/ai-chat/conversations/pin',
        'target' => ChatApiController::class . '::togglePin',
        'methods' => ['POST'],
    ],
    'ai_chat_file_upload' => [
        'path' => '/ai-chat/file-upload',
        'target' => ChatApiController::class . '::fileUpload',
        'methods' => ['POST'],
    ],
    'ai_chat_file_info' => [
        'path' => '/ai-chat/file-info',
        'target' => ChatApiController::class . '::fileInfo',
        'methods' => ['GET'],
    ],
];
