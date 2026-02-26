<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Pricing;

use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;

/**
 * Parses a models.dev JSON response (or compatible snapshot) into ModelDefinition instances.
 * Shared by ModelsDevPricingProvider (live API) and SnapshotPricingProvider (file).
 */
final class ModelsDevResponseParser
{
    private function __construct()
    {
    }

    /**
     * @return array<string, ModelDefinition>
     *
     * @throws \JsonException on malformed JSON
     */
    public static function parse(string $json): array
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
