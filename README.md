# Memory Policy Audit

Check the source of an agent's long-term memories before they reach the model. Hindsight retrieves relevant facts. Relate follows the revisions of their source documents and tells the agent which ones are in effect on the requested date.

[Try the browser demo](https://skybleak333.github.io/memory-policy-audit/) and compare three dates. It runs the same Cypher as the PHP tool using Relate compiled to WebAssembly.

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
  --recall=examples/recall.json \
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

Add both VCS repositories to your Laravel application's Composer configuration.

```sh
composer config repositories.memory-policy-audit vcs https://github.com/skybleak333/memory-policy-audit.git
composer config repositories.hindsight-php vcs https://github.com/skybleak333/hindsight-php.git
composer require fedoseev/memory-policy-audit:^0.2
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

## How the audit decides

Relate builds a local graph of revisions and walks incoming `SUPERSEDES` relationships. A revision is current when it has taken effect and is the only remaining version of its policy at that date. When two branches remain in effect, the audit marks both as conflicting until a later revision supersedes them. Memories from documents missing from the catalog are unverified.

Keep the catalog under the control of whoever approves policy. The audit uses it to check the origin and effective date of a recalled fact. The citation lets the agent or its user read the original policy.

```sh
php tests/audit.php
```

The tests cover historical dates, transitive supersession and conflicting branches on a real Relate database. GitHub Actions also tests live recall and checks the prompt Laravel AI sends to a fake model, using a local Hindsight server.
