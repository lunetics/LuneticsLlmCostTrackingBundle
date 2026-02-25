<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\DataCollector;

use Lunetics\LlmCostTrackingBundle\DataCollector\LlmCostCollector;
use Lunetics\LlmCostTrackingBundle\Service\CostTrackerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LlmCostCollectorTest extends TestCase
{
    #[Test]
    public function itReturnsEmptyDefaultsBeforeLateCollect(): void
    {
        $collector = $this->createCollector();

        $collector->collect(
            static::createStub(\Symfony\Component\HttpFoundation\Request::class),
            static::createStub(\Symfony\Component\HttpFoundation\Response::class),
        );

        self::assertSame([], $collector->getCalls());
        self::assertSame([], $collector->getByModel());
        self::assertSame([], $collector->getUnconfiguredModels());
        self::assertSame(
            ['calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'cost' => 0.0],
            $collector->getTotals(),
        );
    }

    #[Test]
    public function itDelegatesToCostTracker(): void
    {
        $expectedCalls = [
            ['model' => 'gpt-5', 'display_name' => 'GPT-5', 'provider' => 'OpenAI', 'input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150, 'thinking_tokens' => 0, 'cached_tokens' => 0, 'cost' => 0.001],
        ];
        $expectedTotals = ['calls' => 1, 'input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150, 'cost' => 0.001];
        $expectedByModel = ['gpt-5' => ['display_name' => 'GPT-5', 'provider' => 'OpenAI', 'calls' => 1, 'input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150, 'cost' => 0.001]];
        $expectedUnconfigured = ['some-model'];

        $costTracker = $this->createMock(CostTrackerInterface::class);
        $costTracker->method('getSnapshot')->willReturn([
            'calls' => $expectedCalls,
            'by_model' => $expectedByModel,
            'totals' => $expectedTotals,
            'unconfigured_models' => $expectedUnconfigured,
        ]);

        $collector = $this->createCollector($costTracker);
        $collector->lateCollect();

        self::assertSame($expectedCalls, $collector->getCalls());
        self::assertSame($expectedTotals, $collector->getTotals());
        self::assertSame($expectedByModel, $collector->getByModel());
        self::assertSame($expectedUnconfigured, $collector->getUnconfiguredModels());
    }

    #[Test]
    public function itReturnsConfiguredThresholds(): void
    {
        $collector = $this->createCollector(costThresholds: ['low' => 0.05, 'medium' => 0.50]);
        $collector->lateCollect();

        self::assertSame(['low' => 0.05, 'medium' => 0.50], $collector->getCostThresholds());
    }

    #[Test]
    public function itReturnsBudgetWarning(): void
    {
        $collector = $this->createCollector(budgetWarning: 1.50);
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

    /**
     * @param array{low: float, medium: float} $costThresholds
     */
    private function createCollector(
        ?CostTrackerInterface $costTracker = null,
        array $costThresholds = ['low' => 0.01, 'medium' => 0.10],
        ?float $budgetWarning = null,
    ): LlmCostCollector {
        if (null === $costTracker) {
            $costTracker = static::createStub(CostTrackerInterface::class);
            $costTracker->method('getSnapshot')->willReturn([
                'calls' => [],
                'by_model' => [],
                'totals' => ['calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'cost' => 0.0],
                'unconfigured_models' => [],
            ]);
        }

        return new LlmCostCollector(
            $costTracker,
            $costThresholds,
            $budgetWarning,
        );
    }
}
