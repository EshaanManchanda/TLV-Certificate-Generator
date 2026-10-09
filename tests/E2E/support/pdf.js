// @ts-check
// PDF helpers: text extraction (deterministic asserts) and page-1 → PNG
// rendering (for the Ollama visual judge). Rendering happens in a throwaway
// Playwright page using pdf.js served from node_modules, so no native canvas.
const fs = require('fs');
const path = require('path');

const PDFJS_DIR = path.dirname(require.resolve('pdfjs-dist/package.json'));

/** Download a PDF with the page's cookies; asserts it really is a PDF. */
async function fetchPdf(request, url) {
	const res = await request.get(url);
	if (!res.ok()) throw new Error(`GET ${url} → ${res.status()}`);
	const buf = await res.body();
	if (buf.subarray(0, 5).toString() !== '%PDF-') throw new Error(`${url} is not a PDF (starts "${buf.subarray(0, 40).toString()}")`);
	return buf;
}

/** All text in the PDF, pages joined by newlines. */
async function pdfText(buf) {
	const pdfjs = await import('pdfjs-dist/legacy/build/pdf.mjs');
	const doc = await pdfjs.getDocument({ data: new Uint8Array(buf), useSystemFonts: true, verbosity: 0 }).promise;
	const pages = [];
	for (let i = 1; i <= doc.numPages; i++) {
		const tc = await (await doc.getPage(i)).getTextContent();
		pages.push(tc.items.map((it) => ('str' in it ? it.str : '')).join(' '));
	}
	await doc.cleanup?.();
	return pages.join('\n').replace(/\s+/g, ' ');
}

/**
 * Render page 1 to PNG. Needs a browser context (use the test's `context`).
 * @returns {Promise<Buffer>}
 */
async function pdfToPng(context, buf, scale = 2) {
	const page = await context.newPage();
	try {
		await page.route('https://pdfjs.e2e/**', (route) => {
			const file = path.join(PDFJS_DIR, new URL(route.request().url()).pathname);
			route.fulfill({ body: fs.readFileSync(file), contentType: 'text/javascript' });
		});
		await page.route('https://pdfjs.e2e.page/', (route) => route.fulfill({ contentType: 'text/html', body: '<body style="margin:0"><canvas id=c></canvas></body>' }));
		await page.goto('https://pdfjs.e2e.page/');
		await page.evaluate(async ({ b64, scale }) => {
			// @ts-ignore runtime ESM import inside the browser
			const pdfjs = await import('https://pdfjs.e2e/build/pdf.min.mjs');
			pdfjs.GlobalWorkerOptions.workerSrc = 'https://pdfjs.e2e/build/pdf.worker.min.mjs';
			const data = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
			const doc = await pdfjs.getDocument({ data }).promise;
			const p = await doc.getPage(1);
			const vp = p.getViewport({ scale });
			const canvas = /** @type {HTMLCanvasElement} */ (document.getElementById('c'));
			canvas.width = vp.width; canvas.height = vp.height;
			await p.render({ canvasContext: canvas.getContext('2d'), viewport: vp, canvas }).promise;
		}, { b64: buf.toString('base64'), scale });
		return await page.locator('#c').screenshot();
	} finally {
		await page.close();
	}
}

module.exports = { fetchPdf, pdfText, pdfToPng };
