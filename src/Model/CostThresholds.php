<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Model;

final readonly class CostThresholds
{
    public function __construct(
        public float $low,
        public float $medium,
    ) {
    }
}
