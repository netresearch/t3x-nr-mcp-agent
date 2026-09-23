<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Exception;

/**
 * The chat cannot run because an administrator has not finished setting it up:
 * no nr-llm Task, a Task without a configuration, a configuration without a
 * model. Its own class so the failure is classified by type, not by message
 * (ADR-017).
 */
final class ChatNotConfiguredException extends Exception {}
