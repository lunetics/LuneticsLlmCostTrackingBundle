<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Model;

final readonly class CostSnapshot
{
    /**
     * @param list<CallRecord>                $calls
     * @param array<string, ModelAggregation> $byModel
     * @param list<string>                    $unconfiguredModels
     * @param list<SkippedCall>               $skippedCalls
     */
    public function __construct(
        public array $calls,
        public array $byModel,
        public CostSummary $totals,
        public array $unconfiguredModels,
        public array $skippedCalls,
    ) {
    }
}
