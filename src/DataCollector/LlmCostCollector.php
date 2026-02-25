<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\DataCollector;

use Lunetics\LlmCostTrackingBundle\Service\CostTrackerInterface;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;

final class LlmCostCollector extends AbstractDataCollector implements LateDataCollectorInterface
{
    /** @param array{low: float, medium: float} $costThresholds */
    public function __construct(
        private readonly CostTrackerInterface $costTracker,
        private readonly array $costThresholds,
        private readonly ?float $budgetWarning,
    ) {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        // No-op: lateCollect() handles everything.
        // LateDataCollectorInterface ensures lateCollect() is called after the response is sent,
        // which is required for streaming LLM responses where token data resolves late.
    }

    public function lateCollect(): void
    {
        $snapshot = $this->costTracker->getSnapshot();

        $this->data = [
            'calls' => $snapshot['calls'],
            'by_model' => $snapshot['by_model'],
            'totals' => $snapshot['totals'],
            'unconfigured_models' => $snapshot['unconfigured_models'],
            'cost_thresholds' => $this->costThresholds,
            'budget_warning' => $this->budgetWarning,
        ];
    }

    public function getName(): string
    {
        return 'lunetics_llm_cost_tracking';
    }

    public static function getTemplate(): string
    {
        return '@LuneticsLlmCostTracking/data_collector/llm_cost.html.twig';
    }

    /** @return list<array<string, mixed>> */
    public function getCalls(): array
    {
        return $this->data['calls'] ?? [];
    }

    /** @return array<string, array<string, mixed>> */
    public function getByModel(): array
    {
        return $this->data['by_model'] ?? [];
    }

    /** @return array{calls: int, input_tokens: int, output_tokens: int, total_tokens: int, cost: float} */
    public function getTotals(): array
    {
        return $this->data['totals'] ?? ['calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'cost' => 0.0];
    }

    /** @return list<string> */
    public function getUnconfiguredModels(): array
    {
        return $this->data['unconfigured_models'] ?? [];
    }

    /** @return array{low: float, medium: float} */
    public function getCostThresholds(): array
    {
        return $this->data['cost_thresholds'] ?? ['low' => 0.01, 'medium' => 0.10];
    }

    public function getBudgetWarning(): ?float
    {
        return $this->data['budget_warning'] ?? null;
    }
}
