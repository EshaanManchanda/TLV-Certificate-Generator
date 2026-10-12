// @ts-check
// §15 Per-field fonts — each field slot can override the template's font, size and colour.
const { test, expect, adminUrl } = require('./support/fixtures');
const wp = require('./support/wp');

test.describe('§15 Per-field fonts', () => {
	test('field 1 saves its own font, size and colour; field 2 keeps the template default', async ({ page }) => {
		await page.goto(adminUrl('cg-template-edit'));
		await page.fill('#template_name', 'E2E Field Fonts');
		await page.fill('#certificate_type', 'E2E Field Fonts');
		await page.fill('#event_date', '01-02-2026');
		await page.fill('#template_url', wp.bundledBg('classic-gold.jpg'));
		await page.selectOption('#status', 'published');

		await page.selectOption('#field_1_font_style', 'times');
		await page.fill('#field_1_font_size', '28');
		await page.check('input[name="field_1_font_color_custom"]');
		await page.fill('#field_1_font_color', '#cc0000');

		await page.locator('#cg-template-form button.button-primary').first().click();
		await expect(page.locator('body')).toContainText(/Template (added|updated)\./);

		const id = wp.sql('SELECT id FROM {p}cg_certificate_templates WHERE template_name = %s ORDER BY id DESC LIMIT 1', 'E2E Field Fonts')[0].id;
		await page.goto(adminUrl('cg-template-edit', { id: String(id) }));
		await expect(page.locator('#field_1_font_style')).toHaveValue('times');
		await expect(page.locator('#field_1_font_size')).toHaveValue('28');
		await expect(page.locator('input[name="field_1_font_color_custom"]')).toBeChecked();
		await expect(page.locator('#field_1_font_color')).toHaveValue('#cc0000');
		await expect(page.locator('#field_2_font_style')).toHaveValue('');
		await expect(page.locator('#field_2_font_size')).toHaveValue('');
		await expect(page.locator('input[name="field_2_font_color_custom"]')).not.toBeChecked();

		const extra = JSON.parse(wp.sql('SELECT extra_fields FROM {p}cg_certificate_templates WHERE id = %d', id)[0].extra_fields);
		expect([extra.field_1_font_style, Number(extra.field_1_font_size), extra.field_1_font_color]).toEqual(['times', 28, '#cc0000']);
		expect([extra.field_2_font_style, extra.field_2_font_size, extra.field_2_font_color]).toEqual(['', '', '']);
	});

	test('a "Custom text" slot shows its text box and saves text, tags and UPPERCASE', async ({ page }) => {
		await page.goto(adminUrl('cg-template-edit'));
		await page.fill('#template_name', 'E2E Custom Text');
		await page.fill('#certificate_type', 'E2E Custom Text');
		await page.fill('#event_date', '01-02-2026');
		await page.fill('#template_url', wp.bundledBg('classic-gold.jpg'));
		await page.selectOption('#status', 'published');

		await expect(page.locator('#cg-field-text-row-1')).toBeHidden();
		await page.selectOption('#field_1_name', 'custom_text');
		await expect(page.locator('#cg-field-text-row-1')).toBeVisible();
		await page.fill('#field_1_text', 'Awarded to {student_name} for {certificate_type}');
		await page.check('#field_1_text_transform');
		await page.locator('#cg-template-form button.button-primary').first().click();
		await expect(page.locator('body')).toContainText(/Template (added|updated)\./);

		const id = wp.sql('SELECT id FROM {p}cg_certificate_templates WHERE template_name = %s ORDER BY id DESC LIMIT 1', 'E2E Custom Text')[0].id;
		await page.goto(adminUrl('cg-template-edit', { id: String(id) }));
		await expect(page.locator('#field_1_name')).toHaveValue('custom_text');
		await expect(page.locator('#cg-field-text-row-1')).toBeVisible();
		await expect(page.locator('#field_1_text')).toHaveValue('Awarded to {student_name} for {certificate_type}');
		await expect(page.locator('#field_1_text_transform')).toBeChecked();
	});

	test('the font row hides for image slots', async ({ page }) => {
		await page.goto(adminUrl('cg-template-edit'));
		await expect(page.locator('#cg-field-font-row-1')).toBeVisible();
		await page.selectOption('#field_1_type', 'image');
		await expect(page.locator('#cg-field-font-row-1')).toBeHidden();
	});
});
