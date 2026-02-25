<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Model;

final readonly class CallRecord
{
    public function __construct(
        public string $model,
        public string $displayName,
        public string $provider,
        public int $inputTokens,
        public int $outputTokens,
        public int $totalTokens,
        public int $thinkingTokens,
        public int $cachedTokens,
        public float $cost,
    ) {
    }
}
