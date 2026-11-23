export function registerActions(actions, { bridge = false } = {}) {
    actions.register({
        name: 'lookup_refund_policy',
        description: 'Find the approved example annual refund policy on an effective date, with an optional publication cutoff.',
        effect: 'read',
        availability: bridge ? 'bridge' : 'local-only',
        // This action reads only the synthetic catalog already published by the demo.
        callerOrigins: bridge ? ['*'] : [],
        inputSchema: {
            type: 'object',
            properties: {
                as_of: { type: 'string', format: 'date' },
                known_on: { type: 'string', format: 'date', description: 'Only consider policies published by this date.' },
            },
            required: ['as_of'],
            additionalProperties: false,
        },
        outputSchema: {
            type: 'object',
            properties: {
                as_of: { type: 'string', format: 'date' },
                known_on: { type: 'string', format: 'date' },
                context: {
                    type: 'array',
                    items: {
                        type: 'object',
                        properties: {
                            text: { type: 'string' },
                            citation: { type: 'string' },
                            document_id: { type: 'string' },
                            recovered_from: { type: 'string' },
                        },
                        required: ['text', 'citation', 'document_id'],
                        additionalProperties: false,
                    },
                },
            },
            required: ['as_of', 'context'],
            additionalProperties: false,
        },
        execute: ({ as_of, known_on }) => resolvePolicy(as_of, known_on),
    });
}

async function resolvePolicy(date, knownOn) {
    const worker = new Worker(new URL('../worker.js', import.meta.url), { type: 'module' });
    let timeout;
    try {
        return await new Promise((resolve, reject) => {
            timeout = setTimeout(() => reject(new Error('Policy graph took too long to respond.')), 10_000);
            worker.addEventListener('error', () => reject(new Error('The policy graph could not load.')), { once: true });
            worker.addEventListener('message', ({ data }) => {
                if (data.error) {
                    reject(new Error('The policy graph could not resolve the requested date.'));
                    return;
                }
                const { as_of, known_on, context } = data.report;
                resolve(known_on === undefined ? { as_of, context } : { as_of, known_on, context });
            }, { once: true });
            worker.postMessage({ date, knownOn, staleOnly: true, request: 1 });
        });
    } finally {
        clearTimeout(timeout);
        worker.terminate();
    }
}
