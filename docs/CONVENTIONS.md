# Inventoros Official plugin conventions

How every official plugin is laid out, named, tested, released and licensed. The [template](../README.md) implements all of it; start every plugin from it (`gh repo create Inventoros/plugin-{slug} --private --template Inventoros/plugin-template`, then `php bin/rename.php`).

## Contents

1. [Repository layout](#1-repository-layout)
2. [Names and namespaces](#2-names-and-namespaces)
3. [The manifest](#3-the-manifest)
4. [Registration](#4-registration)
5. [Data](#5-data)
6. [UI](#6-ui)
7. [Versioning and compatibility](#7-versioning-and-compatibility)
8. [Testing](#8-testing)
9. [CI](#9-ci)
10. [Packaging and release](#10-packaging-and-release)
11. [Licensing](#11-licensing)
12. [The integration kit](#12-the-integration-kit)
13. [Third-party PHP libraries](#13-third-party-php-libraries)
14. [Security](#14-security)
15. [Git and reviews](#15-git-and-reviews)

## 1. Repository layout

The repository root is the plugin folder. Installed, it lives at `{inventoros}/plugins/{slug}`.

```text
plugin.json              manifest (core fields + the "inventoros" block)
Plugin.php               returns the registration closure; require_once bootstrap.php
bootstrap.php            PSR-4 autoloader for src/ (and tests/ when present)
src/
  {Class}Plugin.php      every registration, in one class
  Http/Controllers/      write and API controllers
  Jobs/  Console/  Models/  Services/  Support/
  Integration/           vendored integration kit (integration plugins only)
database/migrations/     *_plg_{prefix}_*.php, anonymous classes
hooks/                   activate.php (run migrations), deactivate.php (keep data), uninstall.php (drop own data)
lang/{locale}/*.php      server strings: __('{slug}::file.key')
ui/                      Vite library build: src/main.js, src/pages, src/components, src/locales/{locale}.json
dist/                    built bundle (gitignored; built in CI and release)
tests/                   bootstrap.php, TestCase.php, Feature/, Unit/
phpunit.xml  phpunit.pgsql.xml  phpunit.mysql.xml
bin/                     package.php, verify-package.php, rename.php
.github/workflows/       ci.yml, release.yml
docs/                    configuration and screenshots for the README
README.md  CHANGELOG.md  CONTRIBUTING.md  LICENSE
```

Only `plugin.json`, `Plugin.php`, `bootstrap.php`, `src/`, `database/`, `hooks/`, `lang/`, `dist/`, `README.md`, `CHANGELOG.md` and `LICENSE` are packed.

## 2. Names and namespaces

Everything a plugin adds to the shared application carries its slug, so two plugins (or a plugin and core) can never collide, and uninstalling can find exactly what to remove.

| Thing | Rule | Example (`cycle-counts`) |
|-------|------|--------------------------|
| Slug, folder, marketplace slug | `^[a-z0-9][a-z0-9-]{0,63}$` | `cycle-counts` |
| Repository | `Inventoros/plugin-{slug}` | `Inventoros/plugin-cycle-counts` |
| PHP namespace | `Inventoros\Plugins\{Class}` (StudlyCase; must start with a letter, so `3pl` is `ThreePl`) | `Inventoros\Plugins\CycleCounts` |
| Entry class | `{Class}Plugin` | `CycleCountsPlugin` |
| Tables | `plg_{snake slug}_` + name; the manifest `table_prefix` may shorten a long slug (prefix at most 24 characters) | `plg_cycle_counts_assignments` |
| Migration files | `YYYY_MM_DD_HHMMSS_plg_{prefix}_*.php`, real timestamps | `2026_10_05_101500_plg_cycle_counts_create_assignments_table.php` |
| Index and foreign key names | Name them explicitly when the generated name would pass 64 characters (MySQL) | `plg_cc_assign_org_loc_idx` |
| Permissions | `{slug}.{ability}`, registered with `register_permission()`; the ability is lowercase letters, digits and underscores, never hyphens (core rejects `cycle-counts.view-reports`); core names never contain a dot | `cycle-counts.approve_variance` |
| Web URIs (pages and web routes) | `/p/{slug}/...`; write routes under `/p/{slug}/actions/...` | `/p/cycle-counts/sessions/12` |
| Route names | `plg.{slug}.*` | `plg.cycle-counts.sessions.show` |
| API routes | `/api/v1/plugins/{slug}/...`, names `plg.{slug}.api.*` | `/api/v1/plugins/cycle-counts/sessions` |
| Inbound webhooks | `/webhooks/plugins/{slug}/...` (outside the `web` group), names `plg.{slug}.webhooks.*` | `/webhooks/plugins/shopify/42` |
| Inertia pages | `Plugin::{slug}/{Page}` | `Plugin::cycle-counts/Sessions` |
| Artisan commands and schedule names | `{slug}:{command}` | `cycle-counts:generate` |
| Hooks a plugin fires | `{snake slug}_{event}` | `cycle_counts_recount_requested` |
| Outbound webhook events | `{slug}.{event}` (lowercase, digits, underscores after the dot), `register_webhook_event()` | `cycle-counts.session_completed` |
| MCP tools | `{snake slug}_{name}`, `register_mcp_tool()` | `cycle_counts_open_sessions` |
| Server translations | `__('{slug}::{file}.{key}')` | `__('cycle-counts::messages.saved')` |
| Browser translations | `plugins.{slug}.{key}` (the SDK prefixes it) | `t('sessions.title')` |
| Menu `label_key`, widget `title_key` | full key `plugins.{slug}.{key}` (core ignores any other prefix) | `plugins.cycle-counts.nav.title` |
| Permission labels in the role editor | `permissions.{ability}.label` / `.description` in the browser messages | `permissions.approve_variance.label` |
| Cache keys, locks, rate limiters | `plg:{slug}:...` | `plg:shopify:sync:42` |
| Queue names (optional) | `plg-{slug}` | `plg-shopify` |
| CSS classes | `{slug}-*` in scoped styles | `.cycle-counts-grid` |
| Settings keys (when using core `SettingsService`) | `plg.{slug}.*` | `plg.slack.default_channel` |

## 3. The manifest

```json
{
    "name": "Basic Cycle Counts",
    "description": "One sentence for the Plugins page and the marketplace.",
    "version": "1.0.0",
    "author": "Inventoros",
    "author_url": "https://inventoros.com",
    "license": "AGPL-3.0-or-later",
    "requires": "2.0.0",
    "requires_php": "8.4",
    "main_file": "Plugin.php",
    "ui": { "entry": "plugin.js", "styles": ["plugin.css"] },
    "inventoros": {
        "official": true,
        "tier": "free",
        "namespace": "Inventoros\\Plugins\\CycleCounts",
        "table_prefix": "plg_cycle_counts_",
        "permission_prefix": "cycle-counts.",
        "route_name_prefix": "plg.cycle-counts.",
        "uri_prefix": "p/cycle-counts",
        "depends": [],
        "integrates_with": ["barcode"],
        "kit_version": null
    }
}
```

Core reads `name`, `description`, `version`, `author`, `author_url`, `requires`, `requires_php`, `main_file` and `ui`, and ignores the rest. The `inventoros` block is for tooling and reviewers: `tier` is `free` or `paid`; `depends` is always empty for official plugins (see the matrix principles); `integrates_with` lists plugins whose hooks it uses when present; `kit_version` is the integration kit copy it carries.

## 4. Registration

- `Plugin.php` requires `bootstrap.php` once and returns `fn ($slug, $manifest) => (new {Class}Plugin(...))->register()`. Never declare functions or classes in `Plugin.php`.
- `register()` only registers: translations, permissions, webhook events, routes, pages, navigation, hooks, MCP tools, commands, schedule. No queries at registration time (it runs on every request and before installation).
- Permissions: `register_permission('{slug}.{ability}', $label, $description, $category)` when `function_exists('register_permission')`, otherwise map each ability to the nearest core permission (template `Support/Permissions.php`). Never fall back to something broader than core already grants. Admins hold every registered permission; uninstall strips `{slug}.*` from roles and permission sets.
- Routes: reads are `register_page()` pages; writes are `Route::middleware(['web', 'auth'])` under `/p/{slug}/actions`; API routes add `api`, `auth:sanctum` and `api.permission`. Every route name starts `plg.{slug}.` and every URI sits under `/p/{slug}/`, `/api/v1/plugins/{slug}/` or `/webhooks/plugins/{slug}/`: core never registers routes there. Core (from the release after 2.0.0, Inventoros#254) refuses activation, naming the conflicting route, when a plugin page or route reuses a route name, answers a method and URI an existing route answers, or sits under a first URI segment the application uses (`/cycle-counts/...`, `/reports/...`); older cores only skip a colliding page and log it, so namespacing is what keeps a plugin working everywhere.
- Navigation: give menu items, submenu items and widgets a `label_key` / `title_key` under `plugins.{slug}.` alongside the English `label` / `title` (the fallback, and what older cores show). The key must exist in `ui/src/locales/en.json` (the template tests this); a key outside the plugin's own namespace is ignored and logged.
- Hooks: `add_action` / `add_filter` with cheap callbacks that queue real work. Listen to the domain hooks, which fire after commit, once per record per transaction, from every surface (web, REST, GraphQL, MCP, imports, jobs, commands), rather than to one controller: `product_*`, `variant_*`, `order_*`, `purchase_order_*`, `supplier_*`, `category_*`, `location_*`, `warehouse_*` (`_created` / `_updated` / `_deleted`), `approval_requested`, `approval_decided`, `import_finished`, and `stock_changed($product, ?$variant, $change)` with `$change = ['before', 'after', 'locations' => [id => ['before', 'after']]]` for every on-hand change whatever caused it. The `stock_audit_completing` filter may hold an audit's completion (return `true`, or a reason string to refuse). The full list is `HookRegistry` in the core and the core guide.
- Outbound webhook events: `register_webhook_event('{slug}.{event}', $description, $group)` and `dispatch_webhook_event($event, $data, $organizationId)` (sent after commit, signed, retried and SSRF-checked by core). Never put secrets or another organization's data in `$data`. Feature-detect both functions (template `Support/WebhookEvents.php`).
- MCP tools: `register_mcp_tool($slug, ToolClass::class, $permission)`; the tool's `$name` starts with the snake-case slug; core lists and runs it only for users holding the permission whose token also allows it; the tool scopes every query to `$request->user()->organization_id` and asks for explicit confirmation before any write.
- Commands: `Illuminate\Console\Application::starting()`. Schedule: `afterResolving(Schedule::class)` (or immediately if resolved).
- Lifecycle: `activate.php` runs migrations (and so covers updates); `deactivate.php` never deletes data; `uninstall.php` rolls back the plugin's migrations and removes its permission names from roles and permission sets. Nothing in a lifecycle file touches core records.

## 5. Data

- Every plugin row has `organization_id` (foreign key to `organizations`, cascade on delete) and every query filters by it. Every plugin ships a test that a second organization cannot read or change the first one's rows.
- Plugins never add columns to, or alter, core tables. Extra data about a core record goes in a plugin table keyed by the core id (`product_id` foreign key, cascade or null on delete as the meaning requires).
- Core records (stock, orders, POs, audits, shipments) change only through core services (`StockAdjustment::adjust()`, `StockAuditService`, `OrderService`, `PurchaseOrderService`, `ShipmentService`, `ProductLocationStockService`), inside `DB::transaction()`, with row locks where quantities are read then written. Those services are the plugin API (SDK gap G13 tracks making it official).
- Stock and bins: `StockAdjustment::adjust()` keeps the location bins in step with `products.stock` (SUM(bins) never exceeds the total). Pass `locationId` to book one bin; without it a decrease drains bins in fulfilment order and an increase lands in the primary bin. Never pass `syncBins: false` unless you move the bins yourself through `ProductLocationStockService`. Every plugin that changes stock tests that SUM(bins) equals stock afterwards for a fully binned product.
- Actors: code that runs outside a request (jobs, commands, webhook consumers) passes an explicit actor to every core service that writes a ledger row: `StockAdjustment::adjust(actor: ...)`, `StockAuditService::complete($audit, $actor)`, `OrderService::cancel($order, $actor)`, `restockForDeletion($order, $actor)`, `replaceItems($order, $items, $actor)` (the trailing `?User $actor`; without one core uses the signed-in user, then the order's creator, then the organization's first user). Never sign a user in to get attribution.
- Migrations are forward-only once released: never edit a shipped migration; add a new one. `down()` must fully reverse `up()`, because uninstall depends on it.
- Money is a decimal string (`App\Support\Money`), quantities are integers, timestamps are UTC.

## 6. UI

- Plugin pages are runtime bundles (`ui/`, Vite library mode, `vue` mapped to `window.Inventoros.Vue`). Never bundle Vue, Inertia or a component library.
- Use the app's layout and building blocks from `window.Inventoros` (`layouts.AppLayout`, `ui.*`, `Inertia.*`). On SDK v1 cores, fall back to native elements styled with the design tokens; feature-detect `window.Inventoros.apiVersion`.
- The app's Tailwind build does not scan plugins: use `<style scoped>` with the tokens (`hsl(var(--surface-raised))`, `--border-subtle`, `--text-primary`, `--accent`, `--status-danger`, `--ring` ...). Wrap page content in one root element inside `AppLayout`.
- Strings live in `ui/src/locales/{locale}.json` (English required, the core's 13 locales encouraged) and go through `plugin.i18n` when present. No em dashes in copy.
- Every flow works from the keyboard; scanning screens work with a keyboard-wedge scanner and no mouse.

## 7. Versioning and compatibility

- Semantic versions. `version` in `plugin.json`, the git tag (`vX.Y.Z`) and the `CHANGELOG.md` heading must match (the release workflow enforces it).
- `requires` is the lowest Inventoros version the plugin is tested against. A plugin that needs a newer SDK capability either raises `requires` or feature-detects it (`function_exists('register_permission')`, `register_webhook_event`, `register_mcp_tool`, `plugin_licence`, `window.Inventoros.apiVersion >= 2`) and degrades. Things older cores simply ignore (a menu `label_key`, a widget `title_key`) need no detection. Namespaced routes (`plg.{slug}.*`, `/p/{slug}/`) work on every core, so there is no reason to keep an old unprefixed route.
- Patch: fixes only. Minor: new features, new migrations, new permissions. Major: removed features, raised `requires` across a major core version, or data migrations that cannot be reversed on downgrade.
- CI runs against `INVENTOROS_REF` (core `main` today, because the core has no `v2.0.0` tag yet). Once tags exist, CI runs against the `requires` tag and against `main`.

## 8. Testing

- The plugin's PHPUnit suite runs inside a real Inventoros: CI checks out the core, runs `composer install` in the core, then places the plugin at `plugins/{slug}` and runs `vendor/bin/phpunit -c plugins/{slug}/phpunit.xml`. `tests/bootstrap.php` loads the core autoloader and the plugin's own.
- Install the core's dependencies **before** the plugin is in `plugins/`. The core's `composer.json` maps `Plugins\` to `plugins/`, so `composer install` or `dump-autoload` with an official plugin present prints "does not comply with psr-4 autoloading standard" for every `Inventoros\Plugins\{Class}` class. The warning is harmless (the plugin autoloads through its own `bootstrap.php`), but keep CI logs clean; never "fix" it in the plugin or in core.
- `tests/TestCase.php` extends the core `Tests\TestCase`, uses `RefreshDatabase`, creates the plugin's tables by overriding `migrateDatabases()` (right after the core schema, before the per-test transaction: MySQL commits DDL, so creating them inside it would end it), then activates the plugin through `PluginService` and adds its pages and commands the way boot would. On current cores the activation itself runs the route-collision check, so every test also proves the plugin's routes are namespaced.
- Required in every plugin: lifecycle (activate, deactivate keeps data, re-activate is idempotent, uninstall removes only the plugin's tables and permissions and leaves core records), permissions (allowed, refused, guest; for API routes also a token whose abilities lack the permission), tenant isolation, route namespace (every route named `plg.{slug}.*` and under the plugin's URI prefixes), manifest checks (slug, semver, prefix on every table, built bundle), and that every `label_key` / `title_key` exists in `en.json`.
- Required by domain: inventory integrity (quantities through core services, transactions), idempotency of every job and webhook consumer (duplicate delivery is a no-op), retries and permanent failures, external API failures and rate limits (through fake adapters: no live API in tests), race conditions on quantity changes, approval paths, and install/upgrade from the previous release (run the previous version's migrations, then the new ones).
- Databases: SQLite, PostgreSQL and MySQL in CI.

## 9. CI

`.github/workflows/ci.yml` from the template, unchanged apart from `PLUGIN_SLUG`:

1. **UI bundle**: `npm ci && npm run build` in `ui/`, uploaded as an artifact.
2. **Tests** (matrix sqlite, pgsql, mysql): core at `INVENTOROS_REF` with `composer install` run first, then the plugin in `plugins/{slug}`, the plugin's suite; on SQLite also the core's own `--filter=Plugin` tests with the plugin installed.
3. **Package**: `php bin/package.php`, then `php bin/verify-package.php` installs the ZIP into a freshly migrated core through `PluginService::installFromZip()`, activates (which runs the core's route-collision check), deactivates, re-activates and deletes it, and fails if any table, migration record, folder or plugin record is left.

The template repository has one more job, **renamed copy**: it runs `bin/rename.php --slug=sample-stock`, fails if the copy still names the template, then runs the copy's suite and ZIP install. It sits between `# BEGIN template-only` / `# END template-only` markers, which `bin/rename.php` removes, so plugins made from the template never run it.

## 10. Packaging and release

- ZIP layout: one top-level folder named after the slug containing the packed files from section 1; forward-slash entries; no symlinks, `.htaccess`, `.user.ini`, PHAR or PHP under `dist/`, `ui/`, `public/` or `assets/` (the marketplace refuses those); under 2000 entries and 50 MB uncompressed.
- `git tag vX.Y.Z && git push origin vX.Y.Z` runs `release.yml`: checks the tag against `plugin.json` and `CHANGELOG.md`, builds the bundle, packages, verifies the ZIP against the core installer, and publishes a GitHub release with `{slug}-X.Y.Z.zip` and its `.sha256`.
- Signing: the marketplace signs packages with its Ed25519 key over `slug|version|sha256` when it imports them (`php artisan marketplace:import-release-asset {slug} {zip url}` on inventoros.com, or a developer submission). Plugin repositories hold no signing keys. For installs that require signed uploads, `php artisan update:sign {zip}` produces a detached signature with the operator's own key.
- Private repositories (paid plugins) need an authenticated download; publish those through the developer submission until the import command accepts a token (SDK gap G18).

## 11. Licensing

**Free plugins**: AGPL-3.0-or-later, the full license text in `LICENSE`, `"license": "AGPL-3.0-or-later"` in `plugin.json`. The core is licensed AGPL-3.0-only; the two are compatible (an or-later plugin may be combined under 3.0). No licensing logic, keys or proprietary services in free repositories, and no free plugin may require a paid one.

**Paid plugins**: proprietary (`LicenseRef-Inventoros-Commercial`, the commercial terms in `LICENSE`), private repositories, sold through the marketplace.

Enforcement, in order of strength:

1. **Install and update gating (works today)**: the marketplace serves a paid package only to an organization whose connected inventoros.com token owns it; packages are signed.
2. **Runtime licence (Inventoros#251, inventoros.com#34)**: the marketplace signs a per-install, per-organization entitlement; core verifies it on every read, refreshes it daily and allows a 14-day offline grace period. A paid plugin asks `plugin_licence($slug, $organization = null)`, which never throws and never contacts the marketplace, and gets a `PluginLicence` with `status` `valid` / `expired` / `missing` / `unknown`, a `reason`, `allowsWrites()` (the same as `isValid()`) and `toArray()`. Only `valid` (which includes the grace period) unlocks writes.

```php
// Feature-detect: cores without the SDK have no licence to check.
$licence = function_exists('plugin_licence')
    ? plugin_licence('insights', $organizationId)   // pass the organization in jobs, commands and schedules
    : null;

if ($licence !== null && ! $licence->allowsWrites()) {
    // Read-only: render pages with a renew notice (pass $licence->toArray() as a prop),
    // refuse writes with a clear message, skip syncs and record why. Keep all data.
}
```

The template carries this example, commented out, in `SettingsController`; free plugins ship no licence logic.

The licence check **fails safe**:

| State | What the plugin does |
|-------|----------------------|
| `valid` (licensed, or within the offline grace period) | Everything. |
| `expired`, `missing` or `unknown` | Its pages open read-only with a notice and a link to renew; its write routes refuse with a clear message; its schedules and syncs stop and say so in their history. Its data is kept. |
| Never, in any state | Blocking a core page, changing or hiding core records (stock, orders, products), deleting plugin data, phoning home on every request, or failing a request because the marketplace is unreachable. |

No security through obscurity: the check is ordinary readable PHP; the protection is the signed entitlement and the license terms, not hidden code.

## 12. The integration kit

Shared infrastructure for Shopify, WooCommerce, Amazon, ShipStation, QuickBooks and Xero (and later providers).

| Part | What it does |
|------|--------------|
| Connections | One row per external account per organization; credentials in an `encrypted:array` cast, never logged or sent to the browser; connection test; health (last success, consecutive failures, a circuit breaker that pauses syncing after repeated failures and says why). |
| Provider adapter | An interface per provider (`CatalogSource`, `InventoryTarget`, `OrderSource`, ...) with an HTTP implementation and a fake used by every test. No live API in tests. |
| Sync runs and logs | Every initial, incremental, manual or webhook-driven sync is a run (status, counts, started and finished times, error summary) with per-record log lines; shown in the UI, pruned on a schedule. |
| Jobs | `InitialSync`, `IncrementalSync` (cursor based), `PushInventory`, `ProcessWebhook`; all idempotent, `ShouldBeUnique` per connection where needed, `$backoff` retries for transient errors, immediate failure for permanent ones (401, 404, validation). Nothing external runs inside a web request. |
| Rate limiting | Per-connection limiter (`RateLimiter` keyed `plg:{slug}:rate:{connection}`) honouring provider headers (Shopify query cost, Retry-After). |
| Webhook inbox | `POST /webhooks/plugins/{slug}/{connection}`: verify the signature (`hash_equals`), store the payload with the provider's event id under a unique `(connection_id, event_id)` index, answer 200 at once, process in a job. Duplicate deliveries hit the unique index and are acknowledged without reprocessing. |
| Mappings | `(connection_id, external_type, external_id)` to core id, unique both ways; a mapping UI; unmapped records listed for review rather than guessed. |
| Cursors | Last processed timestamp or page token per connection and resource. |

**Where it lives: a vendored, namespaced copy inside each integration plugin.** It is built inside Shopify (plugin 4), then extracted to a private `Inventoros/plugin-integration-kit` repository, from which a script copies it into `src/Integration/` with the plugin's namespace and table prefix rewritten (`kit_version` in the manifest records the copy).

Why not the alternatives:

- **A separate kit plugin that others depend on** breaks independent install and removal: deleting the kit would break every integration, core has no dependency resolution (G17), and two integrations would be forced onto one kit version at upgrade time.
- **A composer package** cannot be installed on cPanel, where there is no composer, and a shared package loaded by two plugins at different versions would collide on class names.
- **Core** is where it should end up, but only once three plugins have exercised the same API: putting a half-designed sync framework in core freezes it into the core's release cadence and its compatibility promises. After Shopify, WooCommerce and Amazon ship, the stable parts (webhook inbox route group, encrypted connection storage, sync-run model) become SDK proposals; plugins then feature-detect them.

Each copy has its own tables (`plg_shopify_connections`, `plg_woocommerce_connections`, ...), so every integration installs, upgrades and uninstalls on its own.

## 13. Third-party PHP libraries

Use what the core already ships first: Laravel's `Http` client (Guzzle), `maatwebsite/excel`, the core's barcode and QR libraries, Sanctum, `laravel/mcp`. A provider SDK is usually not worth it: call the REST or GraphQL API with `Http` behind the adapter interface. When a library is unavoidable, prefix it with php-scoper into `Inventoros\Plugins\{Class}\Vendor\`, commit the scoped copy under `src/Vendor/`, and record its name, version and license in the README. Never ship an unscoped `vendor/` directory: it would clash with the core's own copies.

## 14. Security

- Permission checks on the server for every page, route, job trigger and API endpoint; UI hiding is never the control.
- Tenant scoping on every query; no plugin code may widen `OrganizationScope` or another user's permissions.
- Credentials, tokens and webhook secrets in encrypted columns; never in logs, exceptions, sync logs or page props.
- User-entered URLs (store URLs, webhook targets) validated as https and checked against private and loopback addresses before any request (SSRF).
- Inbound webhooks: signature verified before anything is stored, rate limited, and processed asynchronously.
- AI features: the model never touches the database; tools are deterministic services running as the user; writes and anything financial need explicit confirmation.

## 15. Git and reviews

- Branch from `main`; small logical commits with imperative subjects; `git add` named paths only; confirm the branch before committing.
- Commit messages, PR titles and bodies describe the change and how it was tested; they never mention tools or assistants used to write it, and carry no co-author trailers.
- A plugin is done when: its README covers install, configuration and screenshots; CHANGELOG has the release; CI is green on all three databases and the package job; the release ZIP installs from the marketplace.
