<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle;

use Lunetics\LlmCostTrackingBundle\Command\UpdatePricingCommand;
use Lunetics\LlmCostTrackingBundle\Model\CostThresholds;
use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;
use Lunetics\LlmCostTrackingBundle\Pricing\ModelsDevPricingProvider;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class LuneticsLlmCostTrackingBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->import('../config/definition.php');
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (true !== $config['enabled']) {
            return;
        }

        $container->import('../config/services.php');

        $models = $this->buildModelDefinitions($config['models'] ?? []);

        $builder->getDefinition('lunetics_llm_cost_tracking.model_registry')
            ->replaceArgument('$models', $models);

        if (true === $config['dynamic_pricing']['enabled']) {
            $services = $container->services();

            $services->set('lunetics_llm_cost_tracking.pricing_provider', ModelsDevPricingProvider::class)
                ->arg('$httpClient', service('http_client'))
                ->arg('$cache', service('cache.app'))
                ->arg('$ttl', $config['dynamic_pricing']['ttl'])
                ->arg('$logger', service('logger')->nullOnInvalid());

            $services->set('lunetics_llm_cost_tracking.update_pricing_command', UpdatePricingCommand::class)
                ->arg('$pricingProvider', service('lunetics_llm_cost_tracking.pricing_provider'))
                ->tag('console.command');

            $builder->getDefinition('lunetics_llm_cost_tracking.model_registry')
                ->replaceArgument('$dynamicPricing', new Reference('lunetics_llm_cost_tracking.pricing_provider'));
        }

        $builder->getDefinition('lunetics_llm_cost_tracking.data_collector')
            ->replaceArgument('$costThresholds', (new Definition(CostThresholds::class))
                ->setArguments([$config['cost_thresholds']['low'], $config['cost_thresholds']['medium']]))
            ->replaceArgument('$budgetWarning', $config['budget_warning']);
    }

    /**
     * Merges default models with user-configured models.
     * User config takes precedence over defaults.
     *
     * Returns DI Definition objects (not plain PHP instances) so that the
     * Symfony container compiler can serialize them to XML without hitting
     * the "parameter is an object" restriction in XmlDumper (dev mode).
     *
     * @param array<string, array<string, mixed>> $userModels
     *
     * @return array<string, Definition>
     */
    private function buildModelDefinitions(array $userModels): array
    {
        /** @var array<string, array<string, mixed>> $defaults */
        $defaults = require \dirname(__DIR__).'/config/default_models.php';

        $merged = array_replace($defaults, $userModels);

        $definitions = [];
        foreach ($merged as $modelId => $data) {
            $definitions[$modelId] = (new Definition(ModelDefinition::class))
                ->setArguments([
                    $modelId,
                    $data['display_name'],
                    $data['provider'],
                    (float) $data['input_price_per_million'],
                    (float) $data['output_price_per_million'],
                    isset($data['cached_input_price_per_million']) ? (float) $data['cached_input_price_per_million'] : null,
                    isset($data['thinking_price_per_million']) ? (float) $data['thinking_price_per_million'] : null,
                ]);
        }

        return $definitions;
    }
}
