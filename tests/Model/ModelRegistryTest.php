<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\Model;

use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;
use Lunetics\LlmCostTrackingBundle\Model\ModelRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModelRegistryTest extends TestCase
{
    #[Test]
    public function itReturnsNullForUnknownModel(): void
    {
        $registry = new ModelRegistry();

        self::assertNull($registry->get('unknown-model'));
        self::assertFalse($registry->has('unknown-model'));
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

        self::assertTrue($registry->has('gpt-5'));
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
}
