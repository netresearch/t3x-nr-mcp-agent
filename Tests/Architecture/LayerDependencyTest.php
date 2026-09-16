<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\BuildStep;
use PHPat\Test\PHPat;

final class LayerDependencyTest
{
    private const CONTROLLER_NAMESPACE = 'Netresearch\NrMcpAgent\Controller';

    private const COMMAND_NAMESPACE = 'Netresearch\NrMcpAgent\Command';

    public function testDomainDoesNotDependOnInfrastructure(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrMcpAgent\Domain'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace(self::CONTROLLER_NAMESPACE),
                Selector::inNamespace(self::COMMAND_NAMESPACE),
            )
            ->because('Domain layer must not depend on infrastructure (Controller, Command)');
    }

    public function testServicesDoNotAccessDatabaseDirectly(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrMcpAgent\Service'))
            ->shouldNotDependOn()
            ->classes(Selector::classname('TYPO3\CMS\Core\Database\ConnectionPool'))
            ->because('Services must use repositories instead of accessing the database directly');
    }

    public function testServicesDoNotDependOnControllers(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrMcpAgent\Service'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace(self::CONTROLLER_NAMESPACE))
            ->because('Service layer must not depend on the HTTP layer');
    }

    public function testControllersDoNotInvokeCommandClasses(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::CONTROLLER_NAMESPACE))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace(self::COMMAND_NAMESPACE))
            ->because('Controllers reach background processing through ChatProcessorInterface, never by invoking a CLI command class');
    }
}
