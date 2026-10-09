// @ts-check
// §9 Verification (public) — [cg_verify_certificate] logged out.
const { test, expect } = require('./support/fixtures');
const wp = require('./support/wp');

const S = {
	ben: 'E2E-V-BEN-0001',
	ashaJan: 'E2E-V-ASHA-0001',
	ashaMar: 'E2E-V-ASHA-0002',
	rest: 'E2E-V-REST-0001',
};

test.describe('§09 Verification (public)', () => {
	let verifyUrl;

	test.beforeAll(() => {
		verifyUrl = wp.ensurePage('Verify', '[cg_verify_certificate]');
		wp.seedCertificate({ serial_number: S.ben, student_name: 'E2E Ben Winner', email: 'ben@e2e.test', certificate_type: 'E2E Winner', issued_at: '2026-01-10 00:00:00' });
		wp.seedCertificate({ serial_number: S.ashaJan, student_name: 'E2E Asha Multi', email: 'asha@e2e.test', certificate_type: 'E2E Participation', issued_at: '2026-01-10 00:00:00' });
		wp.seedCertificate({ serial_number: S.ashaMar, student_name: 'E2E Asha Multi', email: 'asha@e2e.test', certificate_type: 'E2E Participation', issued_at: '2026-03-15 00:00:00' });
		wp.seedCertificate({ serial_number: S.rest, student_name: 'E2E Rest Check', email: 'rest@e2e.test' });
		wp.resetRateLimit();
	});
	test.afterAll(() => wp.resetRateLimit());

	async function verify(page, serial) {
		await page.goto(verifyUrl);
		await page.fill('#cg-serial-input', serial);
		await page.click('#cg-verify-btn');
		const result = page.locator('#cg-verify-result');
		await expect(result).toBeVisible();
		await expect(result.locator('h3')).not.toBeEmpty();
		return result;
	}

	test("Ben's serial shows Valid with name, type and date", async ({ anon }) => {
		const r = await verify(anon, S.ben);
		await expect(r.locator('h3')).toHaveText(/Certificate is Valid/);
		await expect(r).toContainText('E2E Ben Winner');
		await expect(r).toContainText('E2E Winner');
		await expect(r).toContainText(/2026|Jan/);
	});

	test("Asha's two serials each show Asha", async ({ anon }) => {
		for (const s of [S.ashaJan, S.ashaMar]) {
			const r = await verify(anon, s);
			await expect(r.locator('h3')).toHaveText(/Certificate is Valid/);
			await expect(r).toContainText('E2E Asha Multi');
			await expect(r).toContainText(s);
		}
	});

	test('unknown serial shows Not Found', async ({ anon }) => {
		const r = await verify(anon, 'E2E-DOES-NOT-EXIST-9');
		await expect(r.locator('h3')).toHaveText(/Certificate Not Found/);
	});

	test('QR deep link (?serial_number=) pre-fills and verifies', async ({ anon }) => {
		const u = new URL(verifyUrl);
		u.searchParams.set('serial_number', S.ben);
		await anon.goto(u.toString());
		await expect(anon.locator('#cg-serial-input')).toHaveValue(S.ben);
		await expect(anon.locator('#cg-verify-result h3')).toHaveText(/Certificate is Valid/);
	});

	test('LinkedIn "Add to Profile" button on a valid certificate with a PDF', async ({ anon }) => {
		// The button only renders when the cert has a pdf_url (from wp_cg_certificates).
		wp.sql("INSERT INTO {p}cg_certificates (recipient_name, recipient_email, recipient_type, certificate_type, serial_number, issued_at, pdf_url, status) VALUES ('E2E Ben Winner','ben@e2e.test','student','E2E Winner',%s,NOW(),'https://example.com/e2e.pdf','active')", S.ben);
		const r = await verify(anon, S.ben);
		const link = r.locator('a[href^="https://www.linkedin.com/profile/add"]');
		await expect(link).toBeVisible();
		const href = new URL(/** @type {string} */ (await link.getAttribute('href')));
		expect(href.searchParams.get('certId')).toBe(S.ben);
		expect(href.searchParams.get('name')).toBeTruthy();
	});

	test('rate limit: ~20 verifies per minute, then 429', async ({ anon }) => {
		wp.resetRateLimit();
		await anon.goto(verifyUrl);
		const statuses = await anon.evaluate(async (serial) => {
			// @ts-ignore localized by the shortcode
			const { ajaxurl, nonce } = window.cgVerify;
			const out = [];
			for (let i = 0; i < 25; i++) {
				const r = await fetch(ajaxurl, { method: 'POST', body: new URLSearchParams({ action: 'cg_public_verify', nonce, serial_number: serial }) });
				out.push(r.status);
			}
			return out;
		}, S.ben);
		expect(statuses.slice(0, 20).every((s) => s === 200)).toBeTruthy();
		expect(statuses.slice(20)).toContain(429);
		wp.resetRateLimit();
	});

	test('rate-limited user sees a "too many attempts" message', async ({ anon }) => {
		wp.resetRateLimit();
		await anon.goto(verifyUrl);
		await anon.evaluate(async () => {
			// @ts-ignore
			const { ajaxurl, nonce } = window.cgVerify;
			for (let i = 0; i < 21; i++) await fetch(ajaxurl, { method: 'POST', body: new URLSearchParams({ action: 'cg_public_verify', nonce, serial_number: 'x' }) });
		});
		await anon.fill('#cg-serial-input', S.ben);
		await anon.click('#cg-verify-btn');
		await expect(anon.locator('#cg-verify-result')).toContainText(/too many/i, { timeout: 5000 });
		wp.resetRateLimit();
	});

	test('REST /verify/<serial> returns valid: true', async ({ anon }) => {
		const res = await anon.request.get(`/wp-json/certificate-generator/v1/verify/${S.rest}`);
		expect(res.status()).toBe(200);
		expect((await res.json()).valid).toBe(true);
	});
});

module.exports = { S };
