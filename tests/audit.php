<?php

declare(strict_types=1);

use MemoryPolicyAudit\Audit;
use MemoryPolicyAudit\Catalog;
use MemoryPolicyAudit\DecisionRecord;
use MemoryPolicyAudit\Relate;

require __DIR__.'/../src/Audit.php';
require __DIR__.'/../src/Catalog.php';
require __DIR__.'/../src/DecisionRecord.php';
require __DIR__.'/../src/Relate.php';

function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('Expected %s, got %s.', json_encode($expected), json_encode($actual)));
    }
}

$catalog = json_decode(file_get_contents(__DIR__.'/../examples/policies.json'), true, flags: JSON_THROW_ON_ERROR);
$recall = json_decode(file_get_contents(__DIR__.'/../examples/recall.json'), true, flags: JSON_THROW_ON_ERROR);
$staleRecall = json_decode(file_get_contents(__DIR__.'/../examples/stale-recall.json'), true, flags: JSON_THROW_ON_ERROR);
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

$historical = $audit->run(new Catalog($catalog), $recall, asOf: '2026-11-05', knownOn: '2026-11-05');
same('2026-11-05', $historical['known_on']);
same([
    'memory_id' => 'current-policy',
    'document_id' => 'annual-refunds-v3',
    'status' => 'not_yet_published',
    'published_on' => '2026-11-10',
], $historical['decisions'][3]);
same(['annual-refunds-v2'], array_column($historical['context'], 'document_id'));
same(['annual-refunds-v1'], array_column($historical['context'], 'recovered_from'));

$record = DecisionRecord::capture(new Catalog($catalog), $recall, $historical);
same([null, null, null, null], array_column($record['recall']['results'], 'text'));
$roundTrip = json_decode(json_encode($record, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
$roundTrip['report'] = array_reverse($roundTrip['report'], true);
same($historical, DecisionRecord::replay($roundTrip, $audit));

$changed = $record;
$changed['report']['context'][0]['citation'] = 'https://policies.example/refunds/annual/v3';
try {
    DecisionRecord::replay($changed, $audit);
    throw new LogicException('A decision with a different citation passed replay.');
} catch (RuntimeException $error) {
    same('Decision record does not match the replayed audit.', $error->getMessage());
}

$corrected = $audit->run(new Catalog($catalog), $staleRecall, asOf: '2026-11-05', knownOn: '2026-11-10');
same(['annual-refunds-v3'], array_column($corrected['context'], 'document_id'));

$scheduled = $audit->run(new Catalog($catalog), $staleRecall, asOf: '2026-12-01', knownOn: '2026-11-23');
same(['annual-refunds-v4'], array_column($scheduled['context'], 'document_id'));
same([], $audit->run(new Catalog($catalog), $recall, asOf: '2026-11-05', knownOn: '2026-08-01')['context']);

$sameEffectiveDate = $catalog;
$sameEffectiveDate['revisions'][2]['effective_on'] = '2026-10-01';
$history = $audit->run(new Catalog($sameEffectiveDate), $staleRecall, asOf: '2026-10-15', knownOn: '2026-10-15');
$correction = $audit->run(new Catalog($sameEffectiveDate), $staleRecall, asOf: '2026-10-15', knownOn: '2026-11-10');
same(['annual-refunds-v2'], array_column($history['context'], 'document_id'));
same(['annual-refunds-v3'], array_column($correction['context'], 'document_id'));

$latePredecessor = $catalog;
$latePredecessor['revisions'][1]['published_on'] = '2026-11-12';
$blockedPath = $audit->run(new Catalog($latePredecessor), $staleRecall, asOf: '2026-11-05', knownOn: '2026-11-10');
same('conflicting', $blockedPath['decisions'][0]['status']);
same([], $blockedPath['context']);
$visiblePath = $audit->run(new Catalog($latePredecessor), $staleRecall, asOf: '2026-11-05', knownOn: '2026-11-12');
same(['annual-refunds-v3'], array_column($visiblePath['context'], 'document_id'));

$report = $audit->run(new Catalog($catalog), $recall, '2026-09-30');
same(['current', 'unverified', 'not_yet_effective', 'not_yet_effective'], array_column($report['decisions'], 'status'));
same(['annual-refunds-v1'], array_column($report['context'], 'document_id'));

$report = $audit->run(new Catalog($catalog), $recall, '2026-12-01');
same(['superseded', 'unverified', 'current', 'superseded'], array_column($report['decisions'], 'status'));
same(['annual-refunds-v4'], array_column($report['context'], 'document_id'));

$report = $audit->run(new Catalog($catalog), $recall, '2026-08-01');
same([], $report['context']);

$report = $audit->run(new Catalog($catalog), $staleRecall, '2026-11-23');
same('superseded', $report['decisions'][0]['status']);
same('annual-refunds-v3', $report['decisions'][0]['resolved_to']);
same([
    [
        'text' => 'Annual plans have a 14-day refund window.',
        'citation' => 'https://policies.example/refunds/annual/v3',
        'document_id' => 'annual-refunds-v3',
        'recovered_from' => 'annual-refunds-v1',
    ],
], $report['context']);

$multipleOld = $staleRecall;
$multipleOld['results'][] = ['id' => 'second-old', 'document_id' => 'annual-refunds-v2', 'text' => 'Old refund rule.'];
$report = $audit->run(new Catalog($catalog), $multipleOld, '2026-11-23');
same(['annual-refunds-v3'], array_column($report['context'], 'document_id'));
same(['annual-refunds-v1'], array_column($report['context'], 'recovered_from'));

$incorrectRecall = $recall;
$incorrectRecall['results'][3]['text'] = 'Annual plans have a 90-day refund window.';
$report = $audit->run(new Catalog($catalog), $incorrectRecall, '2026-11-23');
same(['Annual plans have a 14-day refund window.'], array_column($report['context'], 'text'));
same([], array_column($report['context'], 'recovered_from'));

$withoutExcerpt = $catalog;
unset($withoutExcerpt['revisions'][2]['excerpt']);
$report = $audit->run(new Catalog($withoutExcerpt), $staleRecall, '2026-11-23');
same([], $report['context']);
$report = $audit->run(new Catalog($withoutExcerpt), $incorrectRecall, '2026-11-23');
same(['Annual plans have a 90-day refund window.'], array_column($report['context'], 'text'));
$record = DecisionRecord::capture(new Catalog($withoutExcerpt), $incorrectRecall, $report);
same([null, null, null, 'Annual plans have a 90-day refund window.'], array_column($record['recall']['results'], 'text'));
same($report, DecisionRecord::replay($record, $audit));

$catalog['revisions'][] = [
    'id' => 'annual-refunds-v3-branch',
    'policy' => 'annual-refunds',
    'effective_on' => '2026-11-02',
    'citation' => 'https://policies.example/refunds/annual/v3-branch',
    'excerpt' => 'Alternate policy.',
    'supersedes' => ['annual-refunds-v2'],
];
$report = $audit->run(new Catalog($catalog), $recall, '2026-11-23');
same('conflicting', $report['decisions'][3]['status']);
same(['annual-refunds-v3', 'annual-refunds-v3-branch'], $report['decisions'][3]['current_revisions']);
same([], $report['context']);
$report = $audit->run(new Catalog($catalog), $staleRecall, '2026-11-23');
same([], $report['context']);
same([], array_column($report['decisions'], 'resolved_to'));

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
