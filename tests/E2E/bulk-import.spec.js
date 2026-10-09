// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

// Requires CG_E2E_ADMIN_USER / CG_E2E_ADMIN_PASS env vars for a real wp-admin
// login on the target site (see playwright.config.js for CG_E2E_BASE_URL).
// Bulk CSV import is a Pro/Business-gated feature — if the target site is on
// the free plan, this test skips itself instead of asserting a false failure.

test('admin can bulk-import students via CSV and see them in the list', async ({ page }) => {
	const user = process.env.CG_E2E_ADMIN_USER;
	const pass = process.env.CG_E2E_ADMIN_PASS;
	test.skip(!user || !pass, 'Set CG_E2E_ADMIN_USER / CG_E2E_ADMIN_PASS to run this spec.');

	await page.goto(process.env.CG_E2E_LOGIN_PATH || '/wp-login.php');
	await page.fill('#user_login', user);
	await page.fill('#user_pass', pass);
	await page.click('#wp-submit');
	await expect(page.locator('#wpadminbar')).toBeVisible();

	await page.goto('/wp-admin/admin.php?page=cg-bulk-import');

	const proGateVisible = await page.locator('text=requires the').isVisible().catch(() => false);
	test.skip(proGateVisible, 'Bulk import is Pro/Business-gated and this site is on the free plan.');

	const csvPath = path.join(__dirname, 'fixtures', 'e2e-students.csv');
	fs.mkdirSync(path.dirname(csvPath), { recursive: true });
	const uniqueEmail = `e2e-${Date.now()}@example.com`;
	fs.writeFileSync(
		csvPath,
		'student_name,email,school_name,issue_date,certificate_type\n' +
			`E2E Student,${uniqueEmail},E2E School,2026-01-15,E2E Certificate\n`
	);

	await page.setInputFiles('#students_csv', csvPath);
	await page.click('input[name="submit_students"]');

	await expect(page.locator('.notice-success')).toContainText('Successfully imported 1 students');

	await page.goto('/wp-admin/admin.php?page=cg-students');
	await expect(page.locator('table')).toContainText(uniqueEmail);
});
