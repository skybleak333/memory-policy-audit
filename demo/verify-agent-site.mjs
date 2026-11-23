import assert from 'node:assert/strict';
import { readFile, stat } from 'node:fs/promises';
import { createServer } from 'node:http';
import { extname, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const dist = fileURLToPath(new URL('./dist', import.meta.url));
const browserBinary = process.env.CHROME_BIN ?? (process.platform === 'darwin'
    ? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
    : '/usr/bin/google-chrome');
const testKey = 'test-credential-never-send-to-policy-site';
const privateField = 'support-note-for-caller-only';
const question = 'What was the refund window on 2026-11-05 using policies published by 2026-11-05?';
const historicalDates = { as_of: '2026-11-05', known_on: '2026-11-05' };
const mediaTypes = {
    '.html': 'text/html', '.js': 'text/javascript', '.json': 'application/json',
    '.mjs': 'text/javascript', '.css': 'text/css', '.wasm': 'application/wasm',
};
const send = (res, value) => res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify(value));

async function serve(route) {
    const server = createServer(async (req, res) => {
        try {
            const path = new URL(req.url, 'http://localhost').pathname;
            if (route?.(path, res)) return;
            let file = resolve(dist, '.' + decodeURIComponent(path));
            if (file !== dist && !file.startsWith(dist + sep)) throw new Error('Outside build output.');
            if ((await stat(file)).isDirectory()) file = resolve(file, 'index.html');
            const body = await readFile(file);
            res.writeHead(200, {
                'Access-Control-Allow-Origin': '*',
                'Content-Type': mediaTypes[extname(file)] ?? 'text/plain',
            }).end(body);
        } catch {
            res.writeHead(404).end();
        }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    return { server, origin: 'http://127.0.0.1:' + server.address().port };
}

const policy = await serve();
const target = new URL(process.argv[2] ?? policy.origin + '/agents/policy_audit');
let caller;
let browser;
try {
    caller = await serve((path, res) => {
        if (path === '/fixture') {
            res.writeHead(200, { 'Content-Type': 'text/html' }).end('<!doctype html><title>Support agent test</title>');
            return true;
        }
        if (path === '/agent-manifest.json') {
            send(res, {
                networks: [{ name: 'support', front_man: 'support_agent', hocon: './support.network.json' }],
                llm_router: { base_url: caller.origin + '/v1', byok_router: caller.origin + '/v1', mock: false },
                allowed_peers: [target.origin],
                peer_policy: { mode: 'closed' },
            });
            return true;
        }
        if (path === '/support.network.json') {
            send(res, {
                llm_config: { model_name: 'fixture-model' },
                max_steps: 5,
                tools: [{
                    name: 'support_agent',
                    function: { description: 'Ask the policy site about the active policy.' },
                    instructions: 'Look up the requested date through the policy site.',
                    tools: [target.href],
                }],
            });
            return true;
        }
        return false;
    });

    browser = await chromium.launch({ executablePath: browserBinary });
    const page = await browser.newPage();
    const contacts = [];
    const reportReceipts = [];
    page.on('request', request => {
        if (new URL(request.url()).origin === target.origin) {
            contacts.push(request.allHeaders());
        }
    });

    // Exercise the actual runtime and HTTP stream while keeping inference deterministic.
    await page.route(caller.origin + '/v1/chat/completions', async route => {
        const request = route.request();
        assert.equal(request.headers().authorization, 'Bearer ' + testKey);
        const { messages, tools } = request.postDataJSON();
        const last = messages.at(-1);
        const names = tools.map(tool => tool.function.name);
        let delta;
        if (last.role === 'tool') {
            if (last.content.includes('"status":"succeeded"') && last.content.includes('"as_of"')) {
                reportReceipts.push(JSON.parse(last.content).result);
            }
            delta = { content: last.content };
        } else {
            const name = names.find(name => name === 'refund_policy_reader')
                ?? names.find(name => name === 'website_lookup_refund_policy')
                ?? names.find(name => name.includes('policy_audit'));
            assert.ok(name, 'The runtime did not offer the expected tool. It offered ' + names.join(', '));
            assert.ok(!request.postData().includes(privateField));
            const args = name === 'website_lookup_refund_policy' ? historicalDates : { request: question };
            delta = { tool_calls: [{
                index: 0, id: 'policy-tool-call', type: 'function',
                function: { name, arguments: JSON.stringify(args) },
            }] };
        }
        await route.fulfill({
            contentType: 'text/event-stream',
            body: 'data: ' + JSON.stringify({ choices: [{ index: 0, delta }] }) + '\n\ndata: [DONE]\n\n',
        });
    });

    await page.goto(caller.origin + '/fixture');
    const run = async approval => page.evaluate(async ({ approval, testKey, privateField, question }) => {
        const { Engine } = await import('/agents/runtime/engine.js');
        const engine = new Engine({
            originUrl: location.origin,
            sandboxWorkerUrl: location.origin + '/agents/runtime/sandbox-worker.js',
            byokKey: testKey,
        });
        const consents = [];
        engine.onConsent = async info => {
            consents.push(info);
            return approval;
        };
        const result = await engine.runTurn(question, { slyData: { supportNote: privateField } });
        return { text: result.text, consents };
    }, { approval, testKey, privateField, question });

    const denied = await run('decline');
    assert.equal(denied.consents.length, 1);
    assert.match(denied.text, /declined/i);
    assert.equal(contacts.length, 0, 'The policy site was contacted before consent.');

    const approved = await run('allow');
    assert.equal(approved.consents.length, 1);
    assert.equal(approved.consents[0].peerOrigin, target.host);
    assert.match(approved.text, /21-day refund window/);
    assert.match(approved.text, /https:\/\/policies\.example\/refunds\/annual\/v2/);
    const expected = {
        ...historicalDates,
        context: [{
            text: 'Annual plans have a 21-day refund window.',
            citation: 'https://policies.example/refunds/annual/v2',
            document_id: 'annual-refunds-v2',
            recovered_from: 'annual-refunds-v1',
        }],
    };
    assert.deepEqual(reportReceipts[0], expected);
    assert.ok(contacts.length > 0);
    for (const pendingHeaders of contacts) {
        const headers = await pendingHeaders;
        assert.ok(!headers.authorization);
        assert.ok(!JSON.stringify(headers).includes(testKey));
    }
    console.log('Cross-origin call passed through the official action bridge after consent.');

    const assistantUrl = new URL('./', target);
    await page.goto(assistantUrl.href);
    await page.waitForFunction(() => Boolean(window.__nsweb?.engine?.top));
    const local = await page.evaluate(async dates => {
        const engine = window.__nsweb.engine;
        const scope = { base: engine.top.base, network: engine.top.network.name };
        const action = engine.actions.catalog(scope).find(action => action.name === 'lookup_refund_policy');
        if (!action || action.effect !== 'read' || action.availability !== 'local-only') throw new Error('Local read tool is unavailable.');
        const plan = engine.actions.prepare({ kind: 'action', action: action.ref, arguments: dates });
        const [receipt] = await engine.actions.execute(plan, () => true, 'local-policy-test');
        return receipt;
    }, historicalDates);
    assert.equal(local.status, 'succeeded', local.error);
    assert.deepEqual(local.result, expected);
    console.log('The assistant page returns the same approved policy from Relate.');

    const graphUrl = new URL('../', target);
    graphUrl.search = new URLSearchParams({ 'as-of': '2026-11-05', 'known-on': '2026-11-05', recall: 'stale' });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(graphUrl.href);
    await page.waitForFunction(() => document.querySelector('#citation').textContent.endsWith('/v2'));
    await page.getByLabel('Published by').fill('2026-11-10');
    await page.waitForFunction(() => document.querySelector('#citation').textContent.endsWith('/v3'));
    assert.equal(await page.locator('input[name="as-of"]:checked').inputValue(), '2026-11-05');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth), 390);
    console.log('The graph demo switches from v2 to v3 when only the publication cutoff changes.');

    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('button', { name: 'Download decision JSON' }).click(),
    ]);
    assert.equal(download.suggestedFilename(), 'memory-policy-2026-11-05-published-2026-11-10.json');
    const record = JSON.parse(await readFile(await download.path(), 'utf8'));
    assert.equal(record.format, 'memory-policy-audit/v1');
    assert.equal(record.report.as_of, '2026-11-05');
    assert.equal(record.report.known_on, '2026-11-10');
    assert.deepEqual(record.recall.results.map(result => result.text), [null]);
    assert.equal(record.report.context[0].citation, 'https://policies.example/refunds/annual/v3');
    console.log('The browser downloads the decision for the current dates.');
} finally {
    await browser?.close();
    for (const instance of [policy, caller]) instance?.server.close();
}
