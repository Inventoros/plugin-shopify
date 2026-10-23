# Changelog

All notable changes to this plugin are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the plugin uses [semantic versioning](https://semver.org/).

## [Unreleased]

## [1.2.0] - Unreleased

### Added

- Optional historical order cutoff: backfill older sales without stock, reservations, approvals or new-order announcements. Historical updates and cancellations never restock goods.
- Identify the read_all_orders scope for older import start dates, and revisit start-date-filtered records safely as history.

## [1.1.0] - Unreleased

### Added

- Optional client-credentials authentication for apps and stores in the same Shopify organization, with encrypted expiring tokens and automatic renewal.
- Connection tests identify missing scopes for the enabled sync directions. Validation errors never flash credentials or client IDs.

## [1.0.3] - Unreleased

### Fixed

- Bound GraphQL query sizes and finish nested product variants, order lines and inventory locations before returning records. Retry short throttles on the same read page; refuse broken cursors and partial records.

## [1.0.2] - 2026-10-07

### Fixed

- Order imports, Shopify cancellations and stock pulls now work when the connection's user belongs to the organization through a membership rather than as their home organization (Multi-Company, 3PL operator staff in client organizations): such members are accepted as the user the connection acts as, and the records they create belong to the connection's organization. Users who are not members of the organization are still refused.

## [1.0.1] - 2026-10-05

### Changed

- Route names are now `plg.shopify.*`, and the pages and write actions sit under `/p/shopify/` (the overview moves from `/shopify` to `/p/shopify`). Newer cores reserve unprefixed URIs for the application and refuse plugins that use them; update bookmarks after upgrading. The webhook address, `/webhooks/plugins/shopify/{connection}`, is unchanged, so existing store subscriptions keep delivering.
- On cores whose `OrderService::cancel()` takes an actor (Inventoros#254), Shopify cancellations restock as the connection's user without signing anyone in. Older cores keep the previous behaviour.
- The sidebar item is translated in all 14 languages on cores that translate plugin menu items.

### Fixed

- **Match again** on a product mapping failed with a server error (the mapping has no `connection` relation); it now loads the connection explicitly and suggests the SKU match again.
- CI installs the core's dependencies before placing the plugin in `plugins/`, so composer no longer warns about the plugin's classes.

## [1.0.0] - 2026-10-05

### Added

- Connect Shopify stores with a custom app's Admin API access token and API secret key, stored encrypted; background connection test; webhook registration on public https installs.
- Order import through core `OrderService` with `external_reference`, exactly once across duplicate, replayed and out-of-order deliveries, syncs and resyncs; Shopify cancellations cancel and restock through core.
- Stock sync with a chosen owner: coalesced, queued pushes of absolute quantities per mapped location, or Shopify levels applied as audited core adjustments.
- Product sync with a chosen owner: titles and prices copied from Shopify, or pushed from Inventoros.
- Mapping review: SKU suggestions to confirm, unmatched and conflicting variants, locations mapped to warehouses with a primary location, order mappings with resync.
- Initial, manual and incremental (cursor-based, every 15 minutes) syncs, recorded as runs with per-record logs.
- Retries with backoff, Retry-After and GraphQL cost handling, a circuit breaker, and failed items to retry or dismiss.
- Health per store: last sync, waiting work, error rate, failed items.
- `shopify.order_imported` and `shopify.sync_failed` webhook events and the `shopify_sync_status` MCP tool.
- `shopify.view` and `shopify.manage` permissions with core fallbacks.
- Integration kit 1.0.0 in `src/Kit`, designed to be copied into the next integration plugins.
- Translations in the 14 languages Inventoros ships.
- Tests inside Inventoros on SQLite, PostgreSQL and MySQL against recorded Shopify responses; CI, packaging and release workflows.
