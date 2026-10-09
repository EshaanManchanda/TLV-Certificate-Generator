// @ts-check
// §2 Dashboard — Getting Started checklist + every link resolves.
const { test, expect, adminUrl } = require('./support/fixtures');
const wp = require('./support/wp');

test.describe('§02 Dashboard', () => {
	test('Getting Started reflects real state (template / test certificate / record / issued)', async ({ page }) => {
		const [s] = wp.sql(`SELECT
			(SELECT COUNT(*) FROM {p}cg_certificate_templates) AS t,
			(SELECT COUNT(*) FROM {p}cg_students) + (SELECT COUNT(*) FROM {p}cg_teachers) + (SELECT COUNT(*) FROM {p}cg_schools) AS r,
			(SELECT COUNT(*) FROM {p}cg_certificates) AS c`);
		const testSent = !!wp.getOption('certificate_generator_test_certificate_sent');
		const done = [s.t > 0, testSent, s.r > 0, s.c > 0];
		const doneCount = done.filter(Boolean).length;

		await page.goto(adminUrl('cg-dashboard'));
		const card = page.locator('.wrap .cg-card', { hasText: 'Getting Started' });
		if (doneCount === done.length) {
			// Card hides itself once every step is complete.
			await expect(card).toHaveCount(0);
			return;
		}
		await expect(card.locator('h2')).toContainText(`Getting Started (${doneCount} of ${done.length} done)`);
		const items = card.locator('li');
		await expect(items).toHaveCount(done.length);
		for (let i = 0; i < done.length; i++) {
			if (done[i]) await expect(items.nth(i)).toHaveClass(/is-done/);
			else await expect(items.nth(i)).not.toHaveClass(/is-done/);
		}
	});

	test('every dashboard link opens a real page', async ({ page }) => {
		await page.goto(adminUrl('cg-dashboard'));
		const hrefs = await page.locator('#wpbody-content a[href]').evaluateAll((as) =>
			[...new Set(as.map((a) => /** @type {HTMLAnchorElement} */ (a).href).filter((h) => h.startsWith(location.origin) && !h.includes('#')))]);
		expect(hrefs.length).toBeGreaterThan(0);
		for (const href of hrefs) {
			const res = await page.request.get(href);
			expect(res.status(), href).toBeLessThan(400);
			expect(await res.text(), href).not.toMatch(/Sorry, you are not allowed|There has been a critical error/);
		}
	});
});
