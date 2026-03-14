<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Enum;

enum ConversationStatus: string
{
    case Idle = 'idle';
    case Processing = 'processing';
    case Locked = 'locked';
    case ToolLoop = 'tool_loop';
    case Failed = 'failed';
}
