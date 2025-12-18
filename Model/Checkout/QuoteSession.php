<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\Checkout;

use Magento\Framework\Model\AbstractExtensibleModel;
use WeltPixel\ShopifyCheckout\Api\Data\QuoteSessionExtensionInterface;
use WeltPixel\ShopifyCheckout\Api\Data\QuoteSessionInterface;

/**
 * Magento model representing persisted Shopify checkout session metadata.
 */
class QuoteSession extends AbstractExtensibleModel implements QuoteSessionInterface
{
    /**
     * Initialize resource model binding.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(\WeltPixel\ShopifyCheckout\Model\ResourceModel\QuoteSession::class);
    }

    /** @inheritDoc */
    public function getId(): ?int
    {
        $value = $this->_getData(self::ENTITY_ID);
        return $value !== null ? (int) $value : null;
    }

    /** @inheritDoc */
    public function getQuoteId(): int
    {
        return (int) $this->_getData(self::QUOTE_ID);
    }

    /** @inheritDoc */
    public function setQuoteId(int $quoteId): QuoteSessionInterface
    {
        return $this->setData(self::QUOTE_ID, $quoteId);
    }

    /** @inheritDoc */
    public function getCheckoutToken(): ?string
    {
        $value = $this->_getData(self::CHECKOUT_TOKEN);
        return $value !== null ? (string) $value : null;
    }

    /** @inheritDoc */
    public function setCheckoutToken(?string $token): QuoteSessionInterface
    {
        return $this->setData(self::CHECKOUT_TOKEN, $token);
    }

    /** @inheritDoc */
    public function getCheckoutId(): ?string
    {
        $value = $this->_getData(self::CHECKOUT_ID);
        return $value !== null ? (string) $value : null;
    }

    /** @inheritDoc */
    public function setCheckoutId(?string $checkoutId): QuoteSessionInterface
    {
        return $this->setData(self::CHECKOUT_ID, $checkoutId);
    }

    /** @inheritDoc */
    public function getCheckoutUrl(): ?string
    {
        $value = $this->_getData(self::CHECKOUT_URL);
        return $value !== null ? (string) $value : null;
    }

    /** @inheritDoc */
    public function setCheckoutUrl(?string $url): QuoteSessionInterface
    {
        return $this->setData(self::CHECKOUT_URL, $url);
    }

    /** @inheritDoc */
    public function getStatus(): ?string
    {
        $value = $this->_getData(self::STATUS);
        return $value !== null ? (string) $value : null;
    }

    /** @inheritDoc */
    public function setStatus(?string $status): QuoteSessionInterface
    {
        return $this->setData(self::STATUS, $status);
    }

    /** @inheritDoc */
    public function getCreatedAt(): ?string
    {
        $value = $this->_getData(self::CREATED_AT);
        return $value !== null ? (string) $value : null;
    }

    /** @inheritDoc */
    public function setCreatedAt(?string $createdAt): QuoteSessionInterface
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    /** @inheritDoc */
    public function getUpdatedAt(): ?string
    {
        $value = $this->_getData(self::UPDATED_AT);
        return $value !== null ? (string) $value : null;
    }

    /** @inheritDoc */
    public function setUpdatedAt(?string $updatedAt): QuoteSessionInterface
    {
        return $this->setData(self::UPDATED_AT, $updatedAt);
    }

    /** @inheritDoc */
    public function getExtensionAttributes(): ?QuoteSessionExtensionInterface
    {
        /** @var QuoteSessionExtensionInterface|null $extensionAttributes */
        $extensionAttributes = $this->_getExtensionAttributes();
        return $extensionAttributes;
    }

    /** @inheritDoc */
    public function setExtensionAttributes(?QuoteSessionExtensionInterface $extensionAttributes): QuoteSessionInterface
    {
        $this->_setExtensionAttributes($extensionAttributes);
        return $this;
    }
}
