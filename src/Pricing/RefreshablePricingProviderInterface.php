<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Pricing;

interface RefreshablePricingProviderInterface extends PricingProviderInterface
{
    /**
     * Clears the cached pricing so the next call to getModels() re-fetches from the source.
     */
    public function invalidate(): void;
}
