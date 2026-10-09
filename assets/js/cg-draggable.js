jQuery(document).ready(function($) {
    if (typeof cgDraggable === 'undefined') return;

    var certificatePreview = $('#cg-certificate-preview');
    if (!certificatePreview.length) {
        certificatePreview = $('#poststuff').find('.inside').first();
        if (!certificatePreview.length) return;
    }

    var qrDraggable = $('#cg-qr-draggable');
    var serialDraggable = $('#cg-serial-draggable');

    if (!qrDraggable.length && !serialDraggable.length) {
        createDraggableElements();
    }

    function createDraggableElements() {
        var qrEnabled = $('#qr_enabled').is(':checked');
        var serialEnabled = $('#serial_number_display').is(':checked');

        if (qrEnabled) {
            $('#cg-qr-draggable').remove();
            var qrSize = parseInt($('#qr_size').val()) || 15;
            var qrPosX = parseFloat($('#qr_position_x').val()) || 250;
            var qrPosY = parseFloat($('#qr_position_y').val()) || 180;
            
            var qrPixelX = mmToPixels(qrPosX);
            var qrPixelY = mmToPixels(qrPosY);
            var qrPixelSize = mmToPixels(qrSize);

            certificatePreview.append(
                '<div id="cg-qr-draggable" class="cg-draggable-element cg-qr-element" ' +
                'style="left:' + qrPixelX + 'px;top:' + qrPixelY + 'px;width:' + qrPixelSize + 'px;height:' + qrPixelSize + 'px;">' +
                '<div class="cg-draggable-label">QR Code</div>' +
                '<div class="cg-draggable-size">' + qrSize + 'mm</div>' +
                '</div>'
            );

            makeDraggable('#cg-qr-draggable', 'qr');
        }

        if (serialEnabled) {
            $('#cg-serial-draggable').remove();
            var snPosX = parseFloat($('#serial_number_position_x').val()) || 105;
            var snPosY = parseFloat($('#serial_number_position_y').val()) || 200;
            var snFontSize = parseInt($('#serial_number_font_size').val()) || 10;

            var snPixelX = mmToPixels(snPosX);
            var snPixelY = mmToPixels(snPosY);

            certificatePreview.append(
                '<div id="cg-serial-draggable" class="cg-draggable-element cg-serial-element" ' +
                'style="left:' + snPixelX + 'px;top:' + snPixelY + 'px;font-size:' + (snFontSize * 1.5) + 'px;">' +
                '<div class="cg-draggable-label">Serial: CERT-00000001</div>' +
                '</div>'
            );

            makeDraggable('#cg-serial-draggable', 'serial');
        }
    }

    function mmToPixels(mm) {
        return Math.round(mm * 3.78);
    }

    function pixelsToMm(px) {
        return (px / 3.78).toFixed(1);
    }

    function makeDraggable(selector, type) {
        var $el = $(selector);
        var isDragging = false;
        var startX, startY, origLeft, origTop;

        $el.on('mousedown', function(e) {
            e.preventDefault();
            isDragging = true;
            $el.addClass('cg-dragging');
            startX = e.pageX;
            startY = e.pageY;
            origLeft = parseInt($el.css('left')) || 0;
            origTop = parseInt($el.css('top')) || 0;

            $(document).on('mousemove.cgDrag', onMouseMove);
            $(document).on('mouseup.cgDrag', onMouseUp);
        });

        function onMouseMove(e) {
            if (!isDragging) return;
            var dx = e.pageX - startX;
            var dy = e.pageY - startY;
            var newLeft = origLeft + dx;
            var newTop = origTop + dy;

            newLeft = Math.max(0, Math.min(newLeft, certificatePreview.width() - $el.width()));
            newTop = Math.max(0, Math.min(newTop, certificatePreview.height() - $el.height()));

            $el.css({ left: newLeft, top: newTop });

            var mmX = pixelsToMm(newLeft);
            var mmY = pixelsToMm(newTop);

            if (type === 'qr') {
                $('#qr_position_x').val(mmX);
                $('#qr_position_y').val(mmY);
            } else if (type === 'serial') {
                $('#serial_number_position_x').val(mmX);
                $('#serial_number_position_y').val(mmY);
            }
        }

        function onMouseUp() {
            isDragging = false;
            $el.removeClass('cg-dragging');
            $(document).off('mousemove.cgDrag');
            $(document).off('mouseup.cgDrag');
        }
    }

    $('#qr_enabled, #serial_number_display').on('change', function() {
        createDraggableElements();
    });

    $('#qr_size, #qr_position_x, #qr_position_y, #serial_number_position_x, #serial_number_position_y, #serial_number_font_size').on('change input', function() {
        createDraggableElements();
    });
});
