import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import init, { RelateDb } from '@relate/relate-wasm';
import { audit, seed } from './audit.js';

const root = new URL('../', import.meta.url);
const read = file => readFile(new URL(file, root), 'utf8');
const { revisions } = JSON.parse(await read('examples/policies.json'));
const [nodes, edges, successors] = await Promise.all(
    ['create-revisions', 'create-edges', 'successors'].map(file => read('queries/' + file + '.cypher')),
);

const binary = process.argv[2] ?? 'relate';
const module = new URL('./node_modules/@relate/relate-wasm/relate_bg.wasm', import.meta.url);
await init({ module_or_path: await readFile(module) });

let checked = 0;
for (const [knownOn, dates] of [
    [null, ['2026-09-30', '2026-11-05', '2026-11-23', '2026-12-01']],
    ['2026-11-05', ['2026-11-05']],
    ['2026-11-10', ['2026-11-05']],
    ['2026-11-23', ['2026-12-01']],
    ['2026-08-01', ['2026-11-05']],
]) {
    const db = new RelateDb();
    try {
        seed(db, { nodes, edges }, revisions, knownOn);
        for (const fixture of ['examples/recall.json', 'examples/stale-recall.json']) {
            const recall = JSON.parse(await read(fixture));
            for (const date of dates) {
                const php = execFileSync('php', [
                    'bin/memory-audit', 'audit', '--catalog=examples/policies.json', '--recall=' + fixture,
                    '--as-of=' + date, '--relate=' + binary, '--json',
                    ...(knownOn === null ? [] : ['--known-on=' + knownOn]),
                ], {
                    cwd: fileURLToPath(root),
                    encoding: 'utf8',
                });
                assert.deepEqual(audit(db, successors, revisions, recall, date, knownOn), JSON.parse(php));
                checked++;
            }
        }
    } finally {
        db.free();
    }
}

console.log('Browser WASM and PHP CLI agree on ' + checked + ' date and recall combinations.');
