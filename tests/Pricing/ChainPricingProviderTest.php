<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\Pricing;

use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;
use Lunetics\LlmCostTrackingBundle\Pricing\ChainPricingProvider;
use Lunetics\LlmCostTrackingBundle\Pricing\PricingProviderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChainPricingProviderTest extends TestCase
{
    #[Test]
    public function itMergesResultsFromMultipleProviders(): void
    {
        $providerA = $this->providerReturning(['model-a' => $this->definition('model-a', 'A')]);
        $providerB = $this->providerReturning(['model-b' => $this->definition('model-b', 'B')]);

        $chain = new ChainPricingProvider([$providerA, $providerB]);
        $models = $chain->getModels();

        self::assertArrayHasKey('model-a', $models);
        self::assertArrayHasKey('model-b', $models);
    }

    #[Test]
    public function itFirstProviderWinsOnDuplicateModelId(): void
    {
        $live = $this->definition('shared-model', 'LiveProvider', inputPrice: 1.0);
        $snapshot = $this->definition('shared-model', 'SnapshotProvider', inputPrice: 9.9);

        $chain = new ChainPricingProvider([
            $this->providerReturning(['shared-model' => $live]),
            $this->providerReturning(['shared-model' => $snapshot]),
        ]);

        $models = $chain->getModels();

        self::assertCount(1, $models);
        self::assertSame('LiveProvider', $models['shared-model']->provider);
        self::assertSame(1.0, $models['shared-model']->inputPricePerMillion);
    }

    #[Test]
    public function itReturnsEmptyArrayWhenAllProvidersEmpty(): void
    {
        $chain = new ChainPricingProvider([
            $this->providerReturning([]),
            $this->providerReturning([]),
        ]);

        self::assertSame([], $chain->getModels());
    }

    #[Test]
    public function itPassesThroughSingleProvider(): void
    {
        $model = $this->definition('solo-model', 'Solo');
        $chain = new ChainPricingProvider([$this->providerReturning(['solo-model' => $model])]);

        $models = $chain->getModels();

        self::assertCount(1, $models);
        self::assertArrayHasKey('solo-model', $models);
    }

    #[Test]
    public function itReturnsEmptyArrayForEmptyProviderList(): void
    {
        $chain = new ChainPricingProvider([]);

        self::assertSame([], $chain->getModels());
    }

    /** @param array<string, ModelDefinition> $models */
    private function providerReturning(array $models): PricingProviderInterface
    {
        $provider = static::createStub(PricingProviderInterface::class);
        $provider->method('getModels')->willReturn($models);

        return $provider;
    }

    private function definition(string $modelId, string $provider, float $inputPrice = 1.0): ModelDefinition
    {
        return new ModelDefinition(
            modelId: $modelId,
            displayName: $modelId,
            provider: $provider,
            inputPricePerMillion: $inputPrice,
            outputPricePerMillion: 5.0,
        );
    }
}
