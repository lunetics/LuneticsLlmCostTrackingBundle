<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\DataCollector;

use Lunetics\LlmCostTrackingBundle\DataCollector\LlmCostCollector;
use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;
use Lunetics\LlmCostTrackingBundle\Model\ModelRegistry;
use Lunetics\LlmCostTrackingBundle\Service\CostCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\AiBundle\Profiler\TraceablePlatform;
use Symfony\AI\Platform\Metadata\Metadata;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

final class LlmCostCollectorTest extends TestCase
{
    #[Test]
    public function itReturnsEmptyDataWhenNoCalls(): void
    {
        $collector = $this->createCollector([]);

        $collector->lateCollect();

        $totals = $collector->getTotals();
        self::assertSame(0, $totals['calls']);
        self::assertSame(0.0, $totals['cost']);
        self::assertSame([], $collector->getCalls());
        self::assertSame([], $collector->getByModel());
        self::assertSame([], $collector->getUnconfiguredModels());
    }

    #[Test]
    public function itReturnsConfiguredCurrency(): void
    {
        $collector = $this->createCollector([], currency: 'EUR');

        $collector->lateCollect();

        self::assertSame('EUR', $collector->getCurrency());
    }

    #[Test]
    public function itReturnsConfiguredThresholds(): void
    {
        $collector = $this->createCollector([], costThresholds: ['low' => 0.05, 'medium' => 0.50]);

        $collector->lateCollect();

        self::assertSame(['low' => 0.05, 'medium' => 0.50], $collector->getCostThresholds());
    }

    #[Test]
    public function itReturnsBudgetWarning(): void
    {
        $collector = $this->createCollector([], budgetWarning: 1.50);

        $collector->lateCollect();

        self::assertSame(1.50, $collector->getBudgetWarning());
    }

    #[Test]
    public function itReturnsTemplatePath(): void
    {
        self::assertSame(
            '@LuneticsLlmCostTracking/data_collector/llm_cost.html.twig',
            LlmCostCollector::getTemplate(),
        );
    }

