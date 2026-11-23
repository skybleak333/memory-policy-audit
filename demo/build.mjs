import { cp, mkdir } from 'node:fs/promises';

const dist = new URL('./dist/', import.meta.url);
await mkdir(dist, { recursive: true });

for (const file of ['index.html', 'styles.css', 'app.js', 'audit.js', 'worker.js']) {
    await cp(new URL(file, import.meta.url), new URL(file, dist));
}

for (const directory of ['examples', 'queries']) {
    await cp(new URL('../' + directory, import.meta.url), new URL(directory, dist), { recursive: true });
}

const engine = new URL('./node_modules/@relate/relate-wasm/', import.meta.url);
for (const file of ['relate.js', 'relate_bg.wasm', 'snippets']) {
    await cp(new URL(file, engine), new URL('relate/' + file, dist), { recursive: true });
}
for (const file of ['LICENSE', 'THIRDPARTY-NOTICES.md']) {
    await cp(new URL('licenses/' + file, import.meta.url), new URL('relate/' + file, dist));
}
