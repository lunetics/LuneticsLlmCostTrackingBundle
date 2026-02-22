<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Pricing;

use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches and caches model pricing data from https://models.dev.
 * Prices are in USD per 1 million tokens, matching our ModelDefinition format.
 */
final class ModelsDevPricingProvider
{
    private const string API_URL = 'https://models.dev/api.json';
    private const string CACHE_KEY = 'lunetics_llm.models_dev_pricing';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly int $ttl,
    ) {
    }

    /**
     * Returns all models from models.dev, keyed by model ID.
     * Result is cached for $ttl seconds.
     *
     * @return array<string, ModelDefinition>
     */
    public function getModels(): array
    {
        /* @var array<string, ModelDefinition> */
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter($this->ttl);

            return $this->fetch();
        });
    }

    /**
     * Clears the cached pricing so the next call re-fetches from the API.
     */
    public function invalidate(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }

    /**
     * @return array<string, ModelDefinition>
     */
    private function fetch(): array
    {
        $response = $this->httpClient->request('GET', self::API_URL);
        $data = $response->toArray();

        $models = [];
        foreach ($data as $providerData) {
            if (!\is_array($providerData) || !isset($providerData['name'], $providerData['models']) || !\is_array($providerData['models'])) {
                continue;
            }

            $providerName = (string) $providerData['name'];

            foreach ($providerData['models'] as $modelId => $modelData) {
                if (!\is_array($modelData) || !isset($modelData['cost']['input'], $modelData['cost']['output'])) {
                    continue;
                }

                $cost = $modelData['cost'];

                $models[(string) $modelId] = new ModelDefinition(
                    modelId: (string) $modelId,
                    displayName: isset($modelData['name']) ? (string) $modelData['name'] : (string) $modelId,
                    provider: $providerName,
                    inputPricePerMillion: (float) $cost['input'],
                    outputPricePerMillion: (float) $cost['output'],
                    cachedInputPricePerMillion: isset($cost['cache_read']) ? (float) $cost['cache_read'] : null,
                    thinkingPricePerMillion: isset($cost['reasoning']) ? (float) $cost['reasoning'] : null,
                );
            }
        }

        return $models;
    }
}
