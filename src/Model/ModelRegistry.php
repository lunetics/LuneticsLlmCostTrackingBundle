<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Model;

use Lunetics\LlmCostTrackingBundle\Pricing\ModelsDevPricingProvider;

final class ModelRegistry
{
    /** @var array<string, ModelDefinition> */
    private array $models = [];

    /**
     * @param array<string, ModelDefinition> $models user-configured and bundle-default models
     */
    public function __construct(
        array $models = [],
        private readonly ?ModelsDevPricingProvider $dynamicPricing = null,
    ) {
        foreach ($models as $modelId => $definition) {
            $this->models[$modelId] = $definition;
        }
    }

    public function get(string $modelId): ?ModelDefinition
    {
        if (isset($this->models[$modelId])) {
            return $this->models[$modelId];
        }

        if (null !== $this->dynamicPricing) {
            try {
                return $this->dynamicPricing->getModels()[$modelId] ?? null;
            } catch (\Throwable) {
                // Don't crash the profiler if models.dev is unreachable
            }
        }

        return null;
    }

    public function has(string $modelId): bool
    {
        return null !== $this->get($modelId);
    }

    /**
     * Returns locally registered models (user config + bundle defaults).
     * Does not include models only available via dynamic pricing.
     *
     * @return array<string, ModelDefinition>
     */
    public function all(): array
    {
        return $this->models;
    }
}
