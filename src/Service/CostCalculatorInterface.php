<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Service;

use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;

interface CostCalculatorInterface
{
    public function calculateCost(
        ModelDefinition $model,
        int $inputTokens,
        int $outputTokens,
        int $cachedTokens = 0,
        int $thinkingTokens = 0,
    ): float;
}
