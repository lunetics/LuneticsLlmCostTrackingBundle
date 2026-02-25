<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Service;

use Lunetics\LlmCostTrackingBundle\Model\ModelRegistryInterface;
use Symfony\AI\AiBundle\Profiler\TraceablePlatform;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

final class CostTracker implements CostTrackerInterface
{
    /** @var TraceablePlatform[] */
    private readonly array $platforms;

    /**
     * @var array{
     *     calls: list<array{model: string, display_name: string, provider: string, input_tokens: int, output_tokens: int, total_tokens: int, thinking_tokens: int, cached_tokens: int, cost: float}>,
     *     by_model: array<string, array{display_name: string, provider: string, calls: int, input_tokens: int, output_tokens: int, total_tokens: int, cost: float}>,
     *     totals: array{calls: int, input_tokens: int, output_tokens: int, total_tokens: int, cost: float},
     *     unconfigured_models: list<string>,
     * }|null
     */
    private ?array $snapshot = null;

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
        return $this->compute()['calls'];
    }

    public function getTotals(): array
    {
        return $this->compute()['totals'];
    }

    public function getByModel(): array
    {
        return $this->compute()['by_model'];
    }

    public function getUnconfiguredModels(): array
    {
        return $this->compute()['unconfigured_models'];
    }

    public function getSnapshot(): array
    {
        return $this->compute();
    }

    /**
     * @return array{
     *     calls: list<array{model: string, display_name: string, provider: string, input_tokens: int, output_tokens: int, total_tokens: int, thinking_tokens: int, cached_tokens: int, cost: float}>,
     *     by_model: array<string, array{display_name: string, provider: string, calls: int, input_tokens: int, output_tokens: int, total_tokens: int, cost: float}>,
     *     totals: array{calls: int, input_tokens: int, output_tokens: int, total_tokens: int, cost: float},
     *     unconfigured_models: list<string>,
     * }
     */
    private function compute(): array
    {
        if (null !== $this->snapshot) {
            return $this->snapshot;
        }

        $calls = [];
        $byModel = [];
        $unconfiguredModels = [];
        $totals = ['calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'cost' => 0.0];

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
                $totalTokens = 0;

                if ($tokenUsage instanceof TokenUsageInterface) {
                    $inputTokens = $tokenUsage->getPromptTokens() ?? 0;
                    $outputTokens = $tokenUsage->getCompletionTokens() ?? 0;
                    $thinkingTokens = $tokenUsage->getThinkingTokens() ?? 0;
                    $cachedTokens = $tokenUsage->getCachedTokens() ?? 0;
                    $totalTokens = $tokenUsage->getTotalTokens() ?? ($inputTokens + $outputTokens);
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

                $calls[] = [
                    'model' => $modelString,
                    'display_name' => $displayName,
                    'provider' => $provider,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'total_tokens' => $totalTokens,
                    'thinking_tokens' => $thinkingTokens,
                    'cached_tokens' => $cachedTokens,
                    'cost' => $cost,
                ];

                if (!isset($byModel[$modelString])) {
                    $byModel[$modelString] = [
                        'display_name' => $displayName,
                        'provider' => $provider,
                        'calls' => 0,
                        'input_tokens' => 0,
                        'output_tokens' => 0,
                        'total_tokens' => 0,
                        'cost' => 0.0,
                    ];
                }
                ++$byModel[$modelString]['calls'];
                $byModel[$modelString]['input_tokens'] += $inputTokens;
                $byModel[$modelString]['output_tokens'] += $outputTokens;
                $byModel[$modelString]['total_tokens'] += $totalTokens;
                $byModel[$modelString]['cost'] += $cost;

                ++$totals['calls'];
                $totals['input_tokens'] += $inputTokens;
                $totals['output_tokens'] += $outputTokens;
                $totals['total_tokens'] += $totalTokens;
                $totals['cost'] += $cost;
            }
        }

        foreach ($byModel as &$modelData) {
            $modelData['cost'] = round($modelData['cost'], 6);
        }
        $totals['cost'] = round($totals['cost'], 6);

        return $this->snapshot = [
            'calls' => $calls,
            'by_model' => $byModel,
            'totals' => $totals,
            'unconfigured_models' => array_keys($unconfiguredModels),
        ];
    }
}
