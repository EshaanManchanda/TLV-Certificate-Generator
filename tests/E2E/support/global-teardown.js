// @ts-check
// Restores snapshotted options and removes all E2E data.
// CG_E2E_KEEP_DATA=1 keeps the data (options are still restored).
const fs = require('fs');
const wp = require('./wp');
const { SNAPSHOT } = require('./global-setup');

module.exports = async () => {
	if (fs.existsSync(SNAPSHOT)) {
		const snap = JSON.parse(fs.readFileSync(SNAPSHOT, 'utf8'));
		wp.wpEval(`foreach (${wp.php(snap)} as $n => $v) { if ($v === '__cg_e2e_absent__') delete_option($n); else update_option($n, $v); } echo 1;`);
		fs.rmSync(SNAPSHOT);
	}
	if (process.env.CG_E2E_KEEP_DATA === '1') {
		console.log('[e2e] CG_E2E_KEEP_DATA=1 — leaving E2E rows in place.');
		return;
	}
	console.log('[e2e] cleanup:', JSON.stringify(wp.cleanupE2E()));
};
