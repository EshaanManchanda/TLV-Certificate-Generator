// @ts-check
// Mailpit (Local's built-in mail catcher) API client. Global setup points
// WP Mail SMTP at Mailpit for the run, so nothing leaves the machine.
const { mailpitUrl } = require('./wp');

const BASE = mailpitUrl();

async function api(path, init) {
	if (!BASE) throw new Error('No Mailpit URL (set CG_E2E_MAILPIT_URL)');
	const r = await fetch(BASE + path, init);
	if (!r.ok) throw new Error(`Mailpit ${path} → ${r.status}`);
	return r.json();
}

/** Messages addressed to `to`, newest first. */
async function inbox(to) {
	return (await api(`/api/v1/search?query=${encodeURIComponent(`to:"${to}"`)}`)).messages || [];
}

/** Full message (Text, HTML, Attachments). */
const message = (id) => api(`/api/v1/message/${id}`);

/** Poll until `to` has at least `count` messages. */
async function waitForMail(to, count = 1, timeoutMs = 30_000) {
	const end = Date.now() + timeoutMs;
	for (;;) {
		const msgs = await inbox(to);
		if (msgs.length >= count || Date.now() > end) return msgs;
		await new Promise((r) => setTimeout(r, 1000));
	}
}

/** Delete all messages to @e2e.test so each run starts clean. */
async function clearE2E() {
	if (!BASE) return;
	await fetch(`${BASE}/api/v1/search?query=${encodeURIComponent('to:@e2e.test')}`, { method: 'DELETE' }).catch(() => {});
}

module.exports = { enabled: !!BASE, inbox, message, waitForMail, clearE2E };
