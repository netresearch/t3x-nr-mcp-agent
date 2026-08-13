<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Enum;

enum ConversationStatus: string
{
    case Idle = 'idle';
    case Processing = 'processing';
    case Locked = 'locked';
    case ToolLoop = 'tool_loop';

    /**
     * The run paused because a tool needs a human approval, which is granted in
     * the AI Tasks module rather than here.
     *
     * Deliberately not Failed: nothing went wrong. Treating the pause as a
     * failure made a working safeguard look like a crash, which on a public
     * demo reads as a broken feature.
     *
     * Deliberately not counted as active either, and not resumable from the
     * chat: the run is parked on someone else's decision, and restarting it
     * here would bypass the approval that is still pending.
     */
    case AwaitingApproval = 'awaiting_approval';

    case Failed = 'failed';
}
