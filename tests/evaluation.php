<?php

declare(strict_types=1);

$examples = __DIR__.'/../examples';
$dataset = json_decode(file_get_contents($examples.'/evaluation.json'), true, flags: JSON_THROW_ON_ERROR);
$dataset['cases'] = array_slice($dataset['cases'], 0, 2);
$dataset['cases'][0]['expect']['context'][0]['citation'] = 'https://policies.example/wrong';

$directory = sys_get_temp_dir().'/memory-policy-evaluation-test-'.bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Could not create the temporary dataset directory.');
}

try {
    foreach (['policies.json', 'recall.json', 'stale-recall.json'] as $fixture) {
        if (!copy($examples.'/'.$fixture, $directory.'/'.$fixture)) {
            throw new RuntimeException("Could not copy fixture {$fixture}.");
        }
    }

    $file = $directory.'/dataset.json';
    if (file_put_contents($file, json_encode($dataset, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Could not write the temporary dataset.');
    }
    $arguments = [PHP_BINARY, __DIR__.'/../bin/memory-audit', 'eval', "--dataset={$file}", '--relate='.($argv[1] ?? 'relate'), '--json'];
    exec(implode(' ', array_map(escapeshellarg(...), $arguments)), $output, $status);
    $report = json_decode(implode("\n", $output), true, flags: JSON_THROW_ON_ERROR);

    $first = $report['cases'][0];
    $difference = $first['differences'][0];
    if ($status !== 2 || $report['failed'] !== 1 || $report['passed'] !== 1
        || $first['passed'] || $report['cases'][1]['passed'] !== true
        || count($first['differences']) !== 1 || $difference['field'] !== 'context'
        || $difference['expected'][0]['citation'] !== 'https://policies.example/wrong'
        || $difference['actual'][0]['citation'] !== 'https://policies.example/refunds/annual/v3') {
        throw new RuntimeException('Expected a citation mismatch, a later passing case, and exit code 2.');
    }
    echo "A changed citation fails the dataset without stopping later cases.\n";
} finally {
    foreach (glob($directory.'/*.json') as $path) {
        unlink($path);
    }
    rmdir($directory);
}
