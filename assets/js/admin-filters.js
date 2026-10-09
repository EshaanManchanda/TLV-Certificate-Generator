/**
 * Bulk Send page — filters, live preview, queue status.
 * Certificate Generator Plugin
 */

(function($) {
    'use strict';

    const fmt = (n) => Number(n || 0).toLocaleString();
    const plural = (n, one, many) => fmt(n) + ' ' + (Number(n) === 1 ? one : many);

    class CertFilterManager {
        constructor() {
            this.filters = this.defaultFilters();
            this.previewData = [];
            this.previewOffset = 0;
            this.previewLimit = 100;
            this.total = 0;
            this.updateTimer = null;
            this.request = null;
            this.lastStats = null;
            this.init();
        }

        defaultFilters() {
            return {
                post_types: ['students', 'teachers', 'schools'],
                schools: [],
                events: [],
                certificate_types: [],
                sources: [],
                year: [],
                date_from: '',
                date_to: '',
                email_status: ['not_sent', 'no_email'],
                emails: [],
                email_search: '',
                skip_already_sent: true
            };
        }

        init() {
            this.bindEvents();
            this.loadFilterOptions();
            this.readForm();
            this.updatePreview();
        }

        // Single source of truth: rebuild the filter object from the form.
        readForm() {
            const checked = (name) => $(`input[name="${name}[]"]:checked`).map((i, el) => el.value).get();
            const f = this.filters;

            f.post_types = checked('post_types');
            f.email_status = checked('email_status');
            // Unticking "Already sent" also tells the queue to skip records already emailed.
            f.skip_already_sent = f.email_status.indexOf('sent') === -1;
            f.schools = $('#cert-filter-schools').val() || [];
            f.events = $('#cert-filter-events').val() || [];
            f.certificate_types = $('#cert-filter-certificate-types').val() || [];
            f.sources = $('#cert-filter-sources').val() || [];
            f.year = $('#cert-filter-year').val() || [];
            f.date_from = $('#cert-filter-date-from').val() || '';
            f.date_to = $('#cert-filter-date-to').val() || '';
            f.email_search = ($('#cert-filter-email-search').val() || '').trim();
            f.emails = this.parseEmailList($('#cert-filter-email-list').val());

            const listText = ($('#cert-filter-email-list').val() || '').trim();
            $('#cg-bs-email-list-note').text(listText ? plural(f.emails.length, 'valid email', 'valid emails') + ' recognised' : '');

            const more = [f.year.length, f.sources.length, f.date_from || f.date_to, f.email_search, f.emails.length]
                .filter(Boolean).length;
            $('#cg-bs-more-count').text(more).prop('hidden', more === 0);
        }

        bindEvents() {
            const self = this;

            $('#bulk-send-form').on('change input', 'input, select, textarea', function() {
                self.readForm();
                self.debouncedUpdate();
            });

            $('#cert-clear-filters').on('click', function(e) {
                e.preventDefault();
                self.clearFilters();
            });

            $(document).on('click', '.cert-load-more-btn', function(e) {
                e.preventDefault();
                self.loadMorePreview();
            });

            $('#cert-export-preview').on('click', function(e) {
                e.preventDefault();
                self.exportAll();
            });

            $('#cert-start-bulk-send').on('click', function(e) {
                e.preventDefault();
                self.startBulkSend();
            });
        }

        loadFilterOptions() {
            const lists = {
                schools: '#cert-filter-schools',
                events: '#cert-filter-events',
                certificate_types: '#cert-filter-certificate-types',
                years: '#cert-filter-year',
                sources: '#cert-filter-sources'
            };
            $.each(lists, (type, selector) => {
                $.post(certFilterAjax.ajaxurl, {
                    action: 'cert_get_filter_options',
                    nonce: certFilterAjax.nonce,
                    option_type: type
                }, (response) => {
                    if (response && response.success) {
                        this.populateSelect(selector, response.data);
                    }
                });
            });
        }

        populateSelect(selector, options) {
            const $select = $(selector).empty();
            (options || []).forEach(function(option) {
                const isObj = option && typeof option === 'object';
                $select.append($('<option>', {
                    value: isObj ? option.value : option,
                    text: isObj ? option.label : option
                }));
            });
            if (!$select.children().length) {
                $select.append($('<option>', { disabled: true, text: 'None yet' }));
            }
        }

        debouncedUpdate() {
            clearTimeout(this.updateTimer);
            this.updateTimer = setTimeout(() => this.updatePreview(), 400);
        }

        fetchPage(offset, limit) {
            return $.post(certFilterAjax.ajaxurl, {
                action: 'cert_preview_recipients',
                nonce: certFilterAjax.nonce,
                filters: this.filters,
                limit: limit,
                offset: offset
            });
        }

        updatePreview() {
            const self = this;
            const $content = $('.cert-preview-content');

            if (this.request) {
                this.request.abort();
            }

            // jQuery drops empty arrays from POST, and the server then defaults to "everyone" —
            // so nothing ticked must mean nothing matched, decided here.
            if (!this.filters.post_types.length || !this.filters.email_status.length) {
                this.previewData = [];
                this.renderPreview({ recipients: [], statistics: {} });
                return;
            }

            $content.attr('aria-busy', 'true').addClass('is-loading');
            if (!this.lastStats) {
                $content.html('<div class="cert-loading">Loading preview…</div>');
            }

            this.previewOffset = 0;
            this.request = this.fetchPage(0, this.previewLimit)
                .done(function(response) {
                    if (response && response.success) {
                        self.previewData = response.data.recipients || [];
                        self.renderPreview(response.data);
                    } else {
                        self.showError((response && response.data && response.data.message) || 'Could not load the preview.');
                    }
                })
                .fail(function(xhr, status) {
                    if (status !== 'abort') {
                        self.showError('Could not load the preview. Please refresh the page and try again.');
                    }
                })
                .always(function() {
                    $content.removeAttr('aria-busy').removeClass('is-loading');
                });
        }

        showError(message) {
            this.lastStats = null;
            $('.cert-preview-content').html(`<div class="cert-error-message">${this.escapeHtml(message)}</div>`);
            this.renderSendBar(null);
        }

        loadMorePreview() {
            const self = this;
            const $more = $('.cert-load-more');
            $more.find('button').prop('disabled', true).text('Loading…');

            this.fetchPage(this.previewOffset + this.previewLimit, this.previewLimit).done(function(response) {
                if (!response || !response.success) {
                    $more.find('button').prop('disabled', false).text('Try again');
                    return;
                }
                self.previewOffset += self.previewLimit;
                const rows = response.data.recipients || [];
                self.previewData = self.previewData.concat(rows);
                $('#cert-preview-tbody').append(rows.map((r) => self.renderPreviewRow(r)).join(''));
                self.renderShowing();
            });
        }

        statTile(label, value, tone, hint) {
            return `<div class="cert-stat-box${tone ? ' is-' + tone : ''}">
                        <div class="cert-stat-value">${fmt(value)}</div>
                        <div class="cert-stat-label">${label}</div>
                        ${hint ? `<div class="cert-stat-hint">${hint}</div>` : ''}
                    </div>`;
        }

        renderPreview(data) {
            const self = this;
            const stats = data.statistics || {};
            const recipients = data.recipients || [];

            this.lastStats = stats;
            this.total = Number(stats.total_certificates) || 0;

            let html = '<div class="cert-preview-stats">';
            html += this.statTile('Certificates matched', stats.total_certificates, 'primary');
            html += this.statTile('Emails to send', stats.unique_emails, 'good',
                stats.grouped_sends ? plural(stats.grouped_sends, 'person gets', 'people get') + ' several in one email' : 'one per address');
            html += this.statTile('No email — skipped', stats.no_email, stats.no_email ? 'warn' : '');
            html += this.statTile('Already emailed', stats.already_sent, '',
                stats.already_sent ? 'will be sent again' : '');
            // Dry-run warnings: only shown when there is something to fix.
            if (stats.invalid_email) {
                html += this.statTile('Invalid email', stats.invalid_email, 'warn', 'will not be sent — fix the address');
            }
            if (stats.duplicate_rows) {
                html += this.statTile('Duplicate rows', stats.duplicate_rows, 'warn', 'same person, type and date listed twice');
            }
            if (stats.long_names) {
                html += this.statTile('Names too long', stats.long_names, 'warn', 'printed small and on two lines — widen the field in the template');
            }
            html += '</div>';

            if (recipients.length) {
                html += '<p class="cg-bs-showing" id="cg-bs-showing"></p>';
                html += '<div class="cert-preview-table-wrapper"><table class="cert-preview-table">';
                html += `<thead><tr>
                            <th scope="col">Name</th>
                            <th scope="col">Email</th>
                            <th scope="col">Type</th>
                            <th scope="col">Certificate</th>
                            <th scope="col">School</th>
                            <th scope="col">Status</th>
                         </tr></thead>`;
                html += '<tbody id="cert-preview-tbody">' + recipients.map((r) => self.renderPreviewRow(r)).join('') + '</tbody>';
                html += '</table></div>';
                html += '<div class="cert-load-more"><button type="button" class="button cert-load-more-btn">Load more</button></div>';
            } else {
                html += `<div class="cert-empty-state">
                            <div class="cert-empty-state-text"><strong>No certificates match these filters.</strong><br>Try ticking more recipients or email statuses, or press Reset.</div>
                         </div>`;
            }

            $('.cert-preview-content').html(html);
            this.renderShowing();
            this.renderSendBar(stats);
        }

        renderShowing() {
            const shown = this.previewData.length;
            $('#cg-bs-showing').text(`Showing ${fmt(shown)} of ${plural(this.total, 'certificate', 'certificates')}`);
            $('.cert-load-more').toggle(shown < this.total);
        }

        renderSendBar(stats) {
            const $btn = $('#cert-start-bulk-send');
            const $text = $('#cg-bs-send-summary');

            if (!stats || !stats.unique_emails) {
                $btn.prop('disabled', true).text('Queue emails');
                $text.text(stats ? 'Nothing to send — no matching certificate has an email address.' : '');
                return;
            }

            $btn.prop('disabled', false).text('Queue ' + plural(stats.unique_emails, 'email', 'emails'));
            let summary = `${plural(stats.unique_emails, 'email', 'emails')} carrying ${plural(stats.with_email, 'certificate', 'certificates')}.`;
            if (stats.no_email) {
                summary += ` ${fmt(stats.no_email)} without an email will be skipped.`;
            }
            $text.text(summary);
        }

        renderPreviewRow(r) {
            const type = { students: 'Student', teachers: 'Teacher', schools: 'School' }[r.post_type] || '-';
            return `<tr>
                        <td>${this.escapeHtml(r.name || '-')}</td>
                        <td class="cg-bs-email">${r.email ? this.escapeHtml(r.email) : '<span class="cg-bs-muted">—</span>'}</td>
                        <td>${type}</td>
                        <td>${this.escapeHtml(r.certificate_type || '-')}</td>
                        <td>${this.escapeHtml(r.school_name || '-')}</td>
                        <td>${this.getStatusBadge(r)}</td>
                    </tr>`;
        }

        statusLabel(r) {
            if (!r.email) return 'No email';
            return r.email_status === 'sent' ? 'Sent' : 'Not sent';
        }

        getStatusBadge(r) {
            if (!r.email) {
                return '<span class="cert-status-badge no-email">No email</span>';
            }
            if (r.email_status === 'sent') {
                const when = r.last_sent ? ` title="Last sent ${this.escapeHtml(r.last_sent)}"` : '';
                return `<span class="cert-status-badge sent"${when}>Sent</span>`;
            }
            return '<span class="cert-status-badge pending">Not sent</span>';
        }

        parseEmailList(text) {
            if (!text) return [];
            const seen = {};
            return text.split(/[\s,;]+/)
                .map((e) => e.trim())
                .filter((e) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e) && !seen[e] && (seen[e] = true));
        }

        clearFilters() {
            const form = document.getElementById('bulk-send-form');
            form.reset(); // restores the checkbox defaults written in the markup
            $(form).find('select').val([]);
            this.readForm();
            this.updatePreview();
        }

        // Export every matching row, not just the rows loaded into the table.
        exportAll() {
            const self = this;
            const $btn = $('#cert-export-preview');
            const pageSize = 1000;
            let rows = [];

            if (!this.total) {
                alert('Nothing to export — no certificates match these filters.');
                return;
            }
            $btn.prop('disabled', true).text('Exporting…');

            const next = (offset) => {
                self.fetchPage(offset, pageSize).done(function(response) {
                    const batch = (response && response.success && response.data.recipients) || [];
                    rows = rows.concat(batch);
                    if (batch.length === pageSize) {
                        next(offset + pageSize);
                    } else {
                        self.downloadCsv(rows);
                        $btn.prop('disabled', false).text('Export CSV');
                    }
                }).fail(function() {
                    alert('Export failed. Please try again.');
                    $btn.prop('disabled', false).text('Export CSV');
                });
            };
            next(0);
        }

        downloadCsv(rows) {
            const cell = (v) => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
            const lines = [['Name', 'Email', 'School', 'Certificate Type', 'Recipient Type', 'Status', 'Last Sent'].map(cell).join(',')];
            rows.forEach((r) => {
                lines.push([r.name, r.email, r.school_name, r.certificate_type, r.post_type, this.statusLabel(r), r.last_sent].map(cell).join(','));
            });

            const blob = new Blob(['﻿' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'certificate-recipients-' + new Date().toISOString().slice(0, 10) + '.csv';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        }

        startBulkSend() {
            const self = this;
            const labels = certFilterAjax.i18n || {};
            const stats = this.lastStats || {};

            let confirmMsg = `Queue ${plural(stats.unique_emails, 'email', 'emails')} (${plural(stats.with_email, 'certificate', 'certificates')})?`;
            if (stats.no_email) {
                confirmMsg += `\n${fmt(stats.no_email)} without an email address will be skipped.`;
            }
            if (stats.already_sent) {
                confirmMsg += `\n${fmt(stats.already_sent)} already received their certificate and will get it again.`;
            }
            if (stats.invalid_email) {
                confirmMsg += `\n${fmt(stats.invalid_email)} have an invalid email address and will be skipped.`;
            }
            if (stats.duplicate_rows) {
                confirmMsg += `\n${fmt(stats.duplicate_rows)} duplicate rows (same person, type and date).`;
            }
            if (stats.long_names) {
                confirmMsg += `\n${fmt(stats.long_names)} names are too long for their field and will wrap.`;
            }
            CGUI.confirm({ title: 'Queue certificate emails?', message: confirmMsg, confirmLabel: 'Queue emails' })
                .then(function(ok) { if (ok) self.queueBulkSend(); });
        }

        queueBulkSend() {
            const self = this;
            const labels = certFilterAjax.i18n || {};
            const $button = $('#cert-start-bulk-send');
            $button.prop('disabled', true).text(labels.starting || 'Starting…');

            $.post(certFilterAjax.ajaxurl, {
                action: 'cert_send_to_filtered',
                nonce: certFilterAjax.nonce,
                filters: this.filters
            }).done(function(response) {
                const data = (response && response.data) || {};
                if (response && response.success) {
                    self.flash('success', (data.message || 'Emails queued.') + ' Sending continues in the background.');
                    window.cgBulkSendQueueRefresh && window.cgBulkSendQueueRefresh();
                    self.updatePreview();
                } else {
                    self.flash('error', data.message || labels.error_generic || 'An unexpected error occurred.');
                    self.renderSendBar(self.lastStats);
                }
            }).fail(function() {
                self.flash('error', labels.error_send || 'Failed to start bulk send. Please try again.');
                self.renderSendBar(self.lastStats);
            });
        }

        flash(type, message) {
            $('#bulk-send-result').html(
                `<div class="notice notice-${type === 'success' ? 'success' : 'error'} inline"><p>${this.escapeHtml(message)}</p></div>`
            );
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

    // ── Queue + sending-rate cards ─────────────────────────────────────────────
    function initQueueCards() {
        if (!$('#cg-bs-queue').length) return;

        let timer = null;

        const render = (p) => {
            const s = p.stats || {};
            const rate = p.rate_status || {};
            const usage = rate.usage || {};
            const limit = (rate.limits && rate.limits.emails_per_hour) || usage.hourly_limit || 0;
            const can = rate.can_send || {};
            const waiting = Number(s.pending || 0) + Number(s.sending || 0);

            ['pending', 'sending', 'sent', 'failed'].forEach((k) => $(`[data-q="${k}"]`).text(fmt(s[k])));

            const state = p.is_paused ? ['Paused', 'warn'] : (waiting ? ['Sending', 'good'] : ['Idle', '']);
            $('[data-q="state"]').text(state[0]).attr('class', 'cg-bs-pill' + (state[1] ? ' is-' + state[1] : ''));

            let eta = '';
            if (waiting && p.is_paused) {
                eta = `${plural(waiting, 'email is', 'emails are')} waiting. Resume the queue to continue.`;
            } else if (waiting) {
                eta = `${plural(waiting, 'email', 'emails')} left` + (p.eta_human ? ` — about ${p.eta_human} to finish.` : '.');
            } else {
                eta = 'Nothing waiting to be sent.';
            }
            $('[data-q="eta"]').text(eta);

            $('#pause-queue').prop('disabled', !!p.is_paused);
            $('#resume-queue').prop('disabled', !p.is_paused);
            $('#clear-queue').prop('disabled', !Number(s.pending)).text(
                Number(s.pending) ? `Clear ${plural(s.pending, 'waiting email', 'waiting emails')}` : 'Clear waiting emails'
            );

            const used = Number(usage.last_hour || 0);
            const pct = limit ? Math.min(100, Math.round(used / limit * 100)) : 0;
            $('[data-r="used"]').text(fmt(used));
            $('[data-r="limit"]').text(fmt(limit));
            $('[data-r="bar"]').css('width', pct + '%').toggleClass('is-full', pct >= 90);
            $('.cg-bs-meter').attr({ 'aria-valuenow': used, 'aria-valuemax': limit });
            const canSend = !!can.can_send;
            $('[data-r="state"]').text(canSend ? 'Ready' : 'Limit reached').attr('class', 'cg-bs-pill ' + (canSend ? 'is-good' : 'is-warn'));
            $('[data-r="note"]').text(canSend
                ? `${plural(usage.hourly_remaining, 'more email', 'more emails')} can go out this hour.`
                : (can.reason || 'Hourly limit reached.') + (can.wait_seconds ? ` Next send in about ${Math.ceil(can.wait_seconds / 60)} min.` : ''));

            // Poll only while something is actually being sent.
            clearTimeout(timer);
            if (waiting && !p.is_paused) {
                timer = setTimeout(refresh, 15000);
            }
        };

        const refresh = () => {
            $.post(certFilterAjax.ajaxurl, { action: 'cert_get_queue_progress', nonce: certFilterAjax.nonce }, (r) => {
                if (r && r.success) render(r.data);
            });
        };

        const action = (name, done) => {
            $.post(certFilterAjax.ajaxurl, { action: name, nonce: certFilterAjax.nonce }, (r) => {
                if (done) done(r);
                refresh();
            }).fail(refresh);
        };

        $('#pause-queue').on('click', () => action('cert_pause_queue'));
        $('#resume-queue').on('click', () => action('cert_resume_queue'));
        $('#refresh-status').on('click', refresh);
        $('#clear-queue').on('click', function() {
            CGUI.confirm({
                title: 'Clear the email queue?',
                message: 'Remove all emails that are still waiting to be sent? Emails already sent are not affected.',
                confirmLabel: 'Clear queue',
                danger: true
            }).then(function(ok) { if (ok) action('cert_clear_queue'); });
        });

        window.cgBulkSendQueueRefresh = refresh;
        render(window.cgBulkSendQueue || {});
    }

    $(function() {
        if ($('.cert-filter-panel').length) {
            window.certFilterManager = new CertFilterManager();
        }
        initQueueCards();
    });

})(jQuery);
