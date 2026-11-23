import { boot } from './runtime/index.js';

const status = document.querySelector('#assistant-status');
try {
    await boot('#agent-app', {
        originUrl: new URL('./', import.meta.url).href,
        sandboxWorkerUrl: new URL('./runtime/sandbox-worker.js', import.meta.url).href,
    });
    status.hidden = true;
} catch (error) {
    status.textContent = 'The assistant could not start. You can still look up dates in the graph demo.';
    console.error('Policy assistant setup failed', error);
}
