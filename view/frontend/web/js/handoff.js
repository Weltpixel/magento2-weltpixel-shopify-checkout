define([
    'jquery',
    'mage/translate',
    'mage/cookies',
    'Magento_Customer/js/customer-data'
], function ($, $t, cookies, customerData) {
    'use strict';

    function showFeedback(container, message, isError) {
        if (!container) {
            return;
        }

        container
            .toggleClass('weltpixel-shopifycheckout__feedback--error', !!isError)
            .text(message || '');
    }

    return function (config) {
        var $buttons = $(config.buttonSelector),
            isRequestInFlight = false;

        if (!$buttons.length) {
            return;
        }

        function resolveFeedback($trigger) {
            if (config.feedbackSelector) {
                var $feedback = $(config.feedbackSelector);
                if ($feedback.length) {
                    return $feedback;
                }
            }

            return $trigger.closest('.weltpixel-shopifycheckout').find('[data-role="feedback"]');
        }

        $buttons.each(function () {
            var $button = $(this);

            $button.off('.weltpixelShopifyCheckout');
            $button.on('click.weltpixelShopifyCheckout', function (event) {
                var $feedback = resolveFeedback($button);

                if (event && typeof event.preventDefault === 'function') {
                    event.preventDefault();
                }
                if (config.stopPropagation && event) {
                    if (typeof event.stopImmediatePropagation === 'function') {
                        event.stopImmediatePropagation();
                    } else if (typeof event.stopPropagation === 'function') {
                        event.stopPropagation();
                    }
                }

                if (isRequestInFlight) {
                    return;
                }

                isRequestInFlight = true;
                $button.prop('disabled', true);
                showFeedback($feedback, $t('Preparing Shopify Checkout…'), false);

                $.ajax({
                    url: config.createSessionUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        form_key: $.cookie('form_key')
                    },
                    showLoader: true
                }).done(function (response) {
                    if (response && response.success && response.redirect_url) {
                        if (customerData && typeof customerData.invalidate === 'function') {
                            customerData.invalidate(['cart', 'checkout-data']);
                        }
                        if (customerData && typeof customerData.set === 'function') {
                            customerData.set('cart', {
                                summary_count: 0,
                                items: [],
                                possible_onepage_checkout: true
                            });
                        }
                        window.location.href = response.redirect_url;
                        return;
                    }

                    showFeedback(
                        $feedback,
                        response && response.message ? response.message : $t('We could not redirect you to Shopify Checkout.'),
                        true
                    );
                }).fail(function () {
                    showFeedback(
                        $feedback,
                        $t('An error occurred while connecting to Shopify Checkout.'),
                        true
                    );
                }).always(function () {
                    isRequestInFlight = false;
                    $button.prop('disabled', false);
                });
            });
        });
    };
});
