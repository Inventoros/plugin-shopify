# Changelog

All notable changes to this plugin are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the plugin uses [semantic versioning](https://semver.org/).

## [Unreleased]

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
