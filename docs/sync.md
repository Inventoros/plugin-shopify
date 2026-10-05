# How the sync works

Everything the plugin does with Shopify runs in queued jobs. An Inventoros request never calls Shopify: a stock change, a mapping confirmation or a button press only records what needs doing and queues it.

## Contents

- [Orders](#orders)
- [Stock](#stock)
- [Products](#products)
- [Webhooks](#webhooks)
- [Incremental sync and cursors](#incremental-sync-and-cursors)
- [Retries, rate limits and failures](#retries-rate-limits-and-failures)
- [Health](#health)
- [Data the plugin keeps](#data-the-plugin-keeps)

## Orders

Shopify orders always flow into Inventoros.

- An order is created through core's `OrderService`, exactly as an order entered in Inventoros: product rows are locked, an `order_fulfillment` ledger row is written per line, the units come out of their bins, serials are allocated and `order_created` fires (so core webhooks and other plugins see it).
- It carries `source = shopify`, `external_id` (the Shopify order id) and `external_reference = shopify:{shop}:{id}`, unique per organization.
- **Exactly once**: the order's mapping row is locked while it is created and core's unique `external_reference` backs it up, so `orders/create`, `orders/updated`, a retry, the scheduled sync and a manual resync of the same order produce one Inventoros order.
- **Out of order**: each delivery's `updated_at` is compared with the newest one already applied; older deliveries are ignored. An order cancelled before it was ever imported is remembered, so a late `orders/create` does not import it.
- **Cancellation** in Shopify cancels the Inventoros order through `OrderService::cancel()`, which restocks it once. An order that already shipped in Inventoros is not cancelled; the history says why.
- Lines without a Shopify variant (custom items, tips) are left out with a warning. A line whose variant is not mapped stops the import with an error naming the SKU; map it and press **Retry**. Not enough stock in Inventoros also stops the import, and nothing is changed.
- Test orders are skipped unless **Also import test orders** is on; orders placed before the start date are skipped.

## Stock

One side owns stock levels (the connection setting).

**Inventoros owns stock** (default):

- Core fires `stock_changed` after every committed change, from every surface (adjustments, orders, receipts, transfers, returns, audits, imports). The plugin's listener writes one outbox row per changed item and queues a push for a few seconds later.
- Ten changes to one item before the push leave one row; the push sends the item's **current** quantity, as an absolute value, per mapped location (`inventorySetQuantities`, 250 per call). A retry of the same push reuses its idempotency key; any later push gets a new one.
- Shopify's `inventory_levels/update` webhooks are recorded and not applied.

**Shopify owns stock**:

- `inventory_levels/update` and **Resync stock** compute the difference between Shopify's available quantity and Inventoros' quantity at the mapped warehouse, and apply it as one audited `correction` adjustment through `StockAdjustment::adjust()` (or `adjustVariant()`), in the bin that already holds the product, attributed to the user who connected the store. Stock is never written directly.
- Inventoros never pushes stock to Shopify, so the two never echo each other.

Quantity per mapped location: a warehouse location gets the product's stock in that warehouse's bins; an **All stock** location gets the product's total; a product that has no bins at all is pushed to the primary location only; a variant is pushed to the primary location only. Negative stock is pushed as 0.

## Products

- Initial and incremental product syncs refresh a mapping row per Shopify variant (title, SKU, price, inventory item). New variants wait for review.
- **Shopify owns products** (default): `products/update` copies a mapped product's title to the Inventoros product name and the variant price to the Inventoros price (only when the currencies match).
- **Inventoros owns products**: Inventoros edits (`product_updated`, `variant_updated`) push the product title and variant prices to Shopify through the outbox; Shopify edits only refresh the mapping. Descriptions, images, options and SEO stay Shopify's.

## Webhooks

`POST /webhooks/plugins/shopify/{connection}` handles `orders/create`, `orders/updated`, `orders/cancelled`, `products/create`, `products/update`, `inventory_levels/update` and `app/uninstalled`.

1. The connection must exist, not be disconnected, and have a secret.
2. `X-Shopify-Hmac-Sha256` must match the raw body signed with the API secret key (`hash_equals`), and `X-Shopify-Shop-Domain` must be the connection's shop; otherwise 401 and nothing is stored.
3. The delivery is stored in the inbox under its `X-Shopify-Event-Id` and its body hash. A redelivery or a replayed body is answered 200 and dropped.
4. A job processes it; the request answers 200 at once.

The processing job claims the entry first; a claim succeeds once, so even two workers process a delivery once. `app/uninstalled` is applied even while the connection is paused.

## Incremental sync and cursors

`shopify:sync` runs every 15 minutes. For each connection that can sync it queues an incremental sync of products and orders changed since the stored cursor (minus two minutes for clock skew), re-queues inbox entries left unprocessed, and drains due pushes. Cursors advance after each page, so a sync that stops resumes where it left off; a run processes at most 20 pages and queues its own continuation.

## Retries, rate limits and failures

| What happened | What the job does |
|---------------|-------------------|
| HTTP 429 | Waits for `Retry-After`, without using up an attempt |
| GraphQL `THROTTLED` | Waits (requested cost minus available) / restore rate seconds |
| Local cost budget empty | Waits before sending anything (the plugin mirrors Shopify's leaky bucket per store) |
| 5xx, timeout, network error | Retries after 15 s, 1 min, 4 min, 15 min, then gives up |
| 401 or 403 | Gives up at once and puts the connection in the error state |
| 404, a validation error, an unmapped SKU, not enough stock | Gives up at once |

Work that gives up lands in **Activity > Failed items** with its error, and fires `shopify.sync_failed`. **Retry** queues it again from the first attempt (after you fixed the cause); **Dismiss** removes it. Nothing is lost while it waits: pushes stay in the outbox and webhooks in the inbox.

Five failures in a row open the circuit breaker: the connection pauses for 15 minutes (doubling up to 4 hours) and says so on its card. A success, or **Resume syncing**, closes it.

## Health

Each store card and the `shopify_sync_status` MCP tool report: state (healthy, recent failures, paused, error, disconnected, testing), the last sync and its result, waiting work (webhooks, pushes, running syncs), the error rate over 24 hours (failed or partly failed runs out of all runs) and the number of failed items.

## Data the plugin keeps

All in its own tables, removed when the plugin is deleted:

| Table | Holds |
|-------|-------|
| `plg_shopify_connections` | Stores, encrypted credentials, settings, health |
| `plg_shopify_mappings` | Shopify variant, location and order ids and their Inventoros records |
| `plg_shopify_sync_runs`, `plg_shopify_sync_logs` | History (30 days by default, `shopify:prune --days=`) |
| `plg_shopify_webhook_inbox` | Verified deliveries (processed ones kept 7 days) |
| `plg_shopify_cursors` | Incremental positions |
| `plg_shopify_outbox` | Pushes waiting to be sent |
| `plg_shopify_dead_letters` | Failed work waiting for a retry |
