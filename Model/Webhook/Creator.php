<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\Webhook;

use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use WeltPixel\ShopifyCheckout\Exception\ApiException;
use WeltPixel\ShopifyCheckout\Model\Api\Client;
use WeltPixel\ShopifyCheckout\Model\Config;

/**
 * Manages registration and removal of Shopify webhook subscriptions.
 */
class Creator
{
    /**
     * Default webhook topics that support order creation and fulfillment workflows.
     */
    private const DEFAULT_WEBHOOK_TOPICS = [
        'orders/create',
        'orders/paid',
        'orders/fulfilled',
    ];

    /**
     * Shopify API client.
     *
     * @var Client
     */
    private Client $client;

    /**
     * Shopify configuration provider.
     *
     * @var Config
     */
    private Config $config;

    /**
     * Store manager for resolving base URLs.
     *
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @param Client $client
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Client $client,
        Config $config,
        StoreManagerInterface $storeManager
    ) {
        $this->client = $client;
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    /**
     * Ensure that the default Shopify webhooks exist for the specified environment.
     *
     * @param string|null $environment
     * @return array<int,array<string,mixed>>
     * @throws \Exception
     */
    public function ensureDefaultWebhooks(?string $environment = null): array
    {
        $environment = $environment ? strtolower($environment) : $this->config->getEnvironment();

        if (!in_array($environment, ['sandbox', 'production'], true)) {
            throw new \InvalidArgumentException(__('Unsupported environment: %1', $environment));
        }

        $storeDomain = $this->config->getStoreDomain(null, ScopeInterface::SCOPE_STORE, $environment);
        $accessToken = $this->config->getAdminAccessToken(null, ScopeInterface::SCOPE_STORE, $environment);

        if (!$storeDomain || !$accessToken) {
            throw new \RuntimeException(__('Shopify store domain or access token is missing in configuration.'));
        }

        $address = rtrim($this->storeManager->getStore()->getBaseUrl(), '/') . '/shopifycheckout/webhook/order';
        $results = [];

        foreach (self::DEFAULT_WEBHOOK_TOPICS as $topic) {
            $existing = $this->findExistingWebhook($topic, $address, $environment);

            if ($existing) {
                $results[] = [
                    'topic' => $topic,
                    'status' => 'exists',
                    'id' => (int) ($existing['id'] ?? 0),
                ];
                continue;
            }

            $response = $this->client->callAdmin('POST', 'webhooks.json', [
                'webhook' => [
                    'topic' => $topic,
                    'address' => $address,
                    'format' => 'json',
                ],
            ], [], null, $environment);

            $webhook = $response['body']['webhook'] ?? null;

            if (!$webhook || !isset($webhook['id'])) {
                throw new ApiException(__('Unexpected response from Shopify when creating webhook for topic %1.', $topic));
            }

            $results[] = [
                'topic' => $topic,
                'status' => 'created',
                'id' => (int) $webhook['id'],
            ];
        }

        return $results;
    }

    /**
     * Remove default webhook topics for the given environment if present.
     *
     * @param string|null $environment
     * @return array<int,array<string,mixed>>
     */
    public function deleteDefaultWebhooks(?string $environment = null): array
    {
        $environment = $environment ? strtolower($environment) : $this->config->getEnvironment();

        if (!in_array($environment, ['sandbox', 'production'], true)) {
            throw new \InvalidArgumentException(__('Unsupported environment: %1', $environment));
        }

        $address = rtrim($this->storeManager->getStore()->getBaseUrl(), '/') . '/shopifycheckout/webhook/order';
        $results = [];

        foreach (self::DEFAULT_WEBHOOK_TOPICS as $topic) {
            $existing = $this->findExistingWebhook($topic, $address, $environment);

            if (!$existing || !isset($existing['id'])) {
                $results[] = [
                    'topic' => $topic,
                    'status' => 'missing',
                ];
                continue;
            }

            try {
                $this->client->callAdmin('DELETE', 'webhooks/' . $existing['id'] . '.json', [], [], null, $environment);
                $results[] = [
                    'topic' => $topic,
                    'status' => 'deleted',
                ];
            } catch (ApiException $exception) {
                $results[] = [
                    'topic' => $topic,
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Find an existing webhook that matches the topic and target address.
     *
     * @param string $topic
     * @param string $address
     * @param string $environment
     * @return array<string,mixed>|null
     */
    private function findExistingWebhook(string $topic, string $address, string $environment): ?array
    {
        try {
            $response = $this->client->callAdmin('GET', 'webhooks.json', ['topic' => $topic], [], null, $environment);
        } catch (ApiException $exception) {
            return null;
        }

        $webhooks = $response['body']['webhooks'] ?? [];

        foreach ($webhooks as $webhook) {
            $webhookAddress = isset($webhook['address']) ? rtrim((string) $webhook['address'], '/') : '';
            if ($webhookAddress === rtrim($address, '/')) {
                return $webhook;
            }
        }

        return null;
    }
}
