# Develop and test

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

### CI and releases

CI validates the workflow files, builds the runtime UI, runs the plugin tests on SQLite, PostgreSQL and MySQL, and checks the core plugin system. It then builds the ZIP and runs the real install, activation, deactivation, reactivation and deletion cycle. The manual CI action accepts an Inventoros branch, tag or SHA for compatibility checks.

Pushing a `vX.Y.Z` tag runs that same verification pipeline before publishing. The tag must match `plugin.json` and have a changelog section. The release downloads the verified ZIP, checks its SHA-256 and publishes that artifact; it does not rebuild the archive. Only the publishing job has repository write access. CI artifacts expire after seven days; published release assets remain available.

If a private-repository run fails with no steps and an annotation about account payments or a spending limit, GitHub has refused to allocate a runner. Restore the account's Actions billing or allowance before retrying; changing workflow code cannot clear that account restriction.
