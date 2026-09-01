<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\Template;

use Lunetics\LlmCostTrackingBundle\Model\CallRecord;
use Lunetics\LlmCostTrackingBundle\Model\CostSummary;
use Lunetics\LlmCostTrackingBundle\Model\CostThresholds;
use Lunetics\LlmCostTrackingBundle\Model\ModelAggregation;
use Lunetics\LlmCostTrackingBundle\Model\SkippedCall;
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
        // Genuinely no calls at all: the toolbar/menu must stay silent too.
        self::assertStringNotContainsString('sf-toolbar-value', $html);
    }

    #[Test]
    public function itRendersTheToolbarIconWhenAllCallsAreSkipped(): void
    {
        // The exact case this feature exists for: totals.calls stays 0 (skipped
        // calls never increment it), but the toolbar must still show an icon —
        // otherwise the profiler's primary entry point gives zero signal.
        $html = $this->renderTemplate(
            totals: new CostSummary(0, 0, 0, 0, 0.0),
            skippedCalls: [new SkippedCall('broken-model', \RuntimeException::class, 'boom')],
        );

        self::assertStringContainsString('sf-toolbar-value', $html);
        self::assertStringContainsString('1 skipped', $html);
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
    public function itRendersSkippedCallsNotice(): void
    {
        $html = $this->renderTemplate(
            totals: new CostSummary(1, 1_000, 500, 1_500, 0.00625),
            calls: [new CallRecord('gpt-5', 'GPT-5', 'OpenAI', 1_000, 500, 1_500, 0, 0, 0.00625)],
            byModel: ['gpt-5' => new ModelAggregation('GPT-5', 'OpenAI', 1, 1_000, 500, 1_500, 0.00625)],
            skippedCalls: [new SkippedCall('broken-model', \RuntimeException::class, 'registry lookup exploded')],
        );

        self::assertStringContainsString('1 call skipped', $html);
        self::assertStringContainsString('broken-model', $html);
        self::assertStringContainsString('RuntimeException', $html);
        self::assertStringContainsString('registry lookup exploded', $html);
        self::assertStringContainsString('Skipped Calls', $html);
    }

    #[Test]
    public function itRendersSkippedCallsNoticeEvenWhenAllCallsAreSkipped(): void
    {
        $html = $this->renderTemplate(
            totals: new CostSummary(0, 0, 0, 0, 0.0),
            skippedCalls: [new SkippedCall(null, \RuntimeException::class, 'boom')],
        );

        // The worst case this feature exists for: every call in the request was
        // skipped, so totals.calls is 0 — the empty-state message must not hide it.
        self::assertStringNotContainsString('No LLM calls were made', $html);
        self::assertStringContainsString('1 call skipped', $html);
        self::assertStringContainsString('unknown', $html);
    }

    #[Test]
    public function itCapsTheSkippedCallsListAtTenWithATailCount(): void
    {
        $skippedCalls = [];
        for ($i = 0; $i < 13; ++$i) {
            $skippedCalls[] = new SkippedCall("model-{$i}", \RuntimeException::class, "failure {$i}");
        }

        $html = $this->renderTemplate(
            totals: new CostSummary(0, 0, 0, 0, 0.0),
            skippedCalls: $skippedCalls,
        );

        self::assertStringContainsString('13 calls skipped', $html);
        self::assertStringContainsString('model-0', $html);
        self::assertStringContainsString('model-9', $html);
        self::assertStringNotContainsString('model-10', $html);
        self::assertStringContainsString('and 3 more', $html);
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
     * @param list<SkippedCall>               $skippedCalls
     */
    private function renderTemplate(
        CostSummary $totals = new CostSummary(0, 0, 0, 0, 0.0),
        array $calls = [],
        array $byModel = [],
        array $unconfiguredModels = [],
        array $skippedCalls = [],
        CostThresholds $costThresholds = new CostThresholds(0.01, 0.10),
        ?float $budgetWarning = null,
    ): string {
        $collector = new class($totals, $calls, $byModel, $unconfiguredModels, $skippedCalls, $costThresholds, $budgetWarning) {
            /**
             * @param list<CallRecord>                $calls
             * @param array<string, ModelAggregation> $byModel
             * @param list<string>                    $unconfiguredModels
             * @param list<SkippedCall>               $skippedCalls
             */
            public function __construct(
                private readonly CostSummary $totals,
                private readonly array $calls,
                private readonly array $byModel,
                private readonly array $unconfiguredModels,
                private readonly array $skippedCalls,
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

            /** @return list<SkippedCall> */
            public function getSkippedCalls(): array
            {
                return $this->skippedCalls;
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
