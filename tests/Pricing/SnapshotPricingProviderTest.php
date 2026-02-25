<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\Pricing;

use Lunetics\LlmCostTrackingBundle\Pricing\SnapshotPricingProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SnapshotPricingProviderTest extends TestCase
{
    #[Test]
    public function itLoadsModelsFromSnapshotFile(): void
    {
        $snapshotPath = __DIR__.'/../fixtures/pricing_snapshot_minimal.json';
        $provider = new SnapshotPricingProvider($snapshotPath);

        $models = $provider->getModels();

        self::assertArrayHasKey('test-model', $models);
        self::assertSame(1.0, $models['test-model']->inputPricePerMillion);
        self::assertSame(5.0, $models['test-model']->outputPricePerMillion);
        self::assertSame('TestProvider', $models['test-model']->provider);
    }

    #[Test]
    public function itReturnsEmptyArrayWhenFileNotFound(): void
    {
        $provider = new SnapshotPricingProvider('/nonexistent/path/snapshot.json');

        self::assertSame([], $provider->getModels());
    }

    #[Test]
    public function itReturnsEmptyArrayForEmptyPath(): void
    {
        $provider = new SnapshotPricingProvider('');

        self::assertSame([], $provider->getModels());
    }

    #[Test]
    public function itLogsErrorWhenFileGetContentsReturnsFalse(): void
    {
        if (!\in_array('fail', stream_get_wrappers(), true)) {
            stream_wrapper_register('fail', (new class {
                public mixed $context;

                public function stream_open(): bool
                {
                    return false;
                }

                /** @return array<int|string, int> */
                public function url_stat(): array
                {
                    return ['mode' => 0100644];
                }
            })::class);
        }

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Failed to read pricing snapshot file.', self::arrayHasKey('path'));

        $provider = new SnapshotPricingProvider('fail://test', $logger);

        set_error_handler(static fn () => true);
        try {
            $models = $provider->getModels();
            self::assertSame([], $models);

            // Second call must return the memoized empty array — logger must not fire again.
            // The expects($this->once()) above enforces this implicitly.
            self::assertSame([], $provider->getModels());
        } finally {
            restore_error_handler();
            if (\in_array('fail', stream_get_wrappers(), true)) {
                stream_wrapper_unregister('fail');
            }
        }
    }

    #[Test]
    public function itMemoizesResult(): void
    {
        // Write a temp file, load it, delete it, then verify the second call
        // returns the same data — proving it came from memory, not disk.
        $tmpFile = tempnam(sys_get_temp_dir(), 'snapshot_test_');
        self::assertIsString($tmpFile);

        $fixture = json_encode([[
            'name' => 'TempProvider',
            'models' => [
                'temp-model' => ['name' => 'Temp Model', 'cost' => ['input' => 2.0, 'output' => 8.0]],
            ],
        ]], \JSON_THROW_ON_ERROR);
        file_put_contents($tmpFile, $fixture);

        try {
            $provider = new SnapshotPricingProvider($tmpFile);

            $first = $provider->getModels();
            self::assertArrayHasKey('temp-model', $first);

            unlink($tmpFile);

            // File is gone — result must still come from the memoized cache
            $second = $provider->getModels();
            self::assertArrayHasKey('temp-model', $second);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    #[Test]
    public function itLogsErrorOnParseFailure(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'snapshot_invalid_');
        self::assertIsString($tmpFile);
        file_put_contents($tmpFile, 'not valid json {{{');

        try {
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects($this->once())
                ->method('error')
                ->with('Failed to parse pricing snapshot.', self::arrayHasKey('path'));

            $provider = new SnapshotPricingProvider($tmpFile, $logger);

            self::assertSame([], $provider->getModels());

            // Second call must return the memoized empty array — logger must not fire again.
            // The expects($this->once()) above enforces this implicitly.
            self::assertSame([], $provider->getModels());
        } finally {
            unlink($tmpFile);
        }
    }
}
