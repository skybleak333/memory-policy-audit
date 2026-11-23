import init, { RelateDb } from './relate/relate.js';
import { audit, seed } from './audit.js';

const ready = (async () => {
    const files = [
        'examples/policies.json', 'examples/recall.json', 'examples/stale-recall.json',
        'queries/create-revisions.cypher', 'queries/create-edges.cypher', 'queries/successors.cypher',
    ];
    const [catalog, fullRecall, staleRecall, nodes, edges, successors] = await Promise.all(
        files.map(async file => {
            const response = await fetch(new URL(file, import.meta.url));
            if (!response.ok) throw new Error('Could not load ' + file);
            return file.endsWith('.json') ? response.json() : response.text();
        }),
    );
    await init();
    return { catalog, fullRecall, staleRecall, queries: { nodes, edges, successors } };
})();

self.addEventListener('message', async ({ data: { date, knownOn = null, staleOnly, request } }) => {
    try {
        if (!isDate(date) || (knownOn !== null && !isDate(knownOn))) {
            throw new Error('Use a valid date in YYYY-MM-DD format.');
        }
        const { catalog, fullRecall, staleRecall, queries } = await ready;
        const recall = staleOnly ? staleRecall : fullRecall;
        const db = new RelateDb();
        try {
            seed(db, queries, catalog.revisions, knownOn);
            const report = audit(db, queries.successors, catalog.revisions, recall, date, knownOn);
            self.postMessage({ request, report, catalog, recall, query: queries.nodes.trim() + '\n\n' + queries.successors });
        } finally {
            db.free();
        }
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
