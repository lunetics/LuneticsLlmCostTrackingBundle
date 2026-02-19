<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Service;

use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;

final class CostCalculator implements CostCalculatorInterface
{
    public function calculateCost(
        ModelDefinition $model,
        int $inputTokens,
        int $outputTokens,
        int $cachedTokens = 0,
        int $thinkingTokens = 0,
    ): float {
        $regularInputTokens = max(0, $inputTokens - $cachedTokens);

        $inputCost = ($regularInputTokens / 1_000_000) * $model->inputPricePerMillion;
        $outputCost = ($outputTokens / 1_000_000) * $model->outputPricePerMillion;

        $cachedCost = 0.0;
        if ($cachedTokens > 0 && null !== $model->cachedInputPricePerMillion) {
            $cachedCost = ($cachedTokens / 1_000_000) * $model->cachedInputPricePerMillion;
        } elseif ($cachedTokens > 0) {
            $cachedCost = ($cachedTokens / 1_000_000) * $model->inputPricePerMillion;
        }

        $thinkingCost = 0.0;
        if ($thinkingTokens > 0 && null !== $model->thinkingPricePerMillion) {
            $thinkingCost = ($thinkingTokens / 1_000_000) * $model->thinkingPricePerMillion;
        }

        return round($inputCost + $outputCost + $cachedCost + $thinkingCost, 6);
    }
}
