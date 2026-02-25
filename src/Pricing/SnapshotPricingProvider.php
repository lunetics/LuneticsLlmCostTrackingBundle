<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Pricing;

use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;
use Psr\Log\LoggerInterface;

/**
 * Reads model pricing from a bundled JSON snapshot file.
 * Implements only the base PricingProviderInterface — snapshots are static and
 * do not need cache invalidation or live-fetching capabilities.
 *
 * The result is memoized at the instance level to avoid re-reading a large file
 * on every request during an API outage or when dynamic pricing is disabled.
 */
final class SnapshotPricingProvider implements PricingProviderInterface
{
    /**
     * Memoized snapshot result for the lifetime of this instance.
     * false = not yet attempted; array = loaded (possibly empty on failure).
     *
     * @var array<string, ModelDefinition>|false
     */
    private array|false $cache = false;

    public function __construct(
        private readonly string $snapshotPath,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Returns all models from the snapshot file, keyed by model ID.
     * Returns an empty array if the file is missing or unreadable.
     *
     * @return array<string, ModelDefinition>
     */
    public function getModels(): array
    {
        if (false !== $this->cache) {
            return $this->cache;
        }

        if ('' === $this->snapshotPath || !is_file($this->snapshotPath)) {
            return $this->cache = [];
        }

        try {
            $json = file_get_contents($this->snapshotPath);
            if (false === $json) {
                $this->logger?->error('Failed to read pricing snapshot file.', ['path' => $this->snapshotPath]);

                return $this->cache = [];
            }

            return $this->cache = ModelsDevResponseParser::parse($json);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to parse pricing snapshot.', [
                'path' => $this->snapshotPath,
                'exception' => $e,
            ]);

            return $this->cache = [];
        }
    }
}
