<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Pricing;

use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;

/**
 * Composes multiple PricingProviderInterface implementations into a single provider.
 * Results are merged with first-wins semantics: the first provider to define a model ID
 * takes precedence, so live API data beats snapshot data when both are present.
 */
final class ChainPricingProvider implements PricingProviderInterface
{
    /**
     * @param list<PricingProviderInterface> $providers ordered list; first provider wins on duplicate model IDs
     */
    public function __construct(private readonly array $providers)
    {
    }

    /**
     * @return array<string, ModelDefinition>
     */
    public function getModels(): array
    {
        $models = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->getModels() as $id => $definition) {
                $models[$id] ??= $definition;
            }
        }

        return $models;
    }
}
