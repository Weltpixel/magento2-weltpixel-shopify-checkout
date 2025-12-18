<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Cron;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use WeltPixel\ShopifyCheckout\Model\Api\Client;
use WeltPixel\ShopifyCheckout\Model\Checkout\QuoteSessionStatus;
use WeltPixel\ShopifyCheckout\Model\Config;
use WeltPixel\ShopifyCheckout\Model\Webhook\OrderProcessor;
use WeltPixel\ShopifyCheckout\Model\ResourceModel\QuoteSession\CollectionFactory as QuoteSessionCollectionFactory;

/**
 * Cron job that retries syncing Shopify orders when webhooks were not processed.
 */
class SyncPendingOrders
{
    /**
     * @var QuoteSessionCollectionFactory
     */
    private QuoteSessionCollectionFactory $quoteSessionCollectionFactory;

    /**
     * @var Client
     */
    private Client $client;

    /**
     * @var OrderProcessor
     */
    private OrderProcessor $orderProcessor;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var State
     */
    private State $appState;

    /**
     * @param QuoteSessionCollectionFactory $quoteSessionCollectionFactory
     * @param Client $client
     * @param OrderProcessor $orderProcessor
     * @param Config $config
     * @param LoggerInterface $logger
     * @param State $appState
     */
    public function __construct(
        QuoteSessionCollectionFactory $quoteSessionCollectionFactory,
        Client $client,
        OrderProcessor $orderProcessor,
        Config $config,
        LoggerInterface $logger,
        State $appState
    ) {
        $this->quoteSessionCollectionFactory = $quoteSessionCollectionFactory;
        $this->client = $client;
        $this->orderProcessor = $orderProcessor;
        $this->config = $config;
        $this->logger = $logger;
        $this->appState = $appState;
    }

    /**
     * Execute cron job to pull pending Shopify orders and process them locally.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->ensureAreaCode();
        $environment = $this->config->getEnvironment();
        $collection = $this->quoteSessionCollectionFactory->create();
        $collection->addFieldToFilter('status', ['neq' => QuoteSessionStatus::STATUS_COMPLETED]);

        foreach ($collection as $session) {
            $checkoutToken = $session->getCheckoutToken();
            $checkoutId = $session->getCheckoutId();
            if (!$checkoutToken) {
                continue;
            }

            try {
                $order = $this->loadOrderFromShopify($checkoutId, $checkoutToken, $environment);
                if ($order) {
                    $this->orderProcessor->process($order);
                }
            } catch (\Throwable $exception) {
                $this->logger->error(
                    'Shopify cron order sync failed',
                    [
                        'quote_id' => $session->getQuoteId(),
                        'checkout_token' => $checkoutToken,
                        'exception' => $exception,
                    ]
                );
            }
        }
    }

    /**
     * Attempt to find the Shopify order associated with a checkout session.
     *
     * @param string|null $checkoutId
     * @param string $checkoutToken
     * @param string $environment
     * @return array<string,mixed>|null
     */
    private function loadOrderFromShopify(
        ?string $checkoutId,
        string $checkoutToken,
        string $environment
    ): ?array {
        if ($checkoutId) {
            try {
                $draftResponse = $this->client->callAdmin(
                    'GET',
                    'draft_orders/' . $checkoutId . '.json',
                    [],
                    [],
                    null,
                    $environment
                );

                $draft = $draftResponse['body']['draft_order'] ?? null;
                $orderId = $draft['order_id'] ?? null;

                if ($orderId) {
                    $orderResponse = $this->client->callAdmin(
                        'GET',
                        'orders/' . $orderId . '.json',
                        [],
                        [],
                        null,
                        $environment
                    );

                    return $orderResponse['body']['order'] ?? null;
                }
            } catch (\Throwable $draftException) {
                $this->logger->warning(
                    'Unable to load Shopify draft order during cron sync',
                    ['checkout_id' => $checkoutId, 'exception' => $draftException]
                );
            }
        }

        $orderResponse = $this->client->callAdmin(
            'GET',
            'orders.json',
            [
                'checkout_token' => $checkoutToken,
                'status' => 'any',
                'limit' => 1,
            ],
            [],
            null,
            $environment
        );

        $orders = $orderResponse['body']['orders'] ?? [];

        return $orders[0] ?? null;
    }

    /**
     * Ensure front-end area code is set for the cron execution.
     *
     * @return void
     */
    private function ensureAreaCode(): void
    {
        try {
            $this->appState->getAreaCode();
        } catch (LocalizedException $exception) {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
        }
    }
}
