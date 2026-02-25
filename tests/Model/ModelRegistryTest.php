<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\Model;

use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;
use Lunetics\LlmCostTrackingBundle\Model\ModelRegistry;
use Lunetics\LlmCostTrackingBundle\Pricing\PricingProviderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModelRegistryTest extends TestCase
{
    #[Test]
    public function itReturnsNullForUnknownModel(): void
    {
        $registry = new ModelRegistry();

        self::assertNull($registry->get('unknown-model'));
    }

    #[Test]
    public function itStoresAndRetrievesModels(): void
    {
        $model = new ModelDefinition(
            modelId: 'gpt-5',
            displayName: 'GPT-5',
            provider: 'OpenAI',
            inputPricePerMillion: 1.25,
            outputPricePerMillion: 10.00,
        );

        $registry = new ModelRegistry(['gpt-5' => $model]);

        self::assertSame($model, $registry->get('gpt-5'));
    }

    #[Test]
    public function itReturnsAllModels(): void
    {
        $gpt = new ModelDefinition('gpt-5', 'GPT-5', 'OpenAI', 1.25, 10.00);
        $claude = new ModelDefinition('claude-sonnet-4-6', 'Claude Sonnet 4.6', 'Anthropic', 3.00, 15.00);

        $registry = new ModelRegistry([
            'gpt-5' => $gpt,
            'claude-sonnet-4-6' => $claude,
        ]);

        $all = $registry->all();
        self::assertCount(2, $all);
        self::assertSame($gpt, $all['gpt-5']);
        self::assertSame($claude, $all['claude-sonnet-4-6']);
    }

    #[Test]
    public function itFallsBackToDynamicPricingForUnknownModel(): void
    {
        $dynamicModel = new ModelDefinition('dynamic-model', 'Dynamic Model', 'TestProvider', 2.0, 8.0);

        $dynamicPricing = $this->createMock(PricingProviderInterface::class);
        $dynamicPricing->method('getModels')->willReturn(['dynamic-model' => $dynamicModel]);

        $registry = new ModelRegistry([], $dynamicPricing);

        self::assertSame($dynamicModel, $registry->get('dynamic-model'));
    }

    #[Test]
    public function itPrefersLocalModelOverDynamicPricing(): void
    {
        $localModel = new ModelDefinition('gpt-5', 'GPT-5 Local', 'OpenAI', 1.25, 10.0);
        $dynamicModel = new ModelDefinition('gpt-5', 'GPT-5 Dynamic', 'OpenAI', 2.0, 20.0);

        $dynamicPricing = $this->createMock(PricingProviderInterface::class);
        $dynamicPricing->method('getModels')->willReturn(['gpt-5' => $dynamicModel]);

        $registry = new ModelRegistry(['gpt-5' => $localModel], $dynamicPricing);

        self::assertSame($localModel, $registry->get('gpt-5'));
    }

    #[Test]
    public function itReturnsNullWhenNotFoundInEitherSource(): void
    {
        $dynamicPricing = $this->createMock(PricingProviderInterface::class);
        $dynamicPricing->method('getModels')->willReturn([]);

        $registry = new ModelRegistry([], $dynamicPricing);

        self::assertNull($registry->get('unknown-model'));
    }

    #[Test]
    public function itHandlesDynamicPricingExceptionGracefully(): void
    {
        $dynamicPricing = $this->createMock(PricingProviderInterface::class);
        $dynamicPricing->method('getModels')->willThrowException(new \RuntimeException('Network error'));

        $registry = new ModelRegistry([], $dynamicPricing);

        self::assertNull($registry->get('any-model'));
    }

    #[Test]
    public function itMemoizesDynamicPricingResultWithinInstance(): void
    {
        $dynamicPricing = $this->createMock(PricingProviderInterface::class);
        $dynamicPricing->expects($this->once())
            ->method('getModels')
            ->willReturn([]);

        $registry = new ModelRegistry([], $dynamicPricing);

        // Three lookups for different unknown models — getModels() must be called exactly once.
        $registry->get('unknown-1');
        $registry->get('unknown-2');
        $registry->get('unknown-3');
    }

    #[Test]
    public function itAllDoesNotIncludeDynamicModels(): void
    {
        $localModel = new ModelDefinition('local-model', 'Local', 'TestProvider', 1.0, 1.0);
        $dynamicModel = new ModelDefinition('dynamic-model', 'Dynamic', 'TestProvider', 2.0, 2.0);

        $dynamicPricing = $this->createMock(PricingProviderInterface::class);
        $dynamicPricing->method('getModels')->willReturn(['dynamic-model' => $dynamicModel]);

        $registry = new ModelRegistry(['local-model' => $localModel], $dynamicPricing);

        $all = $registry->all();
        self::assertCount(1, $all);
        self::assertArrayHasKey('local-model', $all);
        self::assertArrayNotHasKey('dynamic-model', $all);
    }
}
