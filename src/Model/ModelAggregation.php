<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Model;

final readonly class ModelAggregation
{
    public function __construct(
        public string $displayName,
        public string $provider,
        public int $calls,
        public int $inputTokens,
        public int $outputTokens,
        public int $totalTokens,
        public float $cost,
    ) {
    }
}
