<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\Pricing;

use Lunetics\LlmCostTrackingBundle\Pricing\ModelsDevPricingProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ModelsDevPricingProviderTest extends TestCase
{
    #[Test]
    public function itParsesInputAndOutputPrices(): void
    {
        $provider = $this->createProvider(self::minimalFixture());
        $models = $provider->getModels();

        self::assertArrayHasKey('gpt-5', $models);
        self::assertSame(1.25, $models['gpt-5']->inputPricePerMillion);
        self::assertSame(10.0, $models['gpt-5']->outputPricePerMillion);
    }

    #[Test]
    public function itMapsCacheReadToCachedInputPrice(): void
    {
        $provider = $this->createProvider(self::minimalFixture());
        $models = $provider->getModels();

        self::assertSame(0.13, $models['gpt-5']->cachedInputPricePerMillion);
    }

    #[Test]
    public function itMapsReasoningToThinkingPrice(): void
    {
        $provider = $this->createProvider(self::minimalFixture());
        $models = $provider->getModels();

        self::assertArrayHasKey('o3', $models);
        self::assertSame(8.0, $models['o3']->thinkingPricePerMillion);
    }

    #[Test]
    public function itSkipsModelsWithMissingCost(): void
    {
        $provider = $this->createProvider(self::minimalFixture());
        $models = $provider->getModels();

        self::assertArrayNotHasKey('no-cost-model', $models);
    }

    #[Test]
    public function itSkipsInvalidProviderEntries(): void
    {
        $provider = $this->createProvider(self::minimalFixture());
        $models = $provider->getModels();

        // 'bad-provider' maps to a string — must not crash and yields no models
        foreach ($models as $model) {
            self::assertNotSame('bad-provider', $model->provider);
        }
    }

    #[Test]
    public function itCachesResultsAndDoesNotRefetch(): void
    {
        $response = static::createStub(ResponseInterface::class);
        $response->method('toArray')->willReturn(self::minimalFixture());

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())->method('request')->willReturn($response);

        $cache = new ArrayAdapter();
        $provider = new ModelsDevPricingProvider($httpClient, $cache, 86400);

        $provider->getModels();
        $provider->getModels(); // second call must hit cache, not HTTP
    }

    #[Test]
    public function itInvalidatesCacheAndRefetches(): void
    {
        $response = static::createStub(ResponseInterface::class);
        $response->method('toArray')->willReturn(self::minimalFixture());

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->exactly(2))->method('request')->willReturn($response);

        $cache = new ArrayAdapter();
        $provider = new ModelsDevPricingProvider($httpClient, $cache, 86400);

        $provider->getModels();
        $provider->invalidate();
        $provider->getModels(); // must refetch after invalidation
    }

    #[Test]
    public function itReturnsEmptyArrayWhenFetchFails(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willThrowException(new \RuntimeException('API down'));

        $provider = new ModelsDevPricingProvider($httpClient, new ArrayAdapter(), 86400);

        self::assertSame([], $provider->getModels());
    }

    #[Test]
    public function itDoesNotRetryImmediatelyAfterFetchFailure(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->willThrowException(new \RuntimeException('API down'));

        $provider = new ModelsDevPricingProvider($httpClient, new ArrayAdapter(), 86400);

        $provider->getModels(); // fails, caches [] with 60s TTL
        $provider->getModels(); // must hit negative cache, not call HTTP again
    }

    #[Test]
    public function itParsesRealApiFixture(): void
    {
        $fixtureData = json_decode(
            (string) file_get_contents(__DIR__.'/../fixtures/models_dev_api.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($fixtureData);

        $provider = $this->createProvider($fixtureData);
        $models = $provider->getModels();

        self::assertArrayHasKey('gpt-5', $models);
        self::assertGreaterThan(0.0, $models['gpt-5']->inputPricePerMillion);
        self::assertGreaterThan(0.0, $models['gpt-5']->outputPricePerMillion);

        self::assertArrayHasKey('claude-sonnet-4-6', $models);
        self::assertGreaterThan(0.0, $models['claude-sonnet-4-6']->inputPricePerMillion);
        self::assertGreaterThan(0.0, $models['claude-sonnet-4-6']->outputPricePerMillion);
    }

    /** @param array<mixed> $apiData */
    private function createProvider(array $apiData, ?CacheInterface $cache = null): ModelsDevPricingProvider
    {
        $response = static::createStub(ResponseInterface::class);
        $response->method('toArray')->willReturn($apiData);

        $httpClient = static::createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        return new ModelsDevPricingProvider($httpClient, $cache ?? new ArrayAdapter(), 86400);
    }

    /** @return array<string, mixed> */
    private static function minimalFixture(): array
    {
        return [
            'openai' => [
                'name' => 'OpenAI',
                'models' => [
                    'gpt-5' => [
                        'name' => 'GPT-5',
                        'cost' => ['input' => 1.25, 'output' => 10.0, 'cache_read' => 0.13],
                    ],
                    'o3' => [
                        'name' => 'o3',
                        'cost' => ['input' => 2.0, 'output' => 8.0, 'reasoning' => 8.0],
                    ],
                ],
            ],
            'anthropic' => [
                'name' => 'Anthropic',
                'models' => [
                    'claude-sonnet-4-6' => [
                        'name' => 'Claude Sonnet 4.6',
                        'cost' => ['input' => 3.0, 'output' => 15.0, 'cache_read' => 0.3],
                    ],
                    'no-cost-model' => [
                        'name' => 'No Cost Model',
                        // intentionally missing 'cost' — should be skipped
                    ],
                ],
            ],
            'bad-provider' => 'not-an-array', // non-array provider entry — should be skipped
        ];
    }
}
