<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\Checkout;

use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;
use WeltPixel\ShopifyCheckout\Exception\ApiException;
use WeltPixel\ShopifyCheckout\Model\Api\Client;
use WeltPixel\ShopifyCheckout\Model\Checkout\Data\CheckoutSession;
use WeltPixel\ShopifyCheckout\Model\ProductSync\DynamicProductManager;
use WeltPixel\ShopifyCheckout\Model\Config;

/**
 * Builds Shopify draft orders and obtains checkout URLs for Magento quotes.
 */
class SessionCreator
{
    /**
     * Shopify REST client.
     *
     * @var Client
     */
    private Client $client;

    /**
     * Draft order payload builder.
     *
     * @var PayloadBuilder
     */
    private PayloadBuilder $payloadBuilder;

    /**
     * Shopify configuration.
     *
     * @var Config
     */
    private Config $config;

    /**
     * Module logger.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Dynamic Shopify product manager.
     *
     * @var DynamicProductManager
     */
    private DynamicProductManager $dynamicProductManager;

    /**
     * @param Client $client
     * @param PayloadBuilder $payloadBuilder
     * @param Config $config
     * @param LoggerInterface $logger
     * @param DynamicProductManager $dynamicProductManager
     */
    public function __construct(
        Client $client,
        PayloadBuilder $payloadBuilder,
        Config $config,
        LoggerInterface $logger,
        DynamicProductManager $dynamicProductManager
    ) {
        $this->client = $client;
        $this->payloadBuilder = $payloadBuilder;
        $this->config = $config;
        $this->logger = $logger;
        $this->dynamicProductManager = $dynamicProductManager;
    }

    /**
     * Creates a Shopify checkout session for the provided Magento quote.
     *
     * @param Quote $quote
     * @param array<string,mixed> $options
     * @return CheckoutSession
     * @throws ApiException
     */
    public function create(Quote $quote, array $options = []): CheckoutSession
    {
        $storeId = (string) $quote->getStoreId();
        $environment = $this->config->getEnvironment($storeId);
        $context = [];

        if ($this->config->shouldCreateProductsBeforeCheckout($storeId)) {
            $context[PayloadBuilder::CONTEXT_DYNAMIC_PRODUCTS] = $this->dynamicProductManager->prepareLineItemProducts(
                $quote,
                $environment
            );
        }

        $payload = $this->payloadBuilder->buildDraftOrderInput($quote, $context);

        if (strtolower($environment) === 'sandbox') {
            $payload['draft_order']['test'] = true;
        }

        $response = $this->client->callAdmin(
            'POST',
            'draft_orders.json',
            $payload,
            [],
            null,
            $environment,
            $storeId
        );
        $body = $response['body']['draft_order'] ?? null;

        if (!$body) {
            $this->logger->error('Shopify response missing draft_order data.', ['body' => $response['body'] ?? []]);
            throw new ApiException(__('Shopify response did not include expected draft order data.'));
        }

        if (!isset($body['invoice_url'], $body['id'])) {
            $this->logger->error('Shopify draft order response missing required fields.', ['body' => $body]);
            throw new ApiException(__('Shopify draft order response missing required fields.'));
        }

        $token = $this->extractTokenFromUrl((string) $body['invoice_url']);

        return new CheckoutSession((string) $body['invoice_url'], $token, (string) $body['id']);
    }

    private function extractTokenFromUrl(string $checkoutUrl): string
    {
        $path = parse_url($checkoutUrl, PHP_URL_PATH) ?: '';
        $segments = array_values(array_filter(explode('/', $path)));
        if (!$segments) {
            return md5($checkoutUrl);
        }

        $last = array_pop($segments);

        if (strtolower($last) === 'invoice' && $segments) {
            $last = array_pop($segments);
        }

        return $last ?: md5($checkoutUrl);
    }

}
