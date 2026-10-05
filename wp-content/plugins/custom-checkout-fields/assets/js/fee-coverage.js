jQuery(function ($) {
    $(document.body).on('change', '#cover_transaction_fee', function () {
        $(document.body).trigger('update_checkout');
    });
});
