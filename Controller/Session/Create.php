<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Controller\Session;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Psr\Log\LoggerInterface;
use WeltPixel\ShopifyCheckout\Exception\ApiException;
use WeltPixel\ShopifyCheckout\Model\Checkout\CheckoutSessionManager;
use WeltPixel\ShopifyCheckout\Model\Config;

/**
 * AJAX controller that initiates the Shopify checkout session for the current quote.
 */
class Create extends Action implements HttpPostActionInterface
{
    /**
     * @var JsonFactory
     */
    private JsonFactory $resultJsonFactory;

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
     * @var FormKeyValidator
     */
    private FormKeyValidator $formKeyValidator;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param CheckoutSession $checkoutSession
     * @param CheckoutSessionManager $checkoutSessionManager
     * @param Config $config
     * @param LoggerInterface $logger
     * @param FormKeyValidator $formKeyValidator
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CheckoutSession $checkoutSession,
        CheckoutSessionManager $checkoutSessionManager,
        Config $config,
        LoggerInterface $logger,
        FormKeyValidator $formKeyValidator
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->checkoutSession = $checkoutSession;
        $this->checkoutSessionManager = $checkoutSessionManager;
        $this->config = $config;
        $this->logger = $logger;
        $this->formKeyValidator = $formKeyValidator;
    }

    /**
     * Prepare a Shopify checkout session for the current cart via AJAX.
     *
     * @return Json
     */
    public function execute(): Json
    {
        /** @var Json $result */
        $result = $this->resultJsonFactory->create();

        if (!$this->formKeyValidator->validate($this->getRequest())) {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => __('Your session has expired. Please refresh the page and try again.'),
            ]);
        }

        if (!$this->config->isEnabled()) {
            return $result->setData([
                'success' => false,
                'message' => __('Shopify checkout is currently disabled.'),
            ]);
        }

        $quote = $this->checkoutSession->getQuote();

        if (!$quote->getId() || !$quote->hasItems()) {
            return $result->setData([
                'success' => false,
                'message' => __('Unable to locate an active cart to prepare Shopify checkout.'),
            ]);
        }

        if ($quote->getHasError()) {
            return $result->setData([
                'success' => false,
                'message' => __('Your cart contains errors. Please review your items before continuing.'),
            ]);
        }

        try {
            $quote->collectTotals();
            $shopifySession = $this->checkoutSessionManager->createForQuote($quote);
        } catch (ApiException|LocalizedException $exception) {
            $this->logger->error(
                'Failed to create Shopify checkout session: {message}',
                ['message' => $exception->getMessage()]
            );
            $this->messageManager->addErrorMessage(
                __('We could not connect to Shopify Checkout. Please try again or use the native checkout.')
            );

            return $result->setData([
                'success' => false,
                'message' => __('We could not redirect you to Shopify Checkout.'),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->critical(
                'Unexpected error during Shopify checkout session creation: {message}',
                ['message' => $exception->getMessage(), 'exception' => $exception]
            );
            $this->messageManager->addErrorMessage(
                __('An unexpected error occurred while preparing Shopify Checkout.')
            );

            return $result->setData([
                'success' => false,
                'message' => __('An unexpected error occurred. Please try again.'),
            ]);
        }

        return $result->setData([
            'success' => true,
            'redirect_url' => $shopifySession->getCheckoutUrl(),
            'token' => $shopifySession->getToken(),
        ]);
    }
}
