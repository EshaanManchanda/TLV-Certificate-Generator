// @ts-check
// §18 In-plugin Documentation — every tab renders without PHP errors and covers the 7.7/7.8 features.
const { test, expect, adminUrl } = require('./support/fixtures');

const TABS = {
	'getting-started': ['Quick Start'],
	'user-guide': ['Revoke, History & Reissue', 'Names in any language', 'Upload and check', 'Preview with', 'Sample files', 'templates.csv', 'My Certificates, Sharing & Privacy'],
	'email-guide': ['Email & Bulk Send Guide'],
	troubleshooting: ['Troubleshooting'],
	faq: ['Which languages can certificates print?', 'Upload and check'],
};

test.describe('§18 Documentation page', () => {
	for (const [tab, phrases] of Object.entries(TABS)) {
		test(`${tab} tab renders`, async ({ page }) => {
			const res = await page.goto(adminUrl('cg-documentation', { tab }));
			expect(res?.status()).toBe(200);
			const body = page.locator('#wpbody-content');
			await expect(body).not.toContainText(/Fatal error|Warning:|Notice:|Parse error/);
			for (const phrase of phrases) {
				await expect(body).toContainText(phrase);
			}
		});
	}
});
