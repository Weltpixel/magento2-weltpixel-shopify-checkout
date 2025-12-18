<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\Checkout\Data;

/**
 * Value object carrying Shopify checkout session identifiers.
 */
class CheckoutSession
{
    /**
     * @var string
     */
    private string $checkoutUrl;

    /**
     * @var string
     */
    private string $token;

    /**
     * @var string|null
     */
    private ?string $checkoutId;

    /**
     * @param string $checkoutUrl
     * @param string $token
     * @param string|null $checkoutId
     */
    public function __construct(string $checkoutUrl, string $token, ?string $checkoutId = null)
    {
        $this->checkoutUrl = $checkoutUrl;
        $this->token = $token;
        $this->checkoutId = $checkoutId;
    }

    /**
     * @return string
     */
    public function getCheckoutUrl(): string
    {
        return $this->checkoutUrl;
    }

    /**
     * @return string
     */
    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * @return string|null
     */
    public function getCheckoutId(): ?string
    {
        return $this->checkoutId;
    }
}
