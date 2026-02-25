<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\Pricing;

use Lunetics\LlmCostTrackingBundle\Pricing\ModelsDevResponseParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModelsDevResponseParserTest extends TestCase
{
    #[Test]
    public function itCannotBeInstantiated(): void
    {
        $reflection = new \ReflectionClass(ModelsDevResponseParser::class);
        $constructor = $reflection->getConstructor();
        
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());

        $constructor->setAccessible(true);
        $instance = $reflection->newInstanceWithoutConstructor();
        $constructor->invoke($instance);
        
        self::assertInstanceOf(ModelsDevResponseParser::class, $instance);
    }

    #[Test]
    public function itSkipsModelsWithMissingInputOrOutputCost(): void
    {
        $json = json_encode([
            [
                'name' => 'Provider',
                'models' => [
                    'missing-output' => ['cost' => ['input' => 1.0]],
                    'missing-input' => ['cost' => ['output' => 1.0]],
                    'valid' => ['cost' => ['input' => 1.0, 'output' => 2.0]],
                ],
            ]
        ], \JSON_THROW_ON_ERROR);

        $models = ModelsDevResponseParser::parse($json);

        self::assertArrayHasKey('valid', $models);
        self::assertArrayNotHasKey('missing-output', $models);
        self::assertArrayNotHasKey('missing-input', $models);
    }
}
