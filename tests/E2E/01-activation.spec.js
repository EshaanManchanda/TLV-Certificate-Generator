// @ts-check
// §1 Activation & upgrade.
const { test, expect, adminUrl } = require('./support/fixtures');
const wp = require('./support/wp');

const PLUGIN_FILE = 'Certificate-Generator-V7.5/certificate-generator.php';

test.describe('§01 Activation & upgrade', () => {
	test('deactivate + reactivate: no errors, menu with award icon', async ({ page }) => {
		wp.setOption('certificate_generator_keep_data_on_uninstall', '1');
		await page.goto('/wp-admin/plugins.php');
		const row = page.locator(`tr[data-plugin="${PLUGIN_FILE}"]`);
		await row.locator('.deactivate a').click();
		await expect(row.locator('.activate a')).toBeVisible();
		await row.locator('.activate a').click();
		// Activation may redirect to the plugin's Getting Started docs page.
		await page.waitForURL((u) => !u.searchParams.has('action'));
		await page.waitForLoadState('load');
		await expect(page.locator('body')).not.toContainText(/Fatal error|critical error|could not be activated/i);
		await page.goto('/wp-admin/plugins.php');
		await expect(page.locator(`tr[data-plugin="${PLUGIN_FILE}"] .deactivate a`)).toBeVisible();

		const menu = page.locator('#toplevel_page_cg-dashboard');
		await expect(menu).toContainText('Certificate Generator');
		await expect(menu.locator('.wp-menu-image')).toHaveClass(/\bdashicons-award\b/);
	});

	test('SQL Migration page: DB version 010, up to date', async ({ page }) => {
		await page.goto(adminUrl('cg-sql-migration'));
		const body = page.locator('body');
		await expect(body).toContainText(/Database version:\s*010/);
		await expect(body).toContainText('up to date');
		expect(wp.getOption('certificate_generator_db_version')).toBe('010');
	});

	test('reloading admin pages twice shows no database errors', async ({ page }) => {
		for (let i = 0; i < 2; i++) {
			await page.goto(adminUrl('cg-dashboard'));
			await expect(page.locator('body')).not.toContainText(/WordPress database error|Fatal error|Warning:/);
		}
	});
});
