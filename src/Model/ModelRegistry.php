<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Model;

final class ModelRegistry
{
    /** @var array<string, ModelDefinition> */
    private array $models = [];

    /**
     * @param array<string, ModelDefinition> $models
     */
    public function __construct(array $models = [])
    {
        foreach ($models as $modelId => $definition) {
            $this->models[$modelId] = $definition;
        }
    }

    public function get(string $modelId): ?ModelDefinition
    {
        return $this->models[$modelId] ?? null;
    }

    public function has(string $modelId): bool
    {
        return isset($this->models[$modelId]);
    }

    /**
     * @return array<string, ModelDefinition>
     */
    public function all(): array
    {
        return $this->models;
    }
}
