// @ts-check
// §19 School Coordinator — sees and works on only their own school; admin pages are closed to them.
const { test, expect, adminUrl } = require('./support/fixtures');
const wp = require('./support/wp');

const LOGIN = 'e2e-coordinator';
const TYPE = 'E2E Coordinator';

/** Logged-in cookies for a user (no login form, same as global-setup). */
function cookiesFor(login, baseURL) {
	const c = wp.wpEval(`
		$u = get_user_by('login', ${wp.php(login)}); $exp = time() + DAY_IN_SECONDS;
		echo json_encode([
			[AUTH_COOKIE, wp_generate_auth_cookie($u->ID, $exp, 'auth')],
			[SECURE_AUTH_COOKIE, wp_generate_auth_cookie($u->ID, $exp, 'secure_auth')],
			[LOGGED_IN_COOKIE, wp_generate_auth_cookie($u->ID, $exp, 'logged_in')],
		]);`);
	const domain = new URL(baseURL).hostname;
	return c.map(([name, value]) => ({ name, value, domain, path: '/', httpOnly: true, secure: false, sameSite: /** @type {'Lax'} */ ('Lax') }));
}

test.describe('§19 School Coordinator', () => {
	/** @type {import('@playwright/test').Page} */
	let page;

	test.beforeAll(async ({ browser }, testInfo) => {
		const id = wp.ensureUser(LOGIN, 'certificate_generator_school_coordinator', 'e2e-coordinator-pass-1');
		wp.wpEval(`update_user_meta(${Number(id)}, 'certificate_generator_school', 'E2E Alpha School'); echo 1;`);
		for (const [name, school] of [['E2E Alpha Kid', 'E2E Alpha School'], ['E2E Beta Kid', 'E2E Beta School']]) {
			wp.seedPerson('students', { name, school, email: `${name.replace(/\s/g, '').toLowerCase()}@e2e.test`, certificate_type: TYPE, issue_date: '2026-01-15' });
		}
		const baseURL = /** @type {string} */ (testInfo.project.use.baseURL);
		const context = await browser.newContext({ baseURL, storageState: { cookies: cookiesFor(LOGIN, baseURL), origins: [] } });
		page = await context.newPage();
	});

	test.afterAll(async () => {
		await page.context().close();
		wp.sql('DELETE FROM {p}cg_students WHERE certificate_type = %s', TYPE);
	});

	test('the Dashboard sends them to Students, which lists only their school', async () => {
		await page.goto(adminUrl('cg-dashboard'));
		await expect(page).toHaveURL(/page=cg-students/);
		const body = page.locator('#wpbody-content');
		await expect(body).toContainText('E2E Alpha Kid');
		await expect(body).not.toContainText('E2E Beta Kid');
		await expect(body).not.toContainText('E2E Beta School');
	});

	test('the menu has their pages and none of the admin ones', async () => {
		await page.goto(adminUrl('cg-students'));
		const menu = page.locator('#toplevel_page_cg-dashboard');
		for (const item of ['Students', 'Teachers', 'Bulk Import', 'Download Certs', 'Bulk Send']) {
			await expect(menu.getByRole('link', { name: item, exact: true })).toBeVisible();
		}
		for (const item of ['Templates', 'Schools', 'Analytics', 'Revoke Certificate', 'Email Logs', 'Serial Settings']) {
			await expect(menu.getByRole('link', { name: item, exact: true })).toHaveCount(0);
		}
	});

	test('admin pages are refused', async () => {
		for (const slug of ['cg-templates', 'cg-analytics', 'cg-revoke-certificate', 'certificate-email-logs']) {
			await page.goto(adminUrl(slug));
			await expect(page.locator('body')).toContainText(/not allowed|Insufficient permissions|Sorry/i);
		}
	});

	test('Bulk Import offers only Students and Teachers, into their school', async () => {
		await page.goto(adminUrl('cg-bulk-import', { tab: 'schools' }));
		const tabs = page.locator('.nav-tab-wrapper, .cg-tabs');
		await expect(tabs).toContainText('Students');
		await expect(tabs).not.toContainText('Schools');
		await expect(tabs).not.toContainText('Certificates');
		await expect(page.locator('body')).toContainText('Every row is saved to E2E Alpha School.');
	});
});
