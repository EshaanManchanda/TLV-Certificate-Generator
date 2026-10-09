// @ts-check
const { defineConfig } = require('@playwright/test');

// Automates doc/dev/MANUAL-TEST-CHECKLIST.md — one spec per section (tests/E2E/NN-*.spec.js).
// Runs against a real local site (default: Local's gema-ecosystem.local). It
// seeds and deletes E2E-tagged data via WP-CLI and temporarily routes mail to
// Mailpit, so never point CG_E2E_BASE_URL at a shared or production site.
// See doc/dev/TESTING.md §4 for env vars (Ollama, Mailpit, PHP paths).
module.exports = defineConfig({
	testDir: './tests/E2E',
	testMatch: /\d\d-.*\.spec\.js$/,
	timeout: 180_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	workers: 1,
	retries: 0,
	globalSetup: require.resolve('./tests/E2E/support/global-setup.js'),
	globalTeardown: require.resolve('./tests/E2E/support/global-teardown.js'),
	reporter: [
		['list'],
		['html', { open: 'never' }],
		['./tests/E2E/support/ollama-reporter.js'],
	],
	use: {
		baseURL: process.env.CG_E2E_BASE_URL || 'http://gema-ecosystem.local',
		storageState: 'playwright/.auth/admin.json',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		acceptDownloads: true,
		// Local by Flywheel sites use a self-signed local CA by default.
		ignoreHTTPSErrors: true,
	},
});
