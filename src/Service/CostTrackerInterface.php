<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Service;

use Lunetics\LlmCostTrackingBundle\Model\CallRecord;
use Lunetics\LlmCostTrackingBundle\Model\CostSnapshot;
use Lunetics\LlmCostTrackingBundle\Model\CostSummary;
use Lunetics\LlmCostTrackingBundle\Model\ModelAggregation;

interface CostTrackerInterface
{
    /** @return list<CallRecord> */
    public function getCalls(): array;

    public function getTotals(): CostSummary;

    /** @return array<string, ModelAggregation> */
    public function getByModel(): array;

    /** @return list<string> */
    public function getUnconfiguredModels(): array;

    /**
     * Returns all tracked data as a consistent point-in-time snapshot.
     *
     * Prefer this over calling individual getters when you need multiple
     * data slices, as it guarantees all arrays reflect the same computation.
     */
    public function getSnapshot(): CostSnapshot;
}
