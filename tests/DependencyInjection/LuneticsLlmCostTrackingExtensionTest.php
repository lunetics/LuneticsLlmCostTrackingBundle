<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\DependencyInjection;

use Lunetics\LlmCostTrackingBundle\LuneticsLlmCostTrackingBundle;
use Lunetics\LlmCostTrackingBundle\Model\CostThresholds;
use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;
use Lunetics\LlmCostTrackingBundle\Model\ModelRegistryInterface;
use Lunetics\LlmCostTrackingBundle\Service\CostCalculatorInterface;
use Lunetics\LlmCostTrackingBundle\Service\CostTrackerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class LuneticsLlmCostTrackingExtensionTest extends TestCase
{
    #[Test]
    public function itRegistersServicesWithDefaultConfig(): void
    {
        $container = $this->buildContainer([]);

        self::assertTrue($container->hasDefinition('lunetics_llm_cost_tracking.model_registry'));
        self::assertTrue($container->hasDefinition('lunetics_llm_cost_tracking.cost_calculator'));
        self::assertTrue($container->hasDefinition('lunetics_llm_cost_tracking.cost_tracker'));
        self::assertTrue($container->hasDefinition('lunetics_llm_cost_tracking.data_collector'));
        self::assertTrue($container->hasAlias(ModelRegistryInterface::class));
        self::assertTrue($container->hasAlias(CostCalculatorInterface::class));
        self::assertTrue($container->hasAlias(CostTrackerInterface::class));
    }

    #[Test]
    public function itRegistersNoServicesWhenDisabled(): void
    {
        $container = $this->buildContainer(['enabled' => false]);

        self::assertFalse($container->hasDefinition('lunetics_llm_cost_tracking.model_registry'));
        self::assertFalse($container->hasDefinition('lunetics_llm_cost_tracking.cost_tracker'));
        self::assertFalse($container->hasDefinition('lunetics_llm_cost_tracking.cost_calculator'));
        self::assertFalse($container->hasDefinition('lunetics_llm_cost_tracking.data_collector'));
        self::assertFalse($container->hasAlias(ModelRegistryInterface::class));
    }

    #[Test]
    public function itSetsCostThresholds(): void
    {
        $container = $this->buildContainer([
            'cost_thresholds' => ['low' => 0.05, 'medium' => 0.50],
        ]);

        $definition = $container->getDefinition('lunetics_llm_cost_tracking.data_collector');

        $thresholds = $definition->getArgument('$costThresholds');
        self::assertInstanceOf(Definition::class, $thresholds);
        self::assertSame(CostThresholds::class, $thresholds->getClass());
        self::assertSame(0.05, $thresholds->getArgument('$low'));
        self::assertSame(0.50, $thresholds->getArgument('$medium'));
    }

    #[Test]
    public function itSetsBudgetWarning(): void
    {
        $container = $this->buildContainer(['budget_warning' => 1.50]);

        $definition = $container->getDefinition('lunetics_llm_cost_tracking.data_collector');

        self::assertSame(1.50, $definition->getArgument('$budgetWarning'));
    }

    #[Test]
    public function itRegistersUserDefinedModels(): void
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

        // User model is registered
        self::assertArrayHasKey('custom-model', $models);

        // No bundled defaults — all coverage comes from the pricing provider at runtime
        self::assertArrayNotHasKey('gpt-5', $models);
        self::assertArrayNotHasKey('claude-sonnet-4-6', $models);
    }

    #[Test]
    public function itWiresUserDefinedModelsToRegistry(): void
    {
        $container = $this->buildContainer([
            'models' => [
                'my-model' => [
                    'display_name' => 'My Model',
                    'provider' => 'MyProvider',
                    'input_price_per_million' => 0.50,
                    'output_price_per_million' => 5.00,
                ],
            ],
        ]);

        $definition = $container->getDefinition('lunetics_llm_cost_tracking.model_registry');
        $models = $definition->getArgument('$models');

        $modelDef = $models['my-model'];
        self::assertInstanceOf(Definition::class, $modelDef);
        self::assertSame(ModelDefinition::class, $modelDef->getClass());
        self::assertSame('My Model', $modelDef->getArgument('$displayName'));
        self::assertSame(0.50, $modelDef->getArgument('$inputPricePerMillion'));
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
    public function itAlwaysRegistersSnapshotProvider(): void
    {
        // Present when dynamic pricing is enabled (default)
        $containerEnabled = $this->buildContainer([]);
        self::assertTrue($containerEnabled->hasDefinition('lunetics_llm_cost_tracking.snapshot_provider'));

        // Also present when dynamic pricing is disabled
        $containerDisabled = $this->buildContainer(['dynamic_pricing' => ['enabled' => false]]);
        self::assertTrue($containerDisabled->hasDefinition('lunetics_llm_cost_tracking.snapshot_provider'));
    }

    #[Test]
    public function itRegistersChainProviderWhenDynamicPricingEnabled(): void
    {
        $container = $this->buildContainer([]);

        self::assertTrue($container->hasDefinition('lunetics_llm_cost_tracking.chain_provider'));
    }

    #[Test]
    public function itWiresDynamicPricingToRegistryByDefault(): void
    {
        $container = $this->buildContainer([]);

        $definition = $container->getDefinition('lunetics_llm_cost_tracking.model_registry');
        $pricingProviderArg = $definition->getArgument('$pricingProvider');

        self::assertInstanceOf(Reference::class, $pricingProviderArg);
        self::assertSame('lunetics_llm_cost_tracking.chain_provider', (string) $pricingProviderArg);
    }

    #[Test]
    public function itDisablesDynamicPricingWhenConfigured(): void
    {
        $container = $this->buildContainer(['dynamic_pricing' => ['enabled' => false]]);

        $definition = $container->getDefinition('lunetics_llm_cost_tracking.model_registry');
        $pricingProviderArg = $definition->getArgument('$pricingProvider');

        // Snapshot is wired as the sole provider when dynamic pricing is disabled
        self::assertInstanceOf(Reference::class, $pricingProviderArg);
        self::assertSame('lunetics_llm_cost_tracking.snapshot_provider', (string) $pricingProviderArg);

        // The live API provider, chain provider, and update command must be fully absent
        self::assertFalse($container->hasDefinition('lunetics_llm_cost_tracking.pricing_provider'));
        self::assertFalse($container->hasDefinition('lunetics_llm_cost_tracking.chain_provider'));
        self::assertFalse($container->hasDefinition('lunetics_llm_cost_tracking.update_pricing_command'));
    }

    #[Test]
    public function itLoggingIsDisabledByDefault(): void
    {
        $container = $this->buildContainer([]);

        self::assertFalse($container->hasDefinition('lunetics_llm_cost_tracking.cost_logger_listener'));
    }

    #[Test]
    public function itRegistersLoggingListenerWhenExplicitlyEnabled(): void
    {
        $container = $this->buildContainer(['logging' => ['enabled' => true]]);

        self::assertTrue($container->hasDefinition('lunetics_llm_cost_tracking.cost_logger_listener'));

        $definition = $container->getDefinition('lunetics_llm_cost_tracking.cost_logger_listener');

        $monologTags = $definition->getTag('monolog.logger');
        self::assertCount(1, $monologTags);
        self::assertSame('ai', $monologTags[0]['channel']);

        $eventTags = $definition->getTag('kernel.event_listener');
        self::assertCount(2, $eventTags);
        $registeredEvents = array_column($eventTags, 'event');
        self::assertContains('kernel.terminate', $registeredEvents);
        self::assertContains('console.terminate', $registeredEvents);
    }

    #[Test]
    public function itRemovesLoggingListenerWhenDisabled(): void
    {
        $container = $this->buildContainer(['logging' => ['enabled' => false]]);

        self::assertFalse($container->hasDefinition('lunetics_llm_cost_tracking.cost_logger_listener'));
    }

    #[Test]
    public function itAppliesCustomLoggingChannel(): void
    {
        $container = $this->buildContainer(['logging' => ['enabled' => true, 'channel' => 'llm']]);

        $definition = $container->getDefinition('lunetics_llm_cost_tracking.cost_logger_listener');

        $monologTags = $definition->getTag('monolog.logger');
        self::assertCount(1, $monologTags);
        self::assertSame('llm', $monologTags[0]['channel']);
    }

    #[Test]
    public function itThrowsExceptionWhenLoggingChannelIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "lunetics_llm_cost_tracking.logging.channel" value cannot be empty when logging is enabled.');

        $this->buildContainer(['logging' => ['enabled' => true, 'channel' => '']]);
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
