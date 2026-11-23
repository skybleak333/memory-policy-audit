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

    public function run(Catalog $catalog, array $recall, string $asOf): array
    {
        Catalog::date($asOf);
        if (!is_array($recall['results'] ?? null) || !array_is_list($recall['results'])) {
            throw new InvalidArgumentException('The recall response must contain a results list.');
        }

        $successors = $this->relate->successors($catalog, $asOf);
        $tips = [];
        foreach ($catalog->revisions as $id => $revision) {
            if ($revision['effective_on'] > $asOf) {
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
        foreach ($recall['results'] as $result) {
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

            if ($revision !== null) {
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
                } elseif (count($tips[$revision['policy']]) > 1) {
                    $decision['status'] = 'conflicting';
                    $decision['current_revisions'] = $tips[$revision['policy']];
                } else {
                    $decision['status'] = 'current';
                    $context[] = [
                        'text' => $text,
                        'citation' => $revision['citation'],
                        'document_id' => $documentId,
                    ];
                }
            }

            $decisions[] = $decision;
        }

        return ['as_of' => $asOf, 'decisions' => $decisions, 'context' => $context];
    }
}
