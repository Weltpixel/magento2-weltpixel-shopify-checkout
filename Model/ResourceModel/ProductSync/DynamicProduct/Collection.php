<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\ResourceModel\ProductSync\DynamicProduct;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use WeltPixel\ShopifyCheckout\Model\ProductSync\DynamicProduct as DynamicProductModel;
use WeltPixel\ShopifyCheckout\Model\ResourceModel\ProductSync\DynamicProduct as DynamicProductResource;

/**
 * Collection for dynamic Shopify product mappings.
 */
class Collection extends AbstractCollection
{
    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(DynamicProductModel::class, DynamicProductResource::class);
    }
}
