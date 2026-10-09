// @ts-check
// Runs once before the suite:
//  1. snapshot options the specs touch (restored by global-teardown; if a
//     previous run crashed, its snapshot is kept so we restore the ORIGINAL)
//  2. route WP Mail SMTP to Local's Mailpit so no real email leaves the box
//  3. wipe leftover E2E data, create e2e admin + subscriber users and write
//     their auth cookies as storageState (no login form → custom login paths
//     like /gema-login don't matter, and no real password is needed)
//  4. record the debug.log offset and warm up Ollama
const fs = require('fs');
const path = require('path');
const wp = require('./wp');
const ollama = require('./ollama');
const mail = require('./mailpit');

const AUTH_DIR = path.resolve(__dirname, '../../../playwright/.auth');
const SNAPSHOT = path.join(AUTH_DIR, 'snapshot.json');
const STATE = path.join(AUTH_DIR, 'state.json');

const SNAPSHOT_OPTIONS = [
	'cg_plan', 'certificate_generator_feature_toggles', 'certificate_generator_serial_prefix', 'certificate_generator_serial_length', 'certificate_generator_serial_suffix',
	'certificate_generator_serial_reset_period', 'certificate_generator_serial_include_date', 'wp_mail_smtp', 'certificate_generator_email_transport',
	'certificate_generator_keep_data_on_uninstall', 'certificate_generator_settings_email',
];

function storageState(baseURL, login) {
	const c = wp.wpEval(`
		$u = get_user_by('login', ${wp.php(login)}); $exp = time() + 2 * DAY_IN_SECONDS;
		echo json_encode([
			[AUTH_COOKIE, wp_generate_auth_cookie($u->ID, $exp, 'auth')],
			[SECURE_AUTH_COOKIE, wp_generate_auth_cookie($u->ID, $exp, 'secure_auth')],
			[LOGGED_IN_COOKIE, wp_generate_auth_cookie($u->ID, $exp, 'logged_in')],
		]);`);
	const domain = new URL(baseURL).hostname;
	const expires = Math.floor(Date.now() / 1000) + 2 * 86400;
	return {
		cookies: c.map(([name, value]) => ({ name, value, domain, path: '/', expires, httpOnly: true, secure: false, sameSite: 'Lax' })),
		origins: [],
	};
}

module.exports = async (config) => {
	const baseURL = config.projects[0].use.baseURL;
	fs.mkdirSync(AUTH_DIR, { recursive: true });

	if (!fs.existsSync(SNAPSHOT)) {
		const snap = wp.wpEval(`$o = []; foreach (${wp.php(SNAPSHOT_OPTIONS)} as $n) $o[$n] = get_option($n, '__cg_e2e_absent__'); echo json_encode($o);`);
		fs.writeFileSync(SNAPSHOT, JSON.stringify(snap, null, 2));
	}

	// Route outgoing mail into Mailpit for the duration of the run.
	const smtpPort = wp.mailpitSmtpPort();
	if (smtpPort) {
		wp.wpEval(`
			$o = get_option('wp_mail_smtp', []);
			$o['mail']['mailer'] = 'smtp';
			$o['smtp'] = array_merge($o['smtp'] ?? [], ['host' => 'localhost', 'port' => ${smtpPort}, 'encryption' => 'none', 'autotls' => false, 'auth' => false]);
			update_option('wp_mail_smtp', $o);
			update_option('certificate_generator_email_transport', 'wp_mail');
			echo 1;`);
	} else {
		console.warn('[e2e] No Mailpit found — email specs will skip and mail settings are left untouched.');
	}

	console.log('[e2e] removed leftovers:', JSON.stringify(wp.cleanupE2E()));
	await mail.clearE2E();
	wp.resetRateLimit();

	const pass = `E2e!${Math.random().toString(36).slice(2)}${Date.now()}`;
	wp.ensureUser('e2e-admin', 'administrator', pass);
	wp.ensureUser('e2e-subscriber', 'subscriber', pass);
	fs.writeFileSync(path.join(AUTH_DIR, 'admin.json'), JSON.stringify(storageState(baseURL, 'e2e-admin')));
	fs.writeFileSync(path.join(AUTH_DIR, 'subscriber.json'), JSON.stringify(storageState(baseURL, 'e2e-subscriber')));

	const info = wp.siteInfo();
	const logSize = fs.existsSync(info.debug_log) ? fs.statSync(info.debug_log).size : 0;
	fs.writeFileSync(STATE, JSON.stringify({ ...info, logOffset: logSize, startedAt: new Date().toISOString() }, null, 2));
	process.env.CG_E2E_DEBUG_LOG = info.debug_log;

	if (await ollama.available()) {
		// Load both models now so the first judged test doesn't eat the cold start.
		await Promise.all([ollama.generate('Reply {"ok":true}', { type: 'object' }), ollama.summarize('Reply OK.')]);
		console.log('[e2e] Ollama ready (mode:', ollama.MODE + ')');
	} else {
		console.log('[e2e] Ollama not available — visual judge, data generation and triage are skipped.');
	}
};

module.exports.SNAPSHOT = SNAPSHOT;
module.exports.STATE = STATE;
module.exports.AUTH_DIR = AUTH_DIR;
