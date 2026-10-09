// @ts-check
// The checklist's standard scenario, seeded in one WP-CLI call: four templates
// (Participation Jan + Mar with different backgrounds, Winner, Mentor) and the
// six people from the data table. Idempotent per run — reuses existing rows.
const wp = require('./wp');
const { PEOPLE } = require('./data');

const TEMPLATES = {
	partJan: { template_name: 'E2E Participation Jan', certificate_type: 'E2E Participation', event_date: '2026-01-10', bg: 'classic-gold.jpg' },
	partMar: { template_name: 'E2E Participation Mar', certificate_type: 'E2E Participation', event_date: '2026-03-15', bg: 'modern-blue.jpg' },
	winner: { template_name: 'E2E Winner', certificate_type: 'E2E Winner', event_date: '2026-01-10', bg: 'modern-blue.jpg' },
	mentor: { template_name: 'E2E Mentor', certificate_type: 'E2E Mentor', event_date: '2026-01-10', bg: 'classic-gold.jpg', entity_type: 'teachers' },
	school: { template_name: 'E2E School Participation', certificate_type: 'E2E Participation', event_date: '2026-01-10', bg: 'classic-gold.jpg', entity_type: 'schools', status: 'draft' },
};

let cache;

/** @returns {{templates: Record<string, number>, people: Record<string, number>}} */
function seedScenario() {
	if (cache) return cache;
	const existing = wp.sql("SELECT id, template_name FROM {p}cg_certificate_templates WHERE template_name LIKE 'E2E %'");
	const templates = {};
	for (const [key, t] of Object.entries(TEMPLATES)) {
		const found = existing.find((r) => r.template_name === t.template_name);
		const { bg, ...rest } = t;
		templates[key] = found ? Number(found.id) : wp.seedTemplate({ ...rest, template_url: wp.bundledBg(bg) });
	}
	const people = {};
	for (const [key, p] of Object.entries(PEOPLE)) {
		const { type, ...row } = p;
		const { table, name } = wp.ENTITY[type];
		const found = wp.sql(`SELECT id FROM {p}${table} WHERE ${name} = %s AND issue_date = %s AND certificate_type = %s`, row.name, row.issue_date, row.certificate_type);
		people[key] = found.length ? Number(found[0].id) : wp.seedPerson(type, row);
	}
	cache = { templates, people };
	return cache;
}

/** Entity row by id. */
const row = (type, id) => wp.sql(`SELECT * FROM {p}${wp.ENTITY[type].table} WHERE id = %d`, id)[0];

module.exports = { seedScenario, row, TEMPLATES };
