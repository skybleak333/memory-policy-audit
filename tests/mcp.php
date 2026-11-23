<?php

declare(strict_types=1);

use Fedoseev\Hindsight\Exceptions\NotFoundException;
use Fedoseev\Hindsight\Hindsight;
use Mcp\Client\Client;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Schema\Enum\ProtocolVersion;

require __DIR__.'/../mcp/vendor/autoload.php';
require __DIR__.'/PolicyScenario.php';

function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('Expected %s, got %s.', json_encode($expected), json_encode($actual)));
    }
}

function connect(ProtocolVersion $version, array $args, array $env): Client
{
    $client = Client::builder()
        ->setClientInfo('Memory Policy Audit test', '1.0.0')
        ->setProtocolVersion($version)
        ->setInitTimeout(10)
        ->setRequestTimeout(60)
        ->build();
    $client->connect(new StdioTransport(PHP_BINARY, [__DIR__.'/../mcp/server.php', ...$args], env: $env));

    return $client;
}

function verifyTool(Client $client, string $name): void
{
    $tools = $client->listTools()->tools;
    same([$name], array_column($tools, 'name'));
    same(true, $tools[0]->annotations->readOnlyHint);
    same(['as_of', 'context', 'decisions'], $tools[0]->outputSchema['required']);
}

function report(Client $client, string $name, array $arguments): array
{
    $result = $client->callTool($name, $arguments);
    same(false, $result->isError);
    same(true, $result->hasStructuredContent());
    $structured = json_decode(json_encode($result->structuredContent, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    same($structured, json_decode($result->content[0]->text, true, flags: JSON_THROW_ON_ERROR));

    return $structured;
}

function verifyRecovery(array $report): void
{
    same('2026-11-23', $report['as_of']);
    same([
        [
            'text' => 'Annual plans have a 14-day refund window.',
            'citation' => 'https://policies.example/refunds/annual/v3',
            'document_id' => 'annual-refunds-v3',
            'recovered_from' => 'annual-refunds-v1',
        ],
    ], $report['context']);
    same(['superseded'], array_column($report['decisions'], 'status'));
    same(['annual-refunds-v3'], array_column($report['decisions'], 'resolved_to'));
}

$env = [...getenv(), 'RELATE_BIN' => $argv[1] ?? 'relate'];
$name = 'lookup_refund_policy';
foreach ([ProtocolVersion::V2025_11_25, ProtocolVersion::V2026_07_28] as $version) {
    $client = connect($version, ['--demo'], $env);
    try {
        verifyTool($client, $name);
        verifyRecovery(report($client, $name, []));
        same(['annual-refunds-v1'], array_column(report($client, $name, ['as_of' => '2026-09-30'])['context'], 'document_id'));
        same(['annual-refunds-v4'], array_column(report($client, $name, ['as_of' => '2026-12-01'])['context'], 'document_id'));
        same([], report($client, $name, ['as_of' => '2026-08-01'])['context']);
        same(true, $client->callTool($name, ['as_of' => '2026-02-30'])->isError);
        $historical = report($client, $name, ['as_of' => '2026-11-05', 'known_on' => '2026-11-05']);
        same('2026-11-05', $historical['known_on']);
        same(['annual-refunds-v2'], array_column($historical['context'], 'document_id'));
        same(['annual-refunds-v1'], array_column($historical['context'], 'recovered_from'));
        $corrected = report($client, $name, ['as_of' => '2026-11-05', 'known_on' => '2026-11-10']);
        same(['annual-refunds-v3'], array_column($corrected['context'], 'document_id'));
        same(true, $client->callTool($name, ['known_on' => '2026-02-30'])->isError);
    } finally {
        $client->disconnect();
    }

    echo "MCP demo passed on protocol {$version->value}.\n";
}

if (($argv[2] ?? null) !== '--live') {
    exit(0);
}

$url = PolicyScenario::url();
$hindsight = (new Hindsight($url))->withTimeout(60);
$bank = 'memory-policy-audit-mcp-'.bin2hex(random_bytes(8));
try {
    PolicyScenario::seed($hindsight, $bank, staleOnly: true);
    $client = connect(ProtocolVersion::V2025_11_25, [], [
        ...$env,
        'POLICY_CATALOG' => __DIR__.'/../examples/policies.json',
        'HINDSIGHT_URL' => $url,
        'HINDSIGHT_API_KEY' => '',
        'HINDSIGHT_BANK' => $bank,
    ]);
    try {
        verifyTool($client, 'lookup_policy');
        verifyRecovery(report($client, 'lookup_policy', [
            'question' => 'What is the annual plan refund window?',
            'as_of' => '2026-11-23',
        ]));
        $historical = report($client, 'lookup_policy', [
            'question' => 'What is the annual plan refund window?',
            'as_of' => '2026-11-23',
            'known_on' => '2026-11-05',
        ]);
        same('2026-11-05', $historical['known_on']);
        same(['annual-refunds-v2'], array_column($historical['context'], 'document_id'));
    } finally {
        $client->disconnect();
    }
} finally {
    try {
        $hindsight->deleteBank($bank);
    } catch (NotFoundException) {
        // The failure occurred before a bank was created.
    }
}

echo "MCP lookup with live Hindsight and Relate passed.\n";
