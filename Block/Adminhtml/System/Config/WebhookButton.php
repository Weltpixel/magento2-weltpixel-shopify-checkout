<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\UrlInterface;
use Magento\Framework\Data\Form\FormKey;

/**
 * Renders create/delete Shopify webhook buttons in system configuration.
 */
class WebhookButton extends Field
{
    private const AJAX_ROUTE = 'weltpixel_shopifycheckout/webhook/create';

    /** @var UrlInterface */
    private UrlInterface $urlBuilder;

    /** @var FormKey */
    protected $formKey;

    /**
     * @param Context $context
     * @param FormKey $formKey
     * @param array<int|string,mixed> $data
     */
    public function __construct(
        Context $context,
        FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->urlBuilder = $context->getUrlBuilder();
        $this->formKey = $formKey;
    }

    /** @inheritDoc */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $element->setValue('');
        $environment = $this->resolveEnvironment($element);

        $createUrl = $this->urlBuilder->getUrl(self::AJAX_ROUTE);
        $deleteUrl = $this->urlBuilder->getUrl('weltpixel_shopifycheckout/webhook/delete');
        $createButtonId = uniqid('weltpixel_shopify_webhook_');
        $deleteButtonId = uniqid('weltpixel_shopify_webhook_delete_');
        $dataParts = ['form_key: "' . $this->formKey->getFormKey() . '"'];
        if ($environment) {
            $dataParts[] = 'environment: "' . $environment . '"';
        }
        $dataString = implode(', ', $dataParts);
        $label = $environment ? __('Create Shopify Webhook (%1)', ucfirst($environment)) : __('Create Shopify Webhook');
        $deleteLabel = $environment ? __('Delete Shopify Webhook (%1)', ucfirst($environment)) : __('Delete Shopify Webhook');

        $html = '<div class="weltpixel-shopify-webhook-buttons">';
        $html .= '<button type="button" id="' . $createButtonId . '" class="action-default">'
            . $label
            . '</button>';
        $html .= '&nbsp;';
        $html .= '<button type="button" id="' . $deleteButtonId . '" class="action-default">'
            . $deleteLabel
            . '</button>';
        $html .= '</div>';

        $html .= '<style>
.weltpixel-shopify-webhook-buttons {
    margin: 10px 0;
}
.weltpixel-shopify-webhook-buttons .action-default {
    margin-right: 10px;
    margin-bottom: 5px;
}
</style>';

        $html .= '<p class="note"><span>'
            . ($environment
                ? __('Creates Shopify order webhooks for the %1 environment. Ensure credentials are saved first.', $environment)
                : __('Creates Shopify order webhooks pointing to the Magento endpoint. Ensure credentials are saved first.')
            )
            . '</span></p>';

        $html .= '<script>
require(["jquery", "mage/translate"], function ($) {
    $("#' . $createButtonId . '").on("click", function () {
        var button = $(this);
        button.prop("disabled", true);
        $.ajax({
            type: "POST",
            url: "' . $createUrl . '",
            data: {' . $dataString . '},
            success: function (response) {
                alert(response && response.message ? response.message : $.mage.__("Webhook created."));
            },
            error: function (xhr) {
                var message = $.mage.__("Unable to create webhook.");
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    message = xhr.responseJSON.message;
                }
                alert(message);
            },
            complete: function () {
                button.prop("disabled", false);
            }
        });
    });

    $("#' . $deleteButtonId . '").on("click", function () {
        var button = $(this);
        button.prop("disabled", true);
        $.ajax({
            type: "POST",
            url: "' . $deleteUrl . '",
            data: {' . $dataString . '},
            success: function (response) {
                alert(response && response.message ? response.message : $.mage.__("Webhook deleted."));
            },
            error: function (xhr) {
                var message = $.mage.__("Unable to delete webhook.");
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    message = xhr.responseJSON.message;
                }
                alert(message);
            },
            complete: function () {
                button.prop("disabled", false);
            }
        });
    });
});
</script>';

        return $html;
}

    private function resolveEnvironment(AbstractElement $element): ?string
    {
        $path = (string) $element->getPath();
        $id = (string) $element->getId();

        if (strpos($path, 'sandbox_credentials') !== false || strpos($id, 'sandbox') !== false) {
            return 'sandbox';
        }

        if (strpos($path, 'production_credentials') !== false || strpos($id, 'production') !== false) {
            return 'production';
        }

        return null;
    }
}
