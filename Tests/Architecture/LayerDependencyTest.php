<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\BuildStep;
use PHPat\Test\PHPat;

final class LayerDependencyTest
{
    public function testDomainDoesNotDependOnInfrastructure(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrMcpAgent\Domain'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Netresearch\NrMcpAgent\Controller'),
                Selector::inNamespace('Netresearch\NrMcpAgent\Command'),
                Selector::inNamespace('Netresearch\NrMcpAgent\Mcp'),
            )
            ->because('Domain layer must not depend on infrastructure (Controller, Command, Mcp)');
    }

    public function testServicesDoNotAccessDatabaseDirectly(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrMcpAgent\Service'))
            ->shouldNotDependOn()
            ->classes(Selector::classname('TYPO3\CMS\Core\Database\ConnectionPool'))
            ->because('Services must use repositories instead of accessing the database directly');
    }

    public function testControllerDoesNotExecuteProcesses(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrMcpAgent\Controller'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Netresearch\NrMcpAgent\Mcp'))
            ->because('Controllers must not depend on MCP layer directly');
    }

    public function testHookDoesNotDependOnController(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrMcpAgent\Hook'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Netresearch\NrMcpAgent\Controller'),
                Selector::inNamespace('Netresearch\NrMcpAgent\Mcp'),
                Selector::inNamespace('Netresearch\NrMcpAgent\Service'),
            )
            ->because('Hook layer must not depend on Controller, Mcp, or Service layers');
    }
}
