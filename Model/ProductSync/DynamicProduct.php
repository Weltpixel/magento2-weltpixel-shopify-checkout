<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\ProductSync;

use Magento\Framework\Model\AbstractModel;

/**
 * Persisted mapping between Magento SKUs and dynamically created Shopify products.
 */
class DynamicProduct extends AbstractModel
{
    public const ENTITY_ID = 'entity_id';
    public const SKU = 'sku';
    public const ENVIRONMENT = 'environment';
    public const SHOPIFY_PRODUCT_ID = 'shopify_product_id';
    public const SHOPIFY_VARIANT_ID = 'shopify_variant_id';
    public const SHOPIFY_IMAGE_ID = 'shopify_image_id';
    public const SYNC_HASH = 'sync_hash';
    public const IS_ARCHIVED = 'is_archived';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    /**
     * Initialize resource model binding.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(\WeltPixel\ShopifyCheckout\Model\ResourceModel\ProductSync\DynamicProduct::class);
    }

    public function getId(): ?int
    {
        $value = $this->_getData(self::ENTITY_ID);
        return $value !== null ? (int) $value : null;
    }

    public function getSku(): string
    {
        return (string) $this->_getData(self::SKU);
    }

    public function setSku(string $sku): self
    {
        return $this->setData(self::SKU, $sku);
    }

    public function getEnvironment(): string
    {
        return (string) $this->_getData(self::ENVIRONMENT);
    }

    public function setEnvironment(string $environment): self
    {
        return $this->setData(self::ENVIRONMENT, $environment);
    }

    public function getShopifyProductId(): string
    {
        return (string) $this->_getData(self::SHOPIFY_PRODUCT_ID);
    }

    public function setShopifyProductId(string $productId): self
    {
        return $this->setData(self::SHOPIFY_PRODUCT_ID, $productId);
    }

    public function getShopifyVariantId(): string
    {
        return (string) $this->_getData(self::SHOPIFY_VARIANT_ID);
    }

    public function setShopifyVariantId(string $variantId): self
    {
        return $this->setData(self::SHOPIFY_VARIANT_ID, $variantId);
    }

    public function getShopifyImageId(): ?string
    {
        $value = $this->_getData(self::SHOPIFY_IMAGE_ID);
        return $value !== null ? (string) $value : null;
    }

    public function setShopifyImageId(?string $imageId): self
    {
        return $this->setData(self::SHOPIFY_IMAGE_ID, $imageId);
    }

    public function getSyncHash(): ?string
    {
        $value = $this->_getData(self::SYNC_HASH);
        return $value !== null ? (string) $value : null;
    }

    public function setSyncHash(?string $hash): self
    {
        return $this->setData(self::SYNC_HASH, $hash);
    }

    public function isArchived(): bool
    {
        return (bool) $this->_getData(self::IS_ARCHIVED);
    }

    public function setIsArchived(bool $isArchived): self
    {
        return $this->setData(self::IS_ARCHIVED, (int) $isArchived);
    }
}
