// @ts-check
// Writes test-results/e2e-report.md in the shape of the manual checklist's
// "Result / Failures found" footer, plus Ollama triage of the failures and of
// new debug.log lines. Without Ollama the tables are still written.
const fs = require('fs');
const path = require('path');
const { summarize } = require('./ollama');

const STATE = path.resolve(__dirname, '../../../playwright/.auth/state.json');
const OUT = path.resolve(__dirname, '../../../test-results/e2e-report.md');

const strip = (s) => String(s || '').replace(/\u001b\[[0-9;]*m/g, '');
const cell = (s) => strip(s).replace(/\|/g, '\\|').replace(/\s+/g, ' ').trim().slice(0, 300);

class OllamaReporter {
	constructor() { this.results = []; }

	onTestEnd(test, result) {
		const titles = test.titlePath().filter(Boolean);
		const sectionTitle = titles.find((t) => /^§\d+/.test(t)) || titles[2] || 'misc';
		this.results.push({
			section: sectionTitle,
			num: Number((sectionTitle.match(/^§(\d+)/) || [])[1] || 99),
			title: test.title,
			outcome: test.outcome(), // expected | unexpected | flaky | skipped
			error: result.errors.map((e) => strip(e.message)).join('\n').slice(0, 1500),
			annotations: [...test.annotations, ...result.annotations],
		});
	}

	async onEnd() {
		let state = {};
		try { state = JSON.parse(fs.readFileSync(STATE, 'utf8')); } catch {}

		const sections = new Map();
		for (const r of this.results) {
			if (!sections.has(r.section)) sections.set(r.section, { num: r.num, rows: [] });
			sections.get(r.section).rows.push(r);
		}
		const ordered = [...sections.entries()].sort((a, b) => a[1].num - b[1].num);
		const verdict = (rows) => (rows.some((r) => r.outcome === 'unexpected') ? '❌' : rows.every((r) => r.outcome === 'skipped') ? '⏭' : '✅');
		const passed = ordered.filter(([, s]) => verdict(s.rows) === '✅').length;
		const failures = this.results.filter((r) => r.outcome === 'unexpected');
		const known = this.results.filter((r) => r.annotations.some((a) => a.type === 'fail'));
		const judged = this.results.flatMap((r) => r.annotations.filter((a) => a.type.startsWith('ollama-')).map((a) => ({ ...a, test: r.title, section: r.section })));

		let newLog = '';
		if (state.debug_log && fs.existsSync(state.debug_log)) {
			const size = fs.statSync(state.debug_log).size;
			if (size > state.logOffset) {
				const fd = fs.openSync(state.debug_log, 'r');
				const buf = Buffer.alloc(Math.min(size - state.logOffset, 200_000));
				fs.readSync(fd, buf, 0, buf.length, Math.max(state.logOffset, size - 200_000));
				fs.closeSync(fd);
				newLog = buf.toString('utf8').split('\n').filter((l) => /Certificate-Generator|PHP (Fatal|Warning)/i.test(l)).slice(-80).join('\n');
			}
		}

		let triage = null;
		if (failures.length || newLog) {
			triage = await summarize(
				'You are triaging an automated E2E run of a WordPress certificate-generator plugin. '
				+ 'For each failure, say in one or two plain-English sentences what most likely broke and where to look '
				+ '(UI selector drift, plugin bug, missing test prerequisite, or environment). Group related failures. '
				+ 'Then list any debug.log lines that look like real plugin bugs. Be concise; use markdown bullets.\n\n'
				+ `FAILURES:\n${failures.map((f) => `- [${f.section}] ${f.title}\n  ${f.error.slice(0, 600)}`).join('\n') || '(none)'}\n\n`
				+ `NEW debug.log LINES:\n${newLog || '(none)'}`,
			);
		}

		const lines = [
			'# E2E Test Report',
			'',
			`Generated from [MANUAL-TEST-CHECKLIST.md](../doc/dev/MANUAL-TEST-CHECKLIST.md) by \`npm run test:e2e\`.`,
			'',
			`**Result:** ${passed} / ${ordered.length} sections passed. Tested on WP ${state.wp || '?'} · PHP ${state.php || '?'} · plugin version ${state.plugin || '?'} · plan ${state.plan || '?'} · date ${new Date().toISOString().slice(0, 10)} · by Playwright.`,
			'',
			'| Section | Result | Passed | Failed | Skipped |',
			'|---|---|---|---|---|',
			...ordered.map(([name, s]) => `| ${cell(name)} | ${verdict(s.rows)} | ${s.rows.filter((r) => r.outcome === 'expected' || r.outcome === 'flaky').length} | ${s.rows.filter((r) => r.outcome === 'unexpected').length} | ${s.rows.filter((r) => r.outcome === 'skipped').length} |`),
			'',
			'**Failures found:**',
			'| Section | Step | What happened |',
			'|---|---|---|',
			...(failures.length ? failures.map((f) => `| ${cell(f.section)} | ${cell(f.title)} | ${cell(f.error.split('\n').find((l) => l.trim()) || f.error)} |`) : ['| — | — | none |']),
			'',
		];
		if (known.length) {
			lines.push('**Known bugs (expected failures — these go red when the bug is fixed, then drop the `test.fail`):**', '');
			for (const k of known) lines.push(`- [${k.section}] ${k.title} — ${k.annotations.find((a) => a.type === 'fail')?.description || ''}`);
			lines.push('');
		}
		if (judged.length) {
			lines.push('**Ollama visual judge:**', '', '| Section | Test | Verdict | Question → reason |', '|---|---|---|---|');
			for (const j of judged) lines.push(`| ${cell(j.section)} | ${cell(j.test)} | ${j.type.replace('ollama-', '')} | ${cell(j.description)} |`);
			lines.push('');
		}
		if (triage) lines.push('## Ollama triage', '', triage, '');
		if (newLog) lines.push('## New debug.log lines', '', '```', newLog, '```', '');

		fs.mkdirSync(path.dirname(OUT), { recursive: true });
		fs.writeFileSync(OUT, lines.join('\n'));
		console.log(`\n[e2e] Report: ${path.relative(process.cwd(), OUT)}  (${passed}/${ordered.length} sections passed)`);
	}

	printsToStdio() { return false; }
}

module.exports = OllamaReporter;
