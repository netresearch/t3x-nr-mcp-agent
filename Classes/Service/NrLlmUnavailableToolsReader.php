<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrLlm\Service\Tool\UnavailableToolsResolverInterface;
use Psr\Container\ContainerInterface;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The nr-llm side of {@see UnavailableToolsReaderInterface}.
 *
 * nr-llm's UnavailableToolsResolverInterface (its ADR-201) is looked up in
 * the container rather than injected, and every failure yields an empty list:
 * the list is information for the model, never a gate, so a resolver that is
 * missing or throws must not break the chat. The gate that decides what the run is offered is nr-llm's own and is
 * untouched by anything here.
 */
final readonly class NrLlmUnavailableToolsReader implements UnavailableToolsReaderInterface
{
    /** The nr-llm service id. */
    public const RESOLVER_SERVICE = UnavailableToolsResolverInterface::class;

    public function __construct(
        private ContainerInterface $container,
    ) {}

    public function read(LlmConfiguration $configuration, ?BackendUserAuthentication $user): array
    {
        try {
            if (!$this->container->has(self::RESOLVER_SERVICE)) {
                return [];
            }

            // Typed as unknown on purpose: what the code relies on is checked
            // below, not assumed.
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
