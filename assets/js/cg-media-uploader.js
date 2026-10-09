/**
 * Shared media library uploader for Certificate Generator admin pages.
 *
 * Usage (auto-init via data attributes):
 *   <button type="button" class="cg-media-btn"
 *           data-input="#my_url_field"
 *           data-preview="#my_preview"
 *           data-title="Select Image"
 *           data-button-text="Use this image">Choose</button>
 *
 * Usage (manual init):
 *   CgMediaUploader.init({
 *     button:     '#my_button',
 *     input:      '#my_url_field',
 *     preview:    '#my_preview',        // optional
 *     title:      'Select Image',       // optional
 *     buttonText: 'Use this image',     // optional
 *   });
 */
(function ($) {
    'use strict';

    window.CgMediaUploader = window.CgMediaUploader || {};

    CgMediaUploader.init = function (opts) {
        var $btn     = $(opts.button);
        var $input   = $(opts.input);
        var $preview = opts.preview ? $(opts.preview) : null;
        var title    = opts.title      || 'Select Image';
        var btnText  = opts.buttonText || 'Use this image';

        if (!$btn.length || !$input.length) {
            console.warn('[CG Media Uploader] Button or input not found:', opts);
            return;
        }

        $btn.on('click', function (e) {
            e.preventDefault();

            if (typeof wp === 'undefined' || !wp.media) {
                console.error('[CG Media Uploader] wp.media unavailable — ensure wp_enqueue_media() was called.');
                return;
            }

            var frame = wp.media({
                title:    title,
                button:   { text: btnText },
                multiple: false,
                library:  { type: 'image' },
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                $input.val(attachment.url).trigger('change');

                if ($preview && $preview.length) {
                    $preview
                        .html('<img src="' + attachment.url + '" style="max-width:300px;max-height:180px;border:1px solid #ddd;border-radius:4px;display:block;">')
                        .show();
                }
            });

            frame.open();
        });

        // Live preview when URL is typed/pasted manually.
        $input.on('change', function () {
            if (!$preview || !$preview.length) return;
            var url = $(this).val().trim();
            if (url) {
                $preview
                    .html('<img src="' + url + '" style="max-width:300px;max-height:180px;border:1px solid #ddd;border-radius:4px;display:block;">')
                    .show();
            } else {
                $preview.hide();
            }
        });
    };

    /**
     * Auto-initialise every element with class `cg-media-btn` using its data-* attributes.
     */
    CgMediaUploader.autoInit = function () {
        $('.cg-media-btn').each(function () {
            var $btn = $(this);
            CgMediaUploader.init({
                button:     $btn,
                input:      $btn.data('input'),
                preview:    $btn.data('preview')    || null,
                title:      $btn.data('title')      || 'Select Image',
                buttonText: $btn.data('button-text')|| 'Use this image',
            });
        });
    };

    $(function () {
        CgMediaUploader.autoInit();
    });

}(jQuery));
