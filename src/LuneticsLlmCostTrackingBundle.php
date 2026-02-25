<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle;

use Lunetics\LlmCostTrackingBundle\Command\UpdatePricingCommand;
use Lunetics\LlmCostTrackingBundle\Model\CostThresholds;
use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;
use Lunetics\LlmCostTrackingBundle\Pricing\ChainPricingProvider;
use Lunetics\LlmCostTrackingBundle\Pricing\ModelsDevPricingProvider;
use Lunetics\LlmCostTrackingBundle\Pricing\SnapshotPricingProvider;
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

        $services = $container->services();

        // The snapshot is always registered as an unconditional baseline —
        // it provides bundled model coverage regardless of dynamic_pricing setting.
        $services->set('lunetics_llm_cost_tracking.snapshot_provider', SnapshotPricingProvider::class)
            ->arg('$snapshotPath', \dirname(__DIR__).'/resources/pricing_snapshot.json')
            ->arg('$logger', service('logger')->nullOnInvalid());

        if (true === $config['dynamic_pricing']['enabled']) {
            // Live API provider
            $services->set('lunetics_llm_cost_tracking.pricing_provider', ModelsDevPricingProvider::class)
                ->arg('$httpClient', service('http_client'))
                ->arg('$cache', service('cache.app'))
                ->arg('$ttl', $config['dynamic_pricing']['ttl'])
                ->arg('$logger', service('logger')->nullOnInvalid());

            // Chain: live API first, snapshot fills gaps
            $services->set('lunetics_llm_cost_tracking.chain_provider', ChainPricingProvider::class)
                ->arg('$providers', [
                    service('lunetics_llm_cost_tracking.pricing_provider'),
                    service('lunetics_llm_cost_tracking.snapshot_provider'),
                ]);

            // Command wired to live provider directly (needs fetchLive())
            $services->set('lunetics_llm_cost_tracking.update_pricing_command', UpdatePricingCommand::class)
                ->arg('$pricingProvider', service('lunetics_llm_cost_tracking.pricing_provider'))
                ->tag('console.command');

            $builder->getDefinition('lunetics_llm_cost_tracking.model_registry')
                ->replaceArgument('$pricingProvider', new Reference('lunetics_llm_cost_tracking.chain_provider'));
        } else {
            // Snapshot is the sole provider when dynamic pricing is disabled
            $builder->getDefinition('lunetics_llm_cost_tracking.model_registry')
                ->replaceArgument('$pricingProvider', new Reference('lunetics_llm_cost_tracking.snapshot_provider'));
        }

        if (true === $config['logging']['enabled']) {
            $channel = $config['logging']['channel'];
            $builder->getDefinition('lunetics_llm_cost_tracking.cost_logger_listener')
                ->addTag('monolog.logger', ['channel' => $channel]);
        } else {
            $builder->removeDefinition('lunetics_llm_cost_tracking.cost_logger_listener');
        }

        $builder->getDefinition('lunetics_llm_cost_tracking.data_collector')
            ->replaceArgument('$costThresholds', (new Definition(CostThresholds::class))
                ->setArgument('$low', $config['cost_thresholds']['low'])
                ->setArgument('$medium', $config['cost_thresholds']['medium']))
            ->replaceArgument('$budgetWarning', $config['budget_warning']);
    }

    /**
     * Builds DI Definition objects for user-configured models.
     *
     * Returns DI Definition objects (not plain PHP instances) so that the
     * Symfony container compiler can serialize them to XML without hitting
     * the "parameter is an object" restriction in XmlDumper (dev mode).
     *
     * Bundled model coverage is always provided by SnapshotPricingProvider, which reads
     * resources/pricing_snapshot.json. When dynamic pricing is enabled, ChainPricingProvider
     * merges live API data (first-wins) with the snapshot baseline.
     *
     * @param array<string, array<string, mixed>> $userModels
     *
     * @return array<string, Definition>
     */
    private function buildModelDefinitions(array $userModels): array
    {
        $definitions = [];
        foreach ($userModels as $modelId => $data) {
            $definitions[$modelId] = (new Definition(ModelDefinition::class))
                ->setArgument('$modelId', $modelId)
                ->setArgument('$displayName', $data['display_name'])
                ->setArgument('$provider', $data['provider'])
                ->setArgument('$inputPricePerMillion', (float) $data['input_price_per_million'])
                ->setArgument('$outputPricePerMillion', (float) $data['output_price_per_million'])
                ->setArgument('$cachedInputPricePerMillion', isset($data['cached_input_price_per_million']) ? (float) $data['cached_input_price_per_million'] : null)
                ->setArgument('$thinkingPricePerMillion', isset($data['thinking_price_per_million']) ? (float) $data['thinking_price_per_million'] : null);
        }

        return $definitions;
    }
}
