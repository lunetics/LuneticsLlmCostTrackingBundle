<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\Template;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * Smoke tests for the Symfony Profiler Twig template.
 *
 * Renders the template with a lightweight anonymous collector stub and stub
 * WebProfiler parent templates, so no Symfony kernel is required. Using
 * strict_variables=true ensures any undefined variable access in the template
 * fails the test immediately rather than silently producing empty output.
 */
final class LlmCostTemplateTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $stubLoader = new ArrayLoader([
            '@WebProfiler/Profiler/layout.html.twig' => '{% block toolbar %}{% endblock %}{% block menu %}{% endblock %}{% block panel %}{% endblock %}',
            '@WebProfiler/Profiler/toolbar_item.html.twig' => '{% if icon is defined %}{{ icon }}{% endif %}{% if text is defined %}{{ text }}{% endif %}',
        ]);

        $bundleLoader = new FilesystemLoader();
        $bundleLoader->addPath(
            \dirname(__DIR__, 2).'/templates',
            'LuneticsLlmCostTracking',
        );

        $this->twig = new Environment(
            new ChainLoader([$stubLoader, $bundleLoader]),
            ['strict_variables' => true],
        );
    }

    #[Test]
    public function itRendersEmptyStateWithoutError(): void
    {
        $html = $this->renderTemplate();

        self::assertStringContainsString('No LLM calls were made', $html);
        self::assertStringNotContainsString('Per-Model Summary', $html);
    }

    #[Test]
    public function itRendersPanelWithCallData(): void
    {
        $html = $this->renderTemplate(
            totals: ['calls' => 1, 'input_tokens' => 1_000, 'output_tokens' => 500, 'total_tokens' => 1_500, 'cost' => 0.00625],
            calls: [[
                'model' => 'gpt-5', 'display_name' => 'GPT-5', 'provider' => 'OpenAI',
                'input_tokens' => 1_000, 'output_tokens' => 500, 'thinking_tokens' => 0, 'cached_tokens' => 0, 'total_tokens' => 1_500, 'cost' => 0.00625,
            ]],
            byModel: ['gpt-5' => [
                'display_name' => 'GPT-5', 'provider' => 'OpenAI', 'calls' => 1,
                'input_tokens' => 1_000, 'output_tokens' => 500, 'total_tokens' => 1_500, 'cost' => 0.00625,
            ]],
        );

        self::assertStringContainsString('GPT-5', $html);
        self::assertStringContainsString('OpenAI', $html);
        self::assertStringContainsString('Per-Model Summary', $html);
        self::assertStringContainsString('Per-Call Detail', $html);
        self::assertStringNotContainsString('No LLM calls were made', $html);
    }

    #[Test]
    public function itRendersBudgetExceededWarning(): void
    {
        $html = $this->renderTemplate(
            totals: ['calls' => 1, 'input_tokens' => 1_000, 'output_tokens' => 500, 'total_tokens' => 1_500, 'cost' => 0.50],
            calls: [[
                'model' => 'gpt-5', 'display_name' => 'GPT-5', 'provider' => 'OpenAI',
                'input_tokens' => 1_000, 'output_tokens' => 500, 'thinking_tokens' => 0, 'cached_tokens' => 0, 'total_tokens' => 1_500, 'cost' => 0.50,
            ]],
            byModel: ['gpt-5' => [
                'display_name' => 'GPT-5', 'provider' => 'OpenAI', 'calls' => 1,
                'input_tokens' => 1_000, 'output_tokens' => 500, 'total_tokens' => 1_500, 'cost' => 0.50,
            ]],
            budgetWarning: 0.25,
        );

        self::assertStringContainsString('Budget exceeded!', $html);
        // Budget threshold formatted to 4 decimal places per template format string
        self::assertStringContainsString('0.2500', $html);
    }

    #[Test]
    public function itRendersUnconfiguredModelWarning(): void
    {
        $html = $this->renderTemplate(
            totals: ['calls' => 1, 'input_tokens' => 1_000, 'output_tokens' => 500, 'total_tokens' => 1_500, 'cost' => 0.0],
            calls: [[
                'model' => 'mystery-model', 'display_name' => 'mystery-model', 'provider' => 'Unknown',
                'input_tokens' => 1_000, 'output_tokens' => 500, 'thinking_tokens' => 0, 'cached_tokens' => 0, 'total_tokens' => 1_500, 'cost' => 0.0,
            ]],
            byModel: ['mystery-model' => [
                'display_name' => 'mystery-model', 'provider' => 'Unknown', 'calls' => 1,
                'input_tokens' => 1_000, 'output_tokens' => 500, 'total_tokens' => 1_500, 'cost' => 0.0,
            ]],
            unconfiguredModels: ['mystery-model'],
        );

        self::assertStringContainsString('Unconfigured models detected', $html);
        self::assertStringContainsString('mystery-model', $html);
        self::assertStringContainsString('lunetics_llm_cost_tracking.models', $html);
    }

    #[Test]
    public function itRendersThinkingAndCachedTokensAsFormattedNumbers(): void
    {
        $html = $this->renderTemplate(
            totals: ['calls' => 1, 'input_tokens' => 10_000, 'output_tokens' => 2_000, 'total_tokens' => 17_000, 'cost' => 0.1269],
            calls: [[
                'model' => 'claude-sonnet-4-6', 'display_name' => 'Claude Sonnet 4.6', 'provider' => 'Anthropic',
                'input_tokens' => 10_000, 'output_tokens' => 2_000, 'thinking_tokens' => 5_000, 'cached_tokens' => 3_000, 'total_tokens' => 17_000, 'cost' => 0.1269,
            ]],
            byModel: ['claude-sonnet-4-6' => [
                'display_name' => 'Claude Sonnet 4.6', 'provider' => 'Anthropic', 'calls' => 1,
                'input_tokens' => 10_000, 'output_tokens' => 2_000, 'total_tokens' => 17_000, 'cost' => 0.1269,
            ]],
        );

        // Non-zero thinking/cached tokens render as number_format output, not '-'
        self::assertStringContainsString('5,000', $html);
        self::assertStringContainsString('3,000', $html);
    }

    #[Test]
    public function itRendersDashForZeroThinkingAndCachedTokens(): void
    {
        $html = $this->renderTemplate(
            totals: ['calls' => 1, 'input_tokens' => 1_000, 'output_tokens' => 500, 'total_tokens' => 1_500, 'cost' => 0.00625],
            calls: [[
                'model' => 'gpt-5', 'display_name' => 'GPT-5', 'provider' => 'OpenAI',
                'input_tokens' => 1_000, 'output_tokens' => 500, 'thinking_tokens' => 0, 'cached_tokens' => 0, 'total_tokens' => 1_500, 'cost' => 0.00625,
            ]],
            byModel: ['gpt-5' => [
                'display_name' => 'GPT-5', 'provider' => 'OpenAI', 'calls' => 1,
                'input_tokens' => 1_000, 'output_tokens' => 500, 'total_tokens' => 1_500, 'cost' => 0.00625,
            ]],
        );

        // Zero thinking/cached tokens render as '-' in the per-call detail table
        $detailOffset = strpos($html, 'Per-Call Detail');
        self::assertNotFalse($detailOffset);
        self::assertStringContainsString('<td class="text-right">-</td>', substr($html, $detailOffset));
    }

    /**
     * Renders the template with an anonymous collector stub populated from the given data.
     *
     * @param array{calls: int, input_tokens: int, output_tokens: int, total_tokens: int, cost: float} $totals
     * @param list<array<string, mixed>>                                                               $calls
     * @param array<string, array<string, mixed>>                                                      $byModel
     * @param list<string>                                                                             $unconfiguredModels
     * @param array{low: float, medium: float}                                                         $costThresholds
     */
    private function renderTemplate(
        array $totals = ['calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'cost' => 0.0],
        array $calls = [],
        array $byModel = [],
        array $unconfiguredModels = [],
        array $costThresholds = ['low' => 0.01, 'medium' => 0.10],
        ?float $budgetWarning = null,
    ): string {
        $collector = new class($totals, $calls, $byModel, $unconfiguredModels, $costThresholds, $budgetWarning) {
            /**
             * @param array{calls: int, input_tokens: int, output_tokens: int, total_tokens: int, cost: float} $totals
             * @param list<array<string, mixed>>                                                               $calls
             * @param array<string, array<string, mixed>>                                                      $byModel
             * @param list<string>                                                                             $unconfiguredModels
             * @param array{low: float, medium: float}                                                         $costThresholds
             */
            public function __construct(
                private readonly array $totals,
                private readonly array $calls,
                private readonly array $byModel,
                private readonly array $unconfiguredModels,
                private readonly array $costThresholds,
                private readonly ?float $budgetWarning,
            ) {
            }

            /** @return array{calls: int, input_tokens: int, output_tokens: int, total_tokens: int, cost: float} */
            public function getTotals(): array
            {
                return $this->totals;
            }

            /** @return list<array<string, mixed>> */
            public function getCalls(): array
            {
                return $this->calls;
            }

            /** @return array<string, array<string, mixed>> */
            public function getByModel(): array
            {
                return $this->byModel;
            }

            /** @return list<string> */
            public function getUnconfiguredModels(): array
            {
                return $this->unconfiguredModels;
            }

            /** @return array{low: float, medium: float} */
            public function getCostThresholds(): array
            {
                return $this->costThresholds;
            }

            public function getBudgetWarning(): ?float
            {
                return $this->budgetWarning;
            }
        };

        return $this->twig->render(
            '@LuneticsLlmCostTracking/data_collector/llm_cost.html.twig',
            ['collector' => $collector],
        );
    }
}
