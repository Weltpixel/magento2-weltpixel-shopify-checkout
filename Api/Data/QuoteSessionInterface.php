<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Api\Data;

use Magento\Framework\Api\ExtensibleDataInterface;
use WeltPixel\ShopifyCheckout\Api\Data\QuoteSessionExtensionInterface;

/**
 * Interface describing Shopify checkout session persistence contract.
 */
interface QuoteSessionInterface extends ExtensibleDataInterface
{
    public const ENTITY_ID = 'entity_id';
    public const QUOTE_ID = 'quote_id';
    public const CHECKOUT_TOKEN = 'checkout_token';
    public const CHECKOUT_ID = 'checkout_id';
    public const CHECKOUT_URL = 'checkout_url';
    public const STATUS = 'status';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    /**
     * Get entity ID.
     *
     * @return int|null
     */
    public function getId(): ?int;

    /**
     * Get Magento quote identifier.
     *
     * @return int
     */
    public function getQuoteId(): int;

    /**
     * Set Magento quote identifier.
     *
     * @param int $quoteId
     * @return $this
     */
    public function setQuoteId(int $quoteId): self;

    /**
     * Get Shopify checkout token.
     *
     * @return string|null
     */
    public function getCheckoutToken(): ?string;

    /**
     * Set Shopify checkout token.
     *
     * @param string|null $token
     * @return $this
     */
    public function setCheckoutToken(?string $token): self;

    /**
     * Get Shopify checkout ID (draft order).
     *
     * @return string|null
     */
    public function getCheckoutId(): ?string;

    /**
     * Set Shopify checkout ID (draft order).
     *
     * @param string|null $checkoutId
     * @return $this
     */
    public function setCheckoutId(?string $checkoutId): self;

    /**
     * Get Shopify checkout URL.
     *
     * @return string|null
     */
    public function getCheckoutUrl(): ?string;

    /**
     * Set Shopify checkout URL.
     *
     * @param string|null $url
     * @return $this
     */
    public function setCheckoutUrl(?string $url): self;

    /**
     * Get checkout status.
     *
     * @return string|null
     */
    public function getStatus(): ?string;

    /**
     * Set checkout status.
     *
     * @param string|null $status
     * @return $this
     */
    public function setStatus(?string $status): self;

    /**
     * Get creation timestamp.
     *
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * Set creation timestamp.
     *
     * @param string|null $createdAt
     * @return $this
     */
    public function setCreatedAt(?string $createdAt): self;

    /**
     * Get update timestamp.
     *
     * @return string|null
     */
    public function getUpdatedAt(): ?string;

    /**
     * Set update timestamp.
     *
     * @param string|null $updatedAt
     * @return $this
     */
    public function setUpdatedAt(?string $updatedAt): self;

    /**
     * Retrieve existing extension attributes object or create a new one.
     *
     * @return \WeltPixel\ShopifyCheckout\Api\Data\QuoteSessionExtensionInterface|null
     */
    public function getExtensionAttributes(): ?QuoteSessionExtensionInterface;

    /**
     * Set extension attributes object.
     *
     * @param \WeltPixel\ShopifyCheckout\Api\Data\QuoteSessionExtensionInterface|null $extensionAttributes
     * @return $this
     */
    public function setExtensionAttributes(?QuoteSessionExtensionInterface $extensionAttributes): self;
}
