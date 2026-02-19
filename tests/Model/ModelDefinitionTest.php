<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\Model;

use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModelDefinitionTest extends TestCase
{
    #[Test]
    public function itCreatesAModelDefinitionWithRequiredFields(): void
    {
        $model = new ModelDefinition(
            modelId: 'gpt-5',
            displayName: 'GPT-5',
            provider: 'OpenAI',
            inputPricePerMillion: 1.25,
            outputPricePerMillion: 10.00,
        );

        self::assertSame('gpt-5', $model->modelId);
        self::assertSame('GPT-5', $model->displayName);
        self::assertSame('OpenAI', $model->provider);
        self::assertSame(1.25, $model->inputPricePerMillion);
        self::assertSame(10.00, $model->outputPricePerMillion);
        self::assertNull($model->cachedInputPricePerMillion);
        self::assertNull($model->thinkingPricePerMillion);
    }

    #[Test]
    public function itCreatesAModelDefinitionWithOptionalPricing(): void
    {
        $model = new ModelDefinition(
            modelId: 'claude-sonnet-4-6',
            displayName: 'Claude Sonnet 4.6',
            provider: 'Anthropic',
            inputPricePerMillion: 3.00,
            outputPricePerMillion: 15.00,
            cachedInputPricePerMillion: 0.30,
            thinkingPricePerMillion: 15.00,
        );

        self::assertSame(0.30, $model->cachedInputPricePerMillion);
        self::assertSame(15.00, $model->thinkingPricePerMillion);
    }
}
