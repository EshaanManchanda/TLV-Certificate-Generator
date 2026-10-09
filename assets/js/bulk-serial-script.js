/* Bulk Serials page: one AJAX request fills every missing serial. */
(function () {
	'use strict';
	var btn = document.getElementById('cg-bulk-generate-btn');
	if (!btn) { return; }
	var progress = document.getElementById('cg-bulk-progress');

	function cell(tr, text, code) {
		var td = document.createElement('td');
		if (code) {
			var c = document.createElement('code');
			c.textContent = text;
			td.appendChild(c);
		} else {
			td.textContent = text;
		}
		tr.appendChild(td);
		return td;
	}

	function fail(msg) {
		CGUI.progressError(progress, msg);
		CGUI.notice('error', msg);
		CGUI.busy(btn, false);
	}

	btn.addEventListener('click', function () {
		CGUI.busy(btn, true);
		CGUI.progress(progress, 0, 0, 'Generating serial numbers… this can take a few minutes for large sites.');

		var data = new URLSearchParams({
			action: 'cg_bulk_generate_serials',
			nonce: cgBulkSerial.nonce,
			students: document.getElementById('cg-bulk-students').checked ? 1 : 0,
			teachers: document.getElementById('cg-bulk-teachers').checked ? 1 : 0,
			schools: document.getElementById('cg-bulk-schools').checked ? 1 : 0
		});

		fetch(cgBulkSerial.ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (response) {
				if (!response.success) {
					fail('Error: ' + (response.data || 'Unknown error'));
					return;
				}
				var r = response.data;
				var total = r.success + r.skipped + r.errors;
				CGUI.progress(progress, total, total, 'Done. Generated ' + r.success + ' · skipped ' + r.skipped + ' (already had one) · errors ' + r.errors + '.');
				CGUI.notice(r.errors ? 'warning' : 'success', r.success + ' serial numbers generated.' + (r.errors ? ' ' + r.errors + ' records could not be updated.' : ''));
				CGUI.busy(btn, false);

				if (r.details && r.details.length) {
					var tbody = document.getElementById('cg-bulk-recent-serials');
					tbody.textContent = '';
					r.details.slice(0, 50).forEach(function (d) {
						var tr = document.createElement('tr');
						cell(tr, d.entity_id).className = 'num';
						cell(tr, d.name);
						cell(tr, '—');
						cell(tr, d.serial, true);
						cell(tr, 'Bulk (' + d.post_type + ')');
						cell(tr, d.issued_at);
						tbody.appendChild(tr);
					});
					if (r.details.length > 50) {
						var more = document.createElement('tr');
						var td = cell(more, 'Showing the first 50 of ' + r.details.length + '.');
						td.colSpan = 6;
						td.className = 'cg-hint';
						tbody.appendChild(more);
					}
				}
			})
			.catch(function () { fail('Request failed. Please try again.'); });
	});
})();
