import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import init, { RelateDb } from '@relate/relate-wasm';
import { audit, seed } from './audit.js';

const root = new URL('../', import.meta.url);
const read = file => readFile(new URL(file, root), 'utf8');
const { revisions } = JSON.parse(await read('examples/policies.json'));
const recall = JSON.parse(await read('examples/recall.json'));
const [nodes, edges, successors] = await Promise.all(
    ['create-revisions', 'create-edges', 'successors'].map(file => read('queries/' + file + '.cypher')),
);

const binary = process.argv[2] ?? 'relate';
const module = new URL('./node_modules/@relate/relate-wasm/relate_bg.wasm', import.meta.url);
await init({ module_or_path: await readFile(module) });

const db = new RelateDb();
try {
    seed(db, { nodes, edges }, revisions);
    for (const date of ['2026-09-30', '2026-11-23', '2026-12-01']) {
        const php = execFileSync('php', ['bin/memory-audit', 'demo', '--as-of=' + date, '--relate=' + binary, '--json'], {
            cwd: fileURLToPath(root),
            encoding: 'utf8',
        });
        assert.deepEqual(audit(db, successors, revisions, recall, date), JSON.parse(php));
    }
} finally {
    db.free();
}

console.log('Browser WASM and PHP CLI agree on all three demo dates.');
