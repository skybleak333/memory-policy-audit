import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { cp, mkdir, mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

// Keep the official runtime, network builder and action bridge on the same revision.
const upstream = 'c178171d7ef3d0cf5c28208da1263bf954435e70';
const checksum = '02d63c8a19f5b37bff46788547bfc6418fb5583872ca2a17994dd6504807dcce';

export async function buildAgentSite(dist) {
    const response = await fetch(
        'https://codeload.github.com/cognizant-ai-labs/agentic-web/tar.gz/' + upstream,
        { signal: AbortSignal.timeout(30_000) },
    );
    if (!response.ok) throw new Error('Could not fetch the reference browser runtime.');
    const archive = Buffer.from(await response.arrayBuffer());
    if (createHash('sha256').update(archive).digest('hex') !== checksum) {
        throw new Error('The Open Agentic Web reference archive failed its SHA-256 check.');
    }

    const scratch = await mkdtemp(join(tmpdir(), 'memory-policy-agent-site-'));
    const site = new URL('agents/', dist);
    try {
        execFileSync('tar', ['-xzf', '-', '--strip-components=1', '-C', scratch], { input: archive });
        const skill = join(scratch, 'skills', 'agentify-website');
        await mkdir(site, { recursive: true });
        await cp(new URL('agents/', import.meta.url), site, { recursive: true });
        await cp(join(skill, 'runtime'), new URL('runtime/', site), { recursive: true });
        await cp(join(skill, 'templates', 'assistant.css'), new URL('assistant.css', site));

        const buildArgs = [
            join(skill, 'scripts', 'build_network.mjs'),
            fileURLToPath(new URL('policy_audit.hocon', site)),
            '--manifest', fileURLToPath(new URL('agent-manifest.json', site)),
        ];
        execFileSync(process.execPath, buildArgs, { stdio: 'inherit' });
        execFileSync(process.execPath, [...buildArgs, '--check'], { stdio: 'inherit' });
        await rm(new URL('policy_audit.hocon', site));
    } finally {
        await rm(scratch, { recursive: true, force: true });
    }
}
