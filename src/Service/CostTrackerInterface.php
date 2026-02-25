<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Service;

interface CostTrackerInterface
{
    /** @return list<array{model: string, display_name: string, provider: string, input_tokens: int, output_tokens: int, total_tokens: int, thinking_tokens: int, cached_tokens: int, cost: float}> */
    public function getCalls(): array;

    /** @return array{calls: int, input_tokens: int, output_tokens: int, total_tokens: int, cost: float} */
    public function getTotals(): array;

    /** @return array<string, array{display_name: string, provider: string, calls: int, input_tokens: int, output_tokens: int, total_tokens: int, cost: float}> */
    public function getByModel(): array;

    /** @return list<string> */
    public function getUnconfiguredModels(): array;

    /**
     * Returns all tracked data as a consistent point-in-time snapshot.
     *
     * Prefer this over calling individual getters when you need multiple
     * data slices, as it guarantees all arrays reflect the same computation.
     *
     * @return array{
     *     calls: list<array{model: string, display_name: string, provider: string, input_tokens: int, output_tokens: int, total_tokens: int, thinking_tokens: int, cached_tokens: int, cost: float}>,
     *     by_model: array<string, array{display_name: string, provider: string, calls: int, input_tokens: int, output_tokens: int, total_tokens: int, cost: float}>,
     *     totals: array{calls: int, input_tokens: int, output_tokens: int, total_tokens: int, cost: float},
     *     unconfigured_models: list<string>,
     * }
     */
    public function getSnapshot(): array;
}
