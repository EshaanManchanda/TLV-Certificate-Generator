// @ts-check
// Test data: the fixed table from doc/dev/MANUAL-TEST-CHECKLIST.md (tagged E2E so
// teardown can find it) plus Ollama-generated edge cases with a static fallback.
const { generate } = require('./ollama');

/** Dates are yyyy-mm-dd (DB format); the checklist shows them as dd-mm-yyyy. */
const PEOPLE = {
	ashaJan: { type: 'students', name: 'E2E Asha Multi', email: 'asha@e2e.test', school: 'E2E Green Valley School', certificate_type: 'E2E Participation', issue_date: '2026-01-10' },
	ashaMar: { type: 'students', name: 'E2E Asha Multi', email: 'asha@e2e.test', school: 'E2E Green Valley School', certificate_type: 'E2E Participation', issue_date: '2026-03-15' },
	ben: { type: 'students', name: 'E2E Ben Winner', email: 'ben@e2e.test', school: 'E2E Green Valley School', certificate_type: 'E2E Winner', issue_date: '2026-01-10' },
	tara: { type: 'teachers', name: 'E2E Tara Teacher', email: 'tara@e2e.test', school: 'E2E Green Valley School', certificate_type: 'E2E Mentor', issue_date: '2026-01-10' },
	school: { type: 'schools', name: 'E2E Green Valley School', email: 'school@e2e.test', certificate_type: 'E2E Participation', issue_date: '2026-01-10', city: 'E2E City' },
	noMail: { type: 'students', name: 'E2E No Mail Kid', email: '', school: 'E2E Green Valley School', certificate_type: 'E2E Participation', issue_date: '2026-01-10' },
};

const FALLBACK_EDGE = [
	{ name: 'E2E José Ñúñez', kind: 'accents' },
	{ name: 'E2E Maximiliana Alexandrina Featherstonehaugh-Wolfeschlegelsteinhausen', kind: 'long' },
	{ name: 'E2E <script>alert(1)</script>', kind: 'xss' },
	{ name: "E2E O'Connor-Smith", kind: 'apostrophe' },
	{ name: 'E2E Zoë Ångström', kind: 'accents' },
];

const EDGE_SCHEMA = {
	type: 'object',
	properties: {
		rows: {
			type: 'array',
			items: {
				type: 'object',
				properties: { name: { type: 'string' }, kind: { type: 'string', enum: ['accents', 'long', 'xss', 'apostrophe', 'unicode'] } },
				required: ['name', 'kind'],
			},
		},
	},
	required: ['rows'],
};

let cached;
/**
 * ~8 edge-case recipient names. Always includes the fallback set so the
 * deterministic checks (accents, long, xss) have something to assert on;
 * Ollama adds variety on top. Every name is forced to start with "E2E ".
 */
async function edgeCases() {
	if (cached) return cached;
	const gen = await generate(
		'Generate 6 realistic but tricky person names for testing a certificate PDF generator: '
		+ 'two with Latin accents/diacritics, one very long (60+ chars), one with an apostrophe, '
		+ 'one using non-Latin Unicode, and one HTML injection payload like <img src=x onerror=alert(1)>. '
		+ 'Return JSON {"rows":[{"name":"...","kind":"accents|long|xss|apostrophe|unicode"}]}.',
		EDGE_SCHEMA,
	);
	const extra = (gen?.rows || [])
		.filter((r) => r && typeof r.name === 'string' && r.name.length > 1 && r.name.length < 120)
		.map((r) => ({ name: `E2E ${r.name.replace(/^E2E\s*/, '')}`, kind: r.kind, generated: true }));
	cached = [...FALLBACK_EDGE, ...extra].map((r, i) => ({ ...r, email: `edge${i}@e2e.test` }));
	return cached;
}

/** Build a CSV string from header + rows (quotes fields as needed). */
function csv(header, rows) {
	const q = (v) => (/[",\n]/.test(String(v ?? '')) ? `"${String(v).replace(/"/g, '""')}"` : String(v ?? ''));
	return [header, ...rows].map((r) => r.map(q).join(',')).join('\n') + '\n';
}

module.exports = { PEOPLE, edgeCases, csv };
