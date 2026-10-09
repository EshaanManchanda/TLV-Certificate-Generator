(function ($) {
  'use strict';

  $(document).ready(function () {
    if (window.CERT_GEN_DEBUG) {
      console.log('Custom admin JS loaded');
    }

    // ── 1. School Name autocomplete ──────────────────────────────
    var $schoolInput = $('#school_name.cg-school-autocomplete');
    if ($schoolInput.length && typeof cgStudentAjax !== 'undefined') {
      $schoolInput.autocomplete({
        minLength: 2,
        delay: 300,
        source: function (request, response) {
          $.get(cgStudentAjax.ajaxurl, {
            action: 'cg_school_autocomplete',
            nonce:  cgStudentAjax.schoolNonce,
            term:   request.term,
          }).done(function (data) {
            response($.isArray(data) ? data : []);
          }).fail(function () { response([]); });
        },
      });
    }

    // ── 2. Inline Add-Field creator ──────────────────────────────
    $(document).on('click', '#cg_add_field_btn', function () {
      var $section  = $(this).closest('.cg-add-field-section');
      var $input    = $('#cg_new_field_slug');
      var $status   = $section.find('.cg-add-field-status');
      var slug      = $.trim($input.val()).toLowerCase().replace(/\s+/g, '_');
      var certType  = $section.data('cert-type');
      var nonce     = $section.data('nonce');
      var postId    = $section.data('post-id');

      $status.removeClass('success error').text('');

      if (!slug) {
        $status.addClass('error').text('Please enter a field name.');
        return;
      }
      if (!/^[a-z][a-z0-9_]*$/.test(slug)) {
        $status.addClass('error').text('Use only lowercase letters, numbers, and underscores. Must start with a letter.');
        return;
      }
      if (!certType) {
        $status.addClass('error').text('Set a Certificate Type in the Student Details box first.');
        return;
      }

      var $btn = $(this).prop('disabled', true).text('Adding\u2026');

      $.post(ajaxurl || (cgStudentAjax && cgStudentAjax.ajaxurl), {
        action:    'cg_add_extra_field',
        nonce:     nonce,
        cert_type: certType,
        slug:      slug,
        post_id:   postId,
      }).done(function (res) {
        if (res.success) {
          $('#cg-extra-fields-list').append(res.data.html);
          $('#cg-no-extra-msg').hide();
          $input.val('');
          $status.addClass('success').text('Field "' + res.data.label + '" added successfully.');
        } else {
          $status.addClass('error').text(res.data.message || 'Could not add field.');
        }
      }).fail(function () {
        $status.addClass('error').text('Server error. Please try again.');
      }).always(function () {
        $btn.prop('disabled', false).text('+ Add Field');
      });
    });

    // ── 3. Required-field validation on form submit ──────────────
    $('form#post').on('submit', function (e) {
      var valid = true;
      $('[data-required="true"]').each(function () {
        var $input = $(this);
        var $err   = $input.siblings('.cg-field-error');
        if (!$.trim($input.val())) {
          $input.addClass('cg-error');
          $err.show();
          valid = false;
        } else {
          $input.removeClass('cg-error');
          $err.hide();
        }
      });
      if (!valid) {
        e.preventDefault();
        $('html, body').animate({ scrollTop: $('.cg-error').first().offset().top - 60 }, 300);
      }
    });

    // Clear error state when user types
    $(document).on('input', '[data-required="true"]', function () {
      $(this).removeClass('cg-error').siblings('.cg-field-error').hide();
    });
  });
})(jQuery);
