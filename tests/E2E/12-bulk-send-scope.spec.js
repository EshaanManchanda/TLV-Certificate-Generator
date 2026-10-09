// @ts-check
// §12 Bulk Send — a filtered send emails only the certificates that matched (not every
// certificate the address has), using the email template of the certificate being sent.
const { test, expect, adminUrl, confirmKit } = require('./support/fixtures');
const wp = require('./support/wp');
const mail = require('./support/mailpit');

const EMAIL = 'scope@e2e.test';
const A = 'E2E ScopeA';
const B = 'E2E ScopeB';
const OPT = 'certificate_generator_settings_email';

test.describe.serial('§12 Bulk Send scope and email template', () => {
	let rowA;
	let savedOpt;

	test.beforeAll(async () => {
		for (const type of [A, B]) {
			wp.seedTemplate({ template_name: type, certificate_type: type, event_date: '2026-02-01', template_url: wp.bundledBg('classic-gold.jpg') });
		}
		// An older ScopeB record makes the address's anchor a *different* certificate
		// than the one being sent — the case that used to pick the wrong template.
		wp.seedCertificate({ student_name: 'E2E Scope Kid', email: EMAIL, certificate_type: B });
		rowA = wp.seedPerson('students', { name: 'E2E Scope Kid', email: EMAIL, school: 'E2E School', certificate_type: A, issue_date: '2026-02-01' });
		wp.seedPerson('students', { name: 'E2E Scope Kid', email: EMAIL, school: 'E2E School', certificate_type: B, issue_date: '2026-02-01' });

		// Priority 0 (certificate type) for both types; Priority 1 (per recipient type) for students.
		for (const type of [A, B]) {
			wp.sql("INSERT INTO {p}cg_email_templates (name, certificate_type, subject, attach_certificate) VALUES (%s, %s, %s, 1)", `E2E ${type}`, type, `Type template for ${type}`);
		}
		savedOpt = wp.getOption(OPT);
		wp.setOption(OPT, { ...(savedOpt || {}), students_email_subject: 'Per-type subject: {certificate_title}', students_email_attach_certificate: '1' });

		wp.wpEval("delete_transient('cg_unique_cert_types'); echo 1;");
		wp.resetRateLimit();
		await mail.clearE2E();
	});

	test.afterAll(() => {
		wp.sql("DELETE FROM {p}cg_email_templates WHERE name LIKE 'E2E %'");
		if (savedOpt) wp.setOption(OPT, savedOpt);
	});

	/** Filter to ScopeA for EMAIL, queue it, run the queue, return the delivered message. */
	async function sendScopeA(page, alreadySent) {
		await page.goto(adminUrl('certificate-bulk-send'));
		await page.locator('#cert-filter-certificate-types option', { hasText: A }).waitFor({ state: 'attached' });
		await page.selectOption('#cert-filter-certificate-types', A);
		if (alreadySent) await page.check('input[name="email_status[]"][value="sent"]');
		await page.locator('.cg-bs-more summary').click();
		await page.fill('#cert-filter-email-search', EMAIL);

		await expect(page.locator('#cg-bs-showing')).toHaveText('Showing 1 of 1 certificate');
		await page.click('#cert-start-bulk-send');
		await confirmKit(page);
		await expect(page.locator('#bulk-send-result')).toContainText('Queued 1 email carrying 1 certificate.');

		const [q] = wp.sql("SELECT scope FROM {p}cert_email_queue WHERE recipient_email = %s AND status = 'pending'", EMAIL);
		expect(JSON.parse(q.scope)).toEqual({ students: [rowA] });

		const before = (await mail.inbox(EMAIL)).length;
		wp.runCron('certificate_generator_process_email_queue');
		const msgs = await mail.waitForMail(EMAIL, before + 1);
		expect(msgs.length, 'certificate email delivered').toBe(before + 1);
		return mail.message(msgs[0].ID); // newest first
	}

	/** Exactly one attachment: the ScopeA row's PDF, with its anti-enumeration hash suffix. */
	function expectOnlyRowA(msg) {
		const names = (msg.Attachments || []).map((a) => a.FileName);
		expect(names).toHaveLength(1);
		expect(names[0]).toMatch(new RegExp(`^certificate_students_row_${rowA}_[0-9a-f]{12}\\.pdf$`));
	}

	test('sends only the matched certificate, with its certificate-type template (Priority 0)', async ({ page }) => {
		test.skip(!mail.enabled, 'Mailpit not configured');
		const msg = await sendScopeA(page, false);

		expect(msg.Subject).toBe(`Type template for ${A}`);
		// Only the ScopeA row's PDF — the ScopeB certificate for the same address stays out.
		expectOnlyRowA(msg);
	});

	test('falls back to the per-type template (Priority 1) when the type has none', async ({ page }) => {
		test.skip(!mail.enabled, 'Mailpit not configured');
		wp.sql("DELETE FROM {p}cg_email_templates WHERE name = %s", `E2E ${A}`);
		wp.resetRateLimit();

		const msg = await sendScopeA(page, true); // ScopeA was emailed by the previous test

		// {certificate_title} must be the certificate sent (A), not the anchor record (B).
		expect(msg.Subject).toBe(`Per-type subject: ${A}`);
		expectOnlyRowA(msg);
	});
});
