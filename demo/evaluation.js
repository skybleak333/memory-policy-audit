function orderedContext(context) {
    return context.map(entry => Object.fromEntries(
        Object.keys(entry).sort().map(key => [key, entry[key]]),
    ));
}

export async function evaluateDataset(dataset, read, runAudit) {
    const fixtures = new Map();
    function fixture(path) {
        if (!fixtures.has(path)) fixtures.set(path, read(path));
        return fixtures.get(path);
    }

    const cases = [];
    for (const test of dataset.cases) {
        const [catalog, recall] = await Promise.all([fixture(test.catalog ?? dataset.catalog), fixture(test.recall)]);
        const report = runAudit(catalog, recall, test.as_of, test.known_on ?? null);
        const actual = {
            statuses: report.decisions.map(decision => decision.status),
            context: orderedContext(report.context),
        };
        const expected = {
            statuses: test.expect.statuses,
            context: orderedContext(test.expect.context),
        };
        const differences = [];
        for (const field of ['statuses', 'context']) {
            if (JSON.stringify(expected[field]) !== JSON.stringify(actual[field])) {
                differences.push({ field, expected: expected[field], actual: actual[field] });
            }
        }
        cases.push({ id: test.id, passed: differences.length === 0, differences, report });
    }

    const passed = cases.filter(test => test.passed).length;
    return { passed, failed: cases.length - passed, cases };
}
