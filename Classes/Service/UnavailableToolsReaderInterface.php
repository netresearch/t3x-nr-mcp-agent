<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The tools of this installation that the chat's run will NOT be offered, each
 * with the reason the gate gives (ADR-017).
 *
 * A seam of its own because the answer comes from an nr-llm service that older
 * nr-llm versions do not have: the implementation detects it, and everything
 * else in the chat only sees a list that may be empty.
 */
interface UnavailableToolsReaderInterface
{
    /**
     * @return list<array{name: string, reason: string}> reason is the value of nr-llm's ToolDenialReason
     */
    public function read(LlmConfiguration $configuration, ?BackendUserAuthentication $user): array;
}
