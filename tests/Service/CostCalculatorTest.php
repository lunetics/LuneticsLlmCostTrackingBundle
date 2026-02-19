<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\Service;

use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;
use Lunetics\LlmCostTrackingBundle\Service\CostCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CostCalculatorTest extends TestCase
{
    private CostCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new CostCalculator();
    }

    #[Test]
    public function itCalculatesBasicCost(): void
    {
        $model = new ModelDefinition(
            modelId: 'gpt-5',
            displayName: 'GPT-5',
            provider: 'OpenAI',
            inputPricePerMillion: 1.25,
            outputPricePerMillion: 10.00,
        );

        // 1000 input tokens at $1.25/M = $0.00125
        // 500 output tokens at $10.00/M = $0.005
        // Total = $0.00625
        $cost = $this->calculator->calculateCost($model, 1000, 500);

        self::assertSame(0.00625, $cost);
    }

    #[Test]
    public function itReturnsZeroForZeroTokens(): void
    {
        $model = new ModelDefinition('gpt-5', 'GPT-5', 'OpenAI', 1.25, 10.00);

        self::assertSame(0.0, $this->calculator->calculateCost($model, 0, 0));
    }

    #[Test]
    public function itCalculatesCachedTokenCostWithDiscount(): void
    {
        $model = new ModelDefinition(
            modelId: 'claude-sonnet-4-6',
            displayName: 'Claude Sonnet 4.6',
            provider: 'Anthropic',
            inputPricePerMillion: 3.00,
            outputPricePerMillion: 15.00,
            cachedInputPricePerMillion: 0.30,
        );

        // 1000 input tokens, 200 are cached
        // 800 regular input at $3.00/M = $0.0024
        // 200 cached input at $0.30/M = $0.00006
        // 500 output at $15.00/M = $0.0075
        // Total = $0.00996
        $cost = $this->calculator->calculateCost($model, 1000, 500, cachedTokens: 200);

        self::assertSame(0.00996, $cost);
    }

    #[Test]
    public function itCalculatesCachedTokensAtFullPriceWhenNoDiscountConfigured(): void
    {
        $model = new ModelDefinition(
            modelId: 'gpt-5',
            displayName: 'GPT-5',
            provider: 'OpenAI',
            inputPricePerMillion: 1.25,
            outputPricePerMillion: 10.00,
        );

        // Without cached_input_price_per_million, cached tokens use the regular input price
        // 800 regular input at $1.25/M = $0.001
        // 200 cached at $1.25/M = $0.00025
        // 500 output at $10.00/M = $0.005
        // Total = $0.00625
        $cost = $this->calculator->calculateCost($model, 1000, 500, cachedTokens: 200);

        self::assertSame(0.00625, $cost);
    }

    #[Test]
    public function itCalculatesThinkingTokenCost(): void
    {
        $model = new ModelDefinition(
            modelId: 'claude-sonnet-4-6',
            displayName: 'Claude Sonnet 4.6',
            provider: 'Anthropic',
            inputPricePerMillion: 3.00,
            outputPricePerMillion: 15.00,
            thinkingPricePerMillion: 15.00,
        );

        // 1000 input at $3.00/M = $0.003
        // 500 output at $15.00/M = $0.0075
        // 300 thinking at $15.00/M = $0.0045
        // Total = $0.015
        $cost = $this->calculator->calculateCost($model, 1000, 500, thinkingTokens: 300);

        self::assertSame(0.015, $cost);
    }

    #[Test]
    public function itIgnoresThinkingTokensWhenNoPriceConfigured(): void
    {
        $model = new ModelDefinition(
            modelId: 'gpt-5',
            displayName: 'GPT-5',
            provider: 'OpenAI',
            inputPricePerMillion: 1.25,
            outputPricePerMillion: 10.00,
        );

        // Thinking tokens should be ignored when no thinking price is set
        $costWithThinking = $this->calculator->calculateCost($model, 1000, 500, thinkingTokens: 300);
        $costWithout = $this->calculator->calculateCost($model, 1000, 500);

        self::assertSame($costWithout, $costWithThinking);
    }

    #[Test]
    #[DataProvider('provideEdgeCases')]
    public function itHandlesEdgeCases(int $input, int $output, float $expectedCost): void
    {
        $model = new ModelDefinition('test', 'Test', 'Test', 1.00, 1.00);

        $cost = $this->calculator->calculateCost($model, $input, $output);

        self::assertSame($expectedCost, $cost);
    }

    /** @return iterable<string, array{int, int, float}> */
    public static function provideEdgeCases(): iterable
    {
        yield 'zero tokens' => [0, 0, 0.0];
        yield 'one million input tokens' => [1_000_000, 0, 1.0];
        yield 'one million output tokens' => [0, 1_000_000, 1.0];
        yield 'single token each' => [1, 1, 0.000002];
    }
}
