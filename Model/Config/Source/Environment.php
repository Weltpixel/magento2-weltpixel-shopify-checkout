<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Supplies sandbox/production options for configuration dropdowns.
 */
class Environment implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'sandbox', 'label' => __('Sandbox')],
            ['value' => 'production', 'label' => __('Production')],
        ];
    }
}
