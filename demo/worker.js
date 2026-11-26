import init, { RelateDb } from './relate/relate.js';
import { audit, seed } from './audit.js';
import { evaluateDataset } from './evaluation.js';

async function read(file) {
    const response = await fetch(new URL(file, import.meta.url));
    if (!response.ok) throw new Error('Could not load ' + file);
    return file.endsWith('.json') ? response.json() : response.text();
}

const ready = (async () => {
    const files = [
        'examples/policies.json', 'examples/recall.json', 'examples/stale-recall.json',
        'queries/create-revisions.cypher', 'queries/create-edges.cypher', 'queries/successors.cypher',
    ];
    const [catalog, fullRecall, staleRecall, nodes, edges, successors] = await Promise.all(files.map(read));
    await init();
    return { catalog, fullRecall, staleRecall, queries: { nodes, edges, successors } };
})();

function runAudit(queries, catalog, recall, date, knownOn) {
    const db = new RelateDb();
    try {
        seed(db, queries, catalog.revisions, knownOn);
        return audit(db, queries.successors, catalog.revisions, recall, date, knownOn);
    } finally {
        db.free();
    }
}

self.addEventListener('message', async ({ data: { date, knownOn = null, staleOnly, request } }) => {
    try {
        const { catalog, fullRecall, staleRecall, queries } = await ready;
        if (request.side === 'evaluation') {
            const dataset = await read('examples/evaluation.json');
            const evaluation = await evaluateDataset(
                dataset,
                file => read('examples/' + file),
                (caseCatalog, recall, asOf, cutoff) => runAudit(queries, caseCatalog, recall, asOf, cutoff),
            );
            self.postMessage({ request, evaluation });
            return;
        }
        if (!isDate(date) || (knownOn !== null && !isDate(knownOn))) {
            throw new Error('Use a valid date in YYYY-MM-DD format.');
        }
        const recall = staleOnly ? staleRecall : fullRecall;
        const report = runAudit(queries, catalog, recall, date, knownOn);
        self.postMessage({ request, report, catalog, recall, query: queries.nodes.trim() + '\n\n' + queries.successors });
    } catch (error) {
        self.postMessage({ request, error: error.message });
    }
});

function isDate(value) {
    return typeof value === 'string'
        && /^\d{4}-\d{2}-\d{2}$/.test(value)
        && !value.startsWith('0000-')
        && Number.isFinite(Date.parse(value))
        && new Date(value).toISOString().slice(0, 10) === value;
}
