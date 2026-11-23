<?php

declare(strict_types=1);

namespace MemoryPolicyAudit;

use InvalidArgumentException;
use RuntimeException;

final class Audit
{
    public function __construct(private readonly Relate $relate)
    {
    }

    public function run(Catalog $catalog, array $recall, string $asOf, ?string $knownOn = null): array
    {
        Catalog::date($asOf);
        if ($knownOn !== null) {
            Catalog::date($knownOn);
        }
        if (!is_array($recall['results'] ?? null) || !array_is_list($recall['results'])) {
            throw new InvalidArgumentException('The recall response must contain a results list.');
        }

        $successors = $this->relate->successors($catalog, $asOf, $knownOn);
        $tips = [];
        foreach ($catalog->revisions as $id => $revision) {
            if ($revision['effective_on'] > $asOf || ($knownOn !== null && $revision['published_on'] > $knownOn)) {
                continue;
            }
            if (!array_key_exists($id, $successors)) {
                throw new RuntimeException("Relate did not return effective revision {$id}.");
            }
            if ($successors[$id] === []) {
                $tips[$revision['policy']][] = $revision['id'];
            }
        }
        foreach ($tips as &$policyTips) {
            sort($policyTips, SORT_STRING);
        }
        unset($policyTips);

        $decisions = [];
        $context = [];
        $currentDocuments = [];
        $recoverable = [];
        foreach ($recall['results'] as $index => $result) {
            if (!is_array($result)) {
                throw new InvalidArgumentException('Each recall result must be an object.');
            }

            $memoryId = Catalog::requiredString($result, 'id');
            $text = Catalog::requiredString($result, 'text');
            $documentId = $result['document_id'] ?? null;
            if ($documentId !== null && !is_string($documentId)) {
                throw new InvalidArgumentException('A recall document_id must be a string or null.');
            }

            $revision = $documentId === null ? null : ($catalog->revisions[$documentId] ?? null);
            $decision = [
                'memory_id' => $memoryId,
                'document_id' => $documentId,
                'status' => 'unverified',
            ];

            if ($revision !== null && $knownOn !== null && $revision['published_on'] > $knownOn) {
                $decision['status'] = 'not_yet_published';
                $decision['published_on'] = $revision['published_on'];
            } elseif ($revision !== null) {
                $policyTips = $tips[$revision['policy']] ?? [];
                $decision += [
                    'policy' => $revision['policy'],
                    'effective_on' => $revision['effective_on'],
                    'citation' => $revision['citation'],
                ];

                if ($revision['effective_on'] > $asOf) {
                    $decision['status'] = 'not_yet_effective';
                } elseif ($successors[$documentId] !== []) {
                    $decision['status'] = 'superseded';
                    $decision['superseded_by'] = $successors[$documentId];

                    if (count($policyTips) === 1 && in_array($policyTips[0], $successors[$documentId], true)) {
                        $replacement = $catalog->revisions[$policyTips[0]];
                        if ($replacement['excerpt'] !== null) {
                            $recoverable[$index] = $replacement;
                        }
                    }
                } elseif (count($policyTips) > 1) {
                    $decision['status'] = 'conflicting';
                    $decision['current_revisions'] = $policyTips;
                } else {
                    $decision['status'] = 'current';
                    if ($revision['excerpt'] === null || !isset($currentDocuments[$documentId])) {
                        $context[$index] = [
                            'text' => $revision['excerpt'] ?? $text,
                            'citation' => $revision['citation'],
                            'document_id' => $documentId,
                        ];
                    }
                    $currentDocuments[$documentId] = true;
                }
            }

            $decisions[] = $decision;
        }

        // A directly recalled current source takes precedence. Substitute at most once per missing source.
        foreach ($recoverable as $index => $replacement) {
            $id = $replacement['id'];
            if (isset($currentDocuments[$id])) {
                continue;
            }

            $decisions[$index]['resolved_to'] = $id;
            $context[$index] = [
                'text' => $replacement['excerpt'],
                'citation' => $replacement['citation'],
                'document_id' => $id,
                'recovered_from' => $decisions[$index]['document_id'],
            ];
            $currentDocuments[$id] = true;
        }

        ksort($context);

        $report = ['as_of' => $asOf];
        if ($knownOn !== null) {
            $report['known_on'] = $knownOn;
        }

        return $report + ['decisions' => $decisions, 'context' => array_values($context)];
    }
}
