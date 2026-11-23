import { captureDecision } from './audit.js';

const status = document.querySelector('#status');
const report = document.querySelector('#report');
const dates = document.querySelectorAll('input[name="as-of"]');
const knownOn = document.querySelector('#known-on');
const compareKnownOn = document.querySelector('#compare-known-on');
const comparison = document.querySelector('#comparison');
const staleOnly = document.querySelector('#stale-only');
const downloadButton = document.querySelector('#download-decision');
const worker = new Worker(new URL('./worker.js', import.meta.url), { type: 'module' });
let request = 0;
let decisionRecord;
let comparisonRecord;
let comparisonError;

const labels = {
    current: 'Current',
    superseded: 'Superseded',
    unverified: 'Unverified',
    not_yet_effective: 'Future policy',
    not_yet_published: 'Published later',
    conflicting: 'Conflicting',
};

const formatDate = new Intl.DateTimeFormat('en', { month: 'short', day: 'numeric', timeZone: 'UTC' });

function show() {
    const date = document.querySelector('input[name="as-of"]:checked').value;
    status.classList.remove('error');
    status.textContent = 'Checking the version graph…';
    report.setAttribute('aria-busy', 'true');
    downloadButton.disabled = true;
    comparison.hidden = true;
    decisionRecord = comparisonRecord = comparisonError = undefined;
    const params = { date, staleOnly: staleOnly.checked };
    worker.postMessage({ ...params, knownOn: knownOn.value || null, request: { id: ++request, side: 'selected' } });
    if (compareKnownOn.value) {
        worker.postMessage({ ...params, knownOn: compareKnownOn.value, request: { id: request, side: 'comparison' } });
    }
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
        case 'not_yet_published':
            return 'Published ' + formatDate.format(new Date(decision.published_on)) + ', after the selected cutoff.';
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
        if (revision.published_on && revision.published_on !== revision.effective_on) {
            item.append(element('small', 'Published ' + formatDate.format(new Date(revision.published_on)), 'publication-date'));
        }
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
    decisionRecord = undefined;
    comparison.hidden = true;
    document.querySelector('#query-panel').hidden = true;
    downloadButton.disabled = true;
    status.classList.add('error');
    status.textContent = 'Could not run the audit. ' + message;
}

function displayComparison() {
    if (!decisionRecord || (!comparisonRecord && !comparisonError)) return;

    comparison.hidden = false;
    const error = document.querySelector('#comparison-error');
    error.hidden = !comparisonError;
    error.textContent = comparisonError ?? '';
    document.querySelector('#comparison-results').hidden = Boolean(comparisonError);
    if (comparisonError) return;

    const snapshots = [comparisonRecord.report, decisionRecord.report];
    document.querySelector('#comparison-sides').replaceChildren(...snapshots.map(({ known_on, context }, index) => {
        const side = element('section', '', 'comparison-side');
        const label = index === 0 ? 'Comparison cutoff' : 'Selected cutoff';
        const cutoff = known_on ? formatDate.format(new Date(known_on)) : 'all publications';
        side.append(element('h3', label + ' · ' + cutoff));
        if (context.length === 0) side.append(element('p', 'No current sources.', 'fact'));
        for (const entry of context) {
            side.append(element('p', entry.text, 'fact'), element('p', entry.citation, 'citation'));
        }
        return side;
    }));

    const changes = [];
    for (const revision of decisionRecord.catalog.revisions) {
        const published = revision.published_on;
        const [previouslyVisible, nowVisible] = snapshots.map(snapshot => !snapshot.known_on || published <= snapshot.known_on);
        if (previouslyVisible === nowVisible) continue;
        changes.push(element('li', (nowVisible ? 'Newly visible: ' : 'No longer visible: ')
            + revision.id + ', published ' + formatDate.format(new Date(published))
            + ', effective ' + formatDate.format(new Date(revision.effective_on)) + '.'));
    }
    document.querySelector('#publication-changes').replaceChildren(...changes);
}

worker.addEventListener('message', ({ data }) => {
    if (data.request.id !== request) return;
    if (data.request.side === 'comparison') {
        comparisonError = data.error;
        if (!data.error) comparisonRecord = captureDecision(data.catalog.revisions, data.recall, data.report);
        displayComparison();
        return;
    }
    if (data.error) {
        fail(data.error);
        return;
    }
    display(data.report, data.catalog, data.recall, data.query);
    decisionRecord = captureDecision(data.catalog.revisions, data.recall, data.report);
    downloadButton.disabled = false;
    displayComparison();
});

function downloadRecord(record) {
    const { as_of, known_on } = record.report;
    const blob = new Blob([JSON.stringify(record, null, 2) + '\n'], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'memory-policy-' + as_of + (known_on ? '-published-' + known_on : '') + '.json';
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

downloadButton.addEventListener('click', () => downloadRecord(decisionRecord));
document.querySelector('#download-comparison').addEventListener('click', () => downloadRecord(comparisonRecord));
document.querySelector('#download-selected').addEventListener('click', () => downloadRecord(decisionRecord));

worker.addEventListener('error', () => fail('The Relate worker could not load.'));
for (const date of dates) date.addEventListener('change', show);
knownOn.addEventListener('change', show);
compareKnownOn.addEventListener('change', show);
staleOnly.addEventListener('change', show);
const options = new URLSearchParams(window.location.search);
for (const date of dates) {
    if (date.value === options.get('as-of')) date.checked = true;
}
knownOn.value = options.get('known-on') ?? '';
compareKnownOn.value = options.get('compare-known-on') ?? '';
document.querySelector('#comparison-options').open = Boolean(compareKnownOn.value);
staleOnly.checked = options.get('recall') === 'stale';
show();
