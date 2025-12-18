<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\Checkout;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use WeltPixel\ShopifyCheckout\Api\QuoteSessionRepositoryInterface;
use WeltPixel\ShopifyCheckout\Exception\ApiException;

/**
 * Handles creation and persistence of Shopify checkout sessions linked to Magento quotes.
 */
class CheckoutSessionManager
{
    /**
     * Shopify session creator service.
     *
     * @var SessionCreator
     */
    private SessionCreator $sessionCreator;

    /**
     * Repository for storing session metadata records.
     *
     * @var QuoteSessionRepositoryInterface
     */
    private QuoteSessionRepositoryInterface $quoteSessionRepository;

    /**
     * Factory for new session instances.
     *
     * @var QuoteSessionFactory
     */
    private QuoteSessionFactory $quoteSessionFactory;

    /**
     * @param SessionCreator $sessionCreator
     * @param QuoteSessionRepositoryInterface $quoteSessionRepository
     * @param QuoteSessionFactory $quoteSessionFactory
     */
    public function __construct(
        SessionCreator $sessionCreator,
        QuoteSessionRepositoryInterface $quoteSessionRepository,
        QuoteSessionFactory $quoteSessionFactory
    ) {
        $this->sessionCreator = $sessionCreator;
        $this->quoteSessionRepository = $quoteSessionRepository;
        $this->quoteSessionFactory = $quoteSessionFactory;
    }

    /**
     * Creates or refreshes a Shopify checkout session for the provided quote.
     *
     * @param Quote $quote
     * @return Data\CheckoutSession
     * @throws ApiException
     * @throws CouldNotSaveException
     */
    public function createForQuote(Quote $quote): Data\CheckoutSession
    {
        $shopifySession = $this->sessionCreator->create($quote);

        try {
            $quoteSession = $this->quoteSessionRepository->getByQuoteId((int) $quote->getId());
        } catch (NoSuchEntityException $exception) {
            $quoteSession = $this->quoteSessionFactory->create();
            $quoteSession->setQuoteId((int) $quote->getId());
        }

        $quoteSession->setCheckoutToken($shopifySession->getToken());
        $quoteSession->setCheckoutId($shopifySession->getCheckoutId());
        $quoteSession->setCheckoutUrl($shopifySession->getCheckoutUrl());
        $quoteSession->setStatus(QuoteSessionStatus::STATUS_PENDING);

        $this->quoteSessionRepository->save($quoteSession);

        return $shopifySession;
    }

    /**
     * Clears stored session metadata for provided quote.
     *
     * @param Quote $quote
     * @return void
     * @throws CouldNotSaveException
     */
    public function clearForQuote(Quote $quote): void
    {
        try {
            $session = $this->quoteSessionRepository->getByQuoteId((int) $quote->getId());
        } catch (NoSuchEntityException $exception) {
            return;
        }

        $session->setCheckoutToken(null);
        $session->setCheckoutId(null);
        $session->setCheckoutUrl(null);
        $session->setStatus(null);

        $this->quoteSessionRepository->save($session);
    }
}
