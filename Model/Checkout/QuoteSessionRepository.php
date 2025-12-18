<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\Checkout;

use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\ValidatorException;
use WeltPixel\ShopifyCheckout\Api\Data\QuoteSessionInterface;
use WeltPixel\ShopifyCheckout\Api\QuoteSessionRepositoryInterface;
use WeltPixel\ShopifyCheckout\Model\ResourceModel\QuoteSession as QuoteSessionResource;
use WeltPixel\ShopifyCheckout\Model\ResourceModel\QuoteSession\CollectionFactory;

/**
 * Repository for managing persisted Shopify checkout session entities.
 */
class QuoteSessionRepository implements QuoteSessionRepositoryInterface
{
    /**
     * @var QuoteSessionResource
     */
    private QuoteSessionResource $resource;

    /**
     * @var QuoteSessionFactory
     */
    private QuoteSessionFactory $quoteSessionFactory;

    /**
     * @var CollectionFactory
     */
    private CollectionFactory $collectionFactory;

    /**
     * @param QuoteSessionResource $resource
     * @param QuoteSessionFactory $quoteSessionFactory
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        QuoteSessionResource $resource,
        QuoteSessionFactory $quoteSessionFactory,
        CollectionFactory $collectionFactory
    ) {
        $this->resource = $resource;
        $this->quoteSessionFactory = $quoteSessionFactory;
        $this->collectionFactory = $collectionFactory;
    }

    /**
     * @inheritDoc
     */
    public function save(QuoteSessionInterface $session): QuoteSessionInterface
    {
        try {
            $this->resource->save($session);
        } catch (ValidatorException $validatorException) {
            throw new CouldNotSaveException(
                __('Unable to save Shopify checkout session: %1', $validatorException->getMessage()),
                $validatorException
            );
        } catch (\Throwable $exception) {
            throw new CouldNotSaveException(
                __('Unable to save Shopify checkout session.'),
                $exception
            );
        }

        return $session;
    }

    /**
     * @inheritDoc
     */
    public function getByQuoteId(int $quoteId): QuoteSessionInterface
    {
        /** @var QuoteSessionInterface|\Magento\Framework\Model\AbstractModel $session */
        $session = $this->quoteSessionFactory->create();
        $this->resource->load($session, $quoteId, QuoteSessionInterface::QUOTE_ID);

        if (!$session->getId()) {
            throw new NoSuchEntityException(__('Shopify checkout session not found for quote ID %1.', $quoteId));
        }

        return $session;
    }

    /**
     * Retrieve a stored checkout session by Shopify checkout token.
     *
     * @param string $token
     * @return QuoteSessionInterface|null
     */
    public function getByCheckoutToken(string $token): ?QuoteSessionInterface
    {
        /** @var QuoteSessionInterface|\Magento\Framework\Model\AbstractModel $session */
        $session = $this->quoteSessionFactory->create();
        $this->resource->load($session, $token, QuoteSessionInterface::CHECKOUT_TOKEN);

        if (!$session->getId()) {
            return null;
        }

        return $session;
    }

    /**
     * @inheritDoc
     */
    public function delete(QuoteSessionInterface $session): void
    {
        try {
            $this->resource->delete($session);
        } catch (\Throwable $exception) {
            throw new CouldNotDeleteException(
                __('Unable to delete Shopify checkout session.'),
                $exception
            );
        }
    }

    /**
     * Remove all stored sessions associated with a specific quote.
     *
     * @param int $quoteId
     * @return void
     */
    public function deleteByQuoteId(int $quoteId): void
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(QuoteSessionInterface::QUOTE_ID, $quoteId);

        foreach ($collection as $session) {
            $this->delete($session);
        }
    }
}
