<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\ChatTask;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Exception\ConfigurationInactiveException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrMcpAgent\Enum\SiteConfigurationRefusal;
use Netresearch\NrMcpAgent\Exception\SiteConfigurationRefusedException;

/**
 * The AI configuration a site names for itself (nr-llm ADR-244), as the chat
 * may run it (ADR-033).
 *
 * The site setting `nrllm.configuration` wins over the chat Task's
 * configuration. What the chat cannot run on is refused, never replaced:
 * a configuration that cannot be used, one without a fixed model, and one
 * whose model or provider cannot call tools. The chat reads and edits pages
 * only through tools, and falling back to the Task's configuration would send
 * the site's content to the provider the site was set up to avoid.
 *
 * Tool calling is read from two places, both of which have to agree:
 *
 * - the model record's capability `tools` ("Tool Use"), the same flag nr-llm
 *   requires of a model it picks by criteria for a tool call (nr-llm ADR-138,
 *   `OperationCapabilityMap`). nr-llm does not check it for a fixed model,
 *   which the chat runs. Discovery writes it for the providers that report it
 *   per model and for the curated OpenAI, Anthropic and Gemini entries; Groq
 *   reports nothing, so a Groq model has to be ticked by hand;
 * - the provider adapter, which nr-llm's agent loop refuses with
 *   "does not support tool calling" when it is not tool-capable.
 */
final readonly class SiteConfigurationSelector
{
    public function __construct(
        private ConfigurationResolver $configurationResolver,
        private ProviderAdapterRegistryInterface $adapterRegistry,
    ) {}

    /**
     * The configuration the site names, or null when it names none (no site,
     * no setting): the chat then runs its Task's configuration.
     *
     * @throws SiteConfigurationRefusedException when the site names one the chat cannot run on
     */
    public function forSite(string $site, AiActorContext $actor): ?LlmConfiguration
    {
        if ($site === '') {
            return null;
        }

        try {
            $configuration = $this->configurationResolver->resolveForSite($site, $actor);
        } catch (ConfigurationNotFoundException|ConfigurationInactiveException|AccessDeniedException $e) {
            throw new SiteConfigurationRefusedException(SiteConfigurationRefusal::Unusable, $site, previous: $e);
        }

        if (!$configuration instanceof LlmConfiguration) {
            return null;
        }

        $model = $configuration->getLlmModel();
        if ($configuration->usesCriteriaSelection() || !$model instanceof Model) {
            throw new SiteConfigurationRefusedException(SiteConfigurationRefusal::NoFixedModel, $site, $configuration->getName());
        }

        if (!$model->supportsTools()) {
            throw new SiteConfigurationRefusedException(SiteConfigurationRefusal::NoToolCalling, $site, $configuration->getName(), $model->getName());
        }

        $adapter = $this->adapterRegistry->createAdapterFromModel($model);
        if (!$adapter instanceof ToolCapableInterface || !$adapter->supportsTools()) {
            throw new SiteConfigurationRefusedException(SiteConfigurationRefusal::NoToolCalling, $site, $configuration->getName(), $model->getName());
        }

        return $configuration;
    }
}
