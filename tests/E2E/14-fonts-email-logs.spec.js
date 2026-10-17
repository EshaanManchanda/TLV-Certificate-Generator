// @ts-check
// §14 Fonts library card + Email Logs resend controls.
const { test, expect, adminUrl, confirmKit } = require('./support/fixtures');
const wp = require('./support/wp');

test.describe('§14 Fonts page', () => {
	test('lists the built-in fonts on every plan, with a sample PDF', async ({ page }) => {
		await page.goto(adminUrl('cg-fonts'));
		const rows = page.locator('#cg-builtin-fonts tbody tr');
		expect(await rows.count()).toBeGreaterThan(20);
		await expect(page.locator('#cg-builtin-fonts')).toContainText('helvetica');
		// georgia is an alias for the open-licensed Gelasio and prints as itself.
		const georgia = rows.filter({ has: page.locator('code', { hasText: /^georgia$/ }) });
		await expect(georgia).toContainText('Gelasio (Georgia-compatible)');
		await expect(georgia).not.toContainText('Prints as Helvetica');

		const href = await page.getByRole('link', { name: 'Download sample PDF' }).getAttribute('href');
		const res = await page.request.get(/** @type {string} */ (href));
		expect(res.headers()['content-type']).toContain('application/pdf');
		expect((await res.body()).subarray(0, 4).toString()).toBe('%PDF');
	});
});

test.describe('§14 Email Logs resend', () => {
	const EMAIL = 'resend@e2e.test';
	const LMS_EMAIL = 'lms@e2e.test';
	let anchor;

	test.beforeAll(() => {
		anchor = wp.seedCertificate({ student_name: 'E2E Resend', email: EMAIL, certificate_type: 'E2E Merit' });
		wp.sql("INSERT INTO {p}cert_email_logs (certificate_id, recipient_email, recipient_name, certificate_type, status, error_message) VALUES (%d, %s, 'E2E Resend', 'E2E Merit', 'failed', 'E2E SMTP down')", Number(anchor), EMAIL);
		// LMS auto-issue logs with certificate_id 0; resend resolves the address's student record.
		wp.seedPerson('students', { name: 'E2E LMS', email: LMS_EMAIL, certificate_type: 'E2E Merit' });
		wp.sql("INSERT INTO {p}cert_email_logs (certificate_id, recipient_email, recipient_name, certificate_type, status, error_message) VALUES (0, %s, 'E2E LMS', 'E2E Merit', 'failed', 'E2E SMTP down')", LMS_EMAIL);
	});

	test('failed rows, LMS ones included, offer Resend; Resend all failed queues both', async ({ page }) => {
		await page.goto(adminUrl('certificate-email-logs', { s: 'e2e.test' }));
		await expect(page.locator(`tr:has-text("${EMAIL}") .resend-email`)).toBeVisible();
		await expect(page.locator(`tr:has-text("${LMS_EMAIL}") .resend-email`)).toBeVisible();
		// "View Certificate" opens the recipient on the Students page (certificate_id isn't a post, and is 0 for LMS rows).
		const view = await page.locator(`tr:has-text("${LMS_EMAIL}")`).getByRole('link', { name: 'View Certificate' }).getAttribute('href');
		expect(view).toContain('page=cg-students&s=lms%40e2e.test');

		const all = page.locator('#cg-resend-all-failed');
		await expect(all).toContainText('Resend all failed');
		// The page reloads once the AJAX call finishes; listen first so the DB check can't run early.
		const reloaded = page.waitForEvent('load');
		await all.click();
		await confirmKit(page);
		await reloaded;

		const queued = wp.sql("SELECT status FROM {p}cert_email_queue WHERE recipient_email = %s", EMAIL);
		expect(queued.map((r) => r.status)).toEqual(['pending']);
		const lmsQueued = wp.sql("SELECT status FROM {p}cert_email_queue WHERE recipient_email = %s", LMS_EMAIL);
		expect(lmsQueued.map((r) => r.status)).toEqual(['pending']);
	});

	test('Export CSV downloads the logs', async ({ page }) => {
		await page.goto(adminUrl('certificate-email-logs'));
		const href = await page.getByRole('link', { name: 'Export CSV' }).getAttribute('href');
		const res = await page.request.get(/** @type {string} */ (href));
		expect(res.status()).toBe(200);
		expect((await res.text())).toContain(EMAIL);
	});
});
