<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Enum;

enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}
