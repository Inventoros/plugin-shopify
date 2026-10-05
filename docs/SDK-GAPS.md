# Inventoros plugin SDK: audit and gaps

Audited against Inventoros `main` at `3ef8ac87` (VERSION 2.0.0; the core has no `v2.0.0` tag yet, the latest tag is `v1.0.8`). Every "Supported" below was exercised by the [plugin template](../README.md): installed into a scratch core, activated, run through its test suite, the core's own plugin tests, `route:cache`, a database queue worker, `schedule:list`, `artisan list`, then deleted.

## Contents

1. [How the plugin system works today](#how-the-plugin-system-works-today)
2. [Capability audit](#capability-audit)
3. [Gaps, ranked](#gaps-ranked)
4. [What the first four plugins need](#what-the-first-four-plugins-need)
5. [Rules for closing a gap](#rules-for-closing-a-gap)

## How the plugin system works today

- A plugin is a folder `plugins/{slug}` with `plugin.json` and a main file. `PluginService` (`app/Services/PluginService.php`) installs (ZIP or marketplace), activates, deactivates, updates (`replaceFromZip`: deactivate, swap files, re-activate, roll back on failure) and deletes it.
- Active plugins load in `AppServiceProvider::boot()` (every web request, artisan command and queue worker), through `PluginMainFile` (`app/Services/Plugins/PluginMainFile.php`), which runs a returned closure once per application so `route:cache` and tests see the same registrations.
- Lifecycle files `hooks/activate.php`, `hooks/deactivate.php`, `hooks/uninstall.php` run via `PluginService::runLifecycleFile()`. `uninstall.php` runs even when the plugin is inactive.
- Hooks: WordPress-style `add_action` / `add_filter` (`app/Support/helpers.php`) over `HookRegistry` (`app/Services/HookRegistry.php`): 55 actions and 27 filters, kept honest by `tests/Feature/PluginHookDocumentationTest.php`.
- UI: `PluginUIService` (menu items, pages, dashboard widgets, page-slot components, all permission-gated server side), `PluginPageRoutes` (GET pages behind `auth`, reserved prefixes, no shadowing), and the runtime bundle (`resources/js/plugins/runtime.js`): a Vite library build whose `vue` import is mapped to `window.Inventoros.Vue`, published from `dist/` to `public/plugin-assets/{slug}/` by `PluginAssetPublisher`.
- Marketplace: `MarketplaceInstaller` downloads, checks the Ed25519 signature over `slug|version|sha256` (`PackageSignature`) and the catalog checksum, then installs through the same path as uploads. Paid packages need the organization's marketplace token (`Organization::marketplace_token`, encrypted).

## Capability audit

Legend: **Supported** works today, documented or proven. **Partial** works with a plugin-side convention or an undocumented Laravel primitive, with caveats. **Missing** cannot be done without touching core.

| # | Capability | Status | How, and the caveats |
|---|------------|--------|----------------------|
| a | Own PHP classes with autoloading | **Partial** | No manifest `autoload` and no composer step. The plugin registers its own PSR-4 autoloader (`spl_autoload_register`) from a file `Plugin.php` `require_once`s (template: `bootstrap.php`). Because plugins load at boot, classes resolve in web requests, artisan and queue workers. Lifecycle files must require the autoloader themselves (an inactive plugin is not loaded). |
| b | Migrations | **Partial** | No core runner. `hooks/activate.php` runs `app('migrator')->run([path])` (pending only, idempotent); updates re-activate, so new migrations run then. `hooks/uninstall.php` runs `migrator->reset([path])`, which rolls back exactly the plugin's migrations. Migrations are recorded in the core `migrations` table, so file names must be globally unique (`*_plg_{slug}_*`). MySQL commits DDL implicitly; plugin tests create tables in `afterRefreshingDatabase()`. |
| c | Permissions | **Missing** (registration) / Partial (checks) | `app/Enums/Permission.php` is closed. `Role::hasPermission()` accepts any string and the web role request accepts any string, but `Permission::grouped()` (role pages, token picker) lists enum cases only, admins' `getAllPermissions()` (`HasRolesAndPermissions`) returns enum cases only (so `auth.permissions` in the browser never contains a plugin permission), and API token abilities (`Api\AuthController::createToken`) and permission-set API validation reject unknown names. Today a plugin must reuse core permissions. Closed by the core PR (gap G2). |
| d | Settings UI | **Partial** | No settings registry or slot on the Settings pages. A plugin registers its own page (`register_page`) plus a menu item. Storage: `SettingsService` (org-scoped key/value with an `encrypted` flag) or the plugin's own table (template convention: own table, dropped on uninstall). |
| e | Routes | GET pages **Supported**; write and API routes **Partial** | `register_page()` gives an auth-gated, permission-gated GET Inertia page. Anything else uses the `Route` facade from the registration closure: works with `route:cache` (proven), but it is undocumented, registered before `routes/web.php` (it could shadow a core route), gets no enforced prefix, and API routes must add `auth:sanctum` and `api.permission` themselves. Gotcha: `PluginPageRoutes::isTaken()` matches with `matches($request, false)` (method ignored), so a raw `PUT /x` makes a page at `GET /x` silently skipped; keep writes under `/{slug}/actions/...`. |
| f | Queued jobs | **Supported** | Standard `ShouldQueue` jobs; the worker boots the app, loads active plugins and resolves the class (proven with the database queue). A job of a plugin deactivated while it waits fails (class not loaded), which is the safe outcome. cPanel hosts drain the queue from the scheduler (`queue.run_via_scheduler`). |
| g | Scheduled tasks | **Partial** (works, undocumented) | `app()->afterResolving(Schedule::class, ...)` (or immediate if already resolved) from the registration closure; appears in `schedule:list` (proven). Needs the core scheduler running (cron on cPanel). |
| h | Listeners on core events and hooks | **Supported**, with coverage gaps | 55 actions (product, stock, order, payment, shipment, PO, customer, return, transfer, stock audit, work order, supplier, dashboard, email) and 27 filters. Several "web form" hooks do not fire from REST, GraphQL, MCP or import (`product_updated` fires only in `Inventory\ProductController`). Laravel model events (`eloquent.updated: App\Models\Inventory\Product`) cover every surface but bind to internal class names. |
| i | Artisan commands | **Partial** (works, undocumented) | `Illuminate\Console\Application::starting(fn ($artisan) => $artisan->resolveCommands([...]))` from the closure (proven in `artisan list`). Tests must register commands on the kernel explicitly because the test Artisan instance exists before activation. |
| j | Inbound webhooks | **Partial** | A raw route outside the `web` group has no session and no CSRF. Signature verification, replay protection and idempotency are the plugin's job (core's `Webhooks\EasyPostWebhookController` is the pattern). The `webhooks` prefix is reserved only for plugin pages; no convention for plugin webhook URLs. |
| k | MCP tools | **Missing** | `App\Mcp\Servers\InventorosServer::$tools` is a hard-coded array; no filter. |
| l | Navigation | **Supported** | `register_menu_item()` (permission, position, submenu, badge). Labels are fixed strings set at boot, so not translatable; no way to place an item inside a core section. |
| m | Dashboard widgets, tabs, slots | **Supported** on listed pages | `register_dashboard_widget()`, `add_page_component()` with tabs on `products.show` / `orders.show`. Slots exist on dashboard, products, orders, purchase orders, suppliers, categories and locations index. None on stock audits, cycle counts, warehouses, transfers, shipments, returns, customers, work orders, settings or the customer portal; no row or bulk actions on index tables. |
| n | Composer dependencies | **Missing** | Plugins ship no `vendor/` resolution; a second copy of a library the core already loads (Guzzle, Symfony, Carbon) would clash. Strategy: use what the core ships (`Http` client, `maatwebsite/excel`, the barcode and QR libraries, Sanctum, `laravel/mcp`); vendor anything else under a prefixed namespace (php-scoper to `Inventoros\Plugins\{Name}\Vendor\`) inside the ZIP. ZIP limits: 2000 entries, 50 MB uncompressed. |
| o | Vue pages via `window.Inventoros` | **Partial** | Runtime bundles work with the app's Vue, Inertia and `AppLayout`, but the SDK shares only `Badge`, `Button`, `Card`, `CardHeader`, `PageHeader`: no inputs, tables, modals or the barcode scanner, and no access to the app's vue-i18n. The app's Tailwind build does not scan plugins (scoped CSS with design tokens). Closed by the core PR (gap G1). |
| p | Licensing for paid plugins | **Partial** (install time only) | The marketplace refuses to serve a paid package without an entitled token (`MarketplaceInstaller::assertAccess`), and packages are signed. Nothing at runtime: a paid plugin copied to another install runs, and subscription expiry is not represented. `config/marketplace.php` defaults `public_key` to empty, so marketplace installs are refused until `INVENTOROS_MARKETPLACE_PUBLIC_KEY` is set, although the guide says the key is committed. |
| q | Clean uninstall | **Supported** (plugin's responsibility) | `deletePlugin()` fires `plugin_uninstalling`, deactivates, runs `uninstall.php`, removes published assets, the DB row and the folder (even if cleanup throws, reported as a warning). Core does not track what a plugin created, so the plugin drops its own tables and removes its own permissions (template does both; the core PR strips `{slug}.*` permissions automatically). |

Also checked:

- **Plugin dependencies**: `requires` / `requires_php` only (`PluginRequirements`). No plugin-to-plugin `depends`. Plugin-to-plugin integration works through hooks a plugin fires itself (`do_action('{slug}_...')`).
- **Report builder**: `report_data_sources` and `report_query_{source}` let plugins add report sources. Supported.
- **Outbound webhook events**: `WebhookService::availableEvents()` is a fixed list; plugins cannot add events.
- **Stable PHP API**: plugins inevitably call core services (`StockAdjustment::adjust()`, `StockAuditService`, `ScanLookupService`, `ReorderService`, `OrderService`, `ProductLocationStockService`, `TrackedStockAllocationService`). None is marked as a public contract.
- **Customer portal**: its own route group and Inertia root; no plugin pages, slots or menu there.

## Gaps, ranked

Ranked by the number of planned plugins blocked or materially degraded. "Blocked" means the plugin cannot meet the brief (native UI, own permissions, correctness) without it; "degraded" means a documented workaround exists.

| Rank | Gap | Minimal generic extension point | Plugins affected | Status |
|------|-----|--------------------------------|------------------|--------|
| G1 | Runtime UI kit and i18n | SDK v2: share `Input`, `DataTable`, `StatTile`, `Modal`, form controls and an async `BarcodeScannerModal`; `plugin.i18n` with `t`, `te`, `locale`, `addMessages` namespaced to `plugins.{slug}` | All 31 (every plugin has UI) | Core PR (this phase) |
| G2 | Plugin permissions | `register_permission('{slug}.{ability}', label, description, category)`: listed in role pages and token picker, granted to admins, accepted as token abilities and in permission sets, stripped on delete | 22 need their own permissions (all paid plugins plus Webhooks+); the rest map onto core permissions | Core PR (this phase) |
| G3 | Channel-correct change hooks | Fire `product_updated` (and a `product_stock_changed` with warehouse/location) from the service layer on every surface, not only the web controller | Shopify, WooCommerce, Amazon, ShipStation, QuickBooks, Xero, Webhooks+, Slack, Teams (9) | Workaround: Eloquent model events in the plugin. Next core PR before Shopify. |
| G4 | Available-to-promise / reservations | A `product_available_quantity` filter plus a reservation contract (hold N units of product/variant/batch at a location with an owner and expiry) that core allocation respects | Rental, Wave Picking, 3PL, Kitting, Consignment, Shopify, WooCommerce, Amazon (8) | Design needed; before Rental |
| G5 | Plugin routes helper | `register_routes(fn () => ...)` mounting a `web` group at `/{slug}` and an `api` group at `/api/v1/plugins/{slug}` (Sanctum + `api.permission`) after core routes; method-aware page conflict check | 29 with write or API routes (all but Insights and Health Pro, which can be read-only pages) | Degraded only: raw routes work (template convention) |
| G6 | Slots on more core pages | `PluginSlot` on stock audits (index/show), cycle counts, warehouses and locations show, transfers, shipments, returns, customers show, work orders, settings index, plus row actions on index tables | Basic and Advanced Cycle Counts, Warehouse Map, Putaway, Packing Station, Wave Picking, Rental, Consignment, 3PL, Manufacturing, Expiry/FEFO, Barcode (12) | Add per plugin, one small core PR each, when the plugin is built |
| G7 | Runtime licence entitlement | Marketplace issues a signed entitlement (slug, account, install, expiry) verified with the key core already trusts; core helper `plugin_entitlement($slug)` caches it with a grace period and never blocks core data | 21 paid plugins | Before the first paid plugin (Insights). Marketplace + core change. |
| G8 | Inbound webhook convention | Reserve `/webhooks/plugins/{slug}/...` for plugin routes outside `web`; document the verify-then-queue pattern | Shopify, WooCommerce, Amazon, ShipStation, Slack, Teams, QuickBooks, Xero (8) | Degraded: integration kit provides it (see CONVENTIONS) |
| G9 | Outbound webhook events | `webhook_events` filter + `WebhookService::dispatchPluginEvent($org, "{slug}.event", $payload)` | Webhooks+, 3PL, Rental, Manufacturing, Expiry/FEFO (5) | Before Webhooks+ |
| G10 | MCP tools | `mcp_tools` filter applied in `InventorosServer`, tools subject to the token's abilities | AI Assistant (blocked); Insights, Forecasting, Replenishment, Health Pro, 3PL (benefit) | Before AI Assistant |
| G11 | Tenant sub-scoping / ownership | No generic hook is safe here. Keep core tables untouched; plugins keep their own ownership ledgers keyed by product/location, with core read-only. Revisit only with a dedicated design. | Multi-Company, 3PL, Consignment (3) | Design phase, XL |
| G12 | Manifest-driven autoload and migrations | `"autoload": {"psr-4": {...}}` and `"migrations": "database/migrations"` handled by core on activate, update and delete | All 31 (boilerplate only) | Degraded only: template convention works |
| G13 | Stable PHP API | Mark the services plugins may call with `@api`, add a contract test, and list them in the guide | All 31 | Documentation PR, any time |
| G14 | Translatable navigation and widget titles | Accept `label_key` / `title_key` (an i18n key resolved in the browser) | All with menu items | Small, with G1 follow-up |
| G15 | Console helpers | `register_command()` and `register_schedule()` wrapping the primitives above | ~15 with commands or schedules | Degraded only |
| G16 | Customer portal extension | Plugin pages and nav entries inside the portal group | 3PL, Rental, Consignment (3) | Before 3PL |
| G17 | Plugin-to-plugin dependencies | Optional `depends` in the manifest checked on activate | 0 by design: plugins integrate through each other's hooks and degrade when the other is absent | Not needed now |
| G18 | Marketplace operations | Committed default marketplace public key in core; import of release assets from private repos (token) for paid plugins | Packaging of every plugin | Ops; outside the plugin SDK |

## What the first four plugins need

| Plugin | Needs | Blocked today by | After the core PR |
|--------|-------|------------------|-------------------|
| CSV Import/Export | upload and queued import, mapping UI, preview table, run history | G1 (inputs, table, modal, i18n) | Unblocked. Reuses `import_data` / `export_data`. |
| Barcode | full-screen keyboard and scanner page, lookups via `ScanLookupService`, moves and adjustments via core services | G1 (inputs, scanner modal, i18n) | Unblocked. Reuses `manage_stock`, `transfer_stock`, `receive_purchase_orders`. |
| Basic Cycle Counts | count sessions over core stock audits, scan-to-count, approvals | G1; G6 (no slot on stock audit pages) | Unblocked with its own pages; a stock-audits slot is a nice-to-have. Reuses the stock audit permissions. |
| Shopify | credentials, webhooks, queues, schedule, mapping UI | G1; G3 (correctness, workaround available); G5/G8 (workaround) | Unblocked; G3 should land before its 1.0. |

## Rules for closing a gap

1. The extension point is generic (named for the capability, not the plugin) and useless to no one but the plugin that asked.
2. Core behaviour without plugins is byte-identical.
3. Permission checks stay server side; a plugin can never widen a tenant scope or another user's permissions.
4. Each gap closed is recorded here with its core PR and the minimum Inventoros version, and plugins that rely on it raise `requires` or feature-detect it (`function_exists()`, `window.Inventoros.apiVersion`).

| Gap | Core PR | Minimum core | Feature detection |
|-----|---------|--------------|-------------------|
| G1 | pending (see the phase 1 report) | next release | `window.Inventoros.apiVersion >= 2`, `plugin.i18n` |
| G2 | pending (see the phase 1 report) | next release | `function_exists('register_permission')` |
