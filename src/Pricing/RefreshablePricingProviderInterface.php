<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Pricing;

use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;

interface RefreshablePricingProviderInterface extends PricingProviderInterface
{
    /**
     * Clears the cached pricing so the next call to getModels() re-fetches from the source.
     */
    public function invalidate(): void;

    /**
     * Fetches fresh pricing from the live API, writes it to the cache, and returns the models.
     *
     * Unlike getModels(), this method never falls back to a local snapshot — it throws on
     * any HTTP or parse failure. Use this when you need to know whether the live API is
     * actually reachable (e.g. CLI commands, health checks, deployment pipelines).
     *
     * @return array<string, ModelDefinition>
     *
     * @throws \Throwable on HTTP failure, timeout, size limit, or invalid API response
     */
    public function fetchLive(): array;
}
