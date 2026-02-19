<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\DependencyInjection;

use Lunetics\LlmCostTrackingBundle\LuneticsLlmCostTrackingBundle;
use Lunetics\LlmCostTrackingBundle\Service\CostCalculatorInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class LuneticsLlmCostTrackingExtensionTest extends TestCase
{
    #[Test]
    public function itRegistersServicesWithDefaultConfig(): void
    {
        $container = $this->buildContainer([]);

        self::assertTrue($container->hasDefinition('lunetics_llm_cost_tracking.model_registry'));
        self::assertTrue($container->hasDefinition('lunetics_llm_cost_tracking.cost_calculator'));
        self::assertTrue($container->hasDefinition('lunetics_llm_cost_tracking.data_collector'));
        self::assertTrue($container->hasAlias(CostCalculatorInterface::class));
    }

    #[Test]
    public function itRegistersNoServicesWhenDisabled(): void
    {
        $container = $this->buildContainer(['enabled' => false]);

        self::assertFalse($container->hasDefinition('lunetics_llm_cost_tracking.model_registry'));
        self::assertFalse($container->hasDefinition('lunetics_llm_cost_tracking.cost_calculator'));
        self::assertFalse($container->hasDefinition('lunetics_llm_cost_tracking.data_collector'));
    }

    #[Test]
    public function itSetsCurrencyOnDataCollector(): void
    {
        $container = $this->buildContainer(['currency' => 'EUR']);

        $definition = $container->getDefinition('lunetics_llm_cost_tracking.data_collector');

        self::assertSame('EUR', $definition->getArgument('$currency'));
    }

    #[Test]
    public function itSetsCostThresholds(): void
    {
        $container = $this->buildContainer([
            'cost_thresholds' => ['low' => 0.05, 'medium' => 0.50],
        ]);

        $definition = $container->getDefinition('lunetics_llm_cost_tracking.data_collector');

        self::assertSame(['low' => 0.05, 'medium' => 0.50], $definition->getArgument('$costThresholds'));
    }

    #[Test]
    public function itSetsBudgetWarning(): void
    {
        $container = $this->buildContainer(['budget_warning' => 1.50]);

        $definition = $container->getDefinition('lunetics_llm_cost_tracking.data_collector');

        self::assertSame(1.50, $definition->getArgument('$budgetWarning'));
    }

    #[Test]
    public function itMergesUserModelsWithDefaults(): void
    {
        $container = $this->buildContainer([
            'models' => [
                'custom-model' => [
                    'display_name' => 'Custom Model',
                    'provider' => 'Custom',
                    'input_price_per_million' => 5.00,
                    'output_price_per_million' => 20.00,
                ],
            ],
        ]);

        $definition = $container->getDefinition('lunetics_llm_cost_tracking.model_registry');
        $models = $definition->getArgument('$models');

        // Default models should be present
        self::assertArrayHasKey('gpt-5', $models);
        self::assertArrayHasKey('claude-sonnet-4-6', $models);

        // User model should be merged in
        self::assertArrayHasKey('custom-model', $models);
    }

    #[Test]
    public function itAllowsOverridingDefaultModelPricing(): void
    {
        $container = $this->buildContainer([
            'models' => [
                'gpt-5' => [
                    'display_name' => 'GPT-5 (discounted)',
                    'provider' => 'OpenAI',
                    'input_price_per_million' => 0.50,
                    'output_price_per_million' => 5.00,
                ],
            ],
        ]);

        $definition = $container->getDefinition('lunetics_llm_cost_tracking.model_registry');
        $models = $definition->getArgument('$models');

        self::assertSame('GPT-5 (discounted)', $models['gpt-5']->displayName);
        self::assertSame(0.50, $models['gpt-5']->inputPricePerMillion);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildContainer(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $bundle = new LuneticsLlmCostTrackingBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([$config], $container);

        return $container;
    }
}
