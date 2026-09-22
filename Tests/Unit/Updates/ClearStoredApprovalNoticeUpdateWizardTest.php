<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Updates;

use Netresearch\NrMcpAgent\Updates\ClearStoredApprovalNoticeUpdateWizard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;

/**
 * The functional test builds the wizard by hand, so nothing there notices a
 * lost or renamed attribute — and without the attribute the container never
 * tags the class, the registry never lists it, and the fix does nothing in
 * production. The attribute is the one registration surface both cores share:
 * native on v13, an alias of the core attribute on v14. The registry has a
 * different class on each core and no alias, so it cannot be asked here.
 *
 * Not pinned: the Services.yaml wiring, which every service of the extension
 * shares.
 */
class ClearStoredApprovalNoticeUpdateWizardTest extends TestCase
{
    #[Test]
    public function isRegisteredAsAnUpgradeWizardUnderItsIdentifier(): void
    {
        $attributes = (new ReflectionClass(ClearStoredApprovalNoticeUpdateWizard::class))
            ->getAttributes(UpgradeWizard::class, ReflectionAttribute::IS_INSTANCEOF);

        self::assertCount(1, $attributes);

        $attribute = $attributes[0]->newInstance();
        self::assertInstanceOf(UpgradeWizard::class, $attribute);
        self::assertSame('nrMcpAgent_clearStoredApprovalNotice', $attribute->identifier);
    }
}
