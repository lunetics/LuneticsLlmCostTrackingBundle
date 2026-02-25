<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Model;

use Lunetics\LlmCostTrackingBundle\Pricing\PricingProviderInterface;
use Psr\Log\LoggerInterface;

final class ModelRegistry
{
    /** @var array<string, ModelDefinition> */
    private array $models = [];

    /** @var array<string, ModelDefinition>|null Memoized dynamic models for the lifetime of this instance. */
    private ?array $dynamicCache = null;

    /**
     * @param array<string, ModelDefinition> $models user-configured and bundle-default models
     */
    public function __construct(
        array $models = [],
        private readonly ?PricingProviderInterface $dynamicPricing = null,
        private readonly ?LoggerInterface $logger = null,
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
            if (null === $this->dynamicCache) {
                try {
                    $this->dynamicCache = $this->dynamicPricing->getModels();
                } catch (\Throwable $e) {
                    $this->logger?->warning('Failed to fetch dynamic LLM pricing from models.dev.', [
                        'exception' => $e,
                    ]);
                    // Memoize the failure so subsequent lookups within the same instance
                    // do not re-attempt the provider call.
                    $this->dynamicCache = [];
                }
            }

            return $this->dynamicCache[$modelId] ?? null;
        }

        return null;
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
