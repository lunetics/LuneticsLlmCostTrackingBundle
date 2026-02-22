<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\DependencyInjection;

use Lunetics\LlmCostTrackingBundle\LuneticsLlmCostTrackingBundle;
use Lunetics\LlmCostTrackingBundle\Service\CostCalculatorInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

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

    #[Test]
    public function itRegistersDynamicPricingProviderWithDefaultTtl(): void
    {
        $container = $this->buildContainer([]);

        self::assertTrue($container->hasDefinition('lunetics_llm_cost_tracking.pricing_provider'));

        $definition = $container->getDefinition('lunetics_llm_cost_tracking.pricing_provider');
        self::assertSame(86400, $definition->getArgument('$ttl'));
    }

    #[Test]
    public function itWiresDynamicPricingToRegistryByDefault(): void
    {
        $container = $this->buildContainer([]);

        $definition = $container->getDefinition('lunetics_llm_cost_tracking.model_registry');
        $dynamicPricingArg = $definition->getArgument('$dynamicPricing');

        self::assertInstanceOf(Reference::class, $dynamicPricingArg);
        self::assertSame('lunetics_llm_cost_tracking.pricing_provider', (string) $dynamicPricingArg);
    }

    #[Test]
    public function itDisablesDynamicPricingWhenConfigured(): void
    {
        $container = $this->buildContainer(['dynamic_pricing' => ['enabled' => false]]);

        $definition = $container->getDefinition('lunetics_llm_cost_tracking.model_registry');
        self::assertNull($definition->getArgument('$dynamicPricing'));

        // The provider and its dependent command must be fully removed so that their
        // unresolvable abstract_args do not break container compilation.
        self::assertFalse($container->hasDefinition('lunetics_llm_cost_tracking.pricing_provider'));
        self::assertFalse($container->hasDefinition('lunetics_llm_cost_tracking.update_pricing_command'));
    }

    #[Test]
    public function itSetsCustomCacheTtl(): void
    {
        $container = $this->buildContainer(['dynamic_pricing' => ['ttl' => 3600]]);

        $definition = $container->getDefinition('lunetics_llm_cost_tracking.pricing_provider');
        self::assertSame(3600, $definition->getArgument('$ttl'));
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
