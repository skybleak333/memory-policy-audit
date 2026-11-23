<?php

declare(strict_types=1);

use Fedoseev\Hindsight\Hindsight;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Server;
use Mcp\Server\Transport\StdioTransport;
use MemoryPolicyAudit\Audit;
use MemoryPolicyAudit\Catalog;
use MemoryPolicyAudit\Relate;

ini_set('display_errors', 'stderr');

function readJson(string $path): array
{
    if (!is_readable($path) || false === ($contents = file_get_contents($path))) {
        throw new RuntimeException("Could not read {$path}.");
    }

    $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new InvalidArgumentException("Expected a JSON object in {$path}.");
    }

    return $data;
}

/** @param Closure(string): array $recall */
function lookup(Audit $audit, Catalog $catalog, string $asOf, Closure $recall): CallToolResult
{
    try {
        Catalog::date($asOf);
    } catch (InvalidArgumentException) {
        return CallToolResult::error([new TextContent('Provide a valid calendar date in YYYY-MM-DD format.')]);
    }

    try {
        $report = $audit->run($catalog, $recall($asOf), $asOf);

        return new CallToolResult(
            [new TextContent(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))],
            structuredContent: $report,
        );
    } catch (Throwable $error) {
        fwrite(STDERR, 'Policy lookup failed ('.$error::class.").\n");

        return CallToolResult::error([new TextContent('Policy lookup failed. Check the local server configuration.')]);
    }
}

try {
    $arguments = array_slice($argv, 1);
    if ($arguments !== [] && $arguments !== ['--demo']) {
        throw new InvalidArgumentException('Usage: php mcp/server.php [--demo]');
    }

    if (!is_file(__DIR__.'/vendor/autoload.php')) {
        throw new RuntimeException('Run composer --working-dir=mcp install first.');
    }
    require __DIR__.'/vendor/autoload.php';

    $demo = $arguments === ['--demo'];
    $audit = new Audit(new Relate(getenv('RELATE_BIN') ?: 'relate'));
    $examples = __DIR__.'/../examples';
    $schema = [
        'type' => 'object',
        'properties' => [
            'as_of' => [
                'type' => 'string',
                'description' => 'Effective date in YYYY-MM-DD. Defaults to '.($demo ? '2026-11-23.' : 'today in UTC.'),
            ],
        ],
        'additionalProperties' => false,
    ];

    if ($demo) {
        $catalog = new Catalog(readJson($examples.'/policies.json'));
        $recall = readJson($examples.'/stale-recall.json');
        $handler = static fn (string $as_of = '2026-11-23'): CallToolResult => lookup(
            $audit,
            $catalog,
            $as_of,
            static fn (string $date): array => $recall,
        );
        $name = 'lookup_refund_policy';
        $description = 'Look up the example annual plan refund policy and its citation using synthetic recall.';
    } else {
        $catalogPath = getenv('POLICY_CATALOG');
        $url = getenv('HINDSIGHT_URL');
        $bank = getenv('HINDSIGHT_BANK');
        if (!$catalogPath || !$url || $bank === false || trim($bank) === '') {
            throw new InvalidArgumentException('Set POLICY_CATALOG, HINDSIGHT_URL and HINDSIGHT_BANK, or use --demo.');
        }

        $catalog = new Catalog(readJson($catalogPath));
        foreach ($catalog->revisions as $revision) {
            if ($revision['excerpt'] === null) {
                throw new InvalidArgumentException("Policy revision {$revision['id']} needs an approved excerpt for MCP.");
            }
        }
        $hindsight = new Hindsight($url, apiKey: getenv('HINDSIGHT_API_KEY') ?: null);
        $schema['properties']['question'] = [
            'type' => 'string',
            'pattern' => '\\S',
            'description' => 'Question about the policies in the configured Hindsight bank.',
        ];
        $schema['required'] = ['question'];
        $handler = static fn (string $question, ?string $as_of = null): CallToolResult => lookup(
            $audit,
            $catalog,
            $as_of ?? gmdate('Y-m-d'),
            static fn (string $date): array => $hindsight->recall($bank, $question, queryTimestamp: $date.'T23:59:59Z')->raw,
        );
        $name = 'lookup_policy';
        $description = 'Recall relevant policy sources and return their approved excerpts and canonical citations as of a date.';
    }

    $server = Server::builder()
        ->setServerInfo('Memory Policy Audit', '0.4.0')
        ->addTool(
            $handler,
            $name,
            description: $description,
            annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
            inputSchema: $schema,
            outputSchema: readJson(__DIR__.'/output-schema.json'),
        )
        ->build();
    exit($server->run(new StdioTransport()));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
