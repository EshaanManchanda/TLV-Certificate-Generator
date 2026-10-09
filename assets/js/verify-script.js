jQuery(document).ready(function ($) {
    var S = JSON.parse($('#cg-verify-styles').text());
    var br = S.border_radius + 'px';

    function icon(paths, size) {
        size = size || 18;
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size +
            '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
            'stroke-linecap="round" stroke-linejoin="round">' + paths + '</svg>';
    }

    // Smart privacy display: skip the row entirely for an empty/unset value,
    // rather than showing a blank "Label: N/A" line.
    function detailRow(iconPaths, label, value, accentColor) {
        if (!value) return '';
        accentColor = accentColor || S.btn_start;
        return '<div style="display:flex;align-items:flex-start;margin-bottom:14px;">' +
            '<div style="min-width:24px;color:' + accentColor + ';margin-right:12px;margin-top:1px;">' +
            icon(iconPaths) + '</div>' +
            '<div><span style="font-weight:600;color:' + S.title_color + ';">' + label + ':</span> ' +
            '<span style="color:' + S.text_color + ';font-size:15px;">' + value + '</span></div></div>';
    }

    // SVG path constants
    var ICON_USER = '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>';
    var ICON_AWARD = '<path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>';
    var ICON_HASH = '<line x1="4" y1="9" x2="20" y2="9"></line><line x1="4" y1="15" x2="20" y2="15"></line><line x1="10" y1="3" x2="8" y2="21"></line><line x1="16" y1="3" x2="14" y2="21"></line>';
    var ICON_CALENDAR = '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>';
    var ICON_CLOCK = '<circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline>';
    var ICON_CHECK = '<path d="M22 11.08V12a10 10 0 11-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline>';
    var ICON_ALERT = '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line>';
    var ICON_X = '<circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line>';

    // LinkedIn's documented "Add to Profile" URL: https://www.linkedin.com/help/linkedin/answer/a566688
    function linkedInButton(d, br) {
        if (!d.pdf_url) return ''; // nothing to link to — omit the button rather than send a dead link

        var params = {
            startTask: 'CERTIFICATION_NAME',
            name: d.certificate_type || 'Certificate',
            organizationName: d.issuer_name || '',
            certUrl: d.pdf_url,
            certId: d.serial_number || ''
        };
        if (d.issued_at) {
            var dt = new Date(String(d.issued_at).replace(' ', 'T'));
            if (!isNaN(dt.getTime())) {
                params.issueYear = dt.getFullYear();
                params.issueMonth = dt.getMonth() + 1;
            }
        }
        var qs = Object.keys(params).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }).join('&');

        return '<div style="margin-top:15px;text-align:center;">' +
            '<a href="https://www.linkedin.com/profile/add?' + qs + '" target="_blank" rel="noopener noreferrer" ' +
            'style="display:inline-flex;align-items:center;gap:8px;background:#0a66c2;color:#fff;' +
            'padding:10px 18px;border-radius:' + br + ';text-decoration:none;font-weight:600;font-size:14px;">' +
            icon('<path d="M16 8a6 6 0 016 6v7h-4v-7a2 2 0 00-2-2 2 2 0 00-2 2v7h-4v-7a6 6 0 016-6z"></path><rect x="2" y="9" width="4" height="12"></rect><circle cx="4" cy="4" r="2"></circle>', 16) +
            ' Add to LinkedIn Profile</a></div>';
    }

    function renderValid(r) {
        var d = r.data;
        var html = '<div style="padding:30px;background:#ffffff;border-radius:' + br + ';' +
            'box-shadow:0 10px 40px rgba(0,0,0,0.08);border-top:4px solid #27ae60;">';

        // Header
        html += '<div style="text-align:center;margin-bottom:25px;">' +
            '<div style="color:#27ae60;margin-bottom:10px;">' + icon(ICON_CHECK, 48) + '</div>' +
            '<h3 style="color:' + S.title_color + ';margin:0;font-size:24px;font-weight:700;">Certificate is Valid</h3>' +
            '</div>';

        // Details
        html += '<div style="margin-bottom:10px;">';
        html += detailRow(ICON_USER, 'Name', d.student_name, '#27ae60');
        if (d.certificate_type) {
            html += detailRow(ICON_AWARD, 'Certificate Type', d.certificate_type, '#27ae60');
        }
        html += detailRow(ICON_HASH, 'Serial Number', d.serial_number, '#27ae60');
        html += detailRow(ICON_CALENDAR, 'Issued', d.issued_at, '#27ae60');
        if (d.expires_at) {
            html += detailRow(ICON_CLOCK, 'Expires', d.expires_at, '#27ae60');
        }
        html += '</div>';

        // Verified badge
        html += '<div style="background:linear-gradient(to right,rgba(39,174,96,0.06),rgba(39,174,96,0.02));' +
            'border-radius:' + br + ';padding:15px 20px;border-left:4px solid #27ae60;display:flex;align-items:center;">' +
            '<div style="color:#27ae60;margin-right:10px;">' +
            icon('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><polyline points="9 12 12 15 16 10"></polyline>') +
            '</div><span style="color:#27ae60;font-weight:600;">This certificate has been verified as authentic.</span></div>';

        html += linkedInButton(d, br);

        html += '</div>';
        return html;
    }

    function renderExpired(r) {
        var d = r.data;
        var html = '<div style="padding:30px;background:#ffffff;border-radius:' + br + ';' +
            'box-shadow:0 10px 40px rgba(0,0,0,0.08);border-top:4px solid #e67e22;">';

        html += '<div style="text-align:center;margin-bottom:25px;">' +
            '<div style="color:#e67e22;margin-bottom:10px;">' + icon(ICON_ALERT, 48) + '</div>' +
            '<h3 style="color:' + S.title_color + ';margin:0;font-size:24px;font-weight:700;">Certificate Expired</h3>' +
            '</div>';

        html += '<div style="margin-bottom:10px;">';
        html += detailRow(ICON_USER, 'Name', d.student_name, '#e67e22');
        if (d.certificate_type) {
            html += detailRow(ICON_AWARD, 'Certificate Type', d.certificate_type, '#e67e22');
        }
        html += detailRow(ICON_HASH, 'Serial Number', d.serial_number, '#e67e22');
        html += detailRow(ICON_CALENDAR, 'Issued', d.issued_at, '#e67e22');
        html += detailRow(ICON_CLOCK, 'Expired On', d.expires_at, '#e67e22');
        html += '</div>';

        html += '<div style="background:linear-gradient(to right,rgba(230,126,34,0.06),rgba(230,126,34,0.02));' +
            'border-radius:' + br + ';padding:15px 20px;border-left:4px solid #e67e22;display:flex;align-items:center;">' +
            '<div style="color:#e67e22;margin-right:10px;">' + icon(ICON_ALERT) + '</div>' +
            '<span style="color:#e67e22;font-weight:600;">This certificate was valid but has since expired.</span></div>';

        html += '</div>';
        return html;
    }

    function renderRevoked(r) {
        var d = r.data || {};
        var html = '<div style="padding:30px;background:#ffffff;border-radius:' + br + ';' +
            'box-shadow:0 10px 40px rgba(0,0,0,0.08);border-top:4px solid #e74c3c;">';

        html += '<div style="text-align:center;margin-bottom:25px;">' +
            '<div style="color:#e74c3c;margin-bottom:10px;">' + icon(ICON_X, 48) + '</div>' +
            '<h3 style="color:' + S.title_color + ';margin:0;font-size:24px;font-weight:700;">Certificate Revoked</h3>' +
            '</div>';

        if (d.student_name) {
            html += '<div style="margin-bottom:10px;">' + detailRow(ICON_USER, 'Name', d.student_name, '#e74c3c') + '</div>';
        }

        html += '<div style="background:linear-gradient(to right,rgba(231,76,60,0.06),rgba(231,76,60,0.02));' +
            'border-radius:' + br + ';padding:15px 20px;border-left:4px solid #e74c3c;display:flex;align-items:center;">' +
            '<div style="color:#e74c3c;margin-right:10px;">' + icon(ICON_ALERT) + '</div>' +
            '<span style="color:#e74c3c;font-weight:600;">' + (r.message || 'This certificate has been revoked.') + '</span></div>';

        html += '</div>';
        return html;
    }

    function renderNotFound() {
        var html = '<div style="padding:40px;background:#ffffff;border-radius:' + br + ';' +
            'box-shadow:0 10px 40px rgba(0,0,0,0.08);text-align:center;">';

        html += '<div style="margin-bottom:20px;animation:cgPulse 2s infinite;color:#e74c3c;">' +
            icon(ICON_X, 64) + '</div>';

        html += '<h3 style="color:' + S.title_color + ';font-size:24px;margin:0 0 12px;font-weight:700;">Certificate Not Found</h3>';
        html += '<p style="color:' + S.text_color + ';margin-bottom:25px;line-height:1.6;font-size:16px;">' +
            'We could not find a certificate matching this serial number. Please double-check the number and try again.</p>';

        // Support box
        html += '<div style="background:linear-gradient(to right,rgba(231,76,60,0.05),rgba(231,76,60,0.02));' +
            'border-radius:' + br + ';padding:20px;border-left:4px solid #e74c3c;text-align:left;">' +
            '<p style="color:' + S.title_color + ';font-weight:600;margin:0 0 8px;">Tips:</p>' +
            '<ul style="margin:0;padding-left:20px;color:' + S.text_color + ';line-height:1.8;">' +
            '<li>Check that you typed the serial number exactly as printed (including dashes).</li>' +
            '<li>The serial number is usually near the bottom of the certificate or in the QR code.</li>' +
            '<li>If you still cannot verify, please contact the issuing organization.</li>' +
            '</ul></div>';

        html += '</div>';
        return html;
    }

    function renderError(msg) {
        return '<div style="padding:20px;background:#ffffff;border-radius:' + br + ';' +
            'box-shadow:0 10px 40px rgba(0,0,0,0.08);text-align:center;border-top:4px solid #e74c3c;">' +
            '<div style="color:#e74c3c;margin-bottom:10px;">' + icon(ICON_X, 40) + '</div>' +
            '<h3 style="color:' + S.title_color + ';margin:0 0 8px;font-size:20px;">Verification Failed</h3>' +
            '<p style="color:' + S.text_color + ';margin:0;">' + (msg || 'An unexpected error occurred. Please try again.') + '</p></div>';
    }

    $('#cg-verify-form').on('submit', function (e) {
        e.preventDefault();
        var serial = $('#cg-serial-input').val().trim();
        if (!serial) return;

        $('#cg-verify-result').hide();
        $('#cg-verify-loading').show();
        $('#cg-verify-btn').prop('disabled', true).css({background: '#ccc', cursor: 'not-allowed'});

        $.ajax({
            url: cgVerify.ajaxurl,
            type: 'POST',
            data: {
                action: 'certificate_generator_public_verify',
                nonce: cgVerify.nonce,
                serial_number: serial
            },
            success: function (response) {
                $('#cg-verify-loading').hide();
                $('#cg-verify-btn').prop('disabled', false)
                    .css({
                        background: 'linear-gradient(135deg,' + S.btn_start + ',' + S.btn_end + ')',
                        cursor: 'pointer'
                    });

                var result = $('#cg-verify-result');
                if (response.success && response.data) {
                    var r = response.data;
                    if (r.valid && !r.expired) {
                        result.html(renderValid(r));
                    } else if (r.valid && r.expired) {
                        result.html(renderExpired(r));
                    } else if (r.revoked) {
                        result.html(renderRevoked(r));
                    } else {
                        result.html(renderNotFound());
                    }
                } else {
                    result.html(renderError(response.data ? response.data.message : null));
                }
                result.show();
            },
            error: function (xhr) {
                $('#cg-verify-loading').hide();
                $('#cg-verify-btn').prop('disabled', false)
                    .css({
                        background: 'linear-gradient(135deg,' + S.btn_start + ',' + S.btn_end + ')',
                        cursor: 'pointer'
                    });
                // Non-2xx JSON errors (e.g. 429 rate limit) carry their own message.
                var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
                $('#cg-verify-result').html(renderError(msg || 'Request failed. Please check your connection and try again.')).show();
            }
        });
    });

    // Auto-verify if serial_number is in the URL
    var urlParams = new URLSearchParams(window.location.search);
    var urlSerial = urlParams.get('serial_number');
    if (urlSerial && $('#cg-verify-form').length) {
        var $input = $('#cg-serial-input');
        $input.val(urlSerial);
        // Trigger floating label up
        $input.prev('label').css({top: '8px', fontSize: '12px', color: S.btn_start});
        $input.css('borderColor', '#eaeaea');
        $('#cg-verify-form').trigger('submit');
    }
});
