/**
 * Certificate Generator — shared admin UI helpers (window.CGUI).
 * Markup counterparts live in includes/Admin/ui.php.
 */
(function () {
	'use strict';

	var i18n = (window.cgUiL10n || {});
	function t(key, fallback) { return i18n[key] || fallback; }
	function $(el) { return typeof el === 'string' ? document.querySelector(el) : (el && el.jquery ? el[0] : el); }

	var CGUI = {};

	/** Update a .cg-progress block (or its <progress>). */
	CGUI.progress = function (el, done, total, text) {
		el = $(el);
		if (!el) { return; }
		var wrap = el.classList.contains('cg-progress') ? el : el.closest('.cg-progress');
		var bar = wrap ? wrap.querySelector('progress') : el;
		total = Math.max(0, total | 0);
		done = Math.min(total, Math.max(0, done | 0));
		var pct = total ? Math.round(done / total * 100) : 0;
		if (wrap) { wrap.hidden = false; wrap.classList.remove('is-error'); }
		if (total) {
			bar.max = total;
			bar.value = done;
			bar.setAttribute('aria-valuetext', done + ' / ' + total + ' (' + pct + '%)');
		} else {
			// Unknown total: indeterminate bar.
			bar.removeAttribute('value');
			bar.removeAttribute('aria-valuetext');
		}
		if (!wrap) { return; }
		var label = wrap.querySelector('.cg-progress__text');
		var count = wrap.querySelector('.cg-progress__count');
		if (label && text !== undefined) { label.textContent = text; }
		if (count) { count.textContent = total ? done + ' / ' + total + ' · ' + pct + '%' : ''; }
		wrap.classList.toggle('is-done', total > 0 && done >= total);
	};

	CGUI.progressError = function (el, text) {
		var wrap = $(el);
		if (!wrap) { return; }
		wrap.classList.add('is-error');
		var bar = wrap.querySelector('progress');
		if (bar && !bar.hasAttribute('value')) { bar.max = 1; bar.value = 1; } // Stop the indeterminate stripe.
		var label = wrap.querySelector('.cg-progress__text');
		if (label && text) { label.textContent = text; }
	};

	/** Mark a button busy (spinner + disabled) or restore it. */
	CGUI.busy = function (btn, on) {
		btn = $(btn);
		if (!btn) { return; }
		btn.classList.toggle('is-busy', !!on);
		btn.disabled = !!on;
		if (on) { btn.setAttribute('aria-busy', 'true'); } else { btn.removeAttribute('aria-busy'); }
	};

	/** Open/close a <dialog class="cg-dialog"> by id. */
	CGUI.modal = function (id) {
		var d = document.getElementById(id);
		return {
			open: function () { if (d && !d.open) { d.showModal(); } },
			close: function () { if (d && d.open) { d.close(); } },
			el: d
		};
	};

	var confirmDialog;
	function buildConfirm() {
		confirmDialog = document.createElement('dialog');
		confirmDialog.className = 'cg-dialog';
		confirmDialog.innerHTML =
			'<form method="dialog">' +
			'<div class="cg-dialog__head"><h2></h2></div>' +
			'<div class="cg-dialog__body"><p></p></div>' +
			'<div class="cg-dialog__actions">' +
			'<button class="button" value="cancel"></button>' +
			'<button class="button button-primary" value="ok"></button>' +
			'</div></form>';
		document.body.appendChild(confirmDialog);
	}

	/**
	 * Promise-based confirm. opts: {title, message, confirmLabel, cancelLabel, danger}
	 * Falls back to window.confirm() where <dialog> is unsupported.
	 */
	CGUI.confirm = function (opts) {
		opts = typeof opts === 'string' ? { message: opts } : (opts || {});
		if (typeof HTMLDialogElement === 'undefined') {
			return Promise.resolve(window.confirm(opts.message));
		}
		if (!confirmDialog) { buildConfirm(); }
		var d = confirmDialog;
		var ok = d.querySelector('button[value="ok"]');
		d.querySelector('h2').textContent = opts.title || t('confirmTitle', 'Are you sure?');
		d.querySelector('p').textContent = opts.message || '';
		d.querySelector('button[value="cancel"]').textContent = opts.cancelLabel || t('cancel', 'Cancel');
		ok.textContent = opts.confirmLabel || t('confirm', 'Confirm');
		ok.classList.toggle('button-link-delete', !!opts.danger);
		d.returnValue = '';
		return new Promise(function (resolve) {
			d.addEventListener('close', function onClose() {
				d.removeEventListener('close', onClose);
				resolve(d.returnValue === 'ok');
			});
			d.showModal();
			ok.focus();
		});
	};

	/** Render a WP-style notice into a container (prepends). */
	CGUI.notice = function (type, msg, container) {
		container = $(container) || document.querySelector('.wrap');
		if (!container) { return null; }
		var n = document.createElement('div');
		n.className = 'notice notice-' + (type || 'info') + ' is-dismissible';
		var p = document.createElement('p');
		p.textContent = msg;
		n.appendChild(p);
		var x = document.createElement('button');
		x.type = 'button';
		x.className = 'notice-dismiss';
		x.innerHTML = '<span class="screen-reader-text">' + t('dismiss', 'Dismiss this notice.') + '</span>';
		x.addEventListener('click', function () { n.remove(); });
		n.appendChild(x);
		var h = container.querySelector('.cg-page-header');
		container.insertBefore(n, h ? h.nextSibling : container.firstChild);
		return n;
	};

	/*
	 * Declarative confirm: <a|button|form data-cg-confirm="Message" [data-cg-confirm-label="Delete"] [data-cg-danger]>
	 * Replays the original action once confirmed.
	 */
	document.addEventListener('click', function (e) {
		var el = e.target.closest('[data-cg-confirm]');
		if (!el || el.tagName === 'FORM' || el.dataset.cgConfirmed) { return; }
		e.preventDefault();
		e.stopImmediatePropagation();
		ask(el).then(function (yes) {
			if (!yes) { return; }
			el.dataset.cgConfirmed = '1';
			el.click();
			delete el.dataset.cgConfirmed;
		});
	}, true);

	document.addEventListener('submit', function (e) {
		var form = e.target;
		if (!form.matches || !form.matches('form[data-cg-confirm]') || form.dataset.cgConfirmed) { return; }
		e.preventDefault();
		var submitter = e.submitter;
		ask(form).then(function (yes) {
			if (!yes) { return; }
			form.dataset.cgConfirmed = '1';
			if (form.requestSubmit) { form.requestSubmit(submitter || undefined); } else { form.submit(); }
			delete form.dataset.cgConfirmed;
		});
	}, true);

	/*
	 * <form data-cg-busy>: mark the submit button busy once the submit really goes ahead,
	 * so long imports/exports can't be double-submitted. Deferred so the button's own
	 * name/value is still part of the POST (disabled controls aren't submitted).
	 */
	document.addEventListener('submit', function (e) {
		var form = e.target;
		if (e.defaultPrevented || !form.matches || !form.matches('form[data-cg-busy]')) { return; }
		var btn = e.submitter || form.querySelector('[type=submit]');
		if (btn) { setTimeout(function () { CGUI.busy(btn, true); }, 0); }
	});
	// Back/forward cache restores the page with the button still busy.
	window.addEventListener('pageshow', function (e) {
		if (!e.persisted) { return; }
		Array.prototype.forEach.call(document.querySelectorAll('form[data-cg-busy] .is-busy'), function (b) { CGUI.busy(b, false); });
	});

	function ask(el) {
		return CGUI.confirm({
			title: el.dataset.cgConfirmTitle,
			message: el.dataset.cgConfirm,
			confirmLabel: el.dataset.cgConfirmLabel,
			danger: el.hasAttribute('data-cg-danger')
		});
	}

	// Close buttons inside kit dialogs + click on backdrop.
	document.addEventListener('click', function (e) {
		var closer = e.target.closest('.cg-dialog [data-cg-close]');
		if (closer) { closer.closest('dialog').close(); return; }
		if (e.target.tagName === 'DIALOG' && e.target.classList.contains('cg-dialog')) { e.target.close(); }
	});

	window.CGUI = CGUI;
})();
