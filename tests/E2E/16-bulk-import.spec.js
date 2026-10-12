// @ts-check
// §16 Bulk import — upload → check (column mapping, date order, dry-run counts) → import.
const path = require('path');
const fs = require('fs');
const os = require('os');
const { test, expect, adminUrl } = require('./support/fixtures');
const wp = require('./support/wp');

test.describe('§16 Bulk import check step', () => {
	test('an unmapped column and an ambiguous date block Import until fixed; then rows are saved', async ({ page }) => {
		const tag = `e2e-import-${Date.now()}`;
		const csv = path.join(os.tmpdir(), `${tag}.csv`);
		// "Pupil" is no known alias, and 03/04/2026 reads as 3 April or 4 March.
		fs.writeFileSync(csv, `Pupil;Mail;School;Issued On;Award\nE2E Importer;${tag}@example.com;E2E Import School;03/04/2026;E2E Import\n`);

		await page.goto(adminUrl('cg-bulk-import', { tab: 'students' }));
		await expect(page.locator('a[download="sample-students.csv"]')).toBeVisible();
		await page.setInputFiles('#students_csv', csv);
		await page.click('input[name="preview_students"]');

		await expect(page.locator('body')).toContainText('semicolon separated');
		await expect(page.locator('body')).toContainText('Required columns not mapped: student_name');
		await expect(page.locator('body')).toContainText('could be 3 April or 4 March');
		await expect(page.locator('input[name="import_students"]')).toBeDisabled();

		await page.selectOption('select[name="cg_map[0]"]', 'student_name');
		await page.selectOption('#cg_date_order', 'dmy');
		await page.locator('form:has(.cg-import-map) input[name="preview_students"]').click();
		await expect(page.locator('body')).toContainText('1 rows: 1 new');
		await expect(page.locator('body')).toContainText('Nothing has been saved yet.');
		expect(wp.sql('SELECT COUNT(*) AS n FROM {p}cg_students WHERE email = %s', `${tag}@example.com`)[0].n).toBe('0');

		await page.click('input[name="import_students"]');
		await expect(page.locator('.cg-import-report')).toContainText('1 rows read → 1 students saved (1 new, 0 updated)');
		const row = wp.sql('SELECT student_name, issue_date FROM {p}cg_students WHERE email = %s', `${tag}@example.com`)[0];
		expect([row.student_name, row.issue_date]).toEqual(['E2E Importer', '2026-04-03']);

		wp.sql('DELETE FROM {p}cg_students WHERE email = %s', `${tag}@example.com`);
		wp.sql('DELETE FROM {p}cg_schools WHERE school_name = %s', 'E2E Import School');
		fs.unlinkSync(csv);
	});
});
