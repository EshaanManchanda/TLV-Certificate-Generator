// @ts-check
// §20 My Certificates — [certificate_generator_my_certificates] logged out: email link, own list,
// hide-my-name, share buttons and link-preview tags on the verify page.
const { test, expect } = require('./support/fixtures');
const wp = require('./support/wp');
const mail = require('./support/mailpit');

const MINE = 'wallet-mine@e2e.test';
const OTHER = 'wallet-other@e2e.test';
const TYPE = 'E2E Wallet';
const OTHER_TYPE = 'E2E WalletOther';

test.describe.serial('§20 My Certificates (public)', () => {
	let pageUrl;

	test.beforeAll(async () => {
		pageUrl = wp.ensurePage('My Certificates', '[certificate_generator_my_certificates]');
		wp.ensurePage('Verify', '[certificate_generator_verify_certificate]');
		for (const type of [TYPE, OTHER_TYPE]) {
			wp.seedTemplate({ template_name: type, certificate_type: type, event_date: '2026-02-01', template_url: wp.bundledBg('classic-gold.jpg') });
		}
		wp.seedPerson('students', { name: 'E2E Wallet Mine', email: MINE, school: 'E2E School', certificate_type: TYPE, issue_date: '2026-02-01' });
		wp.seedPerson('students', { name: 'E2E Wallet Other', email: OTHER, school: 'E2E School', certificate_type: OTHER_TYPE, issue_date: '2026-02-01' });
		wp.sql("DELETE FROM {p}options WHERE option_name LIKE '%certificate\\_generator\\_wallet\\_sent\\_%'");
		wp.resetRateLimit();
		await mail.clearE2E();
	});
	test.afterAll(() => wp.resetRateLimit());

	async function requestLink(anon, email) {
		await anon.goto(pageUrl);
		await anon.fill('#cg-wallet-email', email);
		await anon.click('.cg-wallet-form button[type="submit"]');
		await expect(anon.locator('.cg-wallet-sent')).toBeVisible();
	}

	test('an unknown address gets the same answer and no email', async ({ anon }) => {
		test.skip(!mail.enabled, 'Mailpit not configured');
		await requestLink(anon, 'wallet-nobody@e2e.test');
		await new Promise((r) => setTimeout(r, 2000));
		expect(await mail.inbox('wallet-nobody@e2e.test')).toHaveLength(0);
	});

	test('the emailed link opens only that address\'s certificates; hiding the name shows on verify', async ({ anon }) => {
		test.skip(!mail.enabled, 'Mailpit not configured');
		await requestLink(anon, MINE);
		const [msg] = await mail.waitForMail(MINE, 1);
		expect(msg, 'link emailed').toBeTruthy();
		const full = await mail.message(msg.ID);
		const link = (full.HTML.match(/href="([^"]*cg_wallet=[^"]+)"/) || [])[1]?.replace(/&amp;/g, '&');
		expect(link).toBeTruthy();

		await anon.goto(link);
		await expect(anon).not.toHaveURL(/cg_wallet=/); // the token moved into the cookie
		const list = anon.locator('.cg-wallet-list');
		await expect(list).toContainText(TYPE);
		await expect(list).not.toContainText(OTHER_TYPE);

		// Reload: still signed in from the cookie.
		await anon.reload();
		await expect(list).toContainText(TYPE);

		const toggle = anon.locator('.cg-wallet-show-name').first();
		await expect(toggle).toBeChecked();
		const saved = anon.waitForResponse((r) => r.url().includes('admin-ajax.php') && r.request().method() === 'POST');
		await toggle.uncheck();
		expect((await (await saved).json()).success).toBe(true);

		const verify = await anon.locator('.cg-wallet-list a', { hasText: 'Verify & share' }).first().getAttribute('href');
		await anon.goto(verify);
		const result = anon.locator('#cg-verify-result');
		await expect(result.locator('h3')).toHaveText(/Certificate is Valid/);
		await expect(result).toContainText('Hidden by the recipient');
		await expect(result).not.toContainText('E2E Wallet Mine');
		await expect(result.locator('.cg-share')).toContainText('Share on X');
		await expect(result.locator('.cg-copy-link')).toBeVisible();
		// Link-preview tags, with no name since it's hidden.
		await expect(anon.locator('meta[property="og:title"]')).toHaveAttribute('content', `Verified certificate: ${TYPE}`);
		await expect(anon.locator('meta[property="og:description"]')).not.toHaveAttribute('content', /E\*\*/);

		// Sign out drops the cookie.
		await anon.goto(pageUrl);
		await anon.click('text=Sign out');
		await expect(anon.locator('#cg-wallet-email')).toBeVisible();
	});
});
