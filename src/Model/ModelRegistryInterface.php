<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Model;

interface ModelRegistryInterface
{
    public function get(string $modelId): ?ModelDefinition;
}
