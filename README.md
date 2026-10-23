# Shopify for Inventoros

**Two-way product, stock and order sync for Shopify.** An Inventoros Official plugin (free, AGPL-3.0-or-later).

Connect your Inventoros inventory workflows with Shopify. Synchronize products, inventory levels and orders in both directions through asynchronous processing.

Use mappings to connect records between systems. Webhooks and retries support the sync process, while sync history and health information help you review its status.

![A connected store with its sync health](docs/screenshots/overview.png)

## Contents

- [Features](#features)
- [Screenshots](#screenshots)
- [Install](#install)
- [Set up a store](#set-up-a-store)
- [Configuration](#configuration)
- [Permissions](#permissions)
- [Compatibility](#compatibility)
- [Develop and test](#develop-and-test)
- [FAQ](#faq)
- [License](#license)

## Features

- **Orders into Inventoros, exactly once**: through core's `OrderService` (ledger rows, bins, serials), with `external_reference` set, safe against duplicate, replayed and out-of-order webhooks. Shopify cancellations cancel and restock through core.
- **Stock both ways, one owner**: Inventoros quantities pushed per mapped location, coalesced per item and queued; or Shopify levels applied as audited core adjustments.
- **Products both ways, one owner**: Shopify titles and prices copied into Inventoros, or Inventoros edits pushed to Shopify.
- **Mapping review**: SKU matches are suggestions until you confirm them; conflicts and unmatched variants are listed, never guessed. Shopify locations map to warehouses.
- **Webhooks** verified by HMAC, stored in an idempotent inbox and processed in the background, plus a 15-minute incremental sync as a safety net.
- **Retries and rate limits**: Retry-After, Shopify's GraphQL cost limit, backoff, a circuit breaker, and failed work you can retry from the UI.
- **Health and history**: last sync, waiting work, error rate, every run with its per-record log, errors and failed items.
- **Never blocks Inventoros**: no request waits on Shopify.
- `shopify.order_imported` and `shopify.sync_failed` webhook events, and a read-only `shopify_sync_status` MCP tool.
- Built on the reusable [integration kit](src/Kit/README.md) shared by the Inventoros integration plugins.

## Screenshots

| | |
|-|-|
| ![Mapping review](docs/screenshots/mappings.png) | ![Locations mapped to warehouses](docs/screenshots/locations.png) |
| ![Sync history with a run's log](docs/screenshots/history.png) | ![Failed items with retry](docs/screenshots/failed.png) |
| ![Connecting a store](docs/screenshots/connect.png) | ![The store card on a phone](docs/screenshots/overview-mobile.png) |

## Install

From a release: download `shopify-X.Y.Z.zip` from the [releases](../../releases), upload it in **Plugins**, then activate it. Or install it from **Plugins > Marketplace** once it is listed.

From source:

```bash
cd ui && npm ci && npm run build && cd ..
cp -r . /path/to/inventoros/plugins/shopify     # the folder name is the slug
php artisan plugin:activate shopify              # from the Inventoros root
```

Activation creates the plugin's `plg_shopify_*` tables. Deactivation keeps them. Deleting the plugin drops them and removes its permissions from roles; orders it imported and stock changes it made are core records and stay.

The plugin needs the Inventoros **queue worker** and **scheduler** running (`shopify:sync` every 15 minutes, `shopify:prune` daily). On cPanel the scheduler drains the queue too.

## Set up a store

Install a Shopify app with the scopes `read_products`, `write_products`, `read_inventory`, `write_inventory`, `read_locations` and `read_orders`, then connect under **Shopify > Connect a store** using client credentials for an app and store in your own Shopify organization, or an existing Admin API access token and API secret key. Map locations to warehouses, run a full sync and confirm the SKU suggestions.

The step-by-step guide, with what each status means: **[docs/setup.md](docs/setup.md)**. How orders, stock, products, webhooks and retries behave: **[docs/sync.md](docs/sync.md)**.

## Configuration

Per connection, on the store card's **Settings**:

| Setting | Default | Effect |
|---------|---------|--------|
| Who owns stock levels | Inventoros | Inventoros pushes quantities; or Shopify's levels are applied to Inventoros |
| Who owns product names and prices | Shopify | Shopify edits copied in; or Inventoros edits pushed out |
| Import Shopify orders | On | Orders flow into Inventoros |
| Also import test orders | Off | |
| Import orders placed on or after | The day you connect | Older orders are skipped |

## Permissions

| Permission | Allows |
|------------|--------|
| `shopify.view` (View Shopify sync) | The Shopify pages and the `shopify_sync_status` MCP tool |
| `shopify.manage` (Manage Shopify sync) | Connecting stores, settings, mappings, syncs, retrying and dismissing failed work |

Admins hold both. On Inventoros versions without plugin permissions they fall back to **View Settings** and **Edit Settings**. Every action is checked on the server and limited to the user's organization.

## Compatibility

- Inventoros 2.0.0 or newer with the plugin SDK of Inventoros#250 and #251 (plugin permissions, shared UI components and translations, `stock_changed` and the other domain hooks, `register_webhook_event`, `register_mcp_tool`). Plugin permissions, webhook events and the MCP tool are feature-detected and skipped on cores without them; the `stock_changed` hook is required for stock pushes.
- On cores with organization memberships (Inventoros#257) members from other organizations are included as the user a connection acts as; older cores use the home organization only.
- PHP 8.4+. Shopify Admin GraphQL API **2026-07** (pinned in `ShopifyClient::API_VERSION`).
- Tested against Inventoros `main` on SQLite, PostgreSQL and MySQL.
- Routes follow the plugin route namespace: names `plg.shopify.*`, pages and actions under `/p/shopify/` (the overview moved from `/shopify` to `/p/shopify` in 1.0.1; update bookmarks). The webhook address, `/webhooks/plugins/shopify/{connection}`, is unchanged, so subscriptions registered by 1.0.0 keep delivering and nothing needs re-registering.
- The sidebar item is translated on cores that translate plugin menu items (`label_key`).
- Known limitations and SDK gaps:
  - Orders take stock when imported. External Shopify checkout reservations are not created in Inventoros before the order webhook arrives.
  - On cores with variant location stock, each variant syncs its own bins per mapped warehouse. Older cores retain primary-location variant sync. Local and global stock holds reduce pushed availability; warehouse quantities share a single SKU availability budget, allocated primary location first.
  - On cores before Inventoros#254, `OrderService::cancel()` takes its actor from the signed-in user, so the plugin signs in the connection's user for that one call when it cancels in a background job. Newer cores take the actor as a parameter and nobody is signed in.
  - Fulfillments and tracking are not pushed to Shopify yet; order edits after import are not applied. Completed payment/refund records can be imported with the optional ledger setting.
  - Public multi-merchant OAuth installation is not implemented; see [docs/setup.md](docs/setup.md#7-future-a-public-app-with-oauth).

## Develop and test

The tests run inside a real Inventoros against a fake store built from recorded Shopify responses (`tests/Fixtures`); no test calls Shopify.

```bash
git clone https://github.com/Inventoros/Inventoros.git && cd Inventoros
composer install && cp .env.example .env && php artisan key:generate
git clone git@github.com:Inventoros/plugin-shopify.git plugins/shopify
(cd plugins/shopify/ui && npm ci && npm run build)
vendor/bin/phpunit -c plugins/shopify/phpunit.xml          # SQLite
vendor/bin/phpunit -c plugins/shopify/phpunit.pgsql.xml    # PostgreSQL (DB_* from the environment)
vendor/bin/phpunit -c plugins/shopify/phpunit.mysql.xml    # MySQL
```

README screenshots: `SCREENSHOTS=1 npx playwright test -c plugins/shopify/e2e/playwright.config.ts` against a served, seeded core (see the config file).

## FAQ

**Does Inventoros slow down when Shopify is slow or down?** No. Requests only queue work; Shopify outages show up as retries and failed items, and the connection pauses itself after repeated failures.

**What if the same order arrives twice, or the update before the create?** It is imported once. Older updates are ignored.

**Can two stores sync into one organization?** Yes, one connection per store; each has its own mappings. Two organizations can also connect two different stores; they never see each other's data.

**What happens to my data if I remove the plugin?** Its own tables are dropped. Imported orders, stock adjustments and products stay in Inventoros.

**Where does the integration kit live?** In `src/Kit`, with a README on copying it into another integration plugin.

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE).

### GraphQL page sizes and rate limits

Catalog and order reads use small parent pages and finish nested variant, order
line and inventory-location connections before returning a record. Large
products or orders are not silently truncated. A missing/repeated cursor refuses
partial data. Read-only requests can wait on the same page up to three times,
with a total wait of at most 30 seconds; longer throttles return to the existing
queue retry policy. Mutations keep their existing idempotency and queue retries.

Query shapes are bounded below Shopify's default 1,000-point single-query limit.
Shopify can assign field costs, so response throttle metadata remains authoritative.

### Client credentials and missing scopes

Choose **App client credentials** for apps and stores in the same Shopify
organization. Enter the app client ID and API secret key (its client secret).
The server exchanges these for an expiring token, keeps it encrypted alongside
its expiry, and renews it two minutes before expiration before each API request, including later pages of an already-running client.
A connection-row lock prevents concurrent workers from minting duplicate tokens.
Manual tokens remain supported; they are never replaced automatically.

Connection tests name missing scopes for the enabled sync directions. Updating
app permissions may require reinstalling/reauthorizing the app before testing
again. Secrets and client IDs are write-only and excluded from validation-session
old input. Failed token exchanges report sanitized errors without provider bodies.
No OAuth redirect/authorization-code flow is provided by this option.

See [Shopify client credentials](https://shopify.dev/docs/apps/build/authentication-authorization/client-credentials-grant)
and [token lifetimes](https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens).

### Historical order backfills

An optional **Import orders before this date as history** cutoff separates past
sales from new stock-moving orders. Set the import start date to the earliest
order you want, select the history cutoff, test the connection, then run a full
sync. Dates use UTC. Shopify access beyond 60 days requires approved
`read_all_orders` access as well as order access; connection tests name it when
an older start date is configured.

Orders before the cutoff use core's historical import path: no stock/bin/serial
allocation, reservations, approval requests or new-order announcements. Fulfilled
orders are recorded as shipped, cancelled orders as cancelled, and other orders
as pending; exact remote statuses remain in metadata. No shipments are created.
Subsequent updates and cancellations remain historical even if the cutoff changes,
and never restock the goods. Orders on/after the cutoff keep the normal stock path.

Variant mappings are still required. Orders skipped by the start-date filter in
this version can be revisited as history after changing the range. Manually ignored
records and ignored records with no recorded reason stay ignored. Existing live
orders are never converted into history by changing a setting.

### Payment and refund records

Enable **Record Shopify payments and refunds** after reconciling any manual
payment entries. This optional setting is off by default and requires core's
`OrderPaymentService::recordImported()` API (core PR Inventoros/Inventoros#305).
Older cores retain all existing sync features and show an upgrade hint instead
of enabling payment import. Enabling the setting retests the connection and
registers the refund webhook; scheduled order reconciliation also updates it.

Only successful sales, captures and refunds create ledger records. Pending or
failed transactions and uncaptured authorizations create no receipts. Amounts
and dates come from Shopify transactions in shop currency; a currency mismatch
is refused. There are no calls to capture, charge or refund money in Shopify.
Payment status and balances are derived by core from the imported ledger, never
from a Shopify status label. Actual overpayments remain visible as overpayments.

Provider transaction IDs map to core payment IDs, so repeated webhooks/resyncs
add nothing twice. Conflicting transaction changes and missing mapped payments
need review. Manual voids remain voided. Active manual/unrelated payment rows
block automatic import until reconciled or voided to prevent double counting.
Refunds cannot exceed recorded receipts. Historical imports use the same ledger
path silently, including cancelled orders; live imports announce core payment
hooks only after commit. Importing financial records does not restock returns.

The API reader checks the exact transaction count and refuses incomplete lists
or orders with more than 100 transactions for manual review. It does not
silently import a subset. Financial records and new-order creation are atomic;
an invalid transaction leaves no partial order/payment ledger.
