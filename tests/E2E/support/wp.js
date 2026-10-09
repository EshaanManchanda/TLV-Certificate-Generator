// @ts-check
// WP-CLI bridge: runs PHP inside the target WordPress so specs can seed data,
// read DB state and clean up without going through the UI.
//
// Auto-detects Local by Flywheel's PHP + per-site php.ini (the ini carries the
// MySQL port, so the plain `php` on PATH can't connect). Override with
// CG_E2E_PHP / CG_E2E_PHP_INI / CG_E2E_WPCLI / CG_E2E_WP_PATH.
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const WP_PATH = process.env.CG_E2E_WP_PATH || path.resolve(__dirname, '../../../../../..');
const LOCAL = path.join(process.env.APPDATA || '', 'Local');

/** Local's site id for WP_PATH, read from %APPDATA%/Local/sites.json. */
function localSite() {
	try {
		const sites = JSON.parse(fs.readFileSync(path.join(LOCAL, 'sites.json'), 'utf8'));
		const want = path.resolve(WP_PATH, '../..').toLowerCase();
		for (const [id, s] of Object.entries(sites)) {
			const p = path.resolve(String(s.path).replace(/^~/, os.homedir())).toLowerCase();
			if (p === want) return { id, ...s };
		}
	} catch {}
	return null;
}

function phpBinary() {
	if (process.env.CG_E2E_PHP) return process.env.CG_E2E_PHP;
	const site = localSite();
	const dir = path.join(LOCAL, 'lightning-services');
	const want = site?.services?.php?.version;
	const pick = fs.existsSync(dir)
		&& fs.readdirSync(dir).filter((d) => d.startsWith('php-')).sort()
			.find((d) => !want || d.startsWith(`php-${want}`));
	return pick ? path.join(dir, pick, 'bin', 'win64', 'php.exe') : 'php';
}

function phpIni() {
	if (process.env.CG_E2E_PHP_INI) return process.env.CG_E2E_PHP_INI;
	const site = localSite();
	const ini = site && path.join(LOCAL, 'run', site.id, 'conf', 'php', 'php.ini');
	return ini && fs.existsSync(ini) ? ini : null;
}

const WPCLI = process.env.CG_E2E_WPCLI
	|| ['C:/Program Files (x86)/Local/resources/extraResources/bin/wp-cli/wp-cli.phar', 'C:/Program Files/Local/resources/extraResources/bin/wp-cli/wp-cli.phar']
		.find((p) => fs.existsSync(p))
	|| 'wp-cli.phar';

/** Mailpit web URL for this Local site (CG_E2E_MAILPIT_URL wins). */
function mailpitUrl() {
	if (process.env.CG_E2E_MAILPIT_URL) return process.env.CG_E2E_MAILPIT_URL;
	const port = localSite()?.services?.mailpit?.ports?.WEB?.[0];
	return port ? `http://localhost:${port}` : null;
}

/** Mailpit SMTP port for this Local site. */
function mailpitSmtpPort() {
	return Number(process.env.CG_E2E_MAILPIT_SMTP_PORT || localSite()?.services?.mailpit?.ports?.SMTP?.[0] || 0) || null;
}

const SELF = path.basename(path.resolve(__dirname, '../../..'));

/**
 * Other active plugins, skipped by default: halves WP-CLI boot time. Cached in
 * env so Playwright workers inherit it from global-setup.
 */
function skipPlugins() {
	if (process.env.CG_E2E_SKIP_PLUGINS === undefined) {
		const active = wpEval("echo json_encode(get_option('active_plugins'));", { allPlugins: true });
		process.env.CG_E2E_SKIP_PLUGINS = Object.values(active).map((p) => p.split('/')[0]).filter((d) => d !== SELF).join(',');
	}
	return process.env.CG_E2E_SKIP_PLUGINS;
}

/**
 * Run PHP inside WordPress. The snippet should `echo json_encode(...)` its
 * result; the parsed JSON is returned (or raw text if it isn't JSON).
 * Other plugins are skipped unless `allPlugins` (needed for mail via WP Mail
 * SMTP, LMS hooks, cron that sends email…).
 * @param {string} php body WITHOUT the leading `<?php`
 * @param {{allPlugins?: boolean}} [opts]
 */
