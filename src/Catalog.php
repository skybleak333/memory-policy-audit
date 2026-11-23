<?php

declare(strict_types=1);

namespace MemoryPolicyAudit;

use InvalidArgumentException;

final class Catalog
{
    /** @var array<string, array{id: string, policy: string, effective_on: string, citation: string, supersedes: list<string>, excerpt: ?string}> */
    public readonly array $revisions;

    public function __construct(array $data)
    {
        if (!is_array($data['revisions'] ?? null) || !array_is_list($data['revisions'])) {
            throw new InvalidArgumentException('The catalog must contain a revisions list.');
        }

        $revisions = [];
        foreach ($data['revisions'] as $record) {
            if (!is_array($record)) {
                throw new InvalidArgumentException('Each catalog revision must be an object.');
            }

            $id = self::requiredString($record, 'id');
            if (isset($revisions[$id])) {
                throw new InvalidArgumentException("Duplicate revision ID: {$id}.");
            }

            $citation = self::requiredString($record, 'citation');
            if (!filter_var($citation, FILTER_VALIDATE_URL) || !in_array(parse_url($citation, PHP_URL_SCHEME), ['https', 'http'], true)) {
                throw new InvalidArgumentException("Revision {$id} needs an HTTP(S) citation.");
            }

            $predecessors = $record['supersedes'] ?? [];
            if (!is_array($predecessors) || !array_is_list($predecessors)) {
                throw new InvalidArgumentException("Revision {$id} needs a list of predecessor IDs in supersedes.");
            }
            foreach ($predecessors as $predecessor) {
                if (!is_string($predecessor) || trim($predecessor) === '') {
                    throw new InvalidArgumentException("Revision {$id} contains an invalid predecessor ID.");
                }
            }

            $revisions[$id] = [
                'id' => $id,
                'policy' => self::requiredString($record, 'policy'),
                'effective_on' => self::date(self::requiredString($record, 'effective_on')),
                'citation' => $citation,
                'supersedes' => array_values(array_unique($predecessors, SORT_STRING)),
                'excerpt' => isset($record['excerpt']) ? self::requiredString($record, 'excerpt') : null,
            ];
        }

        foreach ($revisions as $revision) {
            foreach ($revision['supersedes'] as $predecessorId) {
                $predecessor = $revisions[$predecessorId] ?? null;
                if ($predecessor === null || $predecessor['policy'] !== $revision['policy'] || $predecessor['effective_on'] >= $revision['effective_on']) {
                    throw new InvalidArgumentException("Revision {$revision['id']} must supersede an earlier revision of the same policy.");
                }
            }
        }

        $this->revisions = $revisions;
    }

    public static function date(string $date): string
    {
        if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException("Expected a calendar date in YYYY-MM-DD format, got {$date}.");
        }

        return $date;
    }

    public static function requiredString(array $record, string $key): string
    {
        $value = $record[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Expected a non-empty {$key} string.");
        }

        return $value;
    }
}
