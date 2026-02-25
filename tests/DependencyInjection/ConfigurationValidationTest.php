<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\DependencyInjection;

use Lunetics\LlmCostTrackingBundle\LuneticsLlmCostTrackingBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ConfigurationValidationTest extends TestCase
{
    #[Test]
    public function itRejectsLowThresholdGreaterThanMedium(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->buildContainer([
            'cost_thresholds' => ['low' => 1.00, 'medium' => 0.10],
        ]);
    }

    #[Test]
    public function itRejectsNegativeModelPrices(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->buildContainer([
            'models' => [
                'test-model' => [
                    'display_name' => 'Test',
                    'provider' => 'Test',
                    'input_price_per_million' => -1.00,
                    'output_price_per_million' => 5.00,
                ],
            ],
        ]);
    }

    #[Test]
    public function itRejectsNegativeBudgetWarning(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->buildContainer([
            'budget_warning' => -0.50,
        ]);
    }

    #[Test]
    public function itAcceptsValidConfiguration(): void
    {
        $container = $this->buildContainer([
            'budget_warning' => 1.00,
            'cost_thresholds' => ['low' => 0.05, 'medium' => 0.50],
            'models' => [
                'test-model' => [
                    'display_name' => 'Test Model',
                    'provider' => 'TestProvider',
                    'input_price_per_million' => 1.00,
                    'output_price_per_million' => 5.00,
                ],
            ],
        ]);

        self::assertTrue($container->hasDefinition('lunetics_llm_cost_tracking.model_registry'));
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
