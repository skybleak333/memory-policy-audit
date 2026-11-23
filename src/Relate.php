<?php

declare(strict_types=1);

namespace MemoryPolicyAudit;

use RuntimeException;

final class Relate
{
    public function __construct(private readonly string $binary = 'relate')
    {
    }

    /** @return array<string, list<string>> Revision ID => effective revisions reachable through incoming SUPERSEDES edges. */
    public function successors(Catalog $catalog, string $asOf): array
    {
        if ($catalog->revisions === []) {
            return [];
        }

        $directory = sys_get_temp_dir().'/memory-policy-audit-'.bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Could not create a temporary Relate store.');
        }

        $database = $directory.'/policies.rl';
        $parameters = $directory.'/revisions.json';

        try {
            if (false === file_put_contents($parameters, json_encode(array_values($catalog->revisions), JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Could not write the Relate query parameters.');
            }

            $parametersOption = ['--fetch-params', "revisions={$parameters}"];
            $this->query($database, <<<'CYPHER'
                UNWIND $revisions AS item
                CREATE (:Revision {id: item.id, effective_on: item.effective_on})
                CYPHER, $parametersOption);
            $this->query($database, <<<'CYPHER'
                UNWIND $revisions AS item
                UNWIND item.supersedes AS predecessorId
                MATCH (newer:Revision {id: item.id}), (older:Revision {id: predecessorId})
                CREATE (newer)-[:SUPERSEDES]->(older)
                CYPHER, $parametersOption);

            // The leading endpoint is already bound. Keep it on the left of the variable-length pattern.
            $rows = $this->query($database, <<<'CYPHER'
                MATCH (revision:Revision)
                WHERE revision.effective_on <= $date
                OPTIONAL MATCH (revision)<-[:SUPERSEDES*1..]-(newer:Revision)
                WHERE newer.effective_on <= $date
                RETURN revision.id AS id, collect(DISTINCT newer.id) AS successors
                CYPHER, ['--read-only', '--param', 'date='.json_encode($asOf, JSON_THROW_ON_ERROR)]);

            $byRevision = [];
            foreach ($rows as $row) {
                $successors = $row['successors'];
                sort($successors, SORT_STRING);
                $byRevision[$row['id']] = $successors;
            }

            return $byRevision;
        } finally {
            foreach ([$database, $parameters] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    /** @return list<array<string, mixed>> */
    private function query(string $database, string $statement, array $options): array
    {
        $command = [
            $this->binary, '--database', $database, '--mode', 'envelope', '--no-agent-mode', '--max-rows', '0',
            '--timeout', '10s', ...$options, $statement,
        ];

        $stdout = tmpfile();
        $stderr = tmpfile();
        if ($stdout === false || $stderr === false) {
            throw new RuntimeException('Could not create temporary files for Relate output.');
        }

        try {
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => $stdout, 2 => $stderr], $pipes, env_vars: [...getenv(), 'DO_NOT_TRACK' => '1']);
            if ($process === false) {
                throw new RuntimeException("Could not run {$this->binary}.");
            }

            fclose($pipes[0]);
            $status = proc_close($process);
            rewind($stdout);
            rewind($stderr);
            $output = stream_get_contents($stdout);

            if ($status !== 0) {
                $detail = trim((string) stream_get_contents($stderr)) ?: trim((string) $output);
                throw new RuntimeException("Relate exited with status {$status}: {$detail}");
            }

            $envelope = json_decode((string) $output, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($envelope['rows'] ?? null) || ($envelope['truncated'] ?? true)) {
                throw new RuntimeException('Relate did not return a complete row set.');
            }

            return $envelope['rows'];
        } finally {
            fclose($stdout);
            fclose($stderr);
        }
    }
}
