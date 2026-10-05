import { expect, test, type Page } from '@playwright/test';
import { execSync } from 'child_process';
import { fileURLToPath } from 'url';
import path from 'path';

/**
 * README screenshots. Only runs with SCREENSHOTS=1 (see playwright.config.ts).
 */
test.skip(!process.env.SCREENSHOTS, 'set SCREENSHOTS=1 to refresh the README screenshots');

const out = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'docs', 'screenshots');
let connection: number;

test.beforeAll(() => {
    const output = execSync('php plugins/shopify/e2e/seed.php', { cwd: process.cwd(), encoding: 'utf8' });
    connection = JSON.parse(output.slice(output.lastIndexOf('{'))).connection;
});

async function signIn(page: Page) {
    await page.goto('/login', { waitUntil: 'networkidle' });
    await page.locator('#email').fill('e2e-test@inventoros.test');
    await page.locator('#password').fill('E2ETestPassword123!');
    await page.keyboard.press('Enter');
    await page.waitForURL('**/dashboard');
}

const shot = (page: Page, name: string, project: string) =>
    page.screenshot({ path: path.join(out, `${name}${project === 'mobile' ? '-mobile' : ''}.png`), fullPage: true });

test('shopify screenshots', async ({ page }, info) => {
    const project = info.project.name;
    await signIn(page);

    await page.goto('/p/shopify', { waitUntil: 'networkidle' });
    await expect(page.getByText('acme-store.myshopify.com').first()).toBeVisible();
    await shot(page, 'overview', project);

    await page.goto(`/p/shopify/mappings?connection=${connection}`, { waitUntil: 'networkidle' });
    await expect(page.getByText('BOLT-1').first()).toBeVisible();
    await shot(page, 'mappings', project);

    if (project === 'desktop') {
        await page.goto(`/p/shopify/mappings?connection=${connection}&entity=location`, { waitUntil: 'networkidle' });
        await shot(page, 'locations', project);

        await page.goto(`/p/shopify/activity?connection=${connection}`, { waitUntil: 'networkidle' });
        await page.getByRole('link', { name: 'Details' }).first().click();
        await page.waitForLoadState('networkidle');
        await shot(page, 'history', project);

        await page.goto(`/p/shopify/activity?connection=${connection}&tab=failed`, { waitUntil: 'networkidle' });
        await expect(page.getByText('#1003').first()).toBeVisible();
        await shot(page, 'failed', project);

        await page.goto('/p/shopify', { waitUntil: 'networkidle' });
        await page.getByRole('button', { name: 'Connect another store' }).click();
        await shot(page, 'connect', project);
    }
});
