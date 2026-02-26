<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Pricing;

use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches and caches model pricing data from https://models.dev.
 * Prices are in USD per 1 million tokens, matching our ModelDefinition format.
 *
 * On fetch failure, returns an empty array cached for 60 s so the application
 * does not hammer the API during an outage. Callers that need a fallback
 * (e.g. ChainPricingProvider) are responsible for providing one.
 */
final class ModelsDevPricingProvider implements RefreshablePricingProviderInterface
{
    private const API_URL = 'https://models.dev/api.json';
    private const CACHE_KEY = 'lunetics_llm.models_dev_pricing';
    private const MAX_RESPONSE_SIZE = 5 * 1024 * 1024; // 5 MB

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly int $ttl,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Returns all models from models.dev, keyed by model ID.
     * Result is cached for $ttl seconds. Returns [] on fetch failure (cached 60 s).
     *
     * @return array<string, ModelDefinition>
     */
    public function getModels(): array
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter($this->ttl);

            try {
                return $this->fetch();
            } catch (\Throwable $e) {
                $this->logger?->warning('Failed to fetch dynamic LLM pricing from models.dev.', [
                    'exception' => $e,
                ]);
                $item->expiresAfter(60);

                return [];
            }
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
     * Fetches fresh pricing from the live API, writes it to the cache, and returns the models.
     * Throws on any failure — never falls back to a snapshot.
     *
     * @return array<string, ModelDefinition>
     *
     * @throws \Throwable on HTTP failure, timeout, size limit, or invalid API response
     */
    public function fetchLive(): array
    {
        $models = $this->fetch();

        // Replace whatever is in the cache with the fresh live result so that
        // subsequent getModels() calls serve it without a further HTTP round-trip.
        $this->cache->delete(self::CACHE_KEY);
        $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) use ($models): array {
            $item->expiresAfter($this->ttl);

            return $models;
        });

        return $models;
    }

    /**
     * @return array<string, ModelDefinition>
     */
    private function fetch(): array
    {
        $response = $this->httpClient->request('GET', self::API_URL, [
            'timeout' => 10.0,
            'max_duration' => 15.0,
            'buffer' => false,
        ]);

        $body = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            $body .= $chunk->getContent();
            if (\strlen($body) > self::MAX_RESPONSE_SIZE) {
                throw new \RuntimeException(\sprintf('models.dev API response exceeded the %d byte size limit.', self::MAX_RESPONSE_SIZE));
            }
        }

        return ModelsDevResponseParser::parse($body);
    }
}
