// @ts-check
// §21 Certificate Register — filter issued certificates, download them as CSV and PDF; the verify page exists.
const fs = require('fs');
const { test, expect, adminUrl } = require('./support/fixtures');
const wp = require('./support/wp');

const TYPE = 'E2E Register';

test.describe('§21 Certificate Register', () => {
	test.beforeAll(() => {
		wp.seedCertificate({ student_name: 'E2E Reg Valid', certificate_type: TYPE, serial_number: 'E2E-REG-1', certificate_data: JSON.stringify({ school_name: 'E2E Reg School' }) });
		wp.seedCertificate({ student_name: 'E2E Reg Revoked', certificate_type: TYPE, serial_number: 'E2E-REG-2', revoked_at: '2026-01-01 00:00:00', revoked_reason: 'E2E test' });
	});

	test.afterAll(() => {
		wp.sql('DELETE FROM {p}certificate_generator WHERE certificate_type = %s', TYPE);
	});

	test('filters by type and status', async ({ page }) => {
		await page.goto(adminUrl('cg-register', { type: TYPE }));
		const rows = page.locator('.cg-register-table tbody tr');
		await expect(rows).toHaveCount(2);
		await expect(page.locator('.cg-register-count')).toContainText('2 certificates match');

		await page.goto(adminUrl('cg-register', { type: TYPE, status: 'revoked' }));
		await expect(rows).toHaveCount(1);
		await expect(rows.first()).toContainText('E2E Reg Revoked');
		await expect(rows.first()).toContainText('Revoked');
	});

	test('downloads CSV and PDF', async ({ page }) => {
		await page.goto(adminUrl('cg-register', { type: TYPE }));

		const [csv] = await Promise.all([page.waitForEvent('download'), page.locator('#cg-register-csv').click()]);
		const text = fs.readFileSync(/** @type {string} */ (await csv.path()), 'utf8');
		expect(text.charCodeAt(0)).toBe(0xfeff); // BOM
		expect(text).toContain('E2E-REG-1');
		expect(text).toContain('E2E Reg School');

		const [pdf] = await Promise.all([page.waitForEvent('download'), page.locator('#cg-register-pdf').click()]);
		expect(fs.readFileSync(/** @type {string} */ (await pdf.path())).subarray(0, 4).toString()).toBe('%PDF');
	});

	test('the verify page is published and the checklist says so', async ({ page }) => {
		wp.wpEval('certificate_generator_ensure_verify_page(); echo 1;');
		await page.goto(adminUrl('cg-documentation', { tab: 'getting-started' }));
		const step = page.locator('.cg-step', { hasText: 'Publish the certificate verification page' });
		await expect(step).toContainText('Done');
	});
});
