<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Pricing;

use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;

interface PricingProviderInterface
{
    /**
     * Returns all known models keyed by model ID.
     *
     * @return array<string, ModelDefinition>
     */
    public function getModels(): array;
}
