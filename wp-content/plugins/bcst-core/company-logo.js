(function ($) {
    'use strict';
    $(function () {
        var frame;
        $('#bcst-logo-select').on('click', function () {
            if (!frame) {
                frame = wp.media({ title: '选择公司 Logo', button: { text: '使用此 Logo' }, library: { type: 'image' }, multiple: false });
                frame.on('select', function () {
                    var item = frame.state().get('selection').first().toJSON();
                    if (item.type !== 'image') return;
                    $('#bcst-company-logo').val(item.id);
                    $('#bcst-logo-preview').empty().append($('<img>', { src: item.url, alt: item.alt || '公司 Logo' }).css({ maxWidth: '260px', maxHeight: '100px', width: 'auto', height: 'auto' }));
                });
            }
            frame.open();
        });
        $('#bcst-logo-remove').on('click', function () {
            $('#bcst-company-logo').val('0');
            $('#bcst-logo-preview').empty();
        });
    });
})(jQuery);
