// @ts-check
// Local Ollama integration: a vision "judge" for checks selectors can't make,
// a JSON generator for test data, and free-text triage for the report.
//
// CG_E2E_OLLAMA=soft (default) → verdicts are annotations, never failures
//               strict         → a failed verdict fails the test
//               off            → no calls at all
// Unreachable Ollama behaves like `off` (so CI without Ollama stays green).
const HOST = process.env.CG_E2E_OLLAMA_HOST || 'http://localhost:11434';
const VISION = process.env.CG_E2E_OLLAMA_VISION_MODEL || 'gemma3:4b';
const TEXT = process.env.CG_E2E_OLLAMA_TEXT_MODEL || 'llama3.1:8b';
const MODE = (process.env.CG_E2E_OLLAMA || 'soft').toLowerCase();

let up;
async function available() {
	if (MODE === 'off') return false;
	if (up === undefined) {
		try {
			const r = await fetch(`${HOST}/api/tags`, { signal: AbortSignal.timeout(3000) });
			up = r.ok;
		} catch { up = false; }
	}
	return up;
}

async function chat(model, messages, format, timeoutMs = 180_000) {
	const r = await fetch(`${HOST}/api/chat`, {
		method: 'POST',
		headers: { 'content-type': 'application/json' },
		body: JSON.stringify({ model, messages, format, stream: false, keep_alive: '30m', options: { temperature: 0 } }),
		signal: AbortSignal.timeout(timeoutMs),
	});
	if (!r.ok) throw new Error(`ollama ${model} → HTTP ${r.status}: ${await r.text()}`);
	return (await r.json()).message.content;
}

const VERDICT = {
	type: 'object',
	properties: { pass: { type: 'boolean' }, reason: { type: 'string' } },
	required: ['pass', 'reason'],
};

/**
 * Ask the vision model a yes/no question about one or more images.
 * Records the verdict as a test annotation; fails the test only in strict mode.
 * @param {import('@playwright/test').TestInfo} testInfo
 * @param {Buffer|Buffer[]} images PNG(s)
 * @param {string} question phrased so that "pass: true" is the good outcome
 */
async function judge(testInfo, images, question) {
	if (!(await available())) {
		testInfo.annotations.push({ type: 'ollama-skipped', description: question });
		return null;
	}
	const imgs = (Array.isArray(images) ? images : [images]).map((b) => b.toString('base64'));
	imgs.forEach((b, i) => testInfo.attachments.push({ name: `judge-${i + 1}.png`, contentType: 'image/png', body: Buffer.from(b, 'base64') }));
	let verdict;
	try {
		const out = await chat(VISION, [
			{ role: 'system', content: 'You are a strict QA reviewer of certificate PDFs rendered as images. Answer only with JSON {"pass": boolean, "reason": "one short sentence"}.' },
			{ role: 'user', content: question, images: imgs },
		], VERDICT);
		verdict = JSON.parse(out);
	} catch (e) {
		testInfo.annotations.push({ type: 'ollama-error', description: `${question} — ${/** @type {Error} */ (e).message}` });
		return null;
	}
	testInfo.annotations.push({ type: verdict.pass ? 'ollama-pass' : 'ollama-fail', description: `${question} → ${verdict.reason}` });
	if (MODE === 'strict' && !verdict.pass) throw new Error(`Ollama judge failed: ${question}\n${verdict.reason}`);
	return verdict;
}

/**
 * Structured generation with the text model. Returns null when unavailable
 * or when the output doesn't parse — callers must have a fallback.
 */
async function generate(prompt, schema) {
	if (!(await available())) return null;
	try {
		return JSON.parse(await chat(TEXT, [{ role: 'user', content: prompt }], schema, 120_000));
	} catch { return null; }
}

/** Free-text answer from the text model (report triage). */
async function summarize(prompt) {
	if (!(await available())) return null;
	try { return (await chat(TEXT, [{ role: 'user', content: prompt }], undefined, 300_000)).trim(); } catch { return null; }
}

module.exports = { available, judge, generate, summarize, MODE };
