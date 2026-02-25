<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\Pricing;

use Lunetics\LlmCostTrackingBundle\Pricing\ModelsDevPricingProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

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
        $stream = $this->createStream($this->createChunk(self::minimalFixture()), $response);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())->method('request')->willReturn($response);
        $httpClient->method('stream')->willReturn($stream);

        $cache = new ArrayAdapter();
        $provider = new ModelsDevPricingProvider($httpClient, $cache, 86400);

        $provider->getModels();
        $provider->getModels(); // second call must hit cache, not HTTP
    }

    #[Test]
    public function itInvalidatesCacheAndRefetches(): void
    {
        $response = static::createStub(ResponseInterface::class);
        // The same stream instance is reused across both fetches — rewind() resets it.
        $stream = $this->createStream($this->createChunk(self::minimalFixture()), $response);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->exactly(2))->method('request')->willReturn($response);
        $httpClient->method('stream')->willReturn($stream);

        $cache = new ArrayAdapter();
        $provider = new ModelsDevPricingProvider($httpClient, $cache, 86400);

        $provider->getModels();
        $provider->invalidate();
        $provider->getModels(); // must refetch after invalidation
    }

    #[Test]
    public function itReturnsEmptyArrayOnFetchFailure(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willThrowException(new \RuntimeException('API down'));

        $provider = new ModelsDevPricingProvider($httpClient, new ArrayAdapter(), 86400);

        self::assertSame([], $provider->getModels());
    }

    #[Test]
    public function itFetchLiveReturnsParsedModelsAndPopulatesCache(): void
    {
        $response = static::createStub(ResponseInterface::class);
        $stream = $this->createStream($this->createChunk(self::minimalFixture()), $response);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())->method('request')->willReturn($response);
        $httpClient->method('stream')->willReturn($stream);

        $cache = new ArrayAdapter();
        $provider = new ModelsDevPricingProvider($httpClient, $cache, 86400);

        $models = $provider->fetchLive();

        self::assertArrayHasKey('gpt-5', $models);
        self::assertSame(1.25, $models['gpt-5']->inputPricePerMillion);

        // Cache must be warm — second getModels() call must not trigger another HTTP request
        $provider->getModels();
    }

    #[Test]
    public function itFetchLiveThrowsOnApiFailure(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willThrowException(new \RuntimeException('API down'));

        $provider = new ModelsDevPricingProvider($httpClient, new ArrayAdapter(), 86400);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API down');

        $provider->fetchLive();
    }

    #[Test]
    public function itThrowsExceptionOnOversizedResponse(): void
    {
        $chunk = static::createStub(ChunkInterface::class);
        $chunk->method('getContent')->willReturn(str_repeat('a', 5 * 1024 * 1024 + 1));

        $response = static::createStub(ResponseInterface::class);
        $stream = $this->createStream($chunk, $response);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);
        $httpClient->method('stream')->willReturn($stream);

        $provider = new ModelsDevPricingProvider($httpClient, new ArrayAdapter(), 86400);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeded the 5242880 byte size limit');

        $provider->fetchLive();
    }

    #[Test]
    public function itGetModelsSwallowsOversizedResponseAndReturnsEmpty(): void
    {
        // fetchLive() re-throws on an oversized response; getModels() must swallow it
        // and return [] with a short negative-cache TTL (same as any other fetch failure).
        $chunk = static::createStub(ChunkInterface::class);
        $chunk->method('getContent')->willReturn(str_repeat('a', 5 * 1024 * 1024 + 1));

        $response = static::createStub(ResponseInterface::class);
        $stream = $this->createStream($chunk, $response);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())->method('request')->willReturn($response);
        $httpClient->method('stream')->willReturn($stream);

        $provider = new ModelsDevPricingProvider($httpClient, new ArrayAdapter(), 86400);

        self::assertSame([], $provider->getModels());
        $provider->getModels(); // must hit negative cache, not re-request
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
        $stream = $this->createStream($this->createChunk($apiData), $response);

        $httpClient = static::createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);
        $httpClient->method('stream')->willReturn($stream);

        return new ModelsDevPricingProvider($httpClient, $cache ?? new ArrayAdapter(), 86400);
    }

    /**
     * Creates a ChunkInterface stub whose getContent() returns the JSON-encoded $data.
     * Stubs must be configured eagerly (before test execution) — lazy configuration
     * inside willReturnCallback is unreliable in PHPUnit 11's mock state machine.
     *
     * @param array<mixed> $data
     */
    private function createChunk(array $data): ChunkInterface
    {
        $chunk = static::createStub(ChunkInterface::class);
        $chunk->method('getContent')->willReturn(json_encode($data, \JSON_THROW_ON_ERROR));

        return $chunk;
    }

    /**
     * Creates a ResponseStreamInterface that yields exactly one chunk.
     * The stream is rewindable, so the same instance can be reused for multiple
     * getModels() calls (e.g. in cache-invalidation tests).
     */
    private function createStream(ChunkInterface $chunk, ResponseInterface $response): ResponseStreamInterface
    {
        return new class($chunk, $response) implements ResponseStreamInterface {
            private bool $valid = true;

            public function __construct(
                private readonly ChunkInterface $chunk,
                private readonly ResponseInterface $response,
            ) {
            }

            public function current(): ChunkInterface
            {
                return $this->chunk;
            }

            public function key(): ResponseInterface
            {
                return $this->response;
            }

            public function next(): void
            {
                $this->valid = false;
            }

            public function rewind(): void
            {
                $this->valid = true;
            }

            public function valid(): bool
            {
                return $this->valid;
            }
        };
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
