// @ts-check
// §11 Bulk Send — preview numbers must describe the whole filtered set, not the first page.
const { test, expect, adminUrl } = require('./support/fixtures');
const wp = require('./support/wp');

const TYPE = 'E2E BulkSend';

test.describe('§11 Bulk Send preview', () => {
	test.beforeAll(() => {
		// 105 certificates (> the 100-row preview page): rows 1–4 have no email,
		// rows 5 and 6 share an address (one grouped email), the rest are unique.
		wp.wpEval(`
			for ($i = 1; $i <= 105; $i++) {
				$email = $i <= 4 ? '' : 'bs-' . ($i === 6 ? 5 : $i) . '@e2e.test';
				$wpdb->insert($wpdb->prefix . 'cg_students', [
					'student_name' => sprintf('E2E BS %03d', $i), 'email' => $email, 'certificate_type' => '${TYPE}',
					'issue_date' => '2026-02-01', 'year' => 2026, 'status' => 'active', 'import_source' => 'e2e',
				]);
			}
			delete_transient('certificate_generator_unique_cert_types');
			echo 1;`);
	});

	test('stats count every matching certificate and one email per address', async ({ page }) => {
		await page.goto(adminUrl('certificate-bulk-send'));
		await page.locator('#cert-filter-certificate-types option', { hasText: TYPE }).waitFor({ state: 'attached' });
		await page.selectOption('#cert-filter-certificate-types', TYPE);

		const showing = page.locator('#cg-bs-showing');
		await expect(showing).toHaveText('Showing 100 of 105 certificates');

		const tile = (label) => page.locator('.cert-stat-box', { hasText: label }).locator('.cert-stat-value');
		await expect(tile('Certificates matched')).toHaveText('105');
		await expect(tile('Emails to send')).toHaveText('100');
		await expect(tile('No email — skipped')).toHaveText('4');
		await expect(page.locator('.cert-stat-box', { hasText: 'Emails to send' })).toContainText('1 person gets several in one email');

		await expect(page.locator('#cert-start-bulk-send')).toHaveText('Queue 100 emails');
		await expect(page.locator('#cg-bs-send-summary')).toHaveText('100 emails carrying 101 certificates. 4 without an email will be skipped.');

		await page.click('.cert-load-more-btn');
		await expect(showing).toHaveText('Showing 105 of 105 certificates');
		await expect(page.locator('#cert-preview-tbody tr')).toHaveCount(105);
		await expect(page.locator('.cert-load-more')).toBeHidden();
	});

	test('unticking "No email address" removes those rows from every number', async ({ page }) => {
		await page.goto(adminUrl('certificate-bulk-send'));
		await page.locator('#cert-filter-certificate-types option', { hasText: TYPE }).waitFor({ state: 'attached' });
		await page.selectOption('#cert-filter-certificate-types', TYPE);
		await expect(page.locator('#cg-bs-showing')).toHaveText('Showing 100 of 105 certificates');

		await page.uncheck('input[name="email_status[]"][value="no_email"]');
		await expect(page.locator('#cg-bs-showing')).toHaveText('Showing 100 of 101 certificates');
		await expect(page.locator('.cert-stat-box', { hasText: 'No email — skipped' }).locator('.cert-stat-value')).toHaveText('0');

		// Nothing ticked means nothing matched — not "everyone".
		for (const t of ['students', 'teachers', 'schools']) {
			await page.uncheck(`input[name="post_types[]"][value="${t}"]`);
		}
		await expect(page.locator('.cert-stat-box', { hasText: 'Certificates matched' }).locator('.cert-stat-value')).toHaveText('0');
		await expect(page.locator('#cert-start-bulk-send')).toBeDisabled();
	});
});
