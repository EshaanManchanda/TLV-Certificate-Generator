// @ts-check
// §17 Non-Latin names — Hindi, Tamil, Urdu and mixed names render shaped (conjuncts, joined Arabic) via mPDF.
const fs = require('fs');
const { test, expect } = require('./support/fixtures');
const wp = require('./support/wp');
const { pdfToPng } = require('./support/pdf');
const { judge } = require('./support/ollama');

const TYPE = 'E2E Unicode Names';
const NAMES = ['श्रीमती क्षत्रिय', 'தமிழ்செல்வி', 'محمد علی خان', 'Ravi रवि Kumar'];

test.describe('§17 Non-Latin names', () => {
	test.afterAll(() => {
		wp.sql('DELETE FROM {p}cg_students WHERE certificate_type = %s', TYPE);
		wp.sql('DELETE FROM {p}cg_certificate_templates WHERE certificate_type = %s', TYPE);
	});

	test('each name renders as shaped text on the certificate', async ({ context }, testInfo) => {
		wp.seedTemplate({
			template_name: TYPE, certificate_type: TYPE, template_url: wp.bundledBg('classic-gold.jpg'),
			// The name sits above the background's own caption (printed across the middle at ~100 mm).
			fields: [{ name: 'student_name', x: 148.5, y: 85 }, { name: 'school_name', x: 148.5, y: 125 }],
		});
		for (const name of NAMES) {
			const id = wp.seedPerson('students', { name, email: `u${NAMES.indexOf(name)}@example.test`, school: 'विद्या भवन', certificate_type: TYPE, issue_date: '2026-01-15' });
			const path = String(wp.wpEval(`$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cg_students WHERE id = %d", ${Number(id)}), ARRAY_A); echo certificate_generator_generate_pdf_from_row($row);`));
			expect(path, `PDF for ${name}`).toMatch(/\.pdf$/);
			const pdf = fs.readFileSync(path);
			expect(pdf.toString('latin1'), 'drawn by mPDF with a shaping font').toMatch(/\/BaseFont \/MPDFA[A-Z]\+FreeSerif/);

			const png = await pdfToPng(context, pdf);
			fs.writeFileSync(testInfo.outputPath(`name-${NAMES.indexOf(name)}.png`), png);
			await judge(testInfo, png, `Is the large name in the middle of this certificate written as "${name}" with properly formed letters (joined conjuncts / connected Arabic letters, no empty boxes or question marks)?`);
		}
	});
});
