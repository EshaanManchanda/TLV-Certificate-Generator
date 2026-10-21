// @ts-check
// §22 Bulk jobs (7.9.3): "Generate certificates" on the Students list starts a background job and
// opens its progress page, which runs the job to the end (even without WP-Cron), lists failed
// items with their error and offers Retry; the PDF status filter finds who still needs one;
// Import history flags a duplicate row and applies it.
const path = require('path');
const fs = require('fs');
const os = require('os');
const { test, expect, adminUrl, confirmKit } = require('./support/fixtures');
const wp = require('./support/wp');

const TYPE = 'E2E JobType';
const ORPHAN = 'E2E JobNoTemplate';

const removeSeed = () => {
	wp.sql('DELETE FROM {p}cg_students WHERE certificate_type IN (%s, %s) OR email LIKE %s', TYPE, ORPHAN, '%@e2e-jobs.test');
	wp.sql('DELETE FROM {p}cg_certificate_templates WHERE certificate_type = %s', TYPE);
};

test.describe('§22 Bulk jobs', () => {
	test.beforeAll(() => {
		removeSeed();
		wp.seedTemplate({ template_name: TYPE, certificate_type: TYPE, template_url: wp.bundledBg('classic-gold.jpg') });
		for (let i = 1; i <= 30; i++) {
			wp.seedPerson('students', { name: `E2E Job ${String(i).padStart(2, '0')}`, email: `job${i}@e2e-jobs.test`, school: 'E2E Job School', certificate_type: TYPE, issue_date: '2026-10-20' });
		}
		wp.seedPerson('students', { name: 'E2E Job Orphan', email: 'orphan@e2e-jobs.test', school: 'E2E Job School', certificate_type: ORPHAN, issue_date: '2026-10-20' });
	});

	test.afterAll(() => removeSeed());

	test('Generate runs as a job to the end, lists the failed item, and the PDF filter agrees', async ({ page }) => {
		await page.goto(adminUrl('cg-students', { s: 'E2E Job', pdf: 'none' }));
		await page.locator('#cg-cb-all').check();
		const all = page.locator('#cg-select-all-link');
		if (await all.isVisible()) {
			await all.click(); // every matching row, not just this page
		}
		await page.selectOption('#cg-bulk-action', 'generate');
		await page.click('#cg-doaction');
		await confirmKit(page);

		await expect(page).toHaveURL(/page=cg-jobs&job=\d+/);
		// The page polls and runs the batches itself; then reloads with the failed items.
		await expect(page.getByRole('heading', { name: 'Failed items' })).toBeVisible({ timeout: 180_000 });
		await expect(page.locator('#cg-job-text')).toContainText('30 done, 1 failed');
		await expect(page.locator('table', { hasText: 'No published template' })).toBeVisible();
		await expect(page.getByRole('button', { name: 'Retry failed items' })).toBeVisible();

		const made = Number(wp.sql("SELECT COUNT(*) n FROM {p}cg_students WHERE certificate_type = %s AND serial_number <> ''", TYPE)[0].n);
		expect(made).toBe(30);

		await page.goto(adminUrl('cg-students', { s: 'E2E Job', pdf: 'none' }));
		await expect(page.locator('#cg-list-form tbody tr', { hasText: 'E2E Job Orphan' })).toHaveCount(1);
		await expect(page.locator('#cg-list-form tbody tr', { hasText: 'E2E Job 01' })).toHaveCount(0);
		await page.goto(adminUrl('cg-students', { s: 'E2E Job 01', pdf: 'ok' }));
		await expect(page.locator('#cg-list-form tbody tr', { hasText: 'E2E Job 01' })).toHaveCount(1);
	});

	test('Import history: a flagged duplicate is applied from the history tab', async ({ page }) => {
		const csv = path.join(os.tmpdir(), `e2e-flag-${Date.now()}.csv`);
		fs.writeFileSync(csv, 'student_name,email,school_name,certificate_type,issue_date,phone\nE2E Job 02,job2@e2e-jobs.test,E2E Job School,E2E JobType,2026-10-20,555-0102\n');
		await page.goto(adminUrl('cg-bulk-import', { tab: 'students' }));
		await page.setInputFiles('input[name=students_csv]', csv);
		await page.click('input[name=preview_students]');
		await page.check('input[name=cg_existing][value=flag]');
		await page.click('input[name=preview_students][value="Check again"]'); // the check form's button (the upload form has one too)
		await expect(page.locator('.notice-info', { hasText: 'flagged for review' })).toBeVisible();
		await page.click('input[name=import_students]');
		await expect(page.locator('body')).toContainText('flagged for review');
		fs.unlinkSync(csv);

		await page.goto(adminUrl('cg-bulk-import', { tab: 'history' }));
		await page.locator('table a').first().click();
		const row = page.locator('tr', { hasText: 'phone' });
		await expect(row).toContainText('555-0102');
		await row.getByRole('button', { name: 'Apply file values' }).click();
		await expect(page.locator('.notice-success')).toBeVisible();
		expect(wp.sql('SELECT phone FROM {p}cg_students WHERE email = %s', 'job2@e2e-jobs.test')[0].phone).toBe('555-0102');
	});
});
