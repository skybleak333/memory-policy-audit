function publicationDate(revision) {
    return revision.published_on ?? revision.effective_on;
}

function isPublished(revision, knownOn) {
    return knownOn === null || publicationDate(revision) <= knownOn;
}

export function seed(db, queries, revisions, knownOn = null) {
    const params = { revisions: revisions.map(revision => ({
        id: revision.id,
        effective_on: revision.effective_on,
        published_on: publicationDate(revision),
        supersedes: revision.supersedes ?? [],
    })) };
    execute(db, queries.nodes, { ...params, known_on: knownOn });
    execute(db, queries.edges, params);
}

export function audit(db, statement, revisions, recall, date, knownOn = null) {
    const catalog = new Map(revisions.map(revision => [revision.id, revision]));
    const rows = execute(db, statement, { date });
    const successors = new Map(rows.map(row => [row.id, [...row.successors].sort()]));
    const tips = new Map();

    for (const revision of revisions) {
        if (revision.effective_on > date || !isPublished(revision, knownOn)) continue;
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
        if (revision && !isPublished(revision, knownOn)) {
            decision.status = 'not_yet_published';
            decision.published_on = publicationDate(revision);
        } else if (revision) {
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

    const report = { as_of: date };
    if (knownOn !== null) report.known_on = knownOn;
    return { ...report, decisions, context: context.filter(Boolean) };
}

export function captureDecision(revisions, recall, report) {
    const catalog = new Map(revisions.map(revision => [revision.id, revision]));
    return {
        format: 'memory-policy-audit/v1',
        catalog: { revisions: revisions.map(revision => ({
            id: revision.id,
            policy: revision.policy,
            effective_on: revision.effective_on,
            published_on: publicationDate(revision),
            citation: revision.citation,
            supersedes: [...new Set(revision.supersedes ?? [])],
            excerpt: revision.excerpt ?? null,
        })) },
        recall: { results: report.decisions.map((decision, index) => ({
            id: decision.memory_id,
            document_id: decision.document_id,
            text: decision.status === 'current' && catalog.get(decision.document_id).excerpt == null
                ? recall.results[index].text : null,
        })) },
        report,
    };
}

function execute(db, statement, params) {
    const result = JSON.parse(db.execute(statement, JSON.stringify(params)));
    if (result.error || result.truncated || !Array.isArray(result.rows)) {
        throw new Error(result.error?.message ?? 'Relate did not return a complete row set.');
    }
    return result.rows;
}
