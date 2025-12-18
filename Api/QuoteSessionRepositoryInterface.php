<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Api;

use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use WeltPixel\ShopifyCheckout\Api\Data\QuoteSessionInterface;

/**
 * Repository abstraction for Shopify checkout session entities.
 */
interface QuoteSessionRepositoryInterface
{
    /**
     * Persist session data.
     *
     * @param QuoteSessionInterface $session
     * @return QuoteSessionInterface
     * @throws CouldNotSaveException
     */
    public function save(QuoteSessionInterface $session): QuoteSessionInterface;

    /**
     * Load session by Magento quote ID.
     *
     * @param int $quoteId
     * @return QuoteSessionInterface
     * @throws NoSuchEntityException
     */
    public function getByQuoteId(int $quoteId): QuoteSessionInterface;

    /**
     * Retrieve session by checkout token.
     *
     * @param string $token
     * @return QuoteSessionInterface|null
     */
    public function getByCheckoutToken(string $token): ?QuoteSessionInterface;

    /**
     * Delete session entity.
     *
     * @param QuoteSessionInterface $session
     * @return void
     * @throws CouldNotDeleteException
     */
    public function delete(QuoteSessionInterface $session): void;

    /**
     * Delete session entities by quote ID.
     *
     * @param int $quoteId
     * @return void
     */
    public function deleteByQuoteId(int $quoteId): void;
}
