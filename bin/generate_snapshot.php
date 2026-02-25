<?php

declare(strict_types=1);

/**
 * Fetches the current pricing data from models.dev and writes it to
 * resources/pricing_snapshot.json as a versioned fallback for offline use.
 *
 * Usage: php bin/generate_snapshot.php
 */

require_once \dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\HttpClient\HttpClient;

$source = 'https://models.dev/api.json';
$dest = \dirname(__DIR__).'/resources/pricing_snapshot.json';

echo "Fetching {$source} ...\n";

try {
    $client = HttpClient::create();
    $response = $client->request('GET', $source);

    $statusCode = $response->getStatusCode();
    if (200 !== $statusCode) {
        fwrite(STDERR, sprintf("Error: failed to fetch %s (HTTP %d)\n", $source, $statusCode));
        exit(1);
    }

    $json = $response->getContent();
} catch (\Throwable $e) {
    fwrite(STDERR, sprintf("Error: failed to fetch %s: %s\n", $source, $e->getMessage()));
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

$written = file_put_contents(
    $dest,
    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
);
if (false === $written) {
    fwrite(STDERR, "Error: failed to write {$dest}\n");
    exit(1);
}

echo "Snapshot written to resources/pricing_snapshot.json\n";
