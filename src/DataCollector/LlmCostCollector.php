<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\DataCollector;

use Lunetics\LlmCostTrackingBundle\Model\ModelRegistry;
use Lunetics\LlmCostTrackingBundle\Service\CostCalculatorInterface;
use Symfony\AI\AiBundle\Profiler\TraceablePlatform;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;

final class LlmCostCollector extends AbstractDataCollector implements LateDataCollectorInterface
{
    /** @var TraceablePlatform[] */
    private readonly array $platforms;

    /**
     * @param iterable<TraceablePlatform>      $platforms
     * @param array{low: float, medium: float} $costThresholds
     */
    public function __construct(
        iterable $platforms,
        private readonly ModelRegistry $modelRegistry,
        private readonly CostCalculatorInterface $costCalculator,
        private readonly array $costThresholds,
        private readonly ?float $budgetWarning,
    ) {
        $this->platforms = $platforms instanceof \Traversable ? iterator_to_array($platforms) : $platforms;
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        // No-op: lateCollect() handles everything.
        // LateDataCollectorInterface ensures lateCollect() is called after the response is sent,
        // which is required for streaming LLM responses where token data resolves late.
    }

    public function lateCollect(): void
    {
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

        $this->data = [
            'calls' => $calls,
            'by_model' => $byModel,
            'totals' => $totals,
            'unconfigured_models' => array_keys($unconfiguredModels),
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