function wpEval(php, opts = {}) {
	// Code goes over stdin (`eval-file -`): no temp .php files, which AV
	// heuristics (e.g. Norton IDP.Generic) flag and kill php.exe over.
	// try/catch so errors surface here instead of WordPress's generic "critical error" text.
	const code = `<?php\nglobal $wpdb;\ntry {\n${php}\n} catch (\\Throwable $e) { fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); exit(1); }\n`;
	const ini = phpIni();
	const skip = opts.allPlugins ? '' : skipPlugins();
	const args = [...(ini ? ['-c', ini] : []), '-d', 'display_startup_errors=0', WPCLI, `--path=${WP_PATH}`, '--skip-themes',
		...(skip ? [`--skip-plugins=${skip}`] : []), 'eval-file', '-'];
	let out;
	try {
		out = execFileSync(phpBinary(), args, { encoding: 'utf8', input: code, stdio: ['pipe', 'pipe', 'pipe'], timeout: 120_000 });
	} catch (e) {
		const err = /** @type {any} */ (e);
		throw new Error(`wpEval failed: ${String(err.stderr || err.message).replace(/.*imagick.*\n?/gi, '').trim()}\n--- php ---\n${php}`);
	}
	out = out.replace(/^.*PHP Startup:.*$/gm, '').trim();
	try { return JSON.parse(out); } catch { return out; }
}

/** PHP literal for a JS value (via JSON so quoting is always safe). */
const php = (v) => `json_decode(${JSON.stringify(JSON.stringify(v ?? null))}, true)`;

const getOption = (name) => wpEval(`echo json_encode(get_option(${php(name)}, null));`);
const setOption = (name, value) => wpEval(`update_option(${php(name)}, ${php(value)}); echo 1;`);
const deleteOption = (name) => wpEval(`delete_option(${php(name)}); echo 1;`);

/** Run SQL with {p} replaced by the table prefix; returns rows for SELECT. */
function sql(query, ...args) {
	return wpEval(`
		$q = str_replace('{p}', $wpdb->prefix, ${php(query)});
		$a = ${php(args)};
		if ($a) $q = $wpdb->prepare($q, ...$a);
		$r = preg_match('/^\\s*(SELECT|SHOW)/i', $q) ? $wpdb->get_results($q, ARRAY_A) : $wpdb->query($q);
		if ($wpdb->last_error) { fwrite(STDERR, $wpdb->last_error); exit(1); }
		echo json_encode($r);`);
}

const ENTITY = {
	students: { table: 'cg_students', name: 'student_name' },
	teachers: { table: 'cg_teachers', name: 'teacher_name' },
	schools: { table: 'cg_schools', name: 'school_name' },
};

/**
 * Insert a student/teacher/school row. `row` uses the checklist shape
 * ({name, email, school, certificate_type, issue_date yyyy-mm-dd, ...extra cols}).
 * @returns {number} new row id
 */
function seedPerson(type, row) {
	const { table, name } = ENTITY[type];
	const { name: n, school, ...rest } = row;
	const data = { [name]: n, ...rest };
	if (type !== 'schools' && school) data.school_name = school;
	if (data.issue_date && !data.year) data.year = String(data.issue_date).slice(0, 4);
	data.status = data.status || 'active';
	data.import_source = data.import_source || 'e2e';
	return wpEval(`$wpdb->insert($wpdb->prefix . ${php(table)}, ${php(data)}); echo (int) $wpdb->insert_id;`);
}

/**
 * Insert a published template row directly (UI creation is covered by §3).
 * `fields`: [{name, x, y, alignment?}] → stored as field_N_* in extra_fields,
 * the same shape TemplatesPage saves.
 * @returns {number} template id
 */
