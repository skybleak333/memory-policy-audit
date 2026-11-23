export function seed(db, queries, revisions) {
    const params = { revisions: revisions.map(revision => ({ supersedes: [], ...revision })) };
    execute(db, queries.nodes, params);
    execute(db, queries.edges, params);
}

export function audit(db, statement, revisions, recall, date) {
    const catalog = new Map(revisions.map(revision => [revision.id, revision]));
    const rows = execute(db, statement, { date });
    const successors = new Map(rows.map(row => [row.id, [...row.successors].sort()]));
    const tips = new Map();

    for (const revision of revisions) {
        if (revision.effective_on > date) continue;
        const newer = successors.get(revision.id);
        if (!newer) throw new Error('Relate did not return revision ' + revision.id);
        if (newer.length === 0) {
            const active = tips.get(revision.policy) ?? [];
            active.push(revision.id);
            tips.set(revision.policy, active);
        }
    }

    const decisions = [];
    const context = [];
    for (const result of recall.results) {
        const revision = catalog.get(result.document_id);
        const decision = { memory_id: result.id, document_id: result.document_id ?? null, status: 'unverified' };
        if (revision) {
            Object.assign(decision, {
                policy: revision.policy,
                effective_on: revision.effective_on,
                citation: revision.citation,
            });
            if (revision.effective_on > date) {
                decision.status = 'not_yet_effective';
            } else if (successors.get(revision.id).length > 0) {
                decision.status = 'superseded';
                decision.superseded_by = successors.get(revision.id);
            } else if (tips.get(revision.policy).length > 1) {
                decision.status = 'conflicting';
                decision.current_revisions = [...tips.get(revision.policy)].sort();
            } else {
                decision.status = 'current';
                context.push({ text: result.text, citation: revision.citation, document_id: revision.id });
            }
        }
        decisions.push(decision);
    }

    return { as_of: date, decisions, context };
}

function execute(db, statement, params) {
    const result = JSON.parse(db.execute(statement, JSON.stringify(params)));
    if (result.error || result.truncated || !Array.isArray(result.rows)) {
        throw new Error(result.error?.message ?? 'Relate did not return a complete row set.');
    }
    return result.rows;
}
