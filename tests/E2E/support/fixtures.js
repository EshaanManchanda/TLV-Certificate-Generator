// @ts-check
// Shared Playwright fixtures for every spec.
//  - `page`         admin session (storageState from global-setup)
//  - `anon`         logged-out page
//  - `subscriber`   page logged in as a Subscriber
//  - `dialogs`      every alert/confirm seen on `page`/`anon` (auto-accepted)
// Auto checks after each test: no new PHP warnings/fatals from this plugin in
// debug.log, and no uncaught JS errors originating from plugin assets.
const fs = require('fs');
const path = require('path');
const base = require('@playwright/test');
const { STATE, AUTH_DIR } = require('./global-setup');

const PLUGIN_RE = /Certificate-Generator-V7\.5/i;

function debugLogPath() {
	try { return JSON.parse(fs.readFileSync(STATE, 'utf8')).debug_log; } catch { return null; }
}

function watch(p, dialogs, jsErrors) {
	p.on('dialog', (d) => { dialogs.push({ type: d.type(), message: d.message() }); d.accept().catch(() => {}); });
	p.on('pageerror', (e) => { if (PLUGIN_RE.test(e.stack || '')) jsErrors.push(e.message); });
	p.on('console', (m) => { if (m.type() === 'error' && PLUGIN_RE.test(m.location()?.url || '')) jsErrors.push(m.text()); });
	return p;
}

const test = base.test.extend({
	dialogs: async ({}, use) => { await use([]); },
	jsErrors: async ({}, use) => { await use([]); },

	page: async ({ page, dialogs, jsErrors }, use) => {
		await use(watch(page, dialogs, jsErrors));
	},

	anon: async ({ browser, dialogs, jsErrors }, use) => {
		const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
		await use(watch(await ctx.newPage(), dialogs, jsErrors));
		await ctx.close();
	},

	subscriber: async ({ browser }, use) => {
		const ctx = await browser.newContext({ storageState: path.join(AUTH_DIR, 'subscriber.json') });
		await use(await ctx.newPage());
		await ctx.close();
	},

	// Fails the test if it caused new plugin warnings/fatals or plugin JS errors.
	_guards: [async ({ jsErrors }, use, testInfo) => {
		const log = debugLogPath();
		const start = log && fs.existsSync(log) ? fs.statSync(log).size : 0;
		await use();
		if (testInfo.status === 'skipped') return;
		if (log && fs.existsSync(log)) {
			const size = fs.statSync(log).size;
			if (size > start) {
				const fd = fs.openSync(log, 'r');
				const buf = Buffer.alloc(Math.min(size - start, 2_000_000));
				fs.readSync(fd, buf, 0, buf.length, start);
				fs.closeSync(fd);
				const bad = buf.toString('utf8').split('\n')
					.filter((l) => /PHP (Fatal|Warning|Parse error|Deprecated)|WordPress database error/i.test(l) && PLUGIN_RE.test(l));
				if (bad.length) {
					testInfo.attachments.push({ name: 'debug.log', contentType: 'text/plain', body: Buffer.from(bad.join('\n')) });
					base.expect.soft(bad, 'new plugin errors in debug.log').toEqual([]);
				}
			}
		}
		base.expect.soft(jsErrors, 'plugin JS errors in the browser console').toEqual([]);
	}, { auto: true }],
});

const expect = base.expect;

/** Open a plugin admin page: adminUrl('cg-students', {s:'x'}). */
const adminUrl = (slug, params = {}) => `/wp-admin/admin.php?${new URLSearchParams({ page: slug, ...params })}`;

/** Text of all admin notices on the page. */
const notices = async (page) => (await page.locator('.notice, .updated, .error').allInnerTexts()).join('\n');

/** Assert the current page is a WordPress "not allowed" screen (or a login redirect). */
async function expectDenied(page) {
	const body = await page.locator('body').innerText();
	const denied = /not allowed|do not have sufficient permissions|Sorry, you are not allowed/i.test(body);
	expect(denied || !page.url().includes('/wp-admin/admin.php'), `expected access denied at ${page.url()}`).toBeTruthy();
}

/** Plugin AJAX nonce printed on an admin page by wp_localize_script, e.g. jsVar(page,'cgBulkSerial.nonce'). */
const jsVar = (page, expr) => page.evaluate((e) => e.split('.').reduce((o, k) => o?.[k], /** @type {any} */ (window)), expr);

// Accept the kit's CGUI.confirm() <dialog> (not a native dialog, so `dialogs` won't see it).
async function confirmKit(page) {
	await page.locator('dialog.cg-dialog[open] button[value=ok]').click();
}

module.exports = { test, expect, adminUrl, notices, expectDenied, jsVar, confirmKit };
