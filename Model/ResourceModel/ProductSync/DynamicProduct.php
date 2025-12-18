<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\ResourceModel\ProductSync;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for dynamic Shopify product mappings.
 */
class DynamicProduct extends AbstractDb
{
    /**
     * Initialize main table and primary key.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('weltpixel_shopifycheckout_product_map', 'entity_id');
    }

    /**
     * Load mapping by SKU and environment combination.
     *
     * @param AbstractModel $object
     * @param string $sku
     * @param string $environment
     * @return void
     */
    public function loadBySkuAndEnvironment(AbstractModel $object, string $sku, string $environment): void
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where('sku = :sku')
            ->where('environment = :environment')
            ->limit(1);

        $bind = [
            ':sku' => $sku,
            ':environment' => $environment,
        ];

        $data = $connection->fetchRow($select, $bind);

        if ($data) {
            $object->setData($data);
            $object->setOrigData();
            $this->_afterLoad($object);
        } else {
            $object->setData([]);
        }
    }
}
