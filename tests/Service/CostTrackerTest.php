<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\Service;

use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;
use Lunetics\LlmCostTrackingBundle\Model\ModelRegistry;
use Lunetics\LlmCostTrackingBundle\Model\ModelRegistryInterface;
use Lunetics\LlmCostTrackingBundle\Pricing\PricingProviderInterface;
use Lunetics\LlmCostTrackingBundle\Service\CostCalculator;
use Lunetics\LlmCostTrackingBundle\Service\CostCalculatorInterface;
use Lunetics\LlmCostTrackingBundle\Service\CostTracker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Metadata\Metadata;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\AI\Platform\TraceablePlatform;

final class CostTrackerTest extends TestCase
{
    #[Test]
    public function itReturnsEmptyDataWhenNoCalls(): void
    {
        $tracker = $this->createTracker([]);

        $totals = $tracker->getTotals();
        self::assertSame(0, $totals->calls);
        self::assertSame(0.0, $totals->cost);
        self::assertSame([], $tracker->getCalls());
        self::assertSame([], $tracker->getByModel());
        self::assertSame([], $tracker->getUnconfiguredModels());
    }

    #[Test]
    public function itProcessesSingleCallWithConfiguredModel(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('gpt-5', new TokenUsage(1000, 500, null, null, null, null, null, null, 1500)),
        ]);

        $tracker = $this->createTracker([$platform]);

        $totals = $tracker->getTotals();
        self::assertSame(1, $totals->calls);
        self::assertSame(1000, $totals->inputTokens);
        self::assertSame(500, $totals->outputTokens);
        self::assertSame(1500, $totals->totalTokens);
        // (1000/1M * 1.25) + (500/1M * 10.00) = 0.00125 + 0.005 = 0.00625
        self::assertSame(0.00625, $totals->cost);

        $calls = $tracker->getCalls();
        self::assertCount(1, $calls);
        self::assertSame('gpt-5', $calls[0]->model);
        self::assertSame('GPT-5', $calls[0]->displayName);
        self::assertSame('OpenAI', $calls[0]->provider);

        $byModel = $tracker->getByModel();
        self::assertArrayHasKey('gpt-5', $byModel);
        self::assertSame(1, $byModel['gpt-5']->calls);

        self::assertSame([], $tracker->getUnconfiguredModels());
    }

    #[Test]
    public function itAggregatesMultipleCallsForSameModel(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('gpt-5', new TokenUsage(1000, 500, null, null, null, null, null, null, 1500)),
            $this->createCall('gpt-5', new TokenUsage(2000, 1000, null, null, null, null, null, null, 3000)),
        ]);

        $tracker = $this->createTracker([$platform]);

        $totals = $tracker->getTotals();
        self::assertSame(2, $totals->calls);
        self::assertSame(3000, $totals->inputTokens);
        self::assertSame(1500, $totals->outputTokens);

        $byModel = $tracker->getByModel();
        self::assertCount(1, $byModel);
        self::assertSame(2, $byModel['gpt-5']->calls);
        self::assertSame(3000, $byModel['gpt-5']->inputTokens);
        self::assertSame(1500, $byModel['gpt-5']->outputTokens);
    }

    #[Test]
    public function itTracksCallsPerModelSeparately(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('gpt-5', new TokenUsage(1000, 500, null, null, null, null, null, null, 1500)),
            $this->createCall('claude-sonnet-4-6', new TokenUsage(2000, 1000, null, null, null, null, null, null, 3000)),
        ]);

        $tracker = $this->createTracker([$platform]);

        $totals = $tracker->getTotals();
        self::assertSame(2, $totals->calls);

        $byModel = $tracker->getByModel();
        self::assertCount(2, $byModel);
        self::assertArrayHasKey('gpt-5', $byModel);
        self::assertArrayHasKey('claude-sonnet-4-6', $byModel);
        self::assertSame(1, $byModel['gpt-5']->calls);
        self::assertSame(1, $byModel['claude-sonnet-4-6']->calls);
    }

    #[Test]
    public function itTracksUnconfiguredModelWithZeroCost(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('unknown-model', new TokenUsage(1000, 500, null, null, null, null, null, null, 1500)),
        ]);

        $tracker = $this->createTracker([$platform]);

        $totals = $tracker->getTotals();
        self::assertSame(1, $totals->calls);
        self::assertSame(0.0, $totals->cost);

        $calls = $tracker->getCalls();
        self::assertSame('unknown-model', $calls[0]->displayName);
        self::assertSame('Unknown', $calls[0]->provider);

        self::assertSame(['unknown-model'], $tracker->getUnconfiguredModels());
    }

    #[Test]
    public function itHandlesMixOfConfiguredAndUnconfiguredModels(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('gpt-5', new TokenUsage(1000, 500, null, null, null, null, null, null, 1500)),
            $this->createCall('unknown-model', new TokenUsage(1000, 500, null, null, null, null, null, null, 1500)),
        ]);

        $tracker = $this->createTracker([$platform]);

        $totals = $tracker->getTotals();
        self::assertSame(2, $totals->calls);
        self::assertGreaterThan(0.0, $totals->cost);

        $byModel = $tracker->getByModel();
        self::assertGreaterThan(0.0, $byModel['gpt-5']->cost);
        self::assertSame(0.0, $byModel['unknown-model']->cost);

        self::assertSame(['unknown-model'], $tracker->getUnconfiguredModels());
    }

    #[Test]
    public function itSkipsFailedCallsWithoutCrashing(): void
    {
        $platform = $this->createPlatform([
            $this->createFailingCall('gpt-5'),
            $this->createCall('gpt-5', new TokenUsage(1000, 500, null, null, null, null, null, null, 1500)),
        ]);

        $tracker = $this->createTracker([$platform]);

        $totals = $tracker->getTotals();
        self::assertSame(1, $totals->calls);
        self::assertSame(1000, $totals->inputTokens);
    }

    #[Test]
    public function itSkipsCallsWhereModelRegistryThrows(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('broken-model', new TokenUsage(promptTokens: 1000, completionTokens: 500, totalTokens: 1500)),
            $this->createCall('gpt-5', new TokenUsage(promptTokens: 2000, completionTokens: 1000, totalTokens: 3000)),
        ]);

        $registry = new class implements ModelRegistryInterface {
            public function get(string $modelId): ?ModelDefinition
            {
                if ('broken-model' === $modelId) {
                    throw new \RuntimeException('registry lookup exploded');
                }

                if ('gpt-5' === $modelId) {
                    return new ModelDefinition($modelId, 'GPT-5', 'OpenAI', 1.25, 10.00);
                }

                return null;
            }
        };

        $tracker = new CostTracker([$platform], $registry, new CostCalculator());

        $totals = $tracker->getTotals();
        self::assertSame(1, $totals->calls);
        self::assertSame(2000, $totals->inputTokens);

        $calls = $tracker->getCalls();
        self::assertCount(1, $calls);
        self::assertSame('gpt-5', $calls[0]->model);

        self::assertArrayNotHasKey('broken-model', $tracker->getByModel());
        self::assertSame([], $tracker->getUnconfiguredModels());
    }

    #[Test]
    public function itSkipsCallsWhereCostCalculatorThrows(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('gpt-5', new TokenUsage(promptTokens: 1000, completionTokens: 500, totalTokens: 1500)),
            $this->createCall('claude-sonnet-4-6', new TokenUsage(promptTokens: 2000, completionTokens: 1000, totalTokens: 3000)),
        ]);

        $registry = new ModelRegistry([
            'gpt-5' => new ModelDefinition('gpt-5', 'GPT-5', 'OpenAI', 1.25, 10.00),
            'claude-sonnet-4-6' => new ModelDefinition('claude-sonnet-4-6', 'Claude Sonnet 4.6', 'Anthropic', 3.00, 15.00),
        ]);

        $calculator = new class implements CostCalculatorInterface {
            public function calculateCost(
                ModelDefinition $model,
                int $inputTokens,
                int $outputTokens,
                int $cachedTokens = 0,
                int $thinkingTokens = 0,
            ): float {
                if ('gpt-5' === $model->modelId) {
                    throw new \RuntimeException('cost calculator exploded');
                }

                return ($inputTokens / 1_000_000 * $model->inputPricePerMillion)
                    + ($outputTokens / 1_000_000 * $model->outputPricePerMillion);
            }
        };

        $tracker = new CostTracker([$platform], $registry, $calculator);

        $totals = $tracker->getTotals();
        self::assertSame(1, $totals->calls);
        self::assertSame(2000, $totals->inputTokens);

        $calls = $tracker->getCalls();
        self::assertCount(1, $calls);
        self::assertSame('claude-sonnet-4-6', $calls[0]->model);

        self::assertArrayNotHasKey('gpt-5', $tracker->getByModel());
    }

    #[Test]
    public function itTracksSkippedCallsWithExceptionDetails(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('broken-model', new TokenUsage(promptTokens: 1000, completionTokens: 500, totalTokens: 1500)),
            $this->createCall('gpt-5', new TokenUsage(promptTokens: 2000, completionTokens: 1000, totalTokens: 3000)),
        ]);

        $registry = new class implements ModelRegistryInterface {
            public function get(string $modelId): ?ModelDefinition
            {
                if ('broken-model' === $modelId) {
                    throw new \RuntimeException('registry lookup exploded');
                }

                if ('gpt-5' === $modelId) {
                    return new ModelDefinition($modelId, 'GPT-5', 'OpenAI', 1.25, 10.00);
                }

                return null;
            }
        };

        $tracker = new CostTracker([$platform], $registry, new CostCalculator());

        $skippedCalls = $tracker->getSkippedCalls();
        self::assertCount(1, $skippedCalls);
        self::assertSame('broken-model', $skippedCalls[0]->model);
        self::assertSame(\RuntimeException::class, $skippedCalls[0]->exceptionClass);
        self::assertSame('registry lookup exploded', $skippedCalls[0]->exceptionMessage);

        // The working call is unaffected.
        $totals = $tracker->getTotals();
        self::assertSame(1, $totals->calls);
        self::assertCount(1, $tracker->getCalls());
    }

    #[Test]
    public function itResolvesTheModelNameForASkippedCallWhenCostCalculationThrows(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('gpt-5', new TokenUsage(promptTokens: 1000, completionTokens: 500, totalTokens: 1500)),
        ]);

        $registry = new ModelRegistry([
            'gpt-5' => new ModelDefinition('gpt-5', 'GPT-5', 'OpenAI', 1.25, 10.00),
        ]);

        $calculator = new class implements CostCalculatorInterface {
            public function calculateCost(
                ModelDefinition $model,
                int $inputTokens,
                int $outputTokens,
                int $cachedTokens = 0,
                int $thinkingTokens = 0,
            ): float {
                throw new \RuntimeException('cost calculator exploded');
            }
        };

        $tracker = new CostTracker([$platform], $registry, $calculator);

        $skippedCalls = $tracker->getSkippedCalls();
        self::assertCount(1, $skippedCalls);
        self::assertSame('gpt-5', $skippedCalls[0]->model);
        self::assertNotNull($skippedCalls[0]->model);
    }

    #[Test]
    public function itClearsSkippedCallsOnReset(): void
    {
        $platform = $this->createPlatform([
            $this->createFailingCall('gpt-5'),
        ]);

        $tracker = $this->createTracker([$platform]);

        self::assertCount(1, $tracker->getSkippedCalls());

        // Simulate a new request in a long-running runtime
        $platform->reset();
        $tracker->reset();

        self::assertSame([], $tracker->getSkippedCalls());
    }

    #[Test]
    public function itLogsSkippedCallsWhenLoggerIsProvided(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('broken-model', new TokenUsage(promptTokens: 1000, completionTokens: 500, totalTokens: 1500)),
        ]);

        $registry = static::createStub(ModelRegistryInterface::class);
        $registry->method('get')->willThrowException(new \RuntimeException('registry lookup exploded'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $tracker = new CostTracker([$platform], $registry, new CostCalculator(), $logger);

        self::assertSame(0, $tracker->getTotals()->calls);
    }

    #[Test]
    public function itDoesNotLetALoggerFailureEscapeTheSkipPath(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('broken-model', new TokenUsage(promptTokens: 1000, completionTokens: 500, totalTokens: 1500)),
        ]);

        $registry = static::createStub(ModelRegistryInterface::class);
        $registry->method('get')->willThrowException(new \RuntimeException('registry lookup exploded'));

        $logger = static::createStub(LoggerInterface::class);
        $logger->method('warning')->willThrowException(new \RuntimeException('logger exploded too'));

        $tracker = new CostTracker([$platform], $registry, new CostCalculator(), $logger);

        self::assertSame(0, $tracker->getTotals()->calls);

        // A throwing logger must not also erase the SkippedCall record — the model
        // resolution, the record, and the log call are independent best-effort
        // guards. If they shared one guard, both totals.calls AND skippedCalls
        // would be empty here, and the panel's widened empty-state check would
        // render "No LLM calls were made" for a request that made and lost a call.
        $skippedCalls = $tracker->getSkippedCalls();
        self::assertCount(1, $skippedCalls);
        self::assertSame('broken-model', $skippedCalls[0]->model);
        self::assertSame(\RuntimeException::class, $skippedCalls[0]->exceptionClass);
        self::assertSame('registry lookup exploded', $skippedCalls[0]->exceptionMessage);
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

        $tracker = $this->createTracker([$platform]);

        $calls = $tracker->getCalls();
        self::assertSame(10000, $calls[0]->inputTokens);
        self::assertSame(2000, $calls[0]->outputTokens);
        self::assertSame(5000, $calls[0]->thinkingTokens);
        self::assertSame(3000, $calls[0]->cachedTokens);

        // regular input = max(0, 10000 - 3000) = 7000 -> 7000/1M * 3.00 = 0.021
        // output = 2000/1M * 15.00 = 0.03
        // cached = 3000/1M * 0.30 = 0.0009
        // thinking = 5000/1M * 15.00 = 0.075
        // total = 0.1269
        $totals = $tracker->getTotals();
        self::assertSame(0.1269, $totals->cost);
    }

    #[Test]
    public function itHandlesNullTokenUsageGracefully(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('gpt-5'),
        ]);

        $tracker = $this->createTracker([$platform]);

        $totals = $tracker->getTotals();
        self::assertSame(1, $totals->calls);
        self::assertSame(0, $totals->inputTokens);
        self::assertSame(0, $totals->outputTokens);
        self::assertSame(0.0, $totals->cost);
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

        $tracker = $this->createTracker([$platform1, $platform2]);

        $totals = $tracker->getTotals();
        self::assertSame(2, $totals->calls);
        self::assertSame(3000, $totals->inputTokens);
        self::assertSame(1500, $totals->outputTokens);

        $byModel = $tracker->getByModel();
        self::assertCount(2, $byModel);
    }

    #[Test]
    public function itReturnsConsistentSnapshot(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('gpt-5', new TokenUsage(1000, 500, null, null, null, null, null, null, 1500)),
            $this->createCall('unknown-model', new TokenUsage(2000, 1000, null, null, null, null, null, null, 3000)),
        ]);

        $tracker = $this->createTracker([$platform]);

        $snapshot = $tracker->getSnapshot();

        self::assertSame($tracker->getCalls(), $snapshot->calls);
        self::assertSame($tracker->getTotals(), $snapshot->totals);
        self::assertSame($tracker->getByModel(), $snapshot->byModel);
        self::assertSame($tracker->getUnconfiguredModels(), $snapshot->unconfiguredModels);
    }

    #[Test]
    public function itCalculatesCostForModelFromDynamicPricingProvider(): void
    {
        $dynamicModel = new ModelDefinition('gpt-dynamic', 'GPT Dynamic', 'OpenAI', 2.00, 8.00);

        $pricingProvider = $this->createMock(PricingProviderInterface::class);
        $pricingProvider->method('getModels')->willReturn(['gpt-dynamic' => $dynamicModel]);

        $registry = new ModelRegistry([], $pricingProvider);

        $platform = $this->createPlatform([
            $this->createCall('gpt-dynamic', new TokenUsage(1000, 500, null, null, null, null, null, null, 1500)),
        ]);

        $tracker = new CostTracker(
            [$platform],
            $registry,
            new CostCalculator(),
        );

        // (1000/1M * 2.00) + (500/1M * 8.00) = 0.002 + 0.004 = 0.006
        $totals = $tracker->getTotals();
        self::assertSame(0.006, $totals->cost);

        $calls = $tracker->getCalls();
        self::assertSame('GPT Dynamic', $calls[0]->displayName);
        self::assertSame('OpenAI', $calls[0]->provider);

        self::assertSame([], $tracker->getUnconfiguredModels());
    }

    #[Test]
    public function itClearsMemoizedSnapshotOnReset(): void
    {
        $platform = $this->createPlatform([
            $this->createCall('gpt-5', new TokenUsage(100, 50)),
        ]);

        $tracker = $this->createTracker([$platform]);

        $firstSnapshot = $tracker->getSnapshot();
        self::assertSame(1, $firstSnapshot->totals->calls);

        // Simulate a new request in a long-running runtime
        $platform->reset();
        $tracker->reset();

        $secondSnapshot = $tracker->getSnapshot();
        self::assertSame(0, $secondSnapshot->totals->calls);
    }

    #[Test]
    public function itNormalizesAModelObjectRecordedByTraceablePlatform(): void
    {
        $this->skipUnlessModelObjectSupported();

        // symfony/ai-platform >=0.10 lets Platform::invoke() accept a Model
        // OBJECT instead of a string; TraceablePlatform stores it unnormalized
        // as call['model']. CostTracker must resolve it to the model name.
        $platform = $this->createPlatformWithModelObject(
            new Model('gpt-5'),
            new TokenUsage(promptTokens: 1000, completionTokens: 500, totalTokens: 1500),
        );

        $tracker = $this->createTracker([$platform]);

        $totals = $tracker->getTotals();
        self::assertSame(1, $totals->calls);
        self::assertSame(1000, $totals->inputTokens);
        self::assertSame(500, $totals->outputTokens);
        self::assertSame(1500, $totals->totalTokens);
        // (1000/1M * 1.25) + (500/1M * 10.00) = 0.00125 + 0.005 = 0.00625
        self::assertSame(0.00625, $totals->cost);

        $calls = $tracker->getCalls();
        self::assertSame('gpt-5', $calls[0]->model);
        self::assertSame('GPT-5', $calls[0]->displayName);
        self::assertSame('OpenAI', $calls[0]->provider);

        $byModel = $tracker->getByModel();
        self::assertArrayHasKey('gpt-5', $byModel);
        self::assertSame(1, $byModel['gpt-5']->calls);
    }

    /** @param TraceablePlatform[] $platforms */
    private function createTracker(array $platforms): CostTracker
    {
        $registry = new ModelRegistry([
            'gpt-5' => new ModelDefinition('gpt-5', 'GPT-5', 'OpenAI', 1.25, 10.00),
            'claude-sonnet-4-6' => new ModelDefinition('claude-sonnet-4-6', 'Claude Sonnet 4.6', 'Anthropic', 3.00, 15.00, 0.30, 15.00),
        ]);

        return new CostTracker(
            $platforms,
            $registry,
            new CostCalculator(),
        );
    }

    /**
     * @param list<array{model: non-empty-string, input: string, options: array<string, mixed>, result: DeferredResult}> $calls
     */
    private function createPlatform(array $calls): TraceablePlatform
    {
        // In symfony/ai 0.8+, TraceablePlatform::$calls is private and only populated
        // via invoke(). We stub the inner platform to return our pre-built
        // DeferredResults in sequence, then drive the traceable wrapper to record them.
        $deferredResults = array_column($calls, 'result');
        $index = 0;

        $inner = static::createStub(PlatformInterface::class);
        $inner->method('invoke')->willReturnCallback(
            static function () use (&$index, $deferredResults): DeferredResult {
                return $deferredResults[$index++];
            },
        );

        $platform = new TraceablePlatform($inner);

        foreach ($calls as $call) {
            $platform->invoke($call['model'], $call['input'], $call['options']);
        }

        return $platform;
    }

    /**
     * @param non-empty-string $model
     *
     * @return array{model: non-empty-string, input: string, options: array<string, mixed>, result: DeferredResult}
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
     * @param non-empty-string $model
     *
     * @return array{model: non-empty-string, input: string, options: array<string, mixed>, result: DeferredResult}
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

    /**
     * The --prefer-lowest CI lane installs symfony/ai-platform 0.8.0, where
     * TraceablePlatform::invoke() still declares `string $model` only —
     * invoking it with a Model object there would be a genuine TypeError,
     * not the defect this test targets. Skip instead of asserting a
     * version constraint we don't otherwise depend on.
     */
    private function skipUnlessModelObjectSupported(): void
    {
        $modelParameterType = (new \ReflectionMethod(TraceablePlatform::class, 'invoke'))
            ->getParameters()[0]
            ->getType();

        if ($modelParameterType instanceof \ReflectionUnionType) {
            foreach ($modelParameterType->getTypes() as $namedType) {
                if ($namedType instanceof \ReflectionNamedType && Model::class === $namedType->getName()) {
                    return;
                }
            }
        }

        self::markTestSkipped('The installed symfony/ai-platform version does not accept a Model object in TraceablePlatform::invoke().');
    }

    private function createPlatformWithModelObject(Model $model, ?TokenUsageInterface $tokenUsage = null): TraceablePlatform
    {
        $deferredResult = $this->createDeferredResult($tokenUsage);

        $inner = static::createStub(PlatformInterface::class);
        $inner->method('invoke')->willReturn($deferredResult);

        $platform = new TraceablePlatform($inner);
        $platform->invoke($model, 'test input', []);

        return $platform;
    }
}
