<?php

declare(strict_types=1);

namespace MemoryPolicyAudit;

final class DecisionComparison
{
    public function __construct(private readonly Audit $audit)
    {
    }

    public function compare(array $before, array $after): array
    {
        $beforeReport = DecisionRecord::replay($before, $this->audit);
        $afterReport = DecisionRecord::replay($after, $this->audit);
        $beforeCatalog = (new Catalog($before['catalog']))->revisions;
        $afterCatalog = (new Catalog($after['catalog']))->revisions;
        $beforeVisible = self::visibleRevisions($beforeCatalog, $beforeReport['known_on'] ?? null);
        $afterVisible = self::visibleRevisions($afterCatalog, $afterReport['known_on'] ?? null);
        $beforeContext = self::contextByPolicy($beforeReport['context'], $beforeCatalog);
        $afterContext = self::contextByPolicy($afterReport['context'], $afterCatalog);

        $changes = [];
        $policies = array_unique([...array_keys($beforeContext), ...array_keys($afterContext)]);
        sort($policies, SORT_STRING);
        foreach ($policies as $policy) {
            $previous = $beforeContext[$policy] ?? [];
            $current = $afterContext[$policy] ?? [];
            if ($previous !== $current) {
                $changes[] = ['policy' => (string) $policy, 'before' => $previous, 'after' => $current];
            }
        }

        ksort($beforeCatalog, SORT_STRING);
        ksort($afterCatalog, SORT_STRING);

        return [
            'before' => ['as_of' => $beforeReport['as_of'], 'known_on' => $beforeReport['known_on'] ?? null],
            'after' => ['as_of' => $afterReport['as_of'], 'known_on' => $afterReport['known_on'] ?? null],
            'catalog_changed' => $beforeCatalog !== $afterCatalog,
            'recall_sources_changed' => self::recallSources($before) !== self::recallSources($after),
            'newly_visible' => array_values(array_diff_key($afterVisible, $beforeVisible)),
            'no_longer_visible' => array_values(array_diff_key($beforeVisible, $afterVisible)),
            'context_changes' => $changes,
        ];
    }

    private static function visibleRevisions(array $catalog, ?string $knownOn): array
    {
        $visible = [];
        foreach ($catalog as $id => $revision) {
            if ($knownOn === null || $revision['published_on'] <= $knownOn) {
                $visible[$id] = array_intersect_key($revision, array_flip(['id', 'policy', 'published_on', 'effective_on', 'citation']));
            }
        }
        ksort($visible, SORT_STRING);

        return $visible;
    }

    private static function contextByPolicy(array $context, array $catalog): array
    {
        $byPolicy = [];
        foreach ($context as $entry) {
            $revision = $catalog[$entry['document_id']];
            $byPolicy[$revision['policy']][] = [
                'document_id' => $entry['document_id'],
                'text' => $entry['text'],
                'citation' => $entry['citation'],
            ];
        }

        return $byPolicy;
    }

    private static function recallSources(array $record): array
    {
        return array_map(static fn (array $result): array => [$result['id'], $result['document_id']], $record['recall']['results']);
    }
}
