# Inventoros plugin template

The starting point for every Inventoros Official plugin. It is a small, working plugin called **Shopify** that shows each part a real plugin needs, wired the way the portfolio conventions require ([docs/CONVENTIONS.md](docs/CONVENTIONS.md)).

| Part | Where |
|------|-------|
| Manifest (`requires`, UI bundle, portfolio metadata) | `plugin.json` |
| PSR-4 autoloading without composer | `bootstrap.php` |
| One registration class | `src/ShopifyPlugin.php` |
| Migrations, run on activation and rolled back on delete | `database/migrations`, `src/Support/Migrations.php`, `hooks/` |
| A permission, with a fallback for cores that cannot register one | `src/Support/Permissions.php` |
| A settings page in Vue, built with Vite, using the app's layout and components | `ui/`, `src/Http/Controllers/SettingsController.php` |
| A write route (`web` group, CSRF, sign-in) | `ShopifyPlugin::registerRoutes()` |
| A sidebar item | `ShopifyPlugin::registerNavigation()` |
| A hook listener that queues a job | `ShopifyPlugin::registerHooks()`, `src/Jobs/RecordActivity.php` |
| An artisan command on the core scheduler | `src/Console/PruneActivityCommand.php`, `ShopifyPlugin::registerSchedule()` |
| Server and browser translations | `lang/`, `ui/src/locales/` |
| Tests that run inside Inventoros | `tests/`, `phpunit*.xml` |
| CI, packaging and releases | `.github/workflows/`, `bin/` |

## Contents

- [Requirements](#requirements)
- [Start a new plugin](#start-a-new-plugin)
- [Install](#install)
- [Configure](#configure)
- [Develop and test](#develop-and-test)
- [Release](#release)
- [Documentation](#documentation)

## Requirements

- Inventoros 2.0.0 or newer (`requires` in `plugin.json`)
- PHP 8.4 or newer
- Node 22 to build the UI bundle (not needed to install a released ZIP)

## Start a new plugin

```bash
gh repo create Inventoros/plugin-my-thing --private --template Inventoros/plugin-template --clone
cd plugin-my-thing
php bin/rename.php --slug=my-thing --name="My Thing" --class=MyThing
```

`bin/rename.php` rewrites the namespace (`Inventoros\Plugins\MyThing`), the table prefix (`plg_my_thing_`), the permission, route, command and translation prefixes, and the file names that carry them. Review the diff, then replace the Shopify features with your own.

## Install

From a release: download `shopify-X.Y.Z.zip` and upload it in **Plugins**, or install it from **Plugins > Marketplace** once it is listed. Then activate it.

From source:

```bash
cd ui && npm ci && npm run build && cd ..
cp -r . /path/to/inventoros/plugins/shopify     # the folder name is the slug
php artisan plugin:activate shopify              # from the Inventoros root
```

Activation creates the plugin's tables. Deactivation keeps them. Deleting the plugin drops them and removes its permissions from roles; core data is never touched.

## Configure

Open **Shopify** in the sidebar (admins, or users with the **Manage Shopify** permission; on cores without plugin permissions, users with **Edit Settings**).

| Setting | Default | Effect |
|---------|---------|--------|
| Greeting | `Hello from Shopify` | Shown on the settings page. |
| Log low-stock alerts | off | Queues an activity line whenever a product drops to its minimum stock. |

Activity older than 30 days is deleted daily at 04:10 by `shopify:prune-activity`, which needs the Inventoros scheduler running (cron on cPanel, the scheduler container in Docker).

## Develop and test

The tests boot a real Inventoros with the plugin activated through the normal lifecycle, so they need a core checkout:

```bash
git clone https://github.com/Inventoros/Inventoros.git && cd Inventoros
composer install && cp .env.example .env && php artisan key:generate
git clone git@github.com:Inventoros/plugin-template.git plugins/shopify
(cd plugins/shopify/ui && npm ci && npm run build)
vendor/bin/phpunit -c plugins/shopify/phpunit.xml          # SQLite
vendor/bin/phpunit -c plugins/shopify/phpunit.pgsql.xml    # PostgreSQL (DB_HOST etc. from env)
```

CI does the same against `INVENTOROS_REF` on SQLite, PostgreSQL and MySQL, runs the core's own plugin-system tests with the plugin installed, then builds the ZIP and installs, activates, deactivates and deletes it through the core installer (`bin/verify-package.php`).

## Release

1. Bump `version` in `plugin.json` (semver) and add a `## [X.Y.Z]` section to `CHANGELOG.md`.
2. Tag and push: `git tag vX.Y.Z && git push origin vX.Y.Z`.
3. The release workflow checks the tag against `plugin.json`, builds and verifies the ZIP, and attaches it with its sha256 to a GitHub release.
4. The marketplace imports and signs it: `php artisan marketplace:import-release-asset shopify <zip url>` on inventoros.com.

`php bin/package.php` builds the same ZIP locally into `build/`.

## Documentation

- [docs/CONVENTIONS.md](docs/CONVENTIONS.md): repository layout, naming, testing, CI, packaging, licensing and the integration kit.
- [docs/SDK-GAPS.md](docs/SDK-GAPS.md): what the Inventoros plugin SDK supports today and the extension points the portfolio still needs.
- [docs/PLUGIN-MATRIX.md](docs/PLUGIN-MATRIX.md): every planned official plugin, its dependencies and build order.
- The core guide: [docs/PLUGIN_DEVELOPMENT.md](https://github.com/Inventoros/Inventoros/blob/main/docs/PLUGIN_DEVELOPMENT.md).

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE).
