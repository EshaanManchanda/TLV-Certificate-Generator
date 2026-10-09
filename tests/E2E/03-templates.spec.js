// @ts-check
// §3 Templates (Design) — editor, gallery backgrounds, preview PDF, toggles,
// fonts, list page, bulk actions, Free cap.
const { test, expect, adminUrl, notices, confirmKit } = require('./support/fixtures');
const wp = require('./support/wp');
const { pdfText, pdfToPng } = require('./support/pdf');
const { judge } = require('./support/ollama');

/** Fill the editor and save; returns the new template id. */
async function createTemplate(page, t) {
	await page.goto(adminUrl('cg-template-edit'));
	await page.fill('#template_name', t.name);
	await page.fill('#certificate_type', t.type);
	if (t.entity) await page.selectOption('#entity_type', t.entity);
	await page.fill('#event_date', t.date); // dd-mm-yyyy text input
	// Gallery "Use Design" AJAX is broken (see its own test), so set the URL directly.
	if (t.bg) await page.fill('#template_url', wp.bundledBg(t.bg));
	await page.selectOption('#status', 'published');
	await page.locator('#cg-template-form button.button-primary, #cg-template-form input[type=submit].button-primary').first().click();
	await expect(page.locator('body')).toContainText(/Template (added|updated)\./);
	const id = Number(new URL(page.url()).searchParams.get('id')) || Number(wp.sql('SELECT id FROM {p}cg_certificate_templates WHERE template_name = %s ORDER BY id DESC LIMIT 1', t.name)[0]?.id);
	expect(id).toBeGreaterThan(0);
	return id;
}

/**
 * Click Preview. The button builds a hidden form targeting a new tab; headless
 * Chromium downloads PDFs instead of showing them, so capture that form and
 * replay it with fetch (same fields, same nonce) to get the PDF URL.
 */
async function preview(page, context, id) {
	if (!page.url().includes(`id=${id}`)) await page.goto(adminUrl('cg-template-edit', { id: String(id) }));
	// After "Update Template" the notice text shows before the page's scripts bind the
	// Preview handler; clicking that early does nothing and the wait below times out.
	await page.waitForLoadState('load');
	await page.evaluate(() => {
		HTMLFormElement.prototype.submit = function () { /** @type {any} */ (window).__cgPreview = new URLSearchParams(/** @type {any} */ (new FormData(this))).toString(); };
	});
	await page.click('#cg_preview_cert_btn');
	await page.waitForFunction(() => /** @type {any} */ (window).__cgPreview);
	const url = await page.evaluate(async () => {
		const r = await fetch(/** @type {any} */ (window).ajaxurl, { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: /** @type {any} */ (window).__cgPreview });
		return r.url;
	});
	expect(url, 'preview should redirect to a PDF').toMatch(/certificate_preview_\d+\.pdf/);
	const buf = await page.request.get(url).then((r) => r.body());
	expect(buf.subarray(0, 5).toString()).toBe('%PDF-');
	return buf;
}

const withSerialCount = () => Number(wp.sql(`SELECT
	(SELECT COUNT(*) FROM {p}cg_students WHERE serial_number <> '') +
	(SELECT COUNT(*) FROM {p}cg_teachers WHERE serial_number <> '') +
	(SELECT COUNT(*) FROM {p}cg_schools WHERE serial_number <> '') AS n`)[0].n);

