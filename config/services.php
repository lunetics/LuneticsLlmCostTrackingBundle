<?php

declare(strict_types=1);

use Lunetics\LlmCostTrackingBundle\Command\UpdatePricingCommand;
use Lunetics\LlmCostTrackingBundle\DataCollector\LlmCostCollector;
use Lunetics\LlmCostTrackingBundle\Model\ModelRegistry;
use Lunetics\LlmCostTrackingBundle\Pricing\ModelsDevPricingProvider;
use Lunetics\LlmCostTrackingBundle\Service\CostCalculator;
use Lunetics\LlmCostTrackingBundle\Service\CostCalculatorInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\abstract_arg;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('lunetics_llm_cost_tracking.model_registry', ModelRegistry::class)
        ->arg('$models', abstract_arg('Populated by the bundle extension'))
        ->arg('$dynamicPricing', null);

    $services->set('lunetics_llm_cost_tracking.pricing_provider', ModelsDevPricingProvider::class)
        ->arg('$httpClient', service('http_client'))
        ->arg('$cache', service('cache.app'))
        ->arg('$ttl', abstract_arg('Populated by the bundle extension'));

    $services->set('lunetics_llm_cost_tracking.update_pricing_command', UpdatePricingCommand::class)
        ->arg('$pricingProvider', service('lunetics_llm_cost_tracking.pricing_provider'))
        ->tag('console.command');

    $services->set('lunetics_llm_cost_tracking.cost_calculator', CostCalculator::class);

    $services->alias(CostCalculatorInterface::class, 'lunetics_llm_cost_tracking.cost_calculator');

    $services->set('lunetics_llm_cost_tracking.data_collector', LlmCostCollector::class)
        ->arg('$platforms', tagged_iterator('ai.traceable_platform'))
        ->arg('$modelRegistry', service('lunetics_llm_cost_tracking.model_registry'))
        ->arg('$costCalculator', service('lunetics_llm_cost_tracking.cost_calculator'))
        ->arg('$currency', abstract_arg('Populated by the bundle extension'))
        ->arg('$costThresholds', abstract_arg('Populated by the bundle extension'))
        ->arg('$budgetWarning', abstract_arg('Populated by the bundle extension'))
        ->tag('data_collector', [
            'template' => '@LuneticsLlmCostTracking/data_collector/llm_cost.html.twig',
            'id' => 'lunetics_llm_cost_tracking',
        ]);
};
