export function seed(db, queries, revisions) {
    const params = { revisions: revisions.map(({ id, effective_on, supersedes = [] }) => ({ id, effective_on, supersedes })) };
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
    const currentDocuments = new Set();
    const recoverable = new Map();
    for (const [index, result] of recall.results.entries()) {
        const revision = catalog.get(result.document_id);
        const decision = { memory_id: result.id, document_id: result.document_id ?? null, status: 'unverified' };
        if (revision) {
            const policyTips = tips.get(revision.policy) ?? [];
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
                if (policyTips.length === 1 && decision.superseded_by.includes(policyTips[0])) {
                    const replacement = catalog.get(policyTips[0]);
                    if (replacement.excerpt != null) recoverable.set(index, replacement);
                }
            } else if (policyTips.length > 1) {
                decision.status = 'conflicting';
                decision.current_revisions = [...policyTips].sort();
            } else {
                decision.status = 'current';
                if (revision.excerpt == null || !currentDocuments.has(revision.id)) {
                    context[index] = { text: revision.excerpt ?? result.text, citation: revision.citation, document_id: revision.id };
                }
                currentDocuments.add(revision.id);
            }
        }
        decisions.push(decision);
    }

    for (const [index, replacement] of recoverable) {
        if (currentDocuments.has(replacement.id)) continue;
        decisions[index].resolved_to = replacement.id;
        context[index] = {
            text: replacement.excerpt,
            citation: replacement.citation,
            document_id: replacement.id,
            recovered_from: decisions[index].document_id,
        };
        currentDocuments.add(replacement.id);
    }

    return { as_of: date, decisions, context: context.filter(Boolean) };
}

function execute(db, statement, params) {
    const result = JSON.parse(db.execute(statement, JSON.stringify(params)));
    if (result.error || result.truncated || !Array.isArray(result.rows)) {
        throw new Error(result.error?.message ?? 'Relate did not return a complete row set.');
    }
    return result.rows;
}
