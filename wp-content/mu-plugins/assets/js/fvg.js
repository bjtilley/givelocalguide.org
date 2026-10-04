(function(jq) {
    'use strict';

    function fvgCleanup() {
        jq('.fvg-free-voucher').each(function() {
            const row = jq(this);
            const sub = row.find('.product-subtotal, .subtotal');
            if (sub.length) {
                const data = sub.find('.fvg-subtotal').data('fvg-price') || (window.glg_free_gifts_data && window.glg_free_gifts_data.zero_price) || '';
                sub.html('<span class="amount">' + data + '</span>');
            }

            const qty = row.find('.product-quantity, .quantity');
            if (qty.length) {
                qty.find('input, select').remove();
                if (qty.find('span.quantity').length === 0) {
                    qty.append('<span class="quantity">1</span>');
                }
            }
        });

        if (window.glg_free_gifts_data && window.glg_free_gifts_data.debug) {
            console.info('fvgCleanup executed for free vouchers');
        }
    }

    jq(document).ready(fvgCleanup);
    jq(document.body).on('updated_wc_div updated_cart_totals', fvgCleanup);

})(jQuery);

