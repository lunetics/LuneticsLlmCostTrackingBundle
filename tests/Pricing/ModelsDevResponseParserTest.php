<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\Pricing;

use Lunetics\LlmCostTrackingBundle\Pricing\ModelsDevResponseParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModelsDevResponseParserTest extends TestCase
{
    #[Test]
    public function itHasAPrivateConstructor(): void
    {
        $reflection = new \ReflectionClass(ModelsDevResponseParser::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }

    #[Test]
    public function itReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], ModelsDevResponseParser::parse('[]'));
    }

    #[Test]
    public function itSkipsMetaEntry(): void
    {
        // The snapshot generator prepends a _meta entry via array_unshift.
        // The parser must skip it (no 'name'/'models' keys) and still return real models.
        $json = json_encode([
            ['_meta' => ['generated_at' => '2026-02-26', 'source' => 'https://models.dev/api.json']],
            ['name' => 'TestProvider', 'models' => [
                'model-x' => ['cost' => ['input' => 1.0, 'output' => 2.0]],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $models = ModelsDevResponseParser::parse($json);

        self::assertCount(1, $models);
        self::assertArrayHasKey('model-x', $models);
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
            ],
        ], \JSON_THROW_ON_ERROR);

        $models = ModelsDevResponseParser::parse($json);

        self::assertArrayHasKey('valid', $models);
        self::assertArrayNotHasKey('missing-output', $models);
        self::assertArrayNotHasKey('missing-input', $models);
    }
}
