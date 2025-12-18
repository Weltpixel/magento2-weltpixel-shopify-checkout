<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Controller\Session;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect as ResultRedirect;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use WeltPixel\ShopifyCheckout\Exception\ApiException;
use WeltPixel\ShopifyCheckout\Model\Checkout\CheckoutSessionManager;
use WeltPixel\ShopifyCheckout\Model\Config;

/**
 * Frontend controller that redirects Magento checkout traffic to Shopify.
 */
class Redirect extends Action
{
    /**
     * @var CheckoutSession
     */
    private CheckoutSession $checkoutSession;

    /**
     * @var CheckoutSessionManager
     */
    private CheckoutSessionManager $checkoutSessionManager;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Context $context
     * @param CheckoutSession $checkoutSession
     * @param CheckoutSessionManager $checkoutSessionManager
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        CheckoutSession $checkoutSession,
        CheckoutSessionManager $checkoutSessionManager,
        Config $config,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
        $this->checkoutSessionManager = $checkoutSessionManager;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Redirect Magento checkout flow to Shopify when configured.
     *
     * @return ResultRedirect
     */
    public function execute(): ResultRedirect
    {
        /** @var ResultRedirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT);

        if (!$this->config->isEnabled()) {
            return $resultRedirect->setPath('checkout');
        }

        if ($this->config->getCheckoutBehavior() !== Config::CHECKOUT_BEHAVIOR_REPLACE) {
            return $resultRedirect->setPath('checkout');
        }

        $quote = $this->checkoutSession->getQuote();

        if (!$quote->getId() || !$quote->hasItems()) {
            $this->messageManager->addErrorMessage(
                __('We could not prepare Shopify checkout because your cart is empty.')
            );
            return $resultRedirect->setPath('checkout/cart');
        }

        if ($quote->getHasError()) {
            $this->messageManager->addErrorMessage(
                __('Your cart contains errors. Please review your items before continuing.')
            );
            return $resultRedirect->setPath('checkout/cart');
        }

        try {
            $quote->collectTotals();
            $shopifySession = $this->checkoutSessionManager->createForQuote($quote);
            return $resultRedirect->setUrl($shopifySession->getCheckoutUrl());
        } catch (ApiException|LocalizedException $exception) {
            $this->logger->error(
                'Failed to create Shopify checkout session via redirect: {message}',
                ['message' => $exception->getMessage()]
            );
            $this->messageManager->addErrorMessage(
                __('We could not connect to Shopify Checkout. Please try again or contact support.')
            );
        } catch (\Throwable $exception) {
            $this->logger->critical(
                'Unexpected error during Shopify checkout redirect: {message}',
                ['message' => $exception->getMessage(), 'exception' => $exception]
            );
            $this->messageManager->addErrorMessage(
                __('An unexpected error occurred while preparing Shopify Checkout.')
            );
        }

        return $resultRedirect->setPath('checkout/cart');
    }
}
