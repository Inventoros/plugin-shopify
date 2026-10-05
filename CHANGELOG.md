# Changelog

All notable changes to this plugin are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the plugin uses [semantic versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-10-04

### Added

- Settings page (greeting, low-stock logging) per organization, behind the **Manage Shopify** permission, with an **Edit Settings** fallback on cores that cannot register plugin permissions.
- Activity log: a queued job records low-stock alerts and settings changes.
- `shopify:prune-activity` command, scheduled daily at 04:10.
- Migrations run on activation and rolled back when the plugin is deleted; deleting also removes the plugin's permission from roles and permission sets.
- Test suite that runs inside Inventoros on SQLite, PostgreSQL and MySQL; CI, packaging and release workflows.
