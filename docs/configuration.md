# Configuration, permissions and compatibility

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
  - Optional shipment fulfilment and tracking push is available on cores with shipment notification ownership; order edits after import are not applied. Completed payment/refund records can be imported with the optional ledger setting.
  - Public multi-merchant OAuth installation is not implemented; see [docs/setup.md](setup.md#7-future-a-public-app-with-oauth).
