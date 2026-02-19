<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Model;

final readonly class ModelDefinition
{
    public function __construct(
        public string $modelId,
        public string $displayName,
        public string $provider,
        public float $inputPricePerMillion,
        public float $outputPricePerMillion,
        public ?float $cachedInputPricePerMillion = null,
        public ?float $thinkingPricePerMillion = null,
    ) {
    }
}
