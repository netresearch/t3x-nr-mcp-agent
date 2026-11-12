<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\ChatTask;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Site\SiteLlmSettings;
use Netresearch\NrMcpAgent\Enum\SiteConfigurationRefusal;
use Netresearch\NrMcpAgent\Exception\SiteConfigurationRefusedException;
use Netresearch\NrMcpAgent\Service\ChatTask\SiteConfigurationSelector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The selector on its own (ADR-033): which site configuration the chat runs,
 * and which it refuses. nr-llm's real ConfigurationResolver reads the site
 * setting through a SiteFinder double; only the configuration record, its
 * model and the provider adapter are doubles.
 */
#[CoversClass(SiteConfigurationSelector::class)]
final class SiteConfigurationSelectorTest extends TestCase
{
    private string $setting = 'shop-ai';

    private bool $exists = true;

    private bool $active = true;

    private bool $criteria = false;

    private bool $hasModel = true;

    private bool $modelToolFlag = true;

    /** 'tools', 'plain' (not tool-capable) or 'off' (tool-capable, tools switched off). */
    private string $provider = 'tools';

    private int $resolverCalls = 0;

    #[Test]
    public function noSiteNamesNoConfigurationAndAsksNothing(): void
    {
        self::assertNull($this->selector()->forSite('', $this->actor()));
        self::assertSame(0, $this->resolverCalls);
    }

    #[Test]
    public function aSiteWithoutTheSettingNamesNoConfiguration(): void
    {
        $this->setting = '';

        self::assertNull($this->selector()->forSite('shop', $this->actor()));
    }

    #[Test]
    public function aUsableConfigurationWithAToolCapableModelAndProviderIsTheOneTheChatRuns(): void
    {
        $configuration = $this->selector()->forSite('shop', $this->actor());

        self::assertInstanceOf(LlmConfiguration::class, $configuration);
        self::assertSame('Shop AI', $configuration->getName());
    }

    #[Test]
    public function aConfigurationThatDoesNotExistIsRefusedAsUnusable(): void
    {
        $this->exists = false;

        $refusal = $this->refusal();
        self::assertSame(SiteConfigurationRefusal::Unusable, $refusal->reason);
        self::assertSame('shop', $refusal->site);
        self::assertSame('', $refusal->configuration);
        self::assertInstanceOf(ConfigurationNotFoundException::class, $refusal->getPrevious());
    }

    #[Test]
    public function aSwitchedOffConfigurationIsRefusedAsUnusable(): void
    {
        $this->active = false;

        self::assertSame(SiteConfigurationRefusal::Unusable, $this->refusal()->reason);
    }

    #[Test]
    public function aConfigurationThatSelectsItsModelByCriteriaIsRefused(): void
    {
        $this->criteria = true;

        $refusal = $this->refusal();
        self::assertSame(SiteConfigurationRefusal::NoFixedModel, $refusal->reason);
        self::assertSame('Shop AI', $refusal->configuration);
        self::assertSame('', $refusal->model);
    }

    #[Test]
    public function aConfigurationWithoutAModelIsRefused(): void
    {
        $this->hasModel = false;

        self::assertSame(SiteConfigurationRefusal::NoFixedModel, $this->refusal()->reason);
    }

    #[Test]
    public function aModelWithoutTheToolUseFlagIsRefusedBeforeItsProviderIsBuilt(): void
    {
        $this->modelToolFlag = false;
        $this->provider      = 'none';

        $refusal = $this->refusal();
        self::assertSame(SiteConfigurationRefusal::NoToolCalling, $refusal->reason);
        self::assertSame('Small Model', $refusal->model);
    }

    #[Test]
    public function aProviderThatIsNotToolCapableIsRefused(): void
    {
        $this->provider = 'plain';

        $refusal = $this->refusal();
        self::assertSame(SiteConfigurationRefusal::NoToolCalling, $refusal->reason);
        self::assertSame('Shop AI', $refusal->configuration);
        self::assertSame('Small Model', $refusal->model);
    }

    #[Test]
    public function aToolCapableProviderWithToolsSwitchedOffIsRefused(): void
    {
        $this->provider = 'off';

        self::assertSame(SiteConfigurationRefusal::NoToolCalling, $this->refusal()->reason);
    }

    private function refusal(): SiteConfigurationRefusedException
    {
        try {
            $this->selector()->forSite('shop', $this->actor());
        } catch (SiteConfigurationRefusedException $e) {
            return $e;
        }

        self::fail('The selector did not refuse the site configuration.');
    }

    private function actor(): AiActorContext
    {
        return AiActorContext::backendUser(1, true);
    }

    private function selector(): SiteConfigurationSelector
    {
        $site       = fn (): Site => new Site('shop', 1, ['settings' => $this->setting === '' ? [] : ['nrllm' => ['configuration' => $this->setting]]]);
        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getSiteByIdentifier')->willReturnCallback($site);
        $siteFinder->method('getSiteByPageId')->willReturnCallback($site);
        $siteFinder->method('getAllSites')->willReturn([]);

        $model = $this->createStub(Model::class);
        $model->method('supportsTools')->willReturn($this->modelToolFlag);
        $model->method('getName')->willReturn('Small Model');

        $configuration = $this->createStub(LlmConfiguration::class);
        $configuration->method('getName')->willReturn('Shop AI');
        $configuration->method('isActive')->willReturn($this->active);
        $configuration->method('hasAccessRestrictions')->willReturn(false);
        $configuration->method('usesCriteriaSelection')->willReturn($this->criteria);
        $configuration->method('getLlmModel')->willReturn($this->hasModel ? $model : null);

        $configurations = $this->createStub(LlmConfigurationRepository::class);
        $configurations->method('findOneByIdentifier')->willReturnCallback(
            function (string $identifier) use ($configuration): ?LlmConfiguration {
                ++$this->resolverCalls;

                return $identifier === 'shop-ai' && $this->exists ? $configuration : null;
            },
        );

        $toolProvider = $this->createStubForIntersectionOfInterfaces([ProviderInterface::class, ToolCapableInterface::class]);
        $toolProvider->method('supportsTools')->willReturn($this->provider === 'tools');
        self::assertInstanceOf(ProviderInterface::class, $toolProvider);
        $plainProvider = $this->createStub(ProviderInterface::class);

        $adapters = $this->createStub(ProviderAdapterRegistryInterface::class);
        $adapters->method('createAdapterFromModel')->willReturnCallback(
            function () use ($plainProvider, $toolProvider): ProviderInterface {
                self::assertNotSame('none', $this->provider, 'The provider must not be built for a model without the tool flag.');

                return $this->provider === 'plain' ? $plainProvider : $toolProvider;
            },
        );

        return new SiteConfigurationSelector(
            new ConfigurationResolver($configurations, new SiteLlmSettings($siteFinder)),
            $adapters,
        );
    }
}
