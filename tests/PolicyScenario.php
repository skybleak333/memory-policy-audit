<?php

declare(strict_types=1);

use Fedoseev\Hindsight\Hindsight;

final class PolicyScenario
{
    public static function url(): string
    {
        $url = getenv('HINDSIGHT_URL') ?: 'http://127.0.0.1:8888';
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        if (!in_array($host, ['127.0.0.1', '::1', 'localhost'], true)) {
            throw new RuntimeException('The live test writes to a disposable bank and requires a loopback Hindsight server.');
        }

        return $url;
    }

    public static function seed(Hindsight $client, string $bank): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../examples/recall.json'), true, flags: JSON_THROW_ON_ERROR);
        $client->retainBatch($bank, array_map(static fn (array $entry): array => [
            'content' => $entry['text'],
            'document_id' => $entry['document_id'],
            'timestamp' => '2026-11-20T12:00:00Z',
        ], $fixture['results']));
    }
}
