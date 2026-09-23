<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Psr\Container\ContainerInterface;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The nr-llm side of {@see UnavailableToolsReaderInterface}.
 *
 * nr-llm's UnavailableToolsResolverInterface (its ADR-201) arrived after the
 * nr-llm versions this extension supports, so it is looked up by name in the
 * container rather than injected: a constructor type the autoloader cannot
 * resolve would break the whole chat on an older nr-llm, where the list is
 * simply empty and the prompt says nothing about unavailable tools — exactly
 * what it did before.
 *
 * Fail-soft for the same reason: the list is information for the model, never
 * a gate. The gate that decides what the run is offered is nr-llm's own and is
 * untouched by anything here.
 */
final readonly class NrLlmUnavailableToolsReader implements UnavailableToolsReaderInterface
{
    /** The nr-llm service, by name, so this class loads without it. */
    public const RESOLVER_SERVICE = 'Netresearch\\NrLlm\\Service\\Tool\\UnavailableToolsResolverInterface';

    public function __construct(
        private ContainerInterface $container,
    ) {}

    public function read(LlmConfiguration $configuration, ?BackendUserAuthentication $user): array
    {
        try {
            if (!$this->container->has(self::RESOLVER_SERVICE)) {
                return [];
            }

            // Typed as unknown on purpose: the container extension would infer
            // the interface from the name, and on the nr-llm composer resolved
            // for analysis that interface may not exist. What the code relies on
            // is checked below, not assumed.
            /** @var mixed $resolver */
            $resolver = $this->container->get(self::RESOLVER_SERVICE);
            if (!is_object($resolver) || !method_exists($resolver, 'unavailable')) {
                return [];
            }

            $decisions = $resolver->unavailable($configuration, $user);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($decisions)) {
            return [];
        }

        $tools = [];
        foreach ($decisions as $decision) {
            if ($decision instanceof ToolPolicyDecision && !$decision->allowed) {
                $tools[] = ['name' => $decision->toolName, 'reason' => $decision->reason->value];
            }
        }

        return $tools;
    }
}
