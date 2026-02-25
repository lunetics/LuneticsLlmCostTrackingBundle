<?php

declare(strict_types=1);

/**
 * Fetches the current pricing data from models.dev and writes it to
 * resources/pricing_snapshot.json as a versioned fallback for offline use.
 *
 * Usage: php bin/generate_snapshot.php
 */

$source = 'https://models.dev/api.json';
$dest = \dirname(__DIR__).'/resources/pricing_snapshot.json';

echo "Fetching {$source} ...\n";

$json = file_get_contents($source);
if (false === $json) {
    fwrite(STDERR, "Error: failed to fetch {$source}\n");
    exit(1);
}

/** @var array<mixed> $data */
$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

array_unshift($data, [
    '_meta' => [
        'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d'),
        'source' => $source,
    ],
]);

file_put_contents(
    $dest,
    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
);

echo "Snapshot written to resources/pricing_snapshot.json\n";
