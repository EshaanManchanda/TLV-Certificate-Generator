// @ts-check
// §13 Bulk certificate download at scale — the Download Certificates page runs as a
// chunked job (progress bar, one private ZIP per part), the no-JS form still works, and
// [student_search] builds its "Download All" ZIP only when clicked.
const { test, expect, adminUrl } = require('./support/fixtures');
const wp = require('./support/wp');

const TYPE = 'E2E DlJob';
const COUNT = 230; // > CG_ADMIN_EXPORT_ZIP_PART_SIZE (200) → two parts

/** Names inside a downloaded ZIP (read with PHP's ZipArchive). */
const zipEntries = (file) => wp.wpEval(`
	$z = new ZipArchive(); $z->open(${wp.php(file)}); $n = [];
	for ($i = 0; $i < $z->numFiles; $i++) { $s = $z->statIndex($i); $n[] = [$s['name'], $s['comp_method']]; }
	echo json_encode($n);`);

/** Only this spec's rows (cleanupE2E() would also delete the e2e-admin session user). */
const removeSeed = () => {
	wp.sql('DELETE FROM {p}cg_students WHERE certificate_type = %s', TYPE);
	wp.sql('DELETE FROM {p}cg_certificate_templates WHERE certificate_type = %s', TYPE);
};

/** Session cookies for a user, minted like global-setup does (the site has a custom login path). */
const sessionFor = (login) => {
	const c = wp.wpEval(`
		$u = get_user_by('login', ${wp.php(login)}); $exp = time() + HOUR_IN_SECONDS;
		echo json_encode([[AUTH_COOKIE, wp_generate_auth_cookie($u->ID, $exp, 'auth')], [LOGGED_IN_COOKIE, wp_generate_auth_cookie($u->ID, $exp, 'logged_in')]]);`);
	const domain = new URL(process.env.CG_E2E_BASE_URL || 'http://gema-ecosystem.local').hostname;
	return { cookies: c.map(([name, value]) => ({ name, value, domain, path: '/', expires: Math.floor(Date.now() / 1000) + 3600, httpOnly: true, secure: false, sameSite: /** @type {const} */ ('Lax') })), origins: [] };
};