    #[Test]
    public function itProcessesSingleCallWithConfiguredModel(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('gpt-5', new TokenUsage(1000, 500, null, null, null, null, null, null, 1500)),
        ]);

        $collector = $this->createCollector([$platform]);
        $collector->lateCollect();

        $totals = $collector->getTotals();
        self::assertSame(1, $totals['calls']);
        self::assertSame(1000, $totals['input_tokens']);
        self::assertSame(500, $totals['output_tokens']);
        self::assertSame(1500, $totals['total_tokens']);
        // (1000/1M * 1.25) + (500/1M * 10.00) = 0.00125 + 0.005 = 0.00625
        self::assertSame(0.00625, $totals['cost']);

        $calls = $collector->getCalls();
        self::assertCount(1, $calls);
        self::assertSame('gpt-5', $calls[0]['model']);
        self::assertSame('GPT-5', $calls[0]['display_name']);
        self::assertSame('OpenAI', $calls[0]['provider']);

        $byModel = $collector->getByModel();
        self::assertArrayHasKey('gpt-5', $byModel);
        self::assertSame(1, $byModel['gpt-5']['calls']);

        self::assertSame([], $collector->getUnconfiguredModels());
    }

    #[Test]
    public function itAggregatesMultipleCallsForSameModel(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('gpt-5', new TokenUsage(1000, 500, null, null, null, null, null, null, 1500)),
            $this->createCall('gpt-5', new TokenUsage(2000, 1000, null, null, null, null, null, null, 3000)),
        ]);

        $collector = $this->createCollector([$platform]);
        $collector->lateCollect();

        $totals = $collector->getTotals();
        self::assertSame(2, $totals['calls']);
        self::assertSame(3000, $totals['input_tokens']);
        self::assertSame(1500, $totals['output_tokens']);

        $byModel = $collector->getByModel();
        self::assertCount(1, $byModel);
        self::assertSame(2, $byModel['gpt-5']['calls']);
        self::assertSame(3000, $byModel['gpt-5']['input_tokens']);
        self::assertSame(1500, $byModel['gpt-5']['output_tokens']);
    }

    #[Test]
    public function itTracksCallsPerModelSeparately(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('gpt-5', new TokenUsage(1000, 500, null, null, null, null, null, null, 1500)),
            $this->createCall('claude-sonnet-4-6', new TokenUsage(2000, 1000, null, null, null, null, null, null, 3000)),
        ]);

        $collector = $this->createCollector([$platform]);
        $collector->lateCollect();

        $totals = $collector->getTotals();
        self::assertSame(2, $totals['calls']);

        $byModel = $collector->getByModel();
        self::assertCount(2, $byModel);
        self::assertArrayHasKey('gpt-5', $byModel);
        self::assertArrayHasKey('claude-sonnet-4-6', $byModel);
        self::assertSame(1, $byModel['gpt-5']['calls']);
        self::assertSame(1, $byModel['claude-sonnet-4-6']['calls']);
    }

    #[Test]
    public function itTracksUnconfiguredModelWithZeroCost(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('unknown-model', new TokenUsage(1000, 500, null, null, null, null, null, null, 1500)),
        ]);

        $collector = $this->createCollector([$platform]);
        $collector->lateCollect();

        $totals = $collector->getTotals();
        self::assertSame(1, $totals['calls']);
        self::assertSame(0.0, $totals['cost']);

        $calls = $collector->getCalls();
        self::assertSame('unknown-model', $calls[0]['display_name']);
        self::assertSame('Unknown', $calls[0]['provider']);

        self::assertSame(['unknown-model'], $collector->getUnconfiguredModels());
    }

    #[Test]
    public function itHandlesMixOfConfiguredAndUnconfiguredModels(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('gpt-5', new TokenUsage(1000, 500, null, null, null, null, null, null, 1500)),
            $this->createCall('unknown-model', new TokenUsage(1000, 500, null, null, null, null, null, null, 1500)),
        ]);

        $collector = $this->createCollector([$platform]);
        $collector->lateCollect();

        $totals = $collector->getTotals();
        self::assertSame(2, $totals['calls']);
        self::assertGreaterThan(0.0, $totals['cost']);

        $byModel = $collector->getByModel();
        self::assertGreaterThan(0.0, $byModel['gpt-5']['cost']);
        self::assertSame(0.0, $byModel['unknown-model']['cost']);

        self::assertSame(['unknown-model'], $collector->getUnconfiguredModels());
    }

    #[Test]
    public function itSkipsFailedCallsWithoutCrashing(): void
    {
        $platform = $this->createPlatform([
            $this->createFailingCall('gpt-5'),
            $this->createCall('gpt-5', new TokenUsage(1000, 500, null, null, null, null, null, null, 1500)),
        ]);

        $collector = $this->createCollector([$platform]);
        $collector->lateCollect();

        $totals = $collector->getTotals();
        self::assertSame(1, $totals['calls']);
        self::assertSame(1000, $totals['input_tokens']);
    }

    #[Test]
    public function itCalculatesCostWithThinkingAndCachedTokens(): void
    {
        // claude-sonnet-4-6: input=3.00, output=15.00, cached=0.30, thinking=15.00
        $tokenUsage = new TokenUsage(
            promptTokens: 10000,
            completionTokens: 2000,
            thinkingTokens: 5000,
            toolTokens: null,
            cachedTokens: 3000,
            remainingTokens: null,
            remainingTokensMinute: null,
            remainingTokensMonth: null,
            totalTokens: 17000,
        );

        $platform = $this->createPlatform([
            $this->createCall('claude-sonnet-4-6', $tokenUsage),
        ]);

        $collector = $this->createCollector([$platform]);
        $collector->lateCollect();

        $calls = $collector->getCalls();
        self::assertSame(10000, $calls[0]['input_tokens']);
        self::assertSame(2000, $calls[0]['output_tokens']);
        self::assertSame(5000, $calls[0]['thinking_tokens']);
        self::assertSame(3000, $calls[0]['cached_tokens']);

        // regular input = max(0, 10000 - 3000) = 7000 -> 7000/1M * 3.00 = 0.021
        // output = 2000/1M * 15.00 = 0.03
        // cached = 3000/1M * 0.30 = 0.0009
        // thinking = 5000/1M * 15.00 = 0.075
        // total = 0.1269
        $totals = $collector->getTotals();
        self::assertSame(0.1269, $totals['cost']);
    }

    #[Test]
    public function itHandlesNullTokenUsageGracefully(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('gpt-5'),
        ]);

        $collector = $this->createCollector([$platform]);
        $collector->lateCollect();

        $totals = $collector->getTotals();
        self::assertSame(1, $totals['calls']);
        self::assertSame(0, $totals['input_tokens']);
        self::assertSame(0, $totals['output_tokens']);
        self::assertSame(0.0, $totals['cost']);
    }

    #[Test]
    public function itAggregatesCallsFromMultiplePlatforms(): void
    {
        $platform1 = $this->createPlatform([
            $this->createCall('gpt-5', new TokenUsage(1000, 500, null, null, null, null, null, null, 1500)),
        ]);
        $platform2 = $this->createPlatform([
            $this->createCall('claude-sonnet-4-6', new TokenUsage(2000, 1000, null, null, null, null, null, null, 3000)),
        ]);

        $collector = $this->createCollector([$platform1, $platform2]);
        $collector->lateCollect();

        $totals = $collector->getTotals();
        self::assertSame(2, $totals['calls']);
        self::assertSame(3000, $totals['input_tokens']);
        self::assertSame(1500, $totals['output_tokens']);

        $byModel = $collector->getByModel();
        self::assertCount(2, $byModel);
    }

    /**
     * @param iterable<TraceablePlatform>      $platforms
     * @param array{low: float, medium: float} $costThresholds
     */
    private function createCollector(
        iterable $platforms,
        string $currency = 'USD',
        array $costThresholds = ['low' => 0.01, 'medium' => 0.10],
        ?float $budgetWarning = null,
    ): LlmCostCollector {
        $registry = new ModelRegistry([
            'gpt-5' => new ModelDefinition('gpt-5', 'GPT-5', 'OpenAI', 1.25, 10.00),
            'claude-sonnet-4-6' => new ModelDefinition('claude-sonnet-4-6', 'Claude Sonnet 4.6', 'Anthropic', 3.00, 15.00, 0.30, 15.00),
        ]);

        return new LlmCostCollector(
            $platforms,
            $registry,
            new CostCalculator(),
            $currency,
            $costThresholds,
            $budgetWarning,
        );
    }

    /**
     * @param list<array{model: string, input: string, options: array<string, mixed>, result: DeferredResult}> $calls
     */
    private function createPlatform(array $calls): TraceablePlatform
    {
        $platform = new TraceablePlatform(static::createStub(PlatformInterface::class));
        $platform->calls = $calls;

        return $platform;
    }

    /**
     * @return array{model: string, input: string, options: array<string, mixed>, result: DeferredResult}
     */
    private function createCall(string $model, ?TokenUsageInterface $tokenUsage = null): array
    {
        return [
            'model' => $model,
            'input' => 'test input',
            'options' => [],
            'result' => $this->createDeferredResult($tokenUsage),
        ];
    }

    private function createDeferredResult(?TokenUsageInterface $tokenUsage = null): DeferredResult
    {
        $metadata = new Metadata();
        if (null !== $tokenUsage) {
            $metadata->add('token_usage', $tokenUsage);
        }

        $result = static::createStub(ResultInterface::class);
        $result->method('getMetadata')->willReturn($metadata);

        $converter = static::createStub(ResultConverterInterface::class);
        $converter->method('convert')->willReturn($result);
        $converter->method('getTokenUsageExtractor')->willReturn(null);

        return new DeferredResult($converter, static::createStub(RawResultInterface::class));
    }

    /**
     * @return array{model: string, input: string, options: array<string, mixed>, result: DeferredResult}
     */
    private function createFailingCall(string $model): array
    {
        $converter = static::createStub(ResultConverterInterface::class);
        $converter->method('convert')->willThrowException(new \RuntimeException('API error'));
        $converter->method('getTokenUsageExtractor')->willReturn(null);

        return [
            'model' => $model,
            'input' => 'test input',
            'options' => [],
            'result' => new DeferredResult($converter, static::createStub(RawResultInterface::class)),
        ];
    }
}
