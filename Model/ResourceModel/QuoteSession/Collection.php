<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\ResourceModel\QuoteSession;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection for Shopify checkout session entities.
 */
class Collection extends AbstractCollection
{
    /**
     * Initialize collection model/resource bindings.
     */
    protected function _construct(): void
    {
        $this->_init(
            \WeltPixel\ShopifyCheckout\Model\Checkout\QuoteSession::class,
            \WeltPixel\ShopifyCheckout\Model\ResourceModel\QuoteSession::class
        );
    }
}
