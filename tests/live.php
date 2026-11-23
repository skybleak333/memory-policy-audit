<?php

declare(strict_types=1);

use Fedoseev\Hindsight\Exceptions\NotFoundException;
use Fedoseev\Hindsight\Hindsight;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/PolicyScenario.php';

$url = PolicyScenario::url();

$hindsight = (new Hindsight($url))->withTimeout(60);
$bank = 'memory-policy-audit-'.bin2hex(random_bytes(8));

try {
    PolicyScenario::seed($hindsight, $bank);

    $relate = $argv[1] ?? 'relate';
    $command = [
        PHP_BINARY, __DIR__.'/../bin/memory-audit', 'audit', '--catalog='.__DIR__.'/../examples/policies.json',
        '--bank='.$bank, '--query=What is the refund deadline for annual plans?',
        '--as-of=2026-11-23', '--relate='.$relate, '--json',
    ];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes, env_vars: [
        ...getenv(), 'HINDSIGHT_URL' => $url, 'HINDSIGHT_API_KEY' => '',
    ]);
    if ($process === false) {
        throw new RuntimeException('Could not start the audit CLI.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('The audit CLI failed.');
    }

    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $expected = [
        'annual-refunds-v1' => 'superseded',
        'support-chat' => 'unverified',
        'annual-refunds-v4' => 'not_yet_effective',
        'annual-refunds-v3' => 'current',
    ];

    foreach (array_column($report['decisions'], 'status', 'document_id') as $documentId => $status) {
        if (($expected[$documentId] ?? null) !== $status) {
            throw new RuntimeException("Unexpected audit status for {$documentId}: {$status}.");
        }
        unset($expected[$documentId]);
    }
    if ($expected !== [] || array_column($report['context'], 'document_id') !== ['annual-refunds-v3']) {
        throw new RuntimeException('The live audit did not classify all four documents: '.json_encode($report, JSON_THROW_ON_ERROR));
    }

    echo "Live Hindsight and Relate audit passed.\n";
} finally {
    try {
        $hindsight->deleteBank($bank);
    } catch (NotFoundException) {
        // A failure before retain did not create a bank.
    }
}
