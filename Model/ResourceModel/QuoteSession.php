<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for Shopify checkout session entity.
 */
class QuoteSession extends AbstractDb
{
    /**
     * Initialize main table and primary key.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('weltpixel_shopifycheckout_quote', 'entity_id');
    }
}
