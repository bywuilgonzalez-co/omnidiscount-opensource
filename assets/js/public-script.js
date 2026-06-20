(function ($) {
    'use strict';

    function initShortcode($wrap) {
        var $catSelect  = $wrap.find('.drw-cat-select');
        var $sortSelect = $wrap.find('.drw-sort-select');
        var $clearBtn   = $wrap.find('.drw-clear-filter');
        var $saleWrap   = $wrap.find('.drw-sale-wrap');
        var ajaxUrl     = $wrap.data('ajax-url');
        var nonce       = $wrap.data('nonce');
        var limit       = parseInt($wrap.data('limit'), 10)      || 12;
        var columns     = parseInt($wrap.data('columns'), 10)    || 4;
        var scanLimit   = parseInt($wrap.data('scan-limit'), 10) || 240;

        function reload() {
            var category = $catSelect.val() || '';
            var sort     = $sortSelect.val() || 'discount';

            $clearBtn.toggle(category !== '');
            $saleWrap.addClass('drw-loading');

            $.post(ajaxUrl, {
                action:     'drw_filter_sale_items',
                nonce:      nonce,
                category:   category,
                sort:       sort,
                limit:      limit,
                columns:    columns,
                scan_limit: scanLimit
            }).done(function (resp) {
                if (resp && resp.success) {
                    $saleWrap.html(resp.data.html);
                }
            }).always(function () {
                $saleWrap.removeClass('drw-loading');
            });
        }

        $catSelect.on('change', reload);
        $sortSelect.on('change', reload);

        $clearBtn.on('click', function () {
            $catSelect.val('').trigger('change');
        });
    }

    $(document).ready(function () {
        $('.drw-sale-shortcode').each(function () {
            initShortcode($(this));
        });
    });

}(jQuery));
