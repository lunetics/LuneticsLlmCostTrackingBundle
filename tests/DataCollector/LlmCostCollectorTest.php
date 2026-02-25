<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\DataCollector;

use Lunetics\LlmCostTrackingBundle\DataCollector\LlmCostCollector;
use Lunetics\LlmCostTrackingBundle\Model\CallRecord;
use Lunetics\LlmCostTrackingBundle\Model\CostSnapshot;
use Lunetics\LlmCostTrackingBundle\Model\CostSummary;
use Lunetics\LlmCostTrackingBundle\Model\CostThresholds;
use Lunetics\LlmCostTrackingBundle\Model\ModelAggregation;
use Lunetics\LlmCostTrackingBundle\Service\CostTrackerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LlmCostCollectorTest extends TestCase
{
    #[Test]
    public function itReturnsEmptyDefaultsBeforeLateCollect(): void
    {
        $collector = $this->createCollector();

        $collector->collect(
            static::createStub(Request::class),
            static::createStub(Response::class),
        );

        self::assertSame([], $collector->getCalls());
        self::assertSame([], $collector->getByModel());
        self::assertSame([], $collector->getUnconfiguredModels());

        $totals = $collector->getTotals();
        self::assertSame(0, $totals->calls);
        self::assertSame(0, $totals->inputTokens);
        self::assertSame(0, $totals->outputTokens);
        self::assertSame(0, $totals->totalTokens);
        self::assertSame(0.0, $totals->cost);
    }

    #[Test]
    public function itDelegatesToCostTracker(): void
    {
        $expectedCall = new CallRecord(
            model: 'gpt-5',
            displayName: 'GPT-5',
            provider: 'OpenAI',
            inputTokens: 100,
            outputTokens: 50,
            totalTokens: 150,
            thinkingTokens: 0,
            cachedTokens: 0,
            cost: 0.001,
        );
        $expectedTotals = new CostSummary(
            calls: 1,
            inputTokens: 100,
            outputTokens: 50,
            totalTokens: 150,
            cost: 0.001,
        );
        $expectedByModel = [
            'gpt-5' => new ModelAggregation(
                displayName: 'GPT-5',
                provider: 'OpenAI',
                calls: 1,
                inputTokens: 100,
                outputTokens: 50,
                totalTokens: 150,
                cost: 0.001,
            ),
        ];
        $expectedUnconfigured = ['some-model'];

        $snapshot = new CostSnapshot(
            calls: [$expectedCall],
            byModel: $expectedByModel,
            totals: $expectedTotals,
            unconfiguredModels: $expectedUnconfigured,
        );

        $costTracker = $this->createMock(CostTrackerInterface::class);
        $costTracker->method('getSnapshot')->willReturn($snapshot);

        $collector = $this->createCollector($costTracker);
        $collector->lateCollect();

        self::assertSame([$expectedCall], $collector->getCalls());
        self::assertSame($expectedTotals, $collector->getTotals());
        self::assertSame($expectedByModel, $collector->getByModel());
        self::assertSame($expectedUnconfigured, $collector->getUnconfiguredModels());
    }

    #[Test]
    public function itReturnsConfiguredThresholds(): void
    {
        $collector = $this->createCollector(costThresholds: new CostThresholds(0.05, 0.50));
        $collector->lateCollect();

        $thresholds = $collector->getCostThresholds();
        self::assertSame(0.05, $thresholds->low);
        self::assertSame(0.50, $thresholds->medium);
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

    private function createCollector(
        ?CostTrackerInterface $costTracker = null,
        CostThresholds $costThresholds = new CostThresholds(0.01, 0.10),
        ?float $budgetWarning = null,
    ): LlmCostCollector {
        if (null === $costTracker) {
            $costTracker = static::createStub(CostTrackerInterface::class);
            $costTracker->method('getSnapshot')->willReturn(new CostSnapshot(
                calls: [],
                byModel: [],
                totals: new CostSummary(0, 0, 0, 0, 0.0),
                unconfiguredModels: [],
            ));
        }

        return new LlmCostCollector(
            $costTracker,
            $costThresholds,
            $budgetWarning,
        );
    }
}
