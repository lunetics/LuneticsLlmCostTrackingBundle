<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Service;

use Lunetics\LlmCostTrackingBundle\Model\CallRecord;
use Lunetics\LlmCostTrackingBundle\Model\CostSnapshot;
use Lunetics\LlmCostTrackingBundle\Model\CostSummary;
use Lunetics\LlmCostTrackingBundle\Model\ModelAggregation;
use Lunetics\LlmCostTrackingBundle\Model\ModelRegistryInterface;
use Lunetics\LlmCostTrackingBundle\Model\SkippedCall;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\AI\Platform\TraceablePlatform;
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
        private readonly ?LoggerInterface $logger = null,
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

    public function getSkippedCalls(): array
    {
        return $this->compute()->skippedCalls;
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
        $skippedCalls = [];
        $totalCalls = 0;
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $totalTotalTokens = 0;
        $totalCost = 0.0;

        foreach ($this->platforms as $platform) {
            foreach ($platform->getCalls() as $call) {
                try {
                    $result = $call['result']->getResult();
                    $metadata = $result->getMetadata();
                    $tokenUsage = $metadata->get('token_usage');

                    $modelString = $this->resolveModelName($call['model']);
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
                    }
                } catch (\Throwable $e) {
                    // Skip malformed/failed calls, and calls where a user-supplied
                    // ModelRegistryInterface or CostCalculatorInterface implementation
                    // throws (both are advertised, replaceable extension points) —
                    // the entire per-call computation lives inside this guard, before
                    // any of the aggregation writes below, so a throw here can never
                    // leave partial data in $calls/$byModel/the totals. Don't crash
                    // the profiler panel or kernel.terminate cost logging; log the
                    // skip instead, so a systematically throwing extension point does
                    // not silently present as "no LLM calls were made".
                    // Model resolution and the log call are INDEPENDENT best-effort
                    // guards, not one shared try: a throwing logger (already
                    // regression-tested) must not also suppress the SkippedCall
                    // record below it. A shared guard would leave both
                    // totals.calls and skippedCalls empty on that path, and the
                    // panel's widened empty-state check would render "No LLM
                    // calls were made" for a request that made and lost a call —
                    // exactly the case this feature exists to surface. The
                    // SkippedCall construction itself needs no guard (see below).
                    $skippedModel = null;
                    try {
                        $skippedModel = $this->resolveModelName($call['model']);
                    } catch (\Throwable) {
                        // Falls back to null; the skip is still recorded below. A
                        // Model subclass overriding getName() is the one realistic
                        // trigger (Model is not final).
                    }

                    // No guard needed here: SkippedCall's constructor has no
                    // validation, $e::class is always a string, and
                    // Throwable::getMessage() is declared final — none of these
                    // can throw.
                    $skippedCalls[] = new SkippedCall(
                        model: $skippedModel,
                        exceptionClass: $e::class,
                        exceptionMessage: $e->getMessage(),
                    );

                    try {
                        $this->logger?->warning('Skipped an LLM call in cost tracking; a per-call computation step threw.', [
                            'exception' => $e,
                            'model' => $skippedModel,
                        ]);
                    } catch (\Throwable) {
                        // Logging must never make a skipped call fatal.
                    }

                    continue;
                }

                if (null === $modelDefinition) {
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
            skippedCalls: $skippedCalls,
        );
    }

    /**
     * TraceablePlatform::invoke() has accepted `string|Model` since
     * symfony/ai-platform 0.10 and stores whatever was passed in unnormalized,
     * but its `@phpstan-type PlatformCallData` docblock still declares
     * `model: string`. Written inline at the read site, the instanceof check
     * is therefore rejected as `instanceof.alwaysFalse` (PHPStan trusts the
     * stale vendor PHPDoc); this helper boundary gives the check an honest
     * parameter type instead.
     */
    private function resolveModelName(string|Model $model): string
    {
        return $model instanceof Model ? $model->getName() : $model;
    }
}
