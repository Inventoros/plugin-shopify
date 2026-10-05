import { defineConfig, devices } from '@playwright/test';
import { fileURLToPath } from 'url';
import path from 'path';

/**
 * README screenshots of the Shopify pages. Run from the Inventoros root,
 * with the plugin installed at plugins/shopify and activated, the E2E
 * seeder run and the app served with QUEUE_CONNECTION=sync:
 *
 *     php artisan db:seed --class=E2ETestSeeder --force
 *     php artisan plugin:activate shopify
 *     php artisan serve --port=8000 &
 *     SCREENSHOTS=1 APP_URL=http://127.0.0.1:8000 npx playwright test -c plugins/shopify/e2e/playwright.config.ts
 *
 * The spec seeds its own store (e2e/seed.php) from the test fixtures;
 * nothing talks to Shopify.
 */
const here = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
    testDir: here,
    testMatch: /.*\.spec\.ts/,
    fullyParallel: false,
    workers: 1,
    reporter: [['list']],
    timeout: 90000,
    expect: { timeout: 10000 },
    outputDir: path.join(here, 'test-results'),
    use: {
        baseURL: process.env.APP_URL || 'http://127.0.0.1:8000',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1360, height: 900 } } },
        { name: 'mobile', use: { ...devices['Desktop Chrome'], viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true } },
    ],
});