test.describe('§03 Templates (Design)', () => {
	test.describe.configure({ mode: 'serial' });
	const ids = {};

	test('create Participation (10-01-2026, gold), Participation (15-03-2026, blue), Winner, Mentor', async ({ page }) => {
		ids.jan = await createTemplate(page, { name: 'E2E UI Participation Jan', type: 'E2E UI Participation', date: '10-01-2026', bg: 'classic-gold.jpg' });
		ids.mar = await createTemplate(page, { name: 'E2E UI Participation Mar', type: 'E2E UI Participation', date: '15-03-2026', bg: 'modern-blue.jpg' });
		ids.win = await createTemplate(page, { name: 'E2E UI Winner', type: 'E2E UI Winner', date: '10-01-2026', bg: 'modern-blue.jpg' });
		ids.men = await createTemplate(page, { name: 'E2E UI Mentor', type: 'E2E UI Mentor', date: '10-01-2026', bg: 'classic-gold.jpg', entity: 'teachers' });
		const rows = wp.sql("SELECT template_url, event_date, entity_type FROM {p}cg_certificate_templates WHERE template_name LIKE 'E2E UI %' ORDER BY id");
		expect(rows.map((r) => r.event_date)).toEqual(expect.arrayContaining(['2026-01-10', '2026-03-15']));
		const [jan, mar] = [rows.find((r) => r.event_date === '2026-03-15'), rows.find((r) => r.event_date === '2026-01-10')];
		expect(jan.template_url).not.toBe(mar.template_url);
		expect(rows.some((r) => r.entity_type === 'teachers')).toBeTruthy();
	});

	test('gallery "Use Design" fills the template URL', async ({ page }) => {
		await page.goto(adminUrl('cg-template-edit'));
		await page.selectOption('#cg_gallery_select', { index: 1 });
		await page.click('#cg_use_gallery_btn');
		await expect(page.locator('#cg_gallery_status')).toHaveText('Done.', { timeout: 10_000 });
		await expect(page.locator('#template_url')).toHaveValue(/\.jpg$/);
	});

	test('preview: PDF opens with PREVIEW-SERIAL and does not consume real serials', async ({ page, context }, testInfo) => {
		test.skip(!ids.jan, 'needs templates from the previous step');
		const before = withSerialCount();
		const buf = await preview(page, context, ids.jan);
		expect(await pdfText(buf)).toContain('PREVIEW-SERIAL');
		expect(withSerialCount()).toBe(before);
		await judge(testInfo, await pdfToPng(context, buf), 'Is this a certificate with a gold/classic decorative background and readable text placed on it (not a blank page)?');
	});

	test('QR toggle: QR appears on the preview', async ({ page, context }, testInfo) => {
		test.skip(!ids.win, 'needs templates');
		await page.goto(adminUrl('cg-template-edit', { id: String(ids.win) }));
		await page.check('#qr_enabled');
		await page.check('#serial_number_display');
		await page.locator('#cg-template-form button.button-primary').first().click();
		await expect(page.locator('body')).toContainText('Template updated.');
		const buf = await preview(page, context, ids.win);
		expect(await pdfText(buf)).toContain('PREVIEW-SERIAL');
		await judge(testInfo, await pdfToPng(context, buf), 'Does this certificate image contain a QR code (a square black-and-white matrix barcode)?');
	});

	test('font: a non-default font renders (not boxes / fallback)', async ({ page, context }, testInfo) => {
		test.skip(!ids.mar, 'needs templates');
		await page.goto(adminUrl('cg-template-edit', { id: String(ids.mar) }));
		const labels = await page.locator('#font_style_list option').evaluateAll((o) => o.map((x) => /** @type {HTMLOptionElement} */ (x).value));
		const label = labels.find((l) => /italianno/i.test(l)) || labels.find((l) => !/helvetica|arial|times|courier/i.test(l));
		test.skip(!label, 'no decorative font available');
		await page.fill('#font_style_search', /** @type {string} */ (label));
		await page.locator('#font_style_search').dispatchEvent('change');
		await expect(page.locator('#font_style')).not.toHaveValue('helvetica');
		await page.locator('#cg-template-form button.button-primary').first().click();
		await expect(page.locator('body')).toContainText('Template updated.');
		const buf = await preview(page, context, ids.mar);
		expect(await pdfText(buf)).toMatch(/Sample/);
		await judge(testInfo, await pdfToPng(context, buf), `Is the name text on this certificate rendered in a decorative script/handwriting font (like ${label}) and NOT as empty boxes, question marks or a plain fallback font?`);
	});

	test('list page: search, type filter, paginate, sort keep params', async ({ page }) => {
		await page.goto(adminUrl('cg-templates', { s: 'E2E UI' }));
		const rows = page.locator('#cg-list-form tbody tr');
		await expect(rows.first()).toContainText('E2E UI');
		const typeSel = page.locator('select[name=type_filter]');
		if (await typeSel.count()) {
			await typeSel.selectOption('E2E UI Winner');
			await page.getByRole('button', { name: 'Filter' }).click();
			await expect(page).toHaveURL(/type_filter=E2E/);
			await expect(rows).toHaveCount(1);
		}
		const sortLink = page.locator('thead a[href*="orderby="]').first();
		await sortLink.click();
		await expect(page).toHaveURL(/orderby=/);
		await expect(page).toHaveURL(/s=E2E/);
	});

	test('bulk duplicate + delete; F5 does not repeat the action', async ({ page }) => {
		const count = () => Number(wp.sql("SELECT COUNT(*) n FROM {p}cg_certificate_templates WHERE template_name LIKE 'E2E UI%'")[0].n);
		await page.goto(adminUrl('cg-templates', { s: 'E2E UI Winner' }));
		const before = count();
		await page.locator('#cg-list-form tbody input.cg-cb').first().check();
		await page.selectOption('#cg-bulk-action', 'duplicate_to_date');
		await page.fill('input[name=bulk_new_event_date]', '2026-06-01');
		await page.click('#cg-doaction');
		await confirmKit(page);
		await expect(page.locator('.notice-success')).toContainText(/Created 1 template\(s\) for 2026-06-01/);
		expect(count()).toBe(before + 1);
		await page.reload();
		expect(count(), 'F5 must not duplicate again').toBe(before + 1);

		await page.goto(adminUrl('cg-templates', { s: 'E2E UI Winner' }));
		const copy = page.locator('#cg-list-form tbody tr', { hasText: '01-06-2026' }).first();
		await copy.locator('input.cg-cb').check();
		await page.selectOption('#cg-bulk-action', 'delete');
		await page.click('#cg-doaction');
		await confirmKit(page);
		await expect(page.locator('.notice-success')).toContainText(/deleted/);
		expect(count()).toBe(before);
		await page.reload();
		expect(count()).toBe(before);
	});

	test('free plugin: template import has no cap', async ({ page }) => {
		await page.goto(adminUrl('cg-bulk-import', { tab: 'certificates' }));
		const text = await notices(page) + (await page.locator('#wpbody-content').innerText());
		expect(text).not.toMatch(/Plan Limit|limit reached|upgrade/i);
		await expect(page.locator('body')).not.toContainText(/critical error|Fatal/);
	});
});
