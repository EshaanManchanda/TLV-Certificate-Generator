// @ts-check
// Writes test-results/e2e-report.md: one verdict line, a table per checklist section,
// then Failures, Warnings and every test's result. Ollama (optional) triages failures.
// Warnings never fail the run: visual-judge "fail" verdicts, flaky tests, plugin PHP
// notices (from the fixtures' guard) and other new plugin lines in debug.log.
const fs = require('fs');
const path = require('path');
const { summarize } = require('./ollama');

const ROOT = path.resolve(__dirname, '../../..');
const STATE = path.join(ROOT, 'playwright/.auth/state.json');
const OUT = path.join(ROOT, 'test-results/e2e-report.md');

const strip = (s) => String(s || '').replace(/\u001b\[[0-9;]*m/g, '');
const cell = (s, max = 300) => strip(s).replace(/\|/g, '\\|').replace(/\s+/g, ' ').trim().slice(0, max);
const secs = (ms) => `${(ms / 1000).toFixed(1)}s`;
const rel = (p) => path.relative(path.dirname(OUT), p).replace(/\\/g, '/');
const ICON = { passed: '✅', failed: '❌', skipped: '⏭', warning: '⚠️' };

/** A debug.log line without its timestamp and HTML, so repeats of one message compare equal. */
const logMessage = (l) => l.replace(/^\[[^\]]+\]\s*/, '').replace(/<[^>]+>/g, '').trim();

class OllamaReporter {
	constructor() { this.results = []; }

	onBegin() { fs.rmSync(path.join(ROOT, 'test-results/images'), { recursive: true, force: true }); }

