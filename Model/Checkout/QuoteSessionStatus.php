<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\Checkout;

/**
 * Known statuses for Shopify checkout session lifecycle.
 */
class QuoteSessionStatus
{
    /** Shopify checkout initiated; awaiting completion. */
    public const STATUS_PENDING = 'pending';

    /** Checkout successfully completed and processed. */
    public const STATUS_COMPLETED = 'completed';

    /** Checkout failed irrecoverably. */
    public const STATUS_FAILED = 'failed';

    /** Checkout session expired without completion. */
    public const STATUS_EXPIRED = 'expired';
}