function seedTemplate({ fields, ...t }) {
	const data = {
		status: 'published', entity_type: 'students', orientation: 'landscape', page_size: 'A4',
		font_style: 'helvetica', font_size: 24, font_color: '#000000', qr_enabled: 0, serial_number_display: 1,
		serial_number_position_x: 105, serial_number_position_y: 200, expiration_period_unit: 'never', ...t,
	};
	if (data.event_date && !data.year) data.year = String(data.event_date).slice(0, 4);
	const nameField = { students: 'student_name', teachers: 'teacher_name', schools: 'school_name' }[data.entity_type] || 'student_name';
	const list = fields || [{ name: nameField, x: 148.5, y: 100 }, { name: data.entity_type === 'schools' ? 'certificate_type' : 'school_name', x: 148.5, y: 125 }];
	const extra = { template_field_count: list.length };
	list.forEach((f, i) => Object.assign(extra, {
		[`field_${i + 1}_name`]: f.name, [`field_${i + 1}_type`]: f.type || 'text', [`field_${i + 1}_visible`]: '1',
		[`field_${i + 1}_position_x`]: f.x, [`field_${i + 1}_position_y`]: f.y, [`field_${i + 1}_alignment`]: f.alignment || 'C',
		[`field_${i + 1}_width`]: f.width || 100, [`field_${i + 1}_height`]: f.height || '', [`field_${i + 1}_image_url`]: '',
	}));
	data.extra_fields = JSON.stringify(extra);
	return wpEval(`$wpdb->insert($wpdb->prefix . 'cg_certificate_templates', ${php(data)}); echo (int) $wpdb->insert_id;`);
}

/** Bundled background (copied into uploads isn't needed — FPDF loads the URL). */
const bundledBg = (file, base = process.env.CG_E2E_BASE_URL || 'http://gema-ecosystem.local') => `${base}/wp-content/plugins/${path.basename(path.resolve(__dirname, '../../..'))}/assets/templates/${file}`;

/** Insert a row into the legacy verify table (what verify/revoke actually read). */
function seedCertificate(c) {
	const data = {
		student_name: 'E2E Seeded', email: 'seeded@e2e.test', certificate_type: 'Participation',
		generated_via: 'e2e', created_at: now(), issued_at: now(), updated_at: now(), certificate_data: '{}', pdf_path: '', ...c,
	};
	return wpEval(`$wpdb->insert($wpdb->prefix . 'certificate_generator', ${php(data)}); echo (int) $wpdb->insert_id;`);
}

const now = () => new Date().toISOString().slice(0, 19).replace('T', ' ');

const resetRateLimit = () => sql("DELETE FROM {p}options WHERE option_name LIKE '\\_transient\\_cg\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_cg\\_rl\\_%'");

/** Run a cron hook synchronously. */
const runCron = (hook, args = []) => wpEval(`do_action_ref_array(${php(hook)}, ${php(args)}); echo 1;`, { allPlugins: true });

/** Published page containing `content`; reused across runs. @returns {string} permalink */
function ensurePage(title, content) {
	return wpEval(`
		$t = ${php(`E2E ${title}`)};
		$p = get_page_by_title($t, OBJECT, 'page');
		$id = $p ? $p->ID : wp_insert_post(['post_title' => $t, 'post_content' => ${php(content)}, 'post_status' => 'publish', 'post_type' => 'page']);
		delete_transient('cg_verify_page_url');
		echo json_encode(get_permalink($id));`);
}

/** Create (or reset the password of) a test user. */
function ensureUser(login, role, pass) {
	return wpEval(`
		$u = get_user_by('login', ${php(login)});
		$id = $u ? $u->ID : wp_insert_user(['user_login' => ${php(login)}, 'user_email' => ${php(`${login}@e2e.test`)}, 'user_pass' => ${php(pass)}, 'role' => ${php(role)}]);
		if ($u) { wp_set_password(${php(pass)}, $id); (new WP_User($id))->set_role(${php(role)}); }
		echo (int) $id;`);
}