test.describe('§13 Bulk certificate download', () => {
	test.beforeAll(() => {
		removeSeed();
		wp.seedTemplate({ template_name: TYPE, certificate_type: TYPE, event_date: '2026-03-01', template_url: wp.bundledBg('classic-gold.jpg') });
		wp.wpEval(`
			for ($i = 1; $i <= ${COUNT}; $i++) {
				$wpdb->insert($wpdb->prefix . 'cg_students', [
					'student_name' => sprintf('E2E DL %03d', $i), 'email' => sprintf('dl-%03d@e2e.test', $i), 'school_name' => 'E2E School',
					'certificate_type' => '${TYPE}', 'issue_date' => '2026-03-01', 'year' => 2026, 'serial_number' => sprintf('E2EDL-%04d', $i),
					'status' => 'active', 'import_source' => 'e2e',
				]);
			}
			// Four certificates for one student, for the [student_search] ZIP.
			for ($i = 1; $i <= 4; $i++) {
				$wpdb->insert($wpdb->prefix . 'cg_students', [
					'student_name' => 'E2E Zip Student', 'email' => 'zip-student@e2e.test', 'school_name' => 'E2E School ' . $i,
					'certificate_type' => '${TYPE}', 'issue_date' => '2026-03-01', 'year' => 2026, 'serial_number' => 'E2EDLS-' . $i,
					'status' => 'active', 'import_source' => 'e2e',
				]);
			}
			delete_transient('cg_unique_cert_types');
			echo 1;`);
	});

	test.afterAll(() => {
		removeSeed(); // PDFs, records and the e2e users go in global-teardown's cleanupE2E()
		wp.sql("DELETE FROM {p}options WHERE option_name LIKE '\\_transient\\_%cg\\_dljob\\_%' OR option_name LIKE 'cg\\_dljob\\_lock\\_%'");
	});

	const preview = (page) => page.goto(adminUrl('cg-cert-download', { cg_preview: '1', 'filter_cert_type[]': TYPE, filter_email: 'dl-' }));

	test('the job renders every certificate in short requests and offers one ZIP per part', async ({ page }) => {
		await preview(page);
		const start = page.locator('#cg-job-start');
		await expect(start).toHaveText(`Download All ${COUNT} Certificates as ZIP`);
		await expect(page.locator('.cg-zip-btn')).toHaveCount(0); // part buttons replaced by the job UI

		const steps = [];
		page.on('response', async (r) => {
			if (r.url().includes('admin-ajax.php') && r.request().postData()?.includes('cg_cert_dl_step')) steps.push((await r.json()).data);
		});
		await start.click();
		const links = page.locator('#cg-job-links a');
		await expect(links).toHaveCount(2, { timeout: 170_000 });
		await expect(page.locator('#cg-job-status')).toContainText('Done');

		expect(steps.at(-1)).toMatchObject({ processed: COUNT, total: COUNT, complete: true, failed: 0, parts: 2 });
		await expect(links.first()).toHaveText('Download part 1 of 2 (200 certificates)');
		await expect(links.last()).toHaveText('Download part 2 of 2 (30 certificates)');

		const [download] = await Promise.all([page.waitForEvent('download'), links.last().click()]);
		const entries = zipEntries(await download.path());
		expect(entries).toHaveLength(31);
		expect(entries.map((e) => e[0])).toContain('manifest.csv');
		expect(entries.filter((e) => e[0].endsWith('.pdf')).every((e) => e[1] === 0), 'PDFs are stored, not re-deflated').toBeTruthy();
	});

	test('a second run reuses every PDF', async ({ page }) => {
		const before = wp.sql("SELECT COUNT(*) n FROM {p}cg_certificates WHERE recipient_name LIKE 'E2E DL %'")[0].n;
		await preview(page);
		await page.locator('#cg-job-start').click();
		await expect(page.locator('#cg-job-links a')).toHaveCount(2, { timeout: 60_000 });
		const after = wp.sql("SELECT COUNT(*) n FROM {p}cg_certificates WHERE recipient_name LIKE 'E2E DL %'")[0].n;
		expect(after).toBe(before);
	});

	test('the form still downloads a ZIP without JavaScript', async ({ browser }) => {
		const ctx = await browser.newContext({ storageState: 'playwright/.auth/admin.json', javaScriptEnabled: false });
		const page = await ctx.newPage();
		await page.goto(adminUrl('cg-cert-download', { cg_preview: '1', 'filter_cert_type[]': TYPE, filter_email: 'dl-23' }));
		const [download] = await Promise.all([page.waitForEvent('download'), page.locator('.cg-zip-btn').first().click()]);
		expect(download.suggestedFilename()).toMatch(/^certificates_students_.*\.zip$/);
		expect(zipEntries(await download.path()).map((e) => e[0])).toContain('manifest.csv');
		await ctx.close();
	});

	test('another admin cannot fetch someone else\'s job file', async ({ page, browser }) => {
		await preview(page);
		await page.locator('#cg-job-start').click();
		const link = page.locator('#cg-job-links a').first();
		await expect(link).toHaveCount(1, { timeout: 60_000 });
		const href = await link.getAttribute('href');

		wp.ensureUser('e2e-admin2', 'administrator', `E2e!${Date.now()}`);
		const ctx = await browser.newContext({ storageState: sessionFor('e2e-admin2') });
		const other = await ctx.newPage();
		const res = await other.goto(String(href));
		expect(res?.headers()['content-type'] || '').not.toContain('application/zip');
		await ctx.close();
	});

	test('[student_search] builds the Download All ZIP only when clicked', async ({ anon }) => {
		const url = wp.ensurePage('Student Search DL', '[student_search]');
		const zipsBefore = wp.wpEval("echo json_encode(count(glob(cg_certificates_dir() . '/certificates_zip_student_e2e_test_*.zip')));");
		await anon.goto(`${url}${url.includes('?') ? '&' : '?'}student_email=zip-student@e2e.test`);
		const all = anon.locator('a.bulk-download-button').first();
		await expect(all).toHaveAttribute('href', /action=cg_student_zip/);
		expect(wp.wpEval("echo json_encode(count(glob(cg_certificates_dir() . '/certificates_zip_student_e2e_test_*.zip')));")).toBe(zipsBefore);

		const res = await anon.request.get(String(await all.getAttribute('href')));
		expect(res.headers()['content-type']).toContain('zip');
		wp.resetRateLimit();
	});
});