	onTestEnd(test, result) {
		const titles = test.titlePath().filter(Boolean);
		const section = titles.find((t) => /^§\d+/.test(t)) || titles[2] || 'misc';
		// test.annotations and result.annotations can hold the same entries; keep each once.
		const seen = new Set();
		const annotations = [...test.annotations, ...result.annotations].filter((a) => {
			const key = `${a.type}|${a.description}`;
			return seen.has(key) ? false : seen.add(key);
		});
		const outcome = test.outcome(); // expected | unexpected | flaky | skipped
		const error = result.errors[0];
		// Images the visual judge saw are in-memory attachments; write them out so the report can link them.
		const images = result.attachments.filter((a) => a.body && /^image\//.test(a.contentType)).map((a, i) => {
			const file = path.join(ROOT, 'test-results/images', `${section}-${test.title}`.replace(/[^\w-]+/g, '-').slice(0, 80) + `-${i + 1}.png`);
			fs.mkdirSync(path.dirname(file), { recursive: true });
			fs.writeFileSync(file, a.body);
			return file;
		});
		this.results.push({
			section,
			num: Number((section.match(/^§(\d+)/) || [])[1] || 99),
			title: test.title,
			status: outcome === 'unexpected' ? 'failed' : outcome === 'skipped' ? 'skipped' : 'passed',
			flaky: outcome === 'flaky',
			duration: result.duration,
			error: result.errors.map((e) => strip(e.message)).join('\n').slice(0, 1500),
			where: error?.location ? `${path.relative(ROOT, error.location.file).replace(/\\/g, '/')}:${error.location.line}` : `${path.relative(ROOT, test.location.file).replace(/\\/g, '/')}:${test.location.line}`,
			files: result.attachments.filter((a) => a.path).map((a) => ({ name: a.name, path: a.path })),
			images,
			annotations,
			skipReason: annotations.find((a) => a.type === 'skip')?.description || '',
		});
	}

	/** Warnings for one test: what to look at, though the test passed. */
	warningsOf(r) {
		const w = [];
		if (r.flaky) w.push({ kind: 'Flaky', text: 'failed first, passed on retry' });
		for (const a of r.annotations) {
			if (a.type === 'ollama-fail') w.push({ kind: 'Visual judge', text: a.description });
			if (a.type === 'ollama-error') w.push({ kind: 'Visual judge error', text: a.description });
			if (a.type === 'php-notice') w.push({ kind: 'PHP notice', text: a.description });
		}
		return w;
	}

	/** New debug.log lines from either plugin since global-setup, grouped by message. */
	debugLog(state) {
		if (!state.debug_log || !fs.existsSync(state.debug_log)) return [];
		const size = fs.statSync(state.debug_log).size;
		if (size <= state.logOffset) return [];
		const fd = fs.openSync(state.debug_log, 'r');
		const buf = Buffer.alloc(Math.min(size - state.logOffset, 2_000_000));
		fs.readSync(fd, buf, 0, buf.length, Math.max(state.logOffset, size - 2_000_000));
		fs.closeSync(fd);
		const counts = new Map();
		for (const l of buf.toString('utf8').split('\n')) {
			if (!/certificate-generator/i.test(l) || !/PHP |WordPress database error/i.test(l)) continue;
			const m = logMessage(l);
			counts.set(m, (counts.get(m) || 0) + 1);
		}
		return [...counts.entries()].map(([message, count]) => ({ message, count }));
	}

	async onEnd(full) {
		let state = {};
		try { state = JSON.parse(fs.readFileSync(STATE, 'utf8')); } catch {}

		const all = this.results;
		const failed = all.filter((r) => r.status === 'failed');
		const skipped = all.filter((r) => r.status === 'skipped');
		const passed = all.filter((r) => r.status === 'passed');
		const warned = all.map((r) => ({ r, w: this.warningsOf(r) })).filter((x) => x.w.length);
		const log = this.debugLog(state);
		const logErrors = log.filter((l) => !/PHP Notice/i.test(l.message));
		const warningCount = warned.reduce((n, x) => n + x.w.length, 0) + logErrors.length;

		const sections = new Map();
		for (const r of all) {
			if (!sections.has(r.section)) sections.set(r.section, { num: r.num, rows: [] });
			sections.get(r.section).rows.push(r);
		}
		const ordered = [...sections.entries()].sort((a, b) => a[1].num - b[1].num);
		const sectionIcon = (rows) => rows.some((r) => r.status === 'failed') ? ICON.failed
			: rows.every((r) => r.status === 'skipped') ? ICON.skipped
				: rows.some((r) => this.warningsOf(r).length) ? ICON.warning : ICON.passed;
		const greenSections = ordered.filter(([, s]) => sectionIcon(s.rows) !== ICON.failed).length;
		const testIcon = (r) => r.status === 'failed' ? ICON.failed : r.status === 'skipped' ? ICON.skipped : this.warningsOf(r).length ? ICON.warning : ICON.passed;

		const overall = failed.length ? `${ICON.failed} **FAILED**` : !passed.length ? `${ICON.skipped} **NOTHING RAN** (every test skipped)` : warningCount ? `${ICON.warning} **PASSED with warnings**` : `${ICON.passed} **PASSED**`;
		const totals = `${passed.length} passed · ${failed.length} failed · ${warningCount} warnings · ${skipped.length} skipped`;

		let triage = null;
		if (failed.length) {
			triage = await summarize(
				'You are triaging an automated E2E run of a WordPress certificate-generator plugin. '
				+ 'For each failure, say in one or two plain-English sentences what most likely broke and where to look '
				+ '(UI selector drift, plugin bug, missing test prerequisite, or environment). Group related failures. '
				+ 'Be concise; use markdown bullets.\n\n'
				+ `FAILURES:\n${failed.map((f) => `- [${f.section}] ${f.title} (${f.where})\n  ${f.error.slice(0, 600)}`).join('\n')}\n\n`
				+ `NEW debug.log LINES:\n${logErrors.map((l) => `${l.count}× ${l.message}`).join('\n').slice(0, 4000) || '(none)'}`,
			);
		}

		const lines = [
			'# E2E Test Report',
			'',
			`${overall} — ${totals} · ${greenSections} / ${ordered.length} sections green · ${secs(full.duration || 0)}`,
			'',
			`WP ${state.wp || '?'} · PHP ${state.php || '?'} · plugin ${state.plugin || '?'} · plan ${state.plan || '?'} · ${new Date().toISOString().slice(0, 16).replace('T', ' ')} UTC. `
			+ `Automates [MANUAL-TEST-CHECKLIST.md](../doc/dev/MANUAL-TEST-CHECKLIST.md). Full traces: \`npx playwright show-report\`.`,
			'',
			'## Sections',
			'',
			'| Section | Result | Passed | Failed | Warnings | Skipped | Time |',
			'|---|---|---|---|---|---|---|',
			...ordered.map(([name, s]) => `| ${cell(name)} | ${sectionIcon(s.rows)} | ${s.rows.filter((r) => r.status === 'passed').length} | ${s.rows.filter((r) => r.status === 'failed').length} | ${s.rows.reduce((n, r) => n + this.warningsOf(r).length, 0)} | ${s.rows.filter((r) => r.status === 'skipped').length} | ${secs(s.rows.reduce((n, r) => n + r.duration, 0))} |`),
			'',
			`## ${ICON.failed} Failures (${failed.length})`,
			'',
		];
		if (!failed.length) lines.push('None.', '');
		for (const f of failed) {
			lines.push(`### ${cell(f.section)} › ${cell(f.title)}`, '', `At \`${f.where}\``, '', '```', f.error.split('\n').filter((l) => l.trim()).slice(0, 12).join('\n'), '```');
			const files = f.files.map((a) => `[${a.name}](${rel(a.path)})`);
			if (files.length) lines.push('', `Files: ${files.join(' · ')}`);
			lines.push('');
		}

		lines.push(`## ${ICON.warning} Warnings (${warningCount})`, '');
		if (!warningCount) lines.push('None.', '');
		if (warned.length) {
			lines.push('The visual judge is a small local model and can be wrong: open the image before acting on its verdict.', '');
			lines.push('| Section | Test | Kind | Detail | Images |', '|---|---|---|---|---|');
			for (const { r, w } of warned) {
				for (const x of w) {
					const imgs = x.kind.startsWith('Visual judge') ? r.images.map((f, i) => `[${i + 1}](${rel(f)})`).join(' ') : '';
					lines.push(`| ${cell(r.section)} | ${cell(r.title)} | ${x.kind} | ${cell(x.text, 400)} | ${imgs} |`);
				}
			}
			lines.push('');
		}
		if (logErrors.length) {
			lines.push('**Plugin warnings and errors in debug.log this run** (the tests that caused them are also listed under Failures):', '', '| Times | Message |', '|---|---|');
			for (const l of logErrors) lines.push(`| ${l.count} | ${cell(l.message, 500)} |`);
			lines.push('');
		}

		const known = all.filter((r) => r.annotations.some((a) => a.type === 'fail'));
		if (known.length) {
			lines.push('**Known bugs** (expected failures: these turn red when the bug is fixed; then drop the `test.fail`):', '');
			for (const k of known) lines.push(`- [${k.section}] ${k.title} — ${k.annotations.find((a) => a.type === 'fail')?.description || ''}`);
			lines.push('');
		}

		if (triage) lines.push('## Ollama triage', '', triage, '');

		lines.push(`## All tests (${all.length})`, '');
		for (const [name, s] of ordered) {
			lines.push(`<details><summary>${sectionIcon(s.rows)} ${cell(name)} — ${s.rows.length} tests</summary>`, '');
			for (const r of s.rows) {
				const note = r.status === 'skipped' && r.skipReason ? ` — skipped: ${cell(r.skipReason, 150)}` : '';
				lines.push(`- ${testIcon(r)} ${cell(r.title)} (${secs(r.duration)})${note}`);
			}
			lines.push('', '</details>', '');
		}

		const judged = all.flatMap((r) => r.annotations.filter((a) => a.type === 'ollama-pass').map((a) => ({ ...a, r })));
		if (judged.length) {
			lines.push('<details><summary>Visual judge: passed checks</summary>', '', '| Section | Test | Question → reason |', '|---|---|---|');
			for (const j of judged) lines.push(`| ${cell(j.r.section)} | ${cell(j.r.title)} | ${cell(j.description)} |`);
			lines.push('', '</details>', '');
		}

		fs.mkdirSync(path.dirname(OUT), { recursive: true });
		fs.writeFileSync(OUT, lines.join('\n'));
		console.log(`\n[e2e] ${failed.length ? 'FAILED' : !passed.length ? 'NOTHING RAN' : warningCount ? 'PASSED with warnings' : 'PASSED'}: ${totals}`);
		for (const f of failed) console.log(`  ✘ ${f.section} › ${f.title}  (${f.where})`);
		for (const { r, w } of warned) for (const x of w) console.log(`  ! ${r.section} › ${r.title}: ${x.kind} — ${strip(x.text).slice(0, 160)}`);
		for (const l of logErrors) console.log(`  ! debug.log (${l.count}×): ${l.message.slice(0, 160)}`);
		console.log(`[e2e] Report: ${path.relative(process.cwd(), OUT)}`);
	}

	printsToStdio() { return false; }
}

module.exports = OllamaReporter;