/** Delete everything tagged E2E (rows, templates, events, pages, users, PDFs). */
function cleanupE2E() {
	return wpEval(`
		$p = $wpdb->prefix; $n = [];
		$like = "'E2E%'"; $mail = "'%@e2e.test'";
		$ids = ['students' => $wpdb->get_col("SELECT id FROM {$p}cg_students WHERE student_name LIKE $like OR email LIKE $mail OR import_source='e2e'"),
		        'teachers' => $wpdb->get_col("SELECT id FROM {$p}cg_teachers WHERE teacher_name LIKE $like OR email LIKE $mail OR import_source='e2e'"),
		        'schools'  => $wpdb->get_col("SELECT id FROM {$p}cg_schools WHERE school_name LIKE $like OR email LIKE $mail OR import_source='e2e'")];
		$dir = wp_upload_dir()['basedir'] . '/cg_certificates/';
		foreach ($ids as $type => $list) foreach ($list as $id) {
			// rows with no WP post (see _cg_generate_pdf_impl); names end in a hash suffix since 7.5.3
			foreach (glob($dir . "certificate_{$type}_row_{$id}{,_*}.pdf", GLOB_BRACE) ?: [] as $f) @unlink($f);
			if ($type === 'students') { foreach (glob($dir . "certificate_{$id}{,_*}.{pdf,png}", GLOB_BRACE) ?: [] as $f) @unlink($f); }
		}
		$n['certificates'] = $wpdb->query("DELETE FROM {$p}cg_certificates WHERE recipient_name LIKE $like OR recipient_email LIKE $mail");
		$n['legacy']       = $wpdb->query("DELETE FROM {$p}certificate_generator WHERE student_name LIKE $like OR email LIKE $mail");
		$n['students']     = $wpdb->query("DELETE FROM {$p}cg_students WHERE student_name LIKE $like OR email LIKE $mail OR import_source='e2e'");
		$n['teachers']     = $wpdb->query("DELETE FROM {$p}cg_teachers WHERE teacher_name LIKE $like OR email LIKE $mail OR import_source='e2e'");
		$n['schools']      = $wpdb->query("DELETE FROM {$p}cg_schools WHERE school_name LIKE $like OR email LIKE $mail OR import_source='e2e'");
		$n['templates']    = $wpdb->query("DELETE FROM {$p}cg_certificate_templates WHERE template_name LIKE $like OR certificate_type LIKE $like");
		$n['events']       = $wpdb->query("DELETE FROM {$p}cg_events WHERE event_code LIKE 'E2E%' OR event_name LIKE $like");
		$n['queue']        = $wpdb->query("DELETE FROM {$p}cert_email_queue WHERE recipient_email LIKE $mail");
		$n['logs']         = $wpdb->query("DELETE FROM {$p}cert_email_logs WHERE recipient_email LIKE $mail");
		foreach (get_posts(['post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 's' => 'E2E ']) as $pg)
			if (strpos($pg->post_title, 'E2E ') === 0) { wp_delete_post($pg->ID, true); $n['pages'] = ($n['pages'] ?? 0) + 1; }
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach (get_users(['search' => '*@e2e.test', 'search_columns' => ['user_email']]) as $u) { wp_delete_user($u->ID); $n['users'] = ($n['users'] ?? 0) + 1; }
		delete_transient('cg_verify_page_url');
		echo json_encode($n);`);
}

/** Environment facts for the report header. */
const siteInfo = () => wpEval(`
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$pd = get_plugin_data(WP_PLUGIN_DIR . '/Certificate-Generator-V7.5/certificate-generator.php', false, false);
	echo json_encode(['wp' => get_bloginfo('version'), 'php' => PHP_VERSION, 'plugin' => $pd['Version'] ?? '?',
		'debug_log' => (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG)) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log',
		'uploads' => wp_upload_dir()['basedir'], 'plan' => get_option('cg_plan', 'free')]);`);

module.exports = {
	WP_PATH, wpEval, php, sql, getOption, setOption, deleteOption, seedPerson, seedTemplate, seedCertificate,
	resetRateLimit, runCron, bundledBg, ensurePage, ensureUser, cleanupE2E, siteInfo, mailpitUrl, mailpitSmtpPort, ENTITY, now,
};
