<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use WeltPixel\ShopifyCheckout\Model\Config;

/**
 * Provides admin select options for checkout behavior configuration.
 */
class CheckoutBehavior implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => Config::CHECKOUT_BEHAVIOR_SEPARATE,
                'label' => __('Button display on cart page'),
            ],
            [
                'value' => Config::CHECKOUT_BEHAVIOR_REPLACE,
                'label' => __('Replace Magento checkout buttons with Shopify redirect'),
            ],
        ];
    }
}
