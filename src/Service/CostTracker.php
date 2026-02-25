<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Service;

use Lunetics\LlmCostTrackingBundle\Model\CallRecord;
use Lunetics\LlmCostTrackingBundle\Model\CostSnapshot;
use Lunetics\LlmCostTrackingBundle\Model\CostSummary;
use Lunetics\LlmCostTrackingBundle\Model\ModelAggregation;
use Lunetics\LlmCostTrackingBundle\Model\ModelRegistryInterface;
use Symfony\AI\AiBundle\Profiler\TraceablePlatform;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Contracts\Service\ResetInterface;

final class CostTracker implements CostTrackerInterface, ResetInterface
{
    /** @var TraceablePlatform[] */
    private readonly array $platforms;

    private ?CostSnapshot $snapshot = null;

    /** @param iterable<TraceablePlatform> $platforms */
    public function __construct(
        iterable $platforms,
        private readonly ModelRegistryInterface $modelRegistry,
        private readonly CostCalculatorInterface $costCalculator,
    ) {
        $this->platforms = $platforms instanceof \Traversable ? iterator_to_array($platforms) : $platforms;
    }

    public function getCalls(): array
    {
        return $this->compute()->calls;
    }

    public function getTotals(): CostSummary
    {
        return $this->compute()->totals;
    }

    public function getByModel(): array
    {
        return $this->compute()->byModel;
    }

    public function getUnconfiguredModels(): array
    {
        return $this->compute()->unconfiguredModels;
    }

    public function getSnapshot(): CostSnapshot
    {
        return $this->compute();
    }

    public function reset(): void
    {
        $this->snapshot = null;
    }

    private function compute(): CostSnapshot
    {
        if (null !== $this->snapshot) {
            return $this->snapshot;
        }

        $calls = [];
        $byModel = [];
        $unconfiguredModels = [];
        $totalCalls = 0;
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $totalTotalTokens = 0;
        $totalCost = 0.0;

        foreach ($this->platforms as $platform) {
            foreach ($platform->calls as $call) {
                try {
                    $result = $call['result']->getResult();
                    $metadata = $result->getMetadata();
                    $tokenUsage = $metadata->get('token_usage');
                } catch (\Throwable) {
                    // Skip malformed or failed calls — don't crash the entire profiler
                    continue;
                }

                $modelString = $call['model'];
                $modelDefinition = $this->modelRegistry->get($modelString);

                $inputTokens = 0;
                $outputTokens = 0;
                $thinkingTokens = 0;
                $cachedTokens = 0;
                $callTotalTokens = 0;

                if ($tokenUsage instanceof TokenUsageInterface) {
                    $inputTokens = $tokenUsage->getPromptTokens() ?? 0;
                    $outputTokens = $tokenUsage->getCompletionTokens() ?? 0;
                    $thinkingTokens = $tokenUsage->getThinkingTokens() ?? 0;
                    $cachedTokens = $tokenUsage->getCachedTokens() ?? 0;
                    $callTotalTokens = $tokenUsage->getTotalTokens() ?? ($inputTokens + $outputTokens);
                }

                if (null !== $modelDefinition) {
                    $cost = $this->costCalculator->calculateCost(
                        $modelDefinition,
                        $inputTokens,
                        $outputTokens,
                        $cachedTokens,
                        $thinkingTokens,
                    );
                    $displayName = $modelDefinition->displayName;
                    $provider = $modelDefinition->provider;
                } else {
                    $cost = 0.0;
                    $displayName = $modelString;
                    $provider = 'Unknown';
                    $unconfiguredModels[$modelString] = true;
                }

                $calls[] = new CallRecord(
                    model: $modelString,
                    displayName: $displayName,
                    provider: $provider,
                    inputTokens: $inputTokens,
                    outputTokens: $outputTokens,
                    totalTokens: $callTotalTokens,
                    thinkingTokens: $thinkingTokens,
                    cachedTokens: $cachedTokens,
                    cost: $cost,
                );

                if (!isset($byModel[$modelString])) {
                    $byModel[$modelString] = [
                        'displayName' => $displayName,
                        'provider' => $provider,
                        'calls' => 0,
                        'inputTokens' => 0,
                        'outputTokens' => 0,
                        'totalTokens' => 0,
                        'cost' => 0.0,
                    ];
                }
                ++$byModel[$modelString]['calls'];
                $byModel[$modelString]['inputTokens'] += $inputTokens;
                $byModel[$modelString]['outputTokens'] += $outputTokens;
                $byModel[$modelString]['totalTokens'] += $callTotalTokens;
                $byModel[$modelString]['cost'] += $cost;

                ++$totalCalls;
                $totalInputTokens += $inputTokens;
                $totalOutputTokens += $outputTokens;
                $totalTotalTokens += $callTotalTokens;
                $totalCost += $cost;
            }
        }

        $byModelDtos = [];
        foreach ($byModel as $modelId => $data) {
            $byModelDtos[$modelId] = new ModelAggregation(
                displayName: $data['displayName'],
                provider: $data['provider'],
                calls: $data['calls'],
                inputTokens: $data['inputTokens'],
                outputTokens: $data['outputTokens'],
                totalTokens: $data['totalTokens'],
                cost: round($data['cost'], 6),
            );
        }

        return $this->snapshot = new CostSnapshot(
            calls: $calls,
            byModel: $byModelDtos,
            totals: new CostSummary(
                calls: $totalCalls,
                inputTokens: $totalInputTokens,
                outputTokens: $totalOutputTokens,
                totalTokens: $totalTotalTokens,
                cost: round($totalCost, 6),
            ),
            unconfiguredModels: array_keys($unconfiguredModels),
        );
    }
}
