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
    const db = new RelateDb();
    seed(db, { nodes, edges }, catalog.revisions);
    return { db, catalog, fullRecall, staleRecall, successors };
})();

self.addEventListener('message', async ({ data: { date, staleOnly, request } }) => {
    try {
        const { db, catalog, fullRecall, staleRecall, successors } = await ready;
        const recall = staleOnly ? staleRecall : fullRecall;
        const report = audit(db, successors, catalog.revisions, recall, date);
        self.postMessage({ request, report, catalog, recall, query: successors });
    } catch (error) {
        self.postMessage({ request, error: error.message });
    }
});
