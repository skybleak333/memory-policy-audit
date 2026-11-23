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
const exported = new Map();
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
                if (['2026-11-05', '2026-11-10'].includes(knownOn) && fixture === 'examples/stale-recall.json') {
                    const record = captureDecision(revisions, recall, report);
                    assert.deepEqual(record, cli(...params, '--record'));
                    exported.set(knownOn, record);
                }
                checked++;
            }
        }
    } finally {
        db.free();
    }
}

assert.equal(exported.size, 2);
const replayDirectory = await mkdtemp(join(tmpdir(), 'memory-policy-replay-'));
try {
    for (const [knownOn, record] of exported) {
        const file = join(replayDirectory, knownOn + '.json');
        await writeFile(file, JSON.stringify(record));
        assert.deepEqual(cli('replay', '--record=' + file, '--json'), record.report);
    }
    const difference = cli('compare', '--before=' + join(replayDirectory, '2026-11-05.json'), '--after=' + join(replayDirectory, '2026-11-10.json'), '--json');
    assert.deepEqual(difference.newly_visible.map(revision => revision.id), ['annual-refunds-v3']);
    assert.equal(difference.context_changes[0].before[0].citation, 'https://policies.example/refunds/annual/v2');
    assert.equal(difference.context_changes[0].after[0].citation, 'https://policies.example/refunds/annual/v3');
} finally {
    await rm(replayDirectory, { recursive: true, force: true });
}

console.log('Browser WASM and PHP CLI agree on ' + checked + ' date and recall combinations.');
console.log('The PHP CLI replays and compares exported browser decisions.');
