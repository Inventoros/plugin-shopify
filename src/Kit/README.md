# Integration kit 1.0.0

The shared sync infrastructure of the Inventoros Official integration plugins (Shopify first; WooCommerce, ShipStation, Amazon, QuickBooks and Xero next). It is **copied into each plugin under its own namespace and table prefix**, never shared at runtime, so every integration installs, upgrades and uninstalls on its own (see `docs/CONVENTIONS.md`, section 12).

Nothing in `src/Kit` knows about Shopify. The provider-specific code lives next to it (`src/Shopify`, `src/Sync`, `src/Jobs`).

## Contents

- [What is in the kit](#what-is-in-the-kit)
- [Copy it into another plugin](#copy-it-into-another-plugin)
- [Use it](#use-it)
- [Rules the kit enforces](#rules-the-kit-enforces)
- [Upgrading a copy](#upgrading-a-copy)

## What is in the kit

| Part | Class | Table | What it does |
|------|-------|-------|--------------|
| Identity | `Kit` | | Slug, table prefix, hook prefix, cache keys, `Kit::fire()`. The only plugin-specific values. |
| Connections | `Models\Connection`, `Connections\ConnectionHealth`, `Connections\ConnectionTester`, `Jobs\TestConnection` | `connections` | One row per external account per organization. Credentials in an `encrypted:array` cast, hidden from serialization. Status `pending`, `connected`, `error`, `disconnected`. Health: last success and failure, last error, consecutive failures, and a circuit breaker that pauses syncing after 5 failures in a row (15 minutes, doubling up to 4 hours). |
| Sync history | `Models\SyncRun`, `Models\SyncLog`, `Sync\SyncRecorder` | `sync_runs`, `sync_logs` | A run per unit of work (type, resource, trigger, counters, status, error summary) and a log line per record. Every message is scrubbed of the connection's credential values. |
| Jobs and retries | `Jobs\IntegrationJob`, `Jobs\RetryPolicy`, `Jobs\RetryDecision` | | Base job: rate limits re-queue after Retry-After without using an attempt; transient errors retry with backoff (15 s, 1 min, 4 min, 15 min); permanent errors and exhausted retries are dead-lettered. Retries are explicit re-dispatches, so they behave the same on every queue driver. |
| Rate limits | `Support\CostBudget`, `Support\RetryAfter`, `Exceptions\RateLimited` | (cache) | A per-connection leaky bucket mirroring the provider's (Shopify query cost; set `maximum` and `restoreRate` for others). `reserve()` before a request throws `RateLimited` with the wait; `observe()` corrects it from the response. |
| Errors | `Exceptions\TransientError`, `PermanentError`, `RateLimited` | | What an adapter throws. `PermanentError(authentication: true)` puts the connection in the error state. |
| Dead letters | `Jobs\DeadLetterQueue`, `Models\DeadLetter` | `dead_letters` | Failed work with the job class and arguments that rebuild it. Retry from the UI re-dispatches it from attempt 1; only job classes of the owning plugin are accepted. |
| Webhook inbox | `Webhooks\WebhookInbox`, `Webhooks\HmacSignature`, `Models\WebhookInboxEntry` | `webhook_inbox` | Store a verified delivery once per provider event id and once per (topic, body hash); `claim()` succeeds once per delivery, so processing is exactly-once even with two workers. |
| Mappings | `Mapping\MappingStore`, `Mapping\MappingConflict`, `Models\Mapping` | `mappings` | External id to core id per connection and entity, unique both ways. Statuses `mapped`, `suggested` (waiting for review), `unmatched`, `conflict`, `ignored`, `pending`. `isStale()` and `touch()` drop out-of-order deliveries. |
| Cursors | `Sync\CursorStore`, `Models\Cursor` | `cursors` | Incremental position per connection and resource (page token and timestamp), advanced per page and never backwards. |
| Outbox | `Outbox\Outbox`, `Models\OutboxEntry` | `outbox` | Coalescing outbound queue: one row per (kind, subject) however often it changed; `done()` keeps a row that changed again after it was read. |

The tables are created by `database/migrations/*_create_kit_tables.php`.

## Copy it into another plugin

From the new plugin's root (here WooCommerce, namespace `Inventoros\Plugins\WooCommerce`, prefix `plg_woocommerce_`):

```bash
KIT=../plugin-shopify
cp -r $KIT/src/Kit src/Kit
cp $KIT/database/migrations/2026_10_04_223700_plg_shopify_create_kit_tables.php \
   database/migrations/$(date +%Y_%m_%d_%H%M%S)_plg_woocommerce_create_kit_tables.php

# Namespace, table prefix, slug, hook and cache prefixes.
grep -rl 'Inventoros\\Plugins\\Shopify\\Kit' src/Kit database/migrations | xargs sed -i \
  -e 's/Inventoros\\Plugins\\Shopify\\Kit/Inventoros\\Plugins\\WooCommerce\\Kit/g'
sed -i -e "s/SLUG = 'shopify'/SLUG = 'woocommerce'/" \
       -e "s/TABLE_PREFIX = 'plg_shopify_'/TABLE_PREFIX = 'plg_woocommerce_'/" \
       -e "s/HOOK_PREFIX = 'shopify_'/HOOK_PREFIX = 'woocommerce_'/" src/Kit/Kit.php
sed -i -e 's/plg_shopify_/plg_woocommerce_/g' database/migrations/*_plg_woocommerce_create_kit_tables.php
```

Then:

1. Check `grep -rni shopify src/Kit database/migrations` returns nothing but this README's history.
2. Shorten any index name over 64 characters if the new prefix is longer (MySQL); the Shopify names leave room for a 24-character prefix.
3. Set `"kit_version": "1.0.0"` in the plugin's `plugin.json`.
4. Bind your `ConnectionTester` in the plugin's registration: `app()->bindIf(ConnectionTester::class, WooCommerceConnectionTester::class)`.
5. Listen for `{hook prefix}connection_verified` (register webhooks, first sync) and `{hook prefix}sync_failed` (notify).

The kit has no dependency outside Laravel and core's `organizations` and `users` tables.

## Use it

**An adapter** wraps the provider's API behind an interface the plugin owns, and throws only kit exceptions:

```php
try {
    $response = Http::withToken($token)->get($url);
} catch (ConnectionException) {
    throw new TransientError('Could not reach the store.');
}

match (true) {
    $response->status() === 429 => throw new RateLimited('Rate limited.', RetryAfter::seconds($response->header('Retry-After'))),
    in_array($response->status(), [401, 403]) => throw new PermanentError('Credentials rejected.', authentication: true),
    $response->serverError() => throw new TransientError('Server error.'),
    default => null,
};
```

**A job** extends `IntegrationJob`:

```php
final class PushStock extends IntegrationJob
{
    public function __construct(public int $connectionKey) {}

    public function connectionId(): int { return $this->connectionKey; }

    public function arguments(): array { return [$this->connectionKey]; }

    protected function perform(Connection $connection): void
    {
        // Idempotent work. Throw kit exceptions; the base class retries,
        // waits for rate limits, dead-letters and updates health.
    }
}
```

Do not name a job property `$connection` or `$queue`: Laravel's `Queueable` trait owns those.

**A webhook controller** verifies, stores, queues and answers at once:

```php
if (! HmacSignature::matches($request->getContent(), $connection->credential('webhook_secret'), $request->header('X-Signature'))) {
    return response()->json(['message' => 'Invalid signature.'], 401);
}

[$entry, $duplicate] = app(WebhookInbox::class)->accept($connection, $topic, $eventId, $request->getContent());

if (! $duplicate) {
    ProcessWebhook::dispatch($connection->id, $entry->id);
}

return response()->json(['message' => 'Received.']);
```

and the processing job calls `WebhookInbox::claim($entry)` first and returns when it is false.

**Out-of-order deliveries**: before applying a change stamped `updated_at`, `if ($store->isStale($mapping, $updatedAt)) return;`, and after applying it `$store->touch($mapping, $updatedAt)`.

**Coalesced pushes**: a core hook calls `Outbox::put($connection, 'inventory', 'p'.$product->id, [], $delay)` and dispatches a `ShouldBeUniqueUntilProcessing` push job; the job reads `Outbox::due()`, sends the current state of each subject and calls `Outbox::done()`.

## Rules the kit enforces

- Nothing external runs in a web request: tests, syncs, pushes and webhook processing are jobs.
- Every kit query is by connection (and so by organization); there is no global scope, because jobs run without a signed-in user.
- Credentials never reach logs, history, exceptions shown to users, or page props.
- Duplicate webhook deliveries and duplicate job runs are no-ops.
- Core records are never written by the kit; the plugin's sync code changes them through core services only.

## Upgrading a copy

Copies are upgraded by hand: diff `src/Kit` against the newer release, apply the changes, and ship any new kit table changes as a **new** migration in the plugin (released migrations are never edited). Record the new `kit_version` in `plugin.json`. Once three integrations run the same kit version, its stable parts are proposed to core as SDK features and the plugins feature-detect them.
