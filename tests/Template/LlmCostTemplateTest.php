<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\Template;

use Lunetics\LlmCostTrackingBundle\Model\CallRecord;
use Lunetics\LlmCostTrackingBundle\Model\CostSummary;
use Lunetics\LlmCostTrackingBundle\Model\CostThresholds;
use Lunetics\LlmCostTrackingBundle\Model\ModelAggregation;
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
            totals: new CostSummary(1, 1_000, 500, 1_500, 0.00625),
            calls: [new CallRecord('gpt-5', 'GPT-5', 'OpenAI', 1_000, 500, 1_500, 0, 0, 0.00625)],
            byModel: ['gpt-5' => new ModelAggregation('GPT-5', 'OpenAI', 1, 1_000, 500, 1_500, 0.00625)],
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
            totals: new CostSummary(1, 1_000, 500, 1_500, 0.50),
            calls: [new CallRecord('gpt-5', 'GPT-5', 'OpenAI', 1_000, 500, 1_500, 0, 0, 0.50)],
            byModel: ['gpt-5' => new ModelAggregation('GPT-5', 'OpenAI', 1, 1_000, 500, 1_500, 0.50)],
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
            totals: new CostSummary(1, 1_000, 500, 1_500, 0.0),
            calls: [new CallRecord('mystery-model', 'mystery-model', 'Unknown', 1_000, 500, 1_500, 0, 0, 0.0)],
            byModel: ['mystery-model' => new ModelAggregation('mystery-model', 'Unknown', 1, 1_000, 500, 1_500, 0.0)],
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
            totals: new CostSummary(1, 10_000, 2_000, 17_000, 0.1269),
            calls: [new CallRecord('claude-sonnet-4-6', 'Claude Sonnet 4.6', 'Anthropic', 10_000, 2_000, 17_000, 5_000, 3_000, 0.1269)],
            byModel: ['claude-sonnet-4-6' => new ModelAggregation('Claude Sonnet 4.6', 'Anthropic', 1, 10_000, 2_000, 17_000, 0.1269)],
        );

        // Non-zero thinking/cached tokens render as number_format output, not '-'
        self::assertStringContainsString('5,000', $html);
        self::assertStringContainsString('3,000', $html);
    }

    #[Test]
    public function itRendersDashForZeroThinkingAndCachedTokens(): void
    {
        $html = $this->renderTemplate(
            totals: new CostSummary(1, 1_000, 500, 1_500, 0.00625),
            calls: [new CallRecord('gpt-5', 'GPT-5', 'OpenAI', 1_000, 500, 1_500, 0, 0, 0.00625)],
            byModel: ['gpt-5' => new ModelAggregation('GPT-5', 'OpenAI', 1, 1_000, 500, 1_500, 0.00625)],
        );

        // Zero thinking/cached tokens render as '-' in the per-call detail table
        $detailOffset = strpos($html, 'Per-Call Detail');
        self::assertNotFalse($detailOffset);
        self::assertStringContainsString('<td class="text-right">-</td>', substr($html, $detailOffset));
    }

    /**
     * Renders the template with an anonymous collector stub populated from the given data.
     *
     * @param list<CallRecord>                $calls
     * @param array<string, ModelAggregation> $byModel
     * @param list<string>                    $unconfiguredModels
     */
    private function renderTemplate(
        CostSummary $totals = new CostSummary(0, 0, 0, 0, 0.0),
        array $calls = [],
        array $byModel = [],
        array $unconfiguredModels = [],
        CostThresholds $costThresholds = new CostThresholds(0.01, 0.10),
        ?float $budgetWarning = null,
    ): string {
        $collector = new class($totals, $calls, $byModel, $unconfiguredModels, $costThresholds, $budgetWarning) {
            /**
             * @param list<CallRecord>                $calls
             * @param array<string, ModelAggregation> $byModel
             * @param list<string>                    $unconfiguredModels
             */
            public function __construct(
                private readonly CostSummary $totals,
                private readonly array $calls,
                private readonly array $byModel,
                private readonly array $unconfiguredModels,
                private readonly CostThresholds $costThresholds,
                private readonly ?float $budgetWarning,
            ) {
            }

            public function getTotals(): CostSummary
            {
                return $this->totals;
            }

            /** @return list<CallRecord> */
            public function getCalls(): array
            {
                return $this->calls;
            }

            /** @return array<string, ModelAggregation> */
            public function getByModel(): array
            {
                return $this->byModel;
            }

            /** @return list<string> */
            public function getUnconfiguredModels(): array
            {
                return $this->unconfiguredModels;
            }

            public function getCostThresholds(): CostThresholds
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
