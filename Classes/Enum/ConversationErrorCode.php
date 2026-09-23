<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Enum;

/**
 * The kind of failure a conversation's error message describes (ADR-017).
 *
 * Stored next to the message so the request that shows it can decide the
 * wording for the person reading: technical detail and a link for an
 * administrator, a plain sentence for an editor. An ordinary failure has no
 * code and is shown as it was written.
 */
enum ConversationErrorCode: string
{
    /** The AI provider cannot be used as configured — no API key, or a key the provider rejects. */
    case ProviderNotConfigured = 'providerNotConfigured';

    /** The chat itself is not set up: no nr-llm Task, or a Task without a configuration or model. */
    case ChatNotConfigured = 'chatNotConfigured';
}
