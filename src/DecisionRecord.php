<?php

declare(strict_types=1);

namespace MemoryPolicyAudit;

use InvalidArgumentException;
use RuntimeException;

final class DecisionRecord
{
    private const FORMAT = 'memory-policy-audit/v1';

    /** Capture the inputs of a completed audit and the report it produced. */
    public static function capture(Catalog $catalog, array $recall, array $report): array
    {
        $results = [];
        foreach ($report['decisions'] as $index => $decision) {
            $documentId = $decision['document_id'];
            $textUsed = $decision['status'] === 'current' && $catalog->revisions[$documentId]['excerpt'] === null;
            $results[] = [
                'id' => $decision['memory_id'],
                'document_id' => $documentId,
                'text' => $textUsed ? $recall['results'][$index]['text'] : null,
            ];
        }

        return [
            'format' => self::FORMAT,
            'catalog' => ['revisions' => array_values($catalog->revisions)],
            'recall' => ['results' => $results],
            'report' => $report,
        ];
    }

    public static function replay(array $record, Audit $audit): array
    {
        if (($record['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException('Unsupported decision record format.');
        }

        $report = $record['report'] ?? null;
        $catalog = $record['catalog'] ?? null;
        $results = $record['recall']['results'] ?? null;
        if (!is_array($report) || !is_array($catalog) || !is_array($results) || !array_is_list($results)) {
            throw new InvalidArgumentException('A decision record needs a catalog, recall results and a report.');
        }

        $asOf = Catalog::requiredString($report, 'as_of');
        $knownOn = array_key_exists('known_on', $report) ? Catalog::requiredString($report, 'known_on') : null;
        $catalog = new Catalog($catalog);

        foreach ($results as &$result) {
            if (!is_array($result) || !array_key_exists('text', $result)) {
                throw new InvalidArgumentException('Each recorded recall result needs a text field.');
            }
            // Audit validates all recall text. Only text used in context is persisted.
            $result['text'] ??= '[omitted from decision record]';
        }
        unset($result);

        $recall = ['results' => $results];
        $actual = $audit->run($catalog, $recall, $asOf, $knownOn);
        if (self::sortedKeys(self::capture($catalog, $recall, $actual)) !== self::sortedKeys($record)) {
            throw new RuntimeException('Decision record does not match the replayed audit.');
        }

        return $actual;
    }

    private static function sortedKeys(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = self::sortedKeys($entry);
            }
        }

        return $value;
    }
}
