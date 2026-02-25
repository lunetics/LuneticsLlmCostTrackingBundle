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
 * When a live fetch fails, the bundled snapshot at $snapshotPath is used as a
 * fallback so that known models are still priced correctly during outages.
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
        private readonly string $snapshotPath,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Returns all models from models.dev, keyed by model ID.
     * Result is cached for $ttl seconds. On fetch failure, falls back to the
     * bundled snapshot.
     *
     * When the snapshot provides a usable baseline, it is cached for the full TTL
     * so repeated requests during an outage do not each incur a 10–15 s HTTP
     * attempt before falling through to the snapshot. The short TTL (60 s) is
     * used only when there is no snapshot at all, so the provider retries sooner.
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

                $fallback = $this->loadSnapshot();

                $item->expiresAfter([] === $fallback ? 60 : $this->ttl);

                return $fallback;
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
     * Throws on any failure — never falls back to the snapshot.
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

        return $this->parseResponseBody($body);
    }

    /**
     * Loads the bundled pricing snapshot as a fallback when live fetching fails.
     *
     * @return array<string, ModelDefinition>
     */
    private function loadSnapshot(): array
    {
        if ('' === $this->snapshotPath || !is_file($this->snapshotPath)) {
            return [];
        }

        try {
            $json = file_get_contents($this->snapshotPath);
            if (false === $json) {
                return [];
            }

            return $this->parseResponseBody($json);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Parses a models.dev JSON response body (or snapshot) into ModelDefinition instances.
     *
     * @return array<string, ModelDefinition>
     */
    private function parseResponseBody(string $json): array
    {
        /** @var array<mixed> $data */
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        $models = [];
        foreach ($data as $providerData) {
            if (!\is_array($providerData) || !isset($providerData['name'], $providerData['models']) || !\is_array($providerData['models'])) {
                continue;
            }

            $providerName = (string) $providerData['name'];

            foreach ($providerData['models'] as $modelId => $modelData) {
                if (!\is_array($modelData) || !isset($modelData['cost']) || !\is_array($modelData['cost'])) {
                    continue;
                }

                $cost = $modelData['cost'];

                if (!isset($cost['input'], $cost['output'])) {
                    continue;
                }

                // First provider to define a model ID wins; later providers (e.g. resellers)
                // are skipped so canonical pricing takes precedence.
                if (isset($models[(string) $modelId])) {
                    continue;
                }

                $models[(string) $modelId] = new ModelDefinition(
                    modelId: (string) $modelId,
                    displayName: isset($modelData['name']) && \is_string($modelData['name']) ? $modelData['name'] : (string) $modelId,
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
