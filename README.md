# Memory Policy Audit

Check the source of an agent's long-term memories before they reach the model. Hindsight retrieves relevant facts. Relate follows the revisions of their source documents and tells the agent which ones are in effect on the requested date.

[Try the browser demo](https://skybleak333.github.io/memory-policy-audit/) and compare three dates. Turn on **Recall only the oldest policy** to see Relate recover the approved excerpt from the current revision. The demo runs the same Cypher as the PHP tool in WebAssembly.

In the demo, the highest-ranked memory says customers have 30 days to request a refund. The current policy says 14. The draft for next month says 7. The audit keeps the 14-day fact and cites the current document.

```text
superseded          annual-refunds-v1 (annual-refunds-v2, annual-refunds-v3)
unverified          support-chat
not_yet_effective   annual-refunds-v4
current             annual-refunds-v3

Agent context
  Annual plans have a 14-day refund window.
  https://policies.example/refunds/annual/v3
```

## Run the CLI demo

Install PHP 8.2 or later and [Relate CLI 1.2.0](https://github.com/relatedb/relate/releases/tag/v1.2.0). The demo runs locally from the supplied recall response and catalog.

```sh
git clone https://github.com/skybleak333/memory-policy-audit.git
cd memory-policy-audit
php bin/memory-audit demo
php bin/memory-audit demo --as-of=2026-09-30 --json
```

Use `--relate=/path/to/relate` if the binary is outside your PATH. In JSON mode, `context` contains the facts and citations to give the agent. `decisions` records what happened to every recalled fact. The order of accepted facts follows the original recall ranking.

## Audit your own recall

Publish your approved policy revisions as a catalog in the shape of [examples/policies.json](examples/policies.json). Use the same revision ID as `document_id` when retaining the document in Hindsight. Each revision has a policy identifier, its effective date in `YYYY-MM-DD` form, an HTTP(S) citation and an optional list of the earlier revisions it supersedes.

```sh
php bin/memory-audit audit \
  --catalog=examples/policies.json \
  --recall=examples/stale-recall.json \
  --as-of=2026-11-23 --json
```

For a running Hindsight server, install the locked client dependency and pass the bank and question.

```sh
composer install
HINDSIGHT_URL=http://127.0.0.1:8888 php bin/memory-audit audit \
  --catalog=examples/policies.json \
  --bank=support-policies \
  --query='How long can I request a refund on an annual plan?' --json
```

Set `HINDSIGHT_API_KEY` when the server requires authentication. The live mode uses [hindsight-php](https://github.com/skybleak333/hindsight-php) to look up memories in the existing bank. The demo uses synthetic memory and policy data.

## Use the audit inside a Laravel AI agent

Add both public Git repositories to your Laravel application's Composer configuration.

```sh
composer config repositories.memory-policy-audit git https://github.com/skybleak333/memory-policy-audit.git
composer config repositories.hindsight-php git https://github.com/skybleak333/hindsight-php.git
composer require fedoseev/memory-policy-audit:^0.3
```

In an agent that implements `HasMiddleware`, give Hindsight's memory middleware an audit formatter. Use application-owned policy data and a bank populated with those documents.

```php
use Fedoseev\Hindsight\Laravel\Ai\Memory;
use MemoryPolicyAudit\Audit;
use MemoryPolicyAudit\Catalog;
use MemoryPolicyAudit\RecallContext;
use MemoryPolicyAudit\Relate;

public function middleware(): array
{
    $policies = json_decode(file_get_contents(resource_path('policies.json')), true, flags: JSON_THROW_ON_ERROR);
    $context = new RecallContext(new Catalog($policies), new Audit(new Relate()));

    return [
        Memory::bank('support-policies')
            ->withoutRetain()
            ->formatRecallUsing($context(...)),
    ];
}
```

The formatter uses today's UTC date unless you give it an `asOf` date. It adds citations for accepted facts before the first model call. If all recalled sources are excluded, the agent receives no memory block. An audit error propagates to the caller.

## Look up policies from an MCP client

Install the MCP server's dependencies in the cloned repository. It uses the [official PHP MCP SDK](https://github.com/modelcontextprotocol/php-sdk) and runs over standard input and output.

```sh
composer --working-dir=mcp install
```

Add the server to a client that supports stdio. This example shows the JSON configuration used by clients such as Claude Desktop. Use absolute paths to PHP, the server script, and the Relate executable.

```json
{
    "mcpServers": {
        "memory-policy-audit": {
            "command": "/path/to/php",
            "args": ["/path/to/memory-policy-audit/mcp/server.php", "--demo"],
            "env": {"RELATE_BIN": "/path/to/relate"}
        }
    }
}
```

Call `lookup_refund_policy` with `{"as_of": "2026-11-23"}`. Relate finds the 14-day refund rule and returns its citation with `recovered_from: annual-refunds-v1`. Change the date to September 30 or December 1 to see when the window was 30 or 7 days. The demo uses the included recall fixture and needs no Hindsight server or model key.

For live lookup, remove `--demo` and set `POLICY_CATALOG` to the absolute path of your approved catalog, `HINDSIGHT_URL` to your Hindsight endpoint, and `HINDSIGHT_BANK` to the policy bank. Set `HINDSIGHT_API_KEY` in the server environment if authentication is required. Every catalog revision needs an approved `excerpt`. Restart the server after updating the catalog.

The live tool is `lookup_policy`. It takes a `question` and an optional `as_of` date, which defaults to today in UTC. Both tools provide a structured report containing approved text and canonical citations in `context` and explanations in `decisions`. Clients that use text results get the same report as JSON. The server only exposes the configured bank and does not retain conversations.

## Call the example from another agent website

[Policy assistant](https://skybleak333.github.io/memory-policy-audit/agents/) publishes a two-agent [Open Agentic Web network](https://github.com/cognizant-ai-labs/agentic-web). In AI model settings, choose a provider that supports tool calls. Ask which refund window applied on 2026-11-23. The specialist calls the Relate graph and returns the approved excerpt and citation.

Add `https://skybleak333.github.io/memory-policy-audit/agents/policy_audit` to the caller's tools and allow `https://skybleak333.github.io` in its peer policy. After visitor consent, the caller's browser loads the network and invokes the public read action through the action bridge. The action takes only a date and reads the same synthetic catalog as the graph demo. Inference uses the caller's configured provider.

Run the interoperability check with local Chrome. It simulates model replies and checks the real browser runtime, action bridge and Relate WebAssembly. It verifies that declining consent sends no request to the policy site, and that accepting returns the current excerpt from the old recall fixture.

```sh
npm --prefix demo ci
npm --prefix demo run build
npm --prefix demo run test:agent-site
```

Pass the public network URL after `--` to test the deployed site. Use `CHROME_BIN` if Chrome is installed elsewhere. The build fetches a pinned revision of the reference runtime and verifies its checksum. The runtime and its Apache 2.0 license are included in the deployed browser bundle.

## How the audit decides

Relate builds a local graph of revisions and walks incoming `SUPERSEDES` relationships. A revision is current when it has taken effect and is the only remaining version of its policy at that date. When two branches remain in effect, the audit marks both as conflicting until a later revision supersedes them. Memories from documents missing from the catalog are unverified.

Set `excerpt` on a revision to supply its approved wording. When recall finds only a superseded revision, the audit checks for a single current version of that policy and follows the graph to it. It adds the approved excerpt and records the older revision in `recovered_from`. When recall already found the current revision, the excerpt takes precedence over the recalled wording and appears once in context. For revisions without an excerpt, the audit continues to accept recalled text from current sources.

Keep the catalog under the control of whoever approves policy. The audit uses it to check the origin and effective date of a recalled fact. The citation lets the agent or its user read the original policy.

```sh
php tests/audit.php
```

The tests cover historical dates, recovery from stale recall, and conflicting branches on a real Relate database. GitHub Actions also checks the Laravel AI prompt when a local Hindsight server holds only the older revision.
