// @ts-check
// §10 Revoke — admin revoke/reinstate and the public verify result.
const { test, expect, adminUrl, confirmKit } = require('./support/fixtures');
const wp = require('./support/wp');

const SERIAL = 'E2E-R-BEN-0001';
const REASON = 'E2E revoked for testing';

test.describe('§10 Revoke', () => {
	let verifyUrl;
	test.beforeAll(() => {
		verifyUrl = wp.ensurePage('Verify', '[certificate_generator_verify_certificate]');
		wp.seedCertificate({ serial_number: SERIAL, student_name: 'E2E Ben Winner', email: 'ben@e2e.test', certificate_type: 'E2E Winner' });
		wp.resetRateLimit();
	});

	async function publicResult(anon) {
		await anon.goto(`${verifyUrl}${verifyUrl.includes('?') ? '&' : '?'}serial_number=${SERIAL}`);
		const h = anon.locator('#cg-verify-result h3');
		await expect(h).not.toBeEmpty();
		return anon.locator('#cg-verify-result');
	}

	test('revoke → listed → verify shows Revoked with reason → reinstate → Valid', async ({ page, anon }) => {
		await page.goto(adminUrl('cg-revoke-certificate'));
		await page.fill('#cg-revoke-serial', SERIAL);
		await page.fill('#cg-revoke-reason', REASON);
		// Success reloads the page after 1.2s; listen before acting so a stray earlier load can't satisfy it.
		const reloaded = page.waitForEvent('load');
		await page.click('#cg-revoke-btn');
		await confirmKit(page);
		await expect(page.locator('#cg-revoke-result')).toContainText(`Certificate ${SERIAL} has been revoked.`);
		await reloaded;
		await expect(page.locator(`.cg-unrevoke-btn[data-serial="${SERIAL}"]`)).toBeVisible();

		let r = await publicResult(anon);
		await expect(r.locator('h3')).toHaveText(/Certificate Revoked/);
		await expect(r).toContainText(REASON);

		// Reinstate posts via AJAX then reloads the page (no message shown).
		await page.locator(`.cg-unrevoke-btn[data-serial="${SERIAL}"]`).click();
		await Promise.all([page.waitForEvent('load'), confirmKit(page)]);
		await expect(page.locator(`.cg-unrevoke-btn[data-serial="${SERIAL}"]`)).toHaveCount(0);

		r = await publicResult(anon);
		await expect(r.locator('h3')).toHaveText(/Certificate is Valid/);
	});

	test('wrong serial gives "No certificate found"', async ({ page }) => {
		await page.goto(adminUrl('cg-revoke-certificate'));
		await page.fill('#cg-revoke-serial', 'E2E-NOPE-404');
		await page.fill('#cg-revoke-reason', REASON);
		await page.click('#cg-revoke-btn');
		await confirmKit(page);
		await expect(page.locator('#cg-revoke-result')).toContainText(/No certificate found/);
	});

	test('?serial= pre-fills the revoke form (link from Bulk Serials duplicates)', async ({ page }) => {
		await page.goto(adminUrl('cg-revoke-certificate', { serial: SERIAL }));
		await expect(page.locator('#cg-revoke-serial')).toHaveValue(SERIAL);
	});
});
