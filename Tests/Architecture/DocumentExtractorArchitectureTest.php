<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Architecture tests for document extraction module.
 *
 * Enforces clean architecture boundaries to ensure document extractors
 * remain independent from HTTP controllers and chat services.
 */
final class DocumentExtractorArchitectureTest
{
    /**
     * Document extractors must not depend on ChatService or Controllers.
     *
     * Extractors should be pure, stateless utilities that can be
     * reused in different contexts without coupling to service layers.
     */
    public function extractorsDoNotDependOnChatService(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrMcpAgent\Document'))
            ->shouldNotDependOn()
            ->classes(
                Selector::classname(\Netresearch\NrMcpAgent\Service\ChatService::class),
                Selector::inNamespace('Netresearch\NrMcpAgent\Controller'),
            );
    }
}
