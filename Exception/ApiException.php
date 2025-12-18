<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Generic exception for Shopify API failures.
 */
class ApiException extends LocalizedException
{
    /**
     * @param string|Phrase $message
     * @param \Throwable|null $cause
     * @param array<int|string,mixed> $params
     */
    public function __construct(string|Phrase $message, ?\Throwable $cause = null, array $params = [])
    {
        parent::__construct($message instanceof Phrase ? $message : __($message, $params), $cause instanceof \Exception ? $cause : null);
    }
}
