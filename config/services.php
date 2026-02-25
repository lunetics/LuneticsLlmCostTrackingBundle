<?php

declare(strict_types=1);

use Lunetics\LlmCostTrackingBundle\DataCollector\LlmCostCollector;
use Lunetics\LlmCostTrackingBundle\Model\ModelRegistry;
use Lunetics\LlmCostTrackingBundle\Model\ModelRegistryInterface;
use Lunetics\LlmCostTrackingBundle\Service\CostCalculator;
use Lunetics\LlmCostTrackingBundle\Service\CostCalculatorInterface;
use Lunetics\LlmCostTrackingBundle\Service\CostTracker;
use Lunetics\LlmCostTrackingBundle\Service\CostTrackerInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\abstract_arg;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('lunetics_llm_cost_tracking.model_registry', ModelRegistry::class)
        ->arg('$models', abstract_arg('Populated by the bundle extension'))
        ->arg('$dynamicPricing', null)
        ->arg('$logger', service('logger')->nullOnInvalid());

    $services->set('lunetics_llm_cost_tracking.cost_calculator', CostCalculator::class);

    $services->alias(ModelRegistryInterface::class, 'lunetics_llm_cost_tracking.model_registry');

    $services->alias(CostCalculatorInterface::class, 'lunetics_llm_cost_tracking.cost_calculator');

    $services->set('lunetics_llm_cost_tracking.cost_tracker', CostTracker::class)
        ->arg('$platforms', tagged_iterator('ai.traceable_platform'))
        ->arg('$modelRegistry', service('lunetics_llm_cost_tracking.model_registry'))
        ->arg('$costCalculator', service('lunetics_llm_cost_tracking.cost_calculator'));

    $services->alias(CostTrackerInterface::class, 'lunetics_llm_cost_tracking.cost_tracker');

    $services->set('lunetics_llm_cost_tracking.data_collector', LlmCostCollector::class)
        ->arg('$costTracker', service('lunetics_llm_cost_tracking.cost_tracker'))
        ->arg('$costThresholds', abstract_arg('Populated by the bundle extension'))
        ->arg('$budgetWarning', abstract_arg('Populated by the bundle extension'))
        ->tag('data_collector', [
            'template' => '@LuneticsLlmCostTracking/data_collector/llm_cost.html.twig',
            'id' => 'lunetics_llm_cost_tracking',
        ]);
};
