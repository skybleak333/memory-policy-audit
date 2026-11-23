const status = document.querySelector('#status');
const report = document.querySelector('#report');
const dates = document.querySelectorAll('input[name="as-of"]');
const staleOnly = document.querySelector('#stale-only');
const worker = new Worker(new URL('./worker.js', import.meta.url), { type: 'module' });
let request = 0;

const labels = {
    current: 'Current',
    superseded: 'Superseded',
    unverified: 'Unverified',
    not_yet_effective: 'Future policy',
    conflicting: 'Conflicting',
};

const formatDate = new Intl.DateTimeFormat('en', { month: 'short', day: 'numeric', timeZone: 'UTC' });

function show() {
    const date = document.querySelector('input[name="as-of"]:checked').value;
    status.classList.remove('error');
    status.textContent = 'Checking the version graph…';
    report.setAttribute('aria-busy', 'true');
    worker.postMessage({ date, staleOnly: staleOnly.checked, request: ++request });
}

function element(tag, text, className = '') {
    const node = document.createElement(tag);
    node.className = className;
    node.textContent = text;
    return node;
}

function reason(decision) {
    switch (decision.status) {
        case 'current':
            return 'This is the current source on the selected date.';
        case 'superseded':
            if (decision.resolved_to) {
                return 'Relate followed the version graph to ' + decision.resolved_to + '.';
            }
            return 'Replaced by ' + decision.superseded_by.join(', ') + '.';
        case 'not_yet_effective':
            return 'Takes effect ' + formatDate.format(new Date(decision.effective_on)) + '.';
        case 'conflicting':
            return 'Current sources disagree: ' + decision.current_revisions.join(', ') + '.';
        default:
            return 'The source document is missing from the policy catalog.';
    }
}

function display(data, catalog, recall, query) {
    const active = data.context[0];
    document.querySelector('#accepted-text').textContent = active?.text ?? 'No current sources.';
    document.querySelector('#citation').textContent = active?.citation ?? '';
    const recovery = document.querySelector('#recovery');
    recovery.hidden = !active?.recovered_from;
    recovery.textContent = active?.recovered_from
        ? 'Recovered the approved excerpt from the version graph starting at ' + active.recovered_from + '.'
        : '';
    document.querySelector('#timeline').replaceChildren(...catalog.revisions.map(revision => {
        const item = element('li', revision.id.split('-').at(-1), active?.document_id === revision.id ? 'selected' : '');
        if (active?.document_id === revision.id) item.setAttribute('aria-current', 'true');
        const date = element('time', formatDate.format(new Date(revision.effective_on)));
        date.dateTime = revision.effective_on;
        item.append(date);
        return item;
    }));

    const facts = new Map(recall.results.map(result => [result.id, result.text]));
    document.querySelector('#decisions').replaceChildren(...data.decisions.map(decision => {
        const item = element('li', '', 'decision');
        const heading = document.createElement('header');
        heading.append(
            element('span', labels[decision.status], 'label ' + decision.status),
            element('span', decision.document_id ?? decision.memory_id, 'document'),
        );
        item.append(heading, element('p', facts.get(decision.memory_id), 'fact'), element('p', reason(decision), 'reason'));
        return item;
    }));

    document.querySelector('#cypher').textContent = query.trim();
    document.querySelector('#query-panel').hidden = false;
    report.hidden = false;
    report.setAttribute('aria-busy', 'false');
    const count = data.decisions.length;
    status.textContent = 'Relate checked ' + count + (count === 1 ? ' recalled fact.' : ' recalled facts.');
}

function fail(message) {
    report.setAttribute('aria-busy', 'false');
    report.hidden = true;
    document.querySelector('#query-panel').hidden = true;
    status.classList.add('error');
    status.textContent = 'Could not run the audit. ' + message;
}

worker.addEventListener('message', ({ data }) => {
    if (data.request !== request) return;
    if (data.error) {
        fail(data.error);
        return;
    }
    display(data.report, data.catalog, data.recall, data.query);
});

worker.addEventListener('error', () => fail('The Relate worker could not load.'));
for (const date of dates) date.addEventListener('change', show);
staleOnly.addEventListener('change', show);
staleOnly.checked = new URLSearchParams(window.location.search).get('recall') === 'stale';
show();
