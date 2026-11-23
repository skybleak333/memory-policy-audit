<?php

declare(strict_types=1);

use MemoryPolicyAudit\Audit;
use MemoryPolicyAudit\Catalog;
use MemoryPolicyAudit\Relate;

require __DIR__.'/../src/Audit.php';
require __DIR__.'/../src/Catalog.php';
require __DIR__.'/../src/Relate.php';

function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('Expected %s, got %s.', json_encode($expected), json_encode($actual)));
    }
}

$catalog = json_decode(file_get_contents(__DIR__.'/../examples/policies.json'), true, flags: JSON_THROW_ON_ERROR);
$recall = json_decode(file_get_contents(__DIR__.'/../examples/recall.json'), true, flags: JSON_THROW_ON_ERROR);
$audit = new Audit(new Relate($argv[1] ?? 'relate'));

$report = $audit->run(new Catalog($catalog), $recall, '2026-11-23');
same(['superseded', 'unverified', 'not_yet_effective', 'current'], array_column($report['decisions'], 'status'));
same(['annual-refunds-v2', 'annual-refunds-v3'], $report['decisions'][0]['superseded_by']);
same([
    [
        'text' => 'Annual plans have a 14-day refund window.',
        'citation' => 'https://policies.example/refunds/annual/v3',
        'document_id' => 'annual-refunds-v3',
    ],
], $report['context']);

$report = $audit->run(new Catalog($catalog), $recall, '2026-09-30');
same(['current', 'unverified', 'not_yet_effective', 'not_yet_effective'], array_column($report['decisions'], 'status'));
same(['annual-refunds-v1'], array_column($report['context'], 'document_id'));

$report = $audit->run(new Catalog($catalog), $recall, '2026-12-01');
same(['superseded', 'unverified', 'current', 'superseded'], array_column($report['decisions'], 'status'));
same(['annual-refunds-v4'], array_column($report['context'], 'document_id'));

$report = $audit->run(new Catalog($catalog), $recall, '2026-08-01');
same([], $report['context']);

$catalog['revisions'][] = [
    'id' => 'annual-refunds-v3-branch',
    'policy' => 'annual-refunds',
    'effective_on' => '2026-11-02',
    'citation' => 'https://policies.example/refunds/annual/v3-branch',
    'supersedes' => ['annual-refunds-v2'],
];
$report = $audit->run(new Catalog($catalog), $recall, '2026-11-23');
same('conflicting', $report['decisions'][3]['status']);
same(['annual-refunds-v3', 'annual-refunds-v3-branch'], $report['decisions'][3]['current_revisions']);
same([], $report['context']);

$catalog['revisions'][3]['supersedes'][] = 'annual-refunds-v3-branch';
$report = $audit->run(new Catalog($catalog), $recall, '2026-12-01');
same(['annual-refunds-v4'], array_column($report['context'], 'document_id'));

$report = $audit->run(new Catalog(['revisions' => []]), ['results' => [['id' => '1', 'text' => 'No source.']]], '2026-11-23');
same('unverified', $report['decisions'][0]['status']);

try {
    $catalog['revisions'][1]['supersedes'] = ['annual-refunds-v4'];
    new Catalog($catalog);
    throw new RuntimeException('A backward revision edge was accepted.');
} catch (InvalidArgumentException $error) {
    same('Revision annual-refunds-v2 must supersede an earlier revision of the same policy.', $error->getMessage());
}

echo "Relate audit scenarios passed.\n";
