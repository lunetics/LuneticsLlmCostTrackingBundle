<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\DataCollector;

use Lunetics\LlmCostTrackingBundle\Model\CallRecord;
use Lunetics\LlmCostTrackingBundle\Model\CostSummary;
use Lunetics\LlmCostTrackingBundle\Model\CostThresholds;
use Lunetics\LlmCostTrackingBundle\Model\ModelAggregation;
use Lunetics\LlmCostTrackingBundle\Service\CostTrackerInterface;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;

final class LlmCostCollector extends AbstractDataCollector implements LateDataCollectorInterface
{
    public function __construct(
        private readonly CostTrackerInterface $costTracker,
        private readonly CostThresholds $costThresholds,
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
            'snapshot' => $snapshot,
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

    /** @return list<CallRecord> */
    public function getCalls(): array
    {
        return $this->data['snapshot']->calls ?? [];
    }

    /** @return array<string, ModelAggregation> */
    public function getByModel(): array
    {
        return $this->data['snapshot']->byModel ?? [];
    }

    public function getTotals(): CostSummary
    {
        return $this->data['snapshot']->totals ?? new CostSummary(0, 0, 0, 0, 0.0);
    }

    /** @return list<string> */
    public function getUnconfiguredModels(): array
    {
        return $this->data['snapshot']->unconfiguredModels ?? [];
    }

    public function getCostThresholds(): CostThresholds
    {
        return $this->data['cost_thresholds'] ?? new CostThresholds(0.01, 0.10);
    }

    public function getBudgetWarning(): ?float
    {
        return $this->data['budget_warning'] ?? null;
    }
}
