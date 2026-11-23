import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import init, { RelateDb } from '@relate/relate-wasm';
import { audit, captureDecision, seed } from './audit.js';

const root = new URL('../', import.meta.url);
const read = file => readFile(new URL(file, root), 'utf8');
const { revisions } = JSON.parse(await read('examples/policies.json'));
const [nodes, edges, successors] = await Promise.all(
    ['create-revisions', 'create-edges', 'successors'].map(file => read('queries/' + file + '.cypher')),
);

const binary = process.argv[2] ?? 'relate';
const cli = (...args) => JSON.parse(execFileSync('php', ['bin/memory-audit', ...args, '--relate=' + binary], {
    cwd: fileURLToPath(root),
    encoding: 'utf8',
}));
const module = new URL('./node_modules/@relate/relate-wasm/relate_bg.wasm', import.meta.url);
await init({ module_or_path: await readFile(module) });

let checked = 0;
let exported;
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
                const params = [
                    'audit', '--catalog=examples/policies.json', '--recall=' + fixture, '--as-of=' + date,
                    ...(knownOn === null ? [] : ['--known-on=' + knownOn]),
                ];
                const report = audit(db, successors, revisions, recall, date, knownOn);
                assert.deepEqual(report, cli(...params, '--json'));
                if (knownOn === '2026-11-05' && fixture === 'examples/stale-recall.json') {
                    exported = captureDecision(revisions, recall, report);
                    assert.deepEqual(exported, cli(...params, '--record'));
                }
                checked++;
            }
        }
    } finally {
        db.free();
    }
}

assert.ok(exported);
const replayDirectory = await mkdtemp(join(tmpdir(), 'memory-policy-replay-'));
try {
    const file = join(replayDirectory, 'decision.json');
    await writeFile(file, JSON.stringify(exported));
    assert.deepEqual(cli('replay', '--record=' + file, '--json'), exported.report);
} finally {
    await rm(replayDirectory, { recursive: true, force: true });
}

console.log('Browser WASM and PHP CLI agree on ' + checked + ' date and recall combinations.');
console.log('A browser decision record replays on the PHP CLI.');
